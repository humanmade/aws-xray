<?php

namespace HM\Platform\XRay;

use GuzzleHttp\TransferStats;
use WP_Object_Cache;

/*
 * Set initial values and register handlers
 */
function bootstrap() {
	$GLOBALS['hm_platform_xray_errors'] = [];

	global $hm_platform_xray_start_time;
	if ( ! $hm_platform_xray_start_time ) {
		$hm_platform_xray_start_time = microtime( true );
	}

	if ( ! defined( 'AWS_XRAY_DAEMON_IP_ADDRESS' ) ) {
		define( 'AWS_XRAY_DAEMON_IP_ADDRESS', '127.0.0.1' );
	}

	if ( function_exists( 'xhprof_sample_enable' ) ) {
		ini_set( 'xhprof.sampling_interval', 5000 ); // @codingStandardsIgnoreLine
		xhprof_sample_enable();
	}

	if ( function_exists( 'add_action' ) ) {
		// Hook X-Ray into the 'shutdown' action if possible. This allows plugins to control the
		// load order of shutdown functions as they can use the action's priority argument.
		add_action( 'shutdown', __NAMESPACE__ . '\\on_shutdown_action' );
	}

	// As well as using the shutdown action, we register as "direct" shutdown function in the event
	// that the 'shutdown' action is never registered (such as requests that hit advanced-cache.php),
	// of is the add_action API is not yet available.
	register_shutdown_function( __NAMESPACE__ . '\\on_shutdown' );

	$current_error_handler = set_error_handler( function () use ( &$current_error_handler ) { // @codingStandardsIgnoreLine
		call_user_func_array( __NAMESPACE__ . '\\error_handler', func_get_args() );
		if ( $current_error_handler ) {
			return call_user_func_array( $current_error_handler, func_get_args() );
		}
		// Return false from this function, so the normal PHP error handling is still performed.
		return false;
	});

	send_trace_to_daemon( get_in_progress_trace() );
}

/**
 * Shutdown callback to process the trace once everything has finished.
 *
 * This is called by the 'shutdown' WordPress action.
 */
function on_shutdown_action() {
	$use_fastcgi_finish_request = function_exists( 'fastcgi_finish_request' );
	if ( function_exists( 'apply_filters' ) ) {
		$use_fastcgi_finish_request = apply_filters( 'aws_xray.use_fastcgi_finish_request', $use_fastcgi_finish_request );
	}

	if ( $use_fastcgi_finish_request ) {
		fastcgi_finish_request();
	}

	// Check if we were shut down by an error.
	$last_error = error_get_last();
	if ( $last_error ) {
		call_user_func_array( __NAMESPACE__ . '\\error_handler', $last_error );
	}

	if ( function_exists( 'xhprof_sample_enable' ) ) {
		send_trace_to_daemon( get_xhprof_xray_trace() );
	}

	send_trace_to_daemon( get_end_trace() );
	$object_cache_trace = get_object_cache_trace();
	if ( $object_cache_trace ) {
		send_trace_to_daemon( $object_cache_trace );
	}
}

/**
 * Shutdown callback that is triggered via register_shutdown_function.
 *
 * In some cases the on_shutdown_action() function will not be called, so we have
 * this failsafe shutdown function to catch any cases where on_shutdown_action() is not called.
 *
 */
function on_shutdown() {
	// If we shutdown before the plugin API has loaded, return early.
	if ( ! function_exists( 'has_action' ) ) {
		on_shutdown_action();
		return;
	}
	// If the "shutdown_action_hook" function has not been registered
	// to the shutdown action, call it now.
	if ( has_action( 'shutdown', __NAMESPACE__ . '\\on_shutdown_action' ) === false ) {
		on_shutdown_action();
		return;
	}

	// It's possible the script is shutting down before register_shutdown_function( 'shutdown_action_hook' )
	// has been called by WordPress. There's no way to check if this callback has been registered, so we use a heuristic that
	// get_locale() has been defined, which happens just after WordPress calls register_shutdown_function.
	if ( ! function_exists( 'get_locale' ) ) {
		on_shutdown_action();
		return;
	}
}

function error_handler( int $errno, string $errstr, string $errfile = null, int $errline = null ) : bool {
	global $hm_platform_xray_errors;

	$hm_platform_xray_errors[] = compact( 'errno', 'errstr', 'errfile', 'errline' );
	// Allow other error reporting too.
	return false;
}

/**
 * Filter all queries via wpdb to add the filter.
 *
 * @param string $query
 * @return string
 */
function filter_mysql_query( $query ) {
	// Don't add Trace ID to SELECT queries as they will MISS in the MysQL query cache.
	if ( stripos( trim( $query ), 'SELECT' ) === 0 ) {
		return $query;
	}
	$query .= ' # Trace ID: ' . get_root_trace_id();
	return $query;
}

function trace_requests_request( $url, $headers, $data, $method, $options ) {
	$domain = parse_url( $url, PHP_URL_HOST ); // @codingStandardsIgnoreLine
	$trace = [
		'name'       => $domain,
		'id'         => bin2hex( random_bytes( 8 ) ),
		'trace_id'   => get_root_trace_id(),
		'parent_id'  => get_main_trace_id(),
		'type'       => 'subsegment',
		'start_time' => microtime( true ),
		'namespace'  => 'remote',
		'http'       => [
			'request'    => [
				'method'          => $method,
				'url'             => $url,
				'user_agent'      => $options['useragent'],
			],
		],
		'in_progress' => true,
	];
	send_trace_to_daemon( $trace );
	$on_complete = function ( $response ) use ( &$trace, &$on_complete ) {
		global $xray_time_spent_in_remote_requests;
		remove_action( 'http_api_debug', $on_complete );
		$trace['in_progress'] = false;
		$trace['end_time'] = microtime( true );

		$xray_time_spent_in_remote_requests += $trace['end_time'] - $trace['end_time'];

		if ( is_wp_error( $response ) ) {
			$trace['fault'] = true;
			$trace['cause'] = [
				'exceptions' => [
					[
						'id' => bin2hex( random_bytes( 8 ) ),
						'message' => sprintf( '%s (%s)', $response->get_error_message(), $response->get_error_code() ),
					],
				],
			];
		} else {
			$trace['http']['response'] = [
				'status' => $response['response']['code'],
			];
		}

		send_trace_to_daemon( $trace );
		return $response;
	};
	add_action( 'http_api_debug', $on_complete );

	return $url;
}

function trace_wpdb_query( string $query, float $start_time, float $end_time, $errored, $host = null ) {
	$trace = [
		'name'       => $host ?: DB_HOST,
		'id'         => bin2hex( random_bytes( 8 ) ),
		'trace_id'   => get_root_trace_id(),
		'parent_id'  => get_main_trace_id(),
		'type'       => 'subsegment',
		'start_time' => $start_time,
		'end_time'   => $end_time,
		'namespace'  => 'remote',
		'sql'        => [
			'user'            => DB_USER,
			'url'             => $host ?: DB_HOST,
			'database_type'   => 'mysql',
			'sanitized_query' => $query,
		],
		'in_progress' => false,
	];
	if ( $errored ) {
		$trace['fault'] = true;
		$trace['cause'] = [
			'exceptions' => [
				[
					'id' => bin2hex( random_bytes( 8 ) ),
					'message' => $errored,
				],
			],
		];
	}
	send_trace_to_daemon( $trace );
}

/**
 * Send a XRay trace document to AWS using the local daemon running on port 2000.
 *
 * @param array $trace
 */
function send_trace_to_daemon( array $trace ) {
	if ( function_exists( 'apply_filters' ) ) {
		/**
		 * Filters the X-Ray Segment trace before sending it to the daemon.
		 *
		 * @param array $trace The associative array of trace data.
		 */
		$trace = apply_filters( 'aws_xray.trace_to_daemon', $trace );
	}

	if ( function_exists( 'do_action' ) ) {
		do_action( 'aws_xray.send_trace_to_daemon', $trace );
	}

	$header = '{"format": "json", "version": 1}';
	$messages = get_flattened_segments_from_trace( $trace );
	$socket   = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
	foreach ( $messages as $message ) {
		$string = $header . "\n" . json_encode( $message ); // @codingStandardsIgnoreLine wp_json_encode not available.
		$sent_bytes = socket_sendto( $socket, $string, mb_strlen( $string ), 0, AWS_XRAY_DAEMON_IP_ADDRESS, 2000 );
		if ( $sent_bytes === false ) {
			$error = socket_last_error( $socket );
			trigger_error( sprintf( 'Error sending trace to X-Ray daemon, due to error %d (%s) with trace: %s', $error, socket_strerror( $error ), $string ) ); // @codingStandardsIgnoreLine trigger_error ok
		}
	}

	socket_close( $socket );
}

/**
 * To handle traces larger than 64kb we have to flatted out the array of subsegments when required.
 */
function get_flattened_segments_from_trace( array $trace ) : array {
	$max_size = 63 * 1024; // 63 KB, leaving room for UDP headers etc.
	$segments = [];
	if ( empty( $trace['subsegments'] ) || mb_strlen( json_encode( $trace ) ) < $max_size ) { // @codingStandardsIgnoreLine wp_json_encode not available.
		return [ $trace ];
	}

	$subsegments = $trace['subsegments'];
	unset( $trace['subsegments'] );
	$segments[] = $trace;

	foreach ( $subsegments as $subsegment ) {
		$subsegment['parent_id'] = $trace['id'];
		$subsegment['trace_id'] = get_root_trace_id();
		$subsegment['type'] = 'subsegment';
		$segments = array_merge( $segments, get_flattened_segments_from_trace( $subsegment ) );
	}

	return $segments;
}

/**
 * Get the root trace ID for the request
 *
 */
function get_root_trace_id() : string {
	static $trace_id;
	if ( $trace_id ) {
		return $trace_id;
	}
	if ( isset( $_SERVER['HTTP_X_AMZN_TRACE_ID'] ) ) {
		$traces = explode( ';', $_SERVER['HTTP_X_AMZN_TRACE_ID'] );
		$traces = array_reduce(
			$traces, function ( $traces, $trace ) {
				$parts = explode( '=', $trace );
				$traces[ $parts[0] ] = $parts[1];
				return $traces;
			}, []
		);

		if ( isset( $traces['Self'] ) ) {
			$trace_id = $traces['Self'];
		} elseif ( isset( $traces['Root'] ) ) {
			$trace_id = $traces['Root'];
		}
	}

	if ( ! $trace_id ) {
		$trace_id = '1-' . dechex( time() ) . '-' . bin2hex( random_bytes( 12 ) );
	}

	return $trace_id;
}

function get_main_trace_id() : string {
	static $id;
	if ( $id ) {
		return $id;
	}
	$id = bin2hex( random_bytes( 8 ) );
	return $id;
}

/**
 * Get the initial in progress trace for the start of the main segment.
 * NOTE: This function is run before WordPress is bootstrapped, so don't use
 * any WordPress functions here.
 */
function get_in_progress_trace() : array {
	global $hm_platform_xray_start_time;
	$trace = [
		'name'       => defined( 'HM_ENV' ) ? HM_ENV : 'local',
		'id'         => get_main_trace_id(),
		'trace_id'   => get_root_trace_id(),
		'start_time' => $hm_platform_xray_start_time,
		'service'    => [
			'version' => defined( 'HM_DEPLOYMENT_REVISION' ) ? HM_DEPLOYMENT_REVISION : 'dev',
		],
		'origin'     => 'AWS::EC2::Instance',
		'http'       => [
			'request' => [
				'method'    => $_SERVER['REQUEST_METHOD'],
				'url'       => ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
				'client_ip' => $_SERVER['REMOTE_ADDR'],
			],
		],
		'in_progress' => true,
	];
	$metadata = [
		'$_GET'     => $_GET,
		'$_POST'    => $_POST,
		'$_COOKIE'  => $_COOKIE,
		'$_SERVER'  => $_SERVER,
		'response' => [],
	];
	$trace['metadata'] = redact_metadata( $metadata );

	return $trace;
}

/**
 * Get the final trace for the main segment.
 */
function get_end_trace() : array {
	global $hm_platform_xray_start_time, $hm_platform_xray_errors;
	if ( ! $hm_platform_xray_errors ) {
		$hm_platform_xray_errors = [];
	}
	$error_numbers        = _pluck( $hm_platform_xray_errors, 'errno' );
	$is_fatal             = in_array( E_ERROR, $error_numbers, true );
	$has_non_fatal_errors = $error_numbers && ! ! array_diff( [ E_ERROR ], $error_numbers );
	$user                 = function_exists( 'get_current_user_id' ) ?? get_current_user_id();

	$stats = [
		'object_cache' => get_object_cache_stats(),
		'db'           => get_wpdb_stats(),
		'remote'       => get_remote_requests_stats(),
	];

	$trace = [
		'name'       => defined( 'HM_ENV' ) ? HM_ENV : 'local',
		'id'         => get_main_trace_id(),
		'trace_id'   => get_root_trace_id(),
		'start_time' => $hm_platform_xray_start_time,
		'end_time'   => microtime( true ),
		'user'       => $user,
		'service'    => [
			'version' => defined( 'HM_DEPLOYMENT_REVISION' ) ? HM_DEPLOYMENT_REVISION : 'dev',
		],
		'origin'     => 'AWS::EC2::Instance',
		'http'       => [
			'request' => [
				'method'    => $_SERVER['REQUEST_METHOD'],
				'url'       => ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
				'client_ip' => $_SERVER['REMOTE_ADDR'],
			],
			'response' => [
				'status' => http_response_code(),
			],
		],
		'fault' => $is_fatal,
		'error' => $has_non_fatal_errors,
		'cause' => $hm_platform_xray_errors ? [
			'exceptions' => array_map(
				function ( $error ) {
						return [
							'message' => $error['errstr'],
							'type' => get_error_type_for_error_number( $error['errno'] ),
							'stack' => [
								[
									'path' => $error['errfile'],
									'line' => $error['errline'],
								],
							],
						];
				}, array_values( $hm_platform_xray_errors )
			),
		] : null,
		'in_progress' => false,
	];

	$metadata = [
		'$_GET'        => $_GET,
		'$_POST'       => $_POST,
		'$_COOKIE'     => $_COOKIE,
		'$_SERVER'     => $_SERVER,
		'response'     => [
			'headers' => headers_list(),
		],
		'stats'     => $stats,
	];

	$annotations = [
		'memoryUsage' => memory_get_peak_usage() / 1048576, // Convert bytes to mb.
	];

	$trace['metadata'] = redact_metadata( $metadata );
	$trace['annotations'] = $annotations;
	return $trace;
}

function get_xhprof_xray_trace() : array {
	$xhprof_trace = array_map( __NAMESPACE__ . '\\get_xray_segmant_for_xhprof_trace', get_xhprof_trace() );
	if ( ! $xhprof_trace ) {
		return [];
	}
	$xhprof_trace = $xhprof_trace[0];
	$xhprof_trace['trace_id'] = get_root_trace_id();
	$xhprof_trace['name'] = 'xhprof';
	$xhprof_trace['parent_id'] = get_main_trace_id();
	$xhprof_trace['type'] = 'subsegment';
	$xhprof_trace['in_progress'] = false;
	return $xhprof_trace;
}

function get_xray_segmant_for_xhprof_trace( $item ) : array {
	return [
		'name'        => preg_replace( '~[^\\w\\s_\\.:/%&#=+\\\\\\-@]~u', '', $item->name ),
		'subsegments' => array_map( __FUNCTION__, $item->children ),
		'id'          => bin2hex( random_bytes( 8 ) ),
		'start_time'  => $item->start_time,
		'end_time'    => $item->end_time,
	];
}

function get_xhprof_trace() : array {
	if ( ! function_exists( 'xhprof_sample_disable' ) ) {
		return [];
	}

	global $hm_platform_xray_start_time;
	$end_time = microtime( true );

	$stack = xhprof_sample_disable();
	if ( ! $stack ) {
		return [];
	}
	$sample_interval = (int) ini_get( 'xhprof.sampling_interval' );
	$max_frames = 1000;

	// Trim to stack to have a theoretical maximum, essentially reducing the resolution.
	if ( count( $stack ) > $max_frames ) {
		$pluck_every_n = ceil( count( $stack ) / $max_frames );
		$sample_interval = ceil( $sample_interval * ( count( $stack ) / $max_frames ) );

		$stack = array_filter(
			$stack, function ( $value ) use ( $pluck_every_n ) : bool {
				static $frame_number = -1;
				$frame_number++;
				return $frame_number % $pluck_every_n === 0;
			}
		);
	}

	$time_seconds = $sample_interval / 1000000;

	$nodes = [
		(object) [
			'name'       => 'main()',
			'value'      => 1,
			'children'   => [],
			'start_time' => $hm_platform_xray_start_time,
			'end_time'   => $hm_platform_xray_start_time, // End time will be set in add_children_to_nodes as each child node's time bubbles up to the parent.
		],
	];

	foreach ( $stack as $time => $call_stack ) {
		$call_stack = explode( '==>', $call_stack );
		$nodes = add_children_to_nodes( $nodes, $call_stack, (float) $time, $time_seconds );
	}

	return $nodes;
}

/**
 * Accepts [ Node, Node ], [ main, wp-settings, sleep ]
 */
function add_children_to_nodes( array $nodes, array $children, float $sample_time, float $sample_duration ) : array {
	$last_node = $nodes ? $nodes[ count( $nodes ) - 1 ] : null;
	$this_child = $children[0];

	if ( $last_node && $last_node->name === $this_child ) {
		$node            = $last_node;
		$node->value    += ( $sample_duration / 1000 );
		$node->end_time += $sample_duration;
	} else {
		$nodes[] = $node = (object) [  // @codingStandardsIgnoreLine
			'name'       => $this_child,
			'value'      => $sample_duration / 1000,
			'children'   => [],
			'start_time' => $sample_time,
			'end_time'   => $sample_time + $sample_duration,
		];
	}
	if ( count( $children ) > 1 ) {
		$node->children = add_children_to_nodes( $node->children, array_slice( $children, 1 ), $sample_time, $sample_duration );
	}

	return $nodes;

}

function get_error_type_for_error_number( $type ) : string {
	switch ( $type ) {
		case E_ERROR:
			return 'E_ERROR';
		case E_WARNING:
			return 'E_WARNING';
		case E_PARSE:
			return 'E_PARSE';
		case E_NOTICE:
			return 'E_NOTICE';
		case E_CORE_ERROR:
			return 'E_CORE_ERROR';
		case E_CORE_WARNING:
			return 'E_CORE_WARNING';
		case E_COMPILE_ERROR:
			return 'E_COMPILE_ERROR';
		case E_COMPILE_WARNING:
			return 'E_COMPILE_WARNING';
		case E_USER_ERROR:
			return 'E_USER_ERROR';
		case E_USER_WARNING:
			return 'E_USER_WARNING';
		case E_USER_NOTICE:
			return 'E_USER_NOTICE';
		case E_STRICT:
			return 'E_STRICT';
		case E_RECOVERABLE_ERROR:
			return 'E_RECOVERABLE_ERROR';
		case E_DEPRECATED:
			return 'E_DEPRECATED';
		case E_USER_DEPRECATED:
			return 'E_USER_DEPRECATED';
	}
	return '';
}

/**
 * When a PHP error is going to be sent to CloudWatch, append the X-Ray
 * Trace ID to it, so error logs can be tied to Xray.
 *
 * @param array $error
 * @return array
 */
function on_cloudwatch_error_handler_error( array $error ) : array {
	$error['trace_id'] = get_root_trace_id();
	return $error;
}

/*
 * Partial extraction from wp_list_pluck. Extracted in the event the function
 * hasn't yet been defined, such as when there is a fatal early in the boot
 * process.
 */
function _pluck( $list, $field ) {
	/*
		* When index_key is not set for a particular item, push the value
		* to the end of the stack. This is how array_column() behaves.
		*/
	$newlist = [];
	foreach ( $list as $key => $value ) {
		if ( is_object( $value ) ) {
			$newlist[ $key ] = $value->$field;
		} else {
			$newlist[ $key ] = $value[ $field ];
		}
	}

	return $newlist;
}

/**
 * Add an 'on_stats' param to all Guzzle requests send via the AWS SDK.
 *
 * This enabled aws-xray to get data on all requests send with the SDK and report them
 * as remote requests.
 *
 * @param array $params
 * @return array
 */
function on_hm_platform_aws_sdk_params( array $params ) : array {
	$params['http']['on_stats'] = __NAMESPACE__ . '\\on_aws_guzzle_request_stats';
	return $params;
}

/**
 * Callback function for GuzzleHTTP's `on_stats` param.
 *
 * This allows us to send all AWS SDK requests to xray.
 *
 * @param TransferStats $stats
 */
function on_aws_guzzle_request_stats( TransferStats $stats ) {
	global $xray_time_spent_in_remote_requests;
	$xray_time_spent_in_remote_requests += $stats->getHandlerStat( 'total_time' );
	$code = $stats->hasResponse() ? $stats->getResponse()->getStatusCode() : null;

	// For some services the header is requestid, for others: request-id.
	$request_id = $stats->hasResponse() ? $stats->getResponse()->getHeader( 'x-amzn-requestid' ) : null;
	if ( ! $request_id ) {
		$stats->hasResponse() ? $stats->getResponse()->getHeader( 'x-amzn-request-id' ) : null;
	}
	$trace = [
		'name'         => $stats->getRequest()->getUri()->getHost(),
		'id'           => bin2hex( random_bytes( 8 ) ),
		'trace_id'     => get_root_trace_id(),
		'parent_id'    => get_main_trace_id(),
		'type'         => 'subsegment',
		'start_time'   => microtime( true ) - $stats->getHandlerStat( 'total_time' ),
		'end_time'     => microtime( true ),
		'namespace'    => 'remote',
		'http'         => [
			'request'     => [
				'method'     => $stats->getRequest()->getMethod(),
				'url'        => $stats->getHandlerStat( 'url' ),
				'user_agent' => $stats->getRequest()->getHeader( 'user-agent' )[0],
			],
			'response'    => [
				'status'     => $code,
			],
		],
		'aws'          => [
			'request_id'  => $request_id[0] ?? null,
			'operation'   => explode( '.', $stats->getRequest()->getHeader( 'x-amz-target' )[0] ?? 'xray.amz-unknown-target' )[1],
		],
		'in_progress'  => false,
		'fault'        => $code > 499,
		'error'        => $code >= 400 && $code <= 499,
	];

	if ( $code >= 400 ) {
		$trace['cause'] = [
			'exceptions' => [
				[
					'id'      => bin2hex( random_bytes( 8 ) ),
					'message' => (string) $stats->getResponse()->getBody(),
				],
			],
		];
	}
	send_trace_to_daemon( $trace );
}

/**
 * Get the WordPress database time usage stats.
 *
 * @return array {
 *     @type int $time
 * }
 */
function get_wpdb_stats() : array {
	global $wpdb;
	$stats = [];

	if ( isset( $wpdb->time_spent ) ) {
		$stats['time'] = $wpdb->time_spent;
	}

	return $stats;
}

/**
 * Get the remote requests time usage stats.
 *
 * @return array {
 *     @type int $time
 * }
 */
function get_remote_requests_stats() {
	global $xray_time_spent_in_remote_requests;
	return [
		'time' => $xray_time_spent_in_remote_requests,
	];
}

/**
 * Get a segment account for all object-cache time.
 *
 * @return array|null
 */
function get_object_cache_trace() : ?array {
	$stats = get_object_cache_stats();
	if ( empty( $stats['time'] ) ) {
		return null;
	}
	global $hm_platform_xray_start_time;
	return [
		'name'        => 'object-cache',
		'id'          => bin2hex( random_bytes( 8 ) ),
		'trace_id'    => get_root_trace_id(),
		'parent_id'   => get_main_trace_id(),
		'type'        => 'subsegment',
		'start_time'  => $hm_platform_xray_start_time,
		'end_time'    => $hm_platform_xray_start_time + $stats['time'],
		'namespace'   => 'remote',
		'in_progress' => false,
	];
}

/**
 * Get the object cache time usage stats.
 *
 * @return array {
 *     @type int $time
 * }
 */
function get_object_cache_stats() : array {
	global $wp_object_cache;
	if ( ! $wp_object_cache || ! $wp_object_cache instanceof WP_Object_Cache ) {
		return [];
	}

	$stats = [];

	if ( isset( $wp_object_cache->cache_hits ) ) {
		$stats['hits'] = $wp_object_cache->cache_hits;
	}

	if ( isset( $wp_object_cache->cache_misses ) ) {
		$stats['misses'] = $wp_object_cache->cache_misses;
	}

	if ( isset( $wp_object_cache->redis_calls ) ) {
		$stats['remote_calls'] = $wp_object_cache->redis_calls;
	}

	if ( isset( $wp_object_cache->redis ) && isset( $wp_object_cache->redis->time_spent ) ) {
		$stats['time'] = $wp_object_cache->redis->time_spent;
	}

	return $stats;
}

function redact_metadata( $metadata ) {

	$redact_keys_default = [];
	if ( function_exists( 'apply_filters' ) ) {
		$redact_keys_default = apply_filters( 'aws_xray.redact_metadata_keys', $redact_keys_default );
	}

	$redact_keys_required = [
		'$_POST' => [
			'pwd',
		],
	];

	$redact_keys = array_merge_recursive( $redact_keys_default, $redact_keys_required );

	$redacted = $metadata;
	foreach ( $redact_keys as $super => $keys ) {
		if ( ! isset( $metadata[ $super ] ) ) {
			continue;
		}

		foreach ( $keys as $key ) {
			if ( isset( $metadata[ $super ][ $key ] ) ) {
				$redacted[ $super ][ $key ] = 'REDACTED';
			}
		}
	}

	if ( function_exists( 'apply_filters' ) ) {
		$redacted = apply_filters( 'aws_xray.redact_metadata', $redacted );
	}

	return $redacted;
}
