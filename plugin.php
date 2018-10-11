<?php

/**
 * Plugin Name: AWS X-Ray
 * Description: HM Platform plugin for sending data to AWS X-Ray
 * Author: Human made
 * Version: 1.0.1
 */

namespace HM\Platform\XRay;

use Exception;
use function HM\Platform\get_aws_sdk;

$GLOBALS['hm_platform_xray_errors'] = [];

global $hm_platform_xray_start_time;
if ( ! $hm_platform_xray_start_time ) {
	$hm_platform_xray_start_time = microtime( true );
}

if ( ! defined( 'AWS_XRAY_DAEMON_IP_ADDRESS' ) ) {
	define( 'AWS_XRAY_DAEMON_IP_ADDRESS', '127.0.0.1' );
}

bootstrap();

/**
 * Bootstrapper for the plugin.
 */
function bootstrap() {
	add_action( 'shutdown', __NAMESPACE__ . '\\on_shutdown', 99 );
	add_filter( 'query', __NAMESPACE__ . '\\filter_mysql_query' );
	add_action( 'requests-requests.before_request', __NAMESPACE__ . '\\trace_requests_request', 10, 5 );
	set_error_handler( __NAMESPACE__ . '\\error_handler', error_reporting() );
	send_trace_to_daemon( get_in_progress_trace() );
}

/**
 * Shutdown callback to process the trace once everything has finished.
 */
function on_shutdown() {
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}

	// Check if we were shut down by an error.
	$last_error = error_get_last();
	if ( $last_error ) {
		call_user_func_array( __NAMESPACE__ . '\\error_handler', $last_error );
	}
	send_trace_to_daemon( get_xhprof_xray_trace() );
	send_trace_to_daemon( get_end_trace() );
}

function error_handler( int $errno, string $errstr, string $errfile = null, int $errline = null ) : bool {
	global $hm_platform_xray_errors;

	$hm_platform_xray_errors[] = compact( 'errno', 'errstr', 'errfile', 'errline' );
	// Allow other error reporting too.
	return false;
}

/**
 * Filter all queries via wpdb to add the filter.
 */
function filter_mysql_query( $query ) {
	// Don't add Trace ID to SEELCT queries as they will MISS in the MysQL query cache.
	if ( stripos( $query, 'SELECT' ) === 0 ) {
		return $query;
	}
	$query .= ' # Trace ID: ' . get_root_trace_id();
	return $query;
}

function trace_requests_request( $url, $headers, $data, $method, $options ) {
	$domain = parse_url( $url, PHP_URL_HOST );
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
		remove_action( 'requests.after_request', $on_complete );
		$trace['in_progress'] = false;
		$trace['end_time'] = microtime( true );
		$trace['http']['response'] = [
			'status' => $response->status_code,
			'content_length' => $response->headers['content-length'],
		];
		send_trace_to_daemon( $trace );
		return $response;
	};
	add_action( 'requests-requests.after_request', $on_complete );

	return $url;
}

function trace_wpdb_query( string $query, float $start_time, float $end_time, $errored ) {
	$trace = [
		'name'       => DB_HOST,
		'id'         => bin2hex( random_bytes( 8 ) ),
		'trace_id'   => get_root_trace_id(),
		'parent_id'  => get_main_trace_id(),
		'type'       => 'subsegment',
		'start_time' => $start_time,
		'end_time'   => $end_time,
		'namespace'  => 'remote',
		'sql'        => [
			'user'            => DB_USER,
			'url'             => DB_HOST,
			'database_type'   => 'mysql',
			'sanitized_query' => $query,
		],
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
 * Send a XRay trace document to AWS using the HTTP API.
 *
 * This is slower than using the XRay Daemon, but more convenient.
 *
 * @param array $trace
 */
function send_trace_to_aws( array $trace ) {
	try {
		$response = get_aws_sdk()->createXRay( [ 'version' => '2016-04-12' ] )->putTraceSegments([
			'TraceSegmentDocuments' => [ json_encode( $trace ) ],
		]);
	} catch ( Exception $e ) {
		trigger_error( $e->getMessage(), E_USER_WARNING );
	}
}

/**
 * Send a XRay trace document to AWS using the local daemon running on port 2000.
 *
 * @param array $trace
 */
function send_trace_to_daemon( array $trace ) {
	$header = '{"format": "json", "version": 1}';
	$messages = get_flattened_segments_from_trace( $trace );
	$socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
	foreach ( $messages as $message ) {
		$string = $header . "\n" . json_encode( $message );
		$sent_bytes = socket_sendto( $socket, $string, mb_strlen( $string ), 0, AWS_XRAY_DAEMON_IP_ADDRESS, 2000 );
	}

	socket_close( $socket );
}

/**
 * To handle traces larger than 64kb we have to flatted out the array of subsegments when required.
 */
function get_flattened_segments_from_trace( array $trace ) : array {
	$segments = [];
	if ( empty( $trace['subsegments'] ) || mb_strlen( json_encode( $trace ) ) < 1024 ) {
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
		$traces = array_reduce( $traces, function ( $traces, $trace ) {
			$parts = explode( '=', $trace );
			$traces[ $parts[0] ] = $parts[1];
			return $traces;
		}, [] );

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
 */
function get_in_progress_trace() : array {
	global $hm_platform_xray_start_time;
	$trace = [
		'name'       => defined( 'HM_ENV' ) ? HM_ENV : 'local',
		'id'         => get_main_trace_id(),
		'trace_id'   => get_root_trace_id(),
		'start_time' => $hm_platform_xray_start_time,
		'service'    => [
			'version' => HM_DEPLOYMENT_REVISION,
		],
		'origin'     => 'AWS::EC2::Instance',
		'http'       => [
			'request' => [
				'method'    => $_SERVER['REQUEST_METHOD'],
				'url'       => ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
				'client_ip' => $_SERVER['REMOTE_ADDR'],
			],
		],
		'metadata' => [
			'$_GET'     => $_GET,
			'$_POST'    => $_POST,
			'$_COOKIE'  => $_COOKIE,
			'$_SERVER'  => $_SERVER,
		],
		'in_progress' => true,
	];

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
	$error_numbers = wp_list_pluck( $hm_platform_xray_errors, 'errno' );
	$is_fatal = in_array( E_ERROR, $error_numbers, true );
	$has_non_fatal_errors = !! array_diff( [ E_ERROR ], $error_numbers );

	return [
		'name'       => defined( 'HM_ENV' ) ? HM_ENV : 'local',
		'id'         => get_main_trace_id(),
		'trace_id'   => get_root_trace_id(),
		'start_time' => $hm_platform_xray_start_time,
		'end_time'   => microtime( true ),
		'user'       => get_current_user_id(),
		'service'    => [
			'version' => HM_DEPLOYMENT_REVISION,
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
		'metadata' => [
			'$_GET'     => $_GET,
			'$_POST'    => $_POST,
			'$_COOKIE'  => $_COOKIE,
			'$_SERVER'  => $_SERVER,
		],
		'fault' => $is_fatal,
		'error' => $has_non_fatal_errors,
		'cause' => $hm_platform_xray_errors ? [
			'exceptions' => array_map( function ( $error ) {
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
			}, array_values( $hm_platform_xray_errors ) ),
		] : null,
		'in_progress' => false,
	];
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
	return $xhprof_trace;
}

function get_xray_segmant_for_xhprof_trace( $item ) : array {
	return [
		'name'        => preg_replace( '~[^\w\s_\.:/%&#=+\-@]~u', '', $item->name ),
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

		$stack = array_filter( $stack, function ( $value ) use ( $pluck_every_n ) : bool {
			static $frame_number = -1;
			$frame_number++;
			return $frame_number % $pluck_every_n === 0;
		} );
	}

	$time_seconds = $sample_interval / 1000000;

	$nodes = [ (object) [
		'name'       => 'main()',
		'value'      => 1,
		'children'   => [],
		'start_time' => $hm_platform_xray_start_time,
		'end_time'   => $hm_platform_xray_start_time,
	] ];

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
		$node = $last_node;
		$node->value += ( $sample_duration / 1000 );
		$node->end_time += $sample_duration;
	} else {
		$nodes[] = $node = (object) [
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
