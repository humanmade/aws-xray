<?php

/**
 * Plugin Name: AWS X-Ray
 * Description: HM Platform plugin for sending data to AWS X-Ray
 * Author: Human made
 */

namespace HM\Platform\XRay;

use function HM\Platform\get_aws_sdk;
use Exception;

$GLOBALS['hm_platform_xray_errors'] = [];

add_action( 'shutdown', __NAMESPACE__ . '\\on_shutdown', 99 );
$GLOBALS['hm_platform_prev_error_handler'] = set_error_handler( __NAMESPACE__ . '\\error_handler' );

/**
 * Shutdown callback to process the trace once everything has finished.
 */
function on_shutdown() {
	// Only run on requests via PHP-FPM.
	if ( ! function_exists( 'fastcgi_finish_request' ) ) {
		return;
	}
	fastcgi_finish_request();

	// Check if we were shut down by an error.
	$last_error = error_get_last();
	if ( $last_error ) {
		call_user_func_array( __NAMESPACE__ . '\\error_handler', $last_error );
	}
	send_trace_to_aws( get_trace() );
}

function error_handler( int $errno, string $errstr, string $errfile = null, int $errline = null ) : bool {
	global $hm_platform_xray_errors, $hm_platform_prev_error_handler;
	$hm_platform_xray_errors[ microtime( true ) ] = compact( 'errno', 'errstr', 'errfile', 'errline' );
	// Allow other error reporting too.
	if ( $hm_platform_prev_error_handler ) {
		call_user_func( $hm_platform_prev_error_handler, $errno, $errstr, $errfile, $errline );
	}
	return false;
}

/**
 * Send a XRay trace document to AWS using the HTTP API.
 *
 * This is slower than using the XRay Daemon, but more confenient.
 *
 * @param array $trace
 */
function send_trace_to_aws( $trace ) {
	try {
		$response = get_aws_sdk()->createXRay( [ 'version' => '2016-04-12' ] )->putTraceSegments([
			'TraceSegmentDocuments' => [ json_encode( $trace ) ],
		]);
	} catch ( Exception $e ) {
		trigger_error( $e->getMessage(), E_USER_WARNING );
	}
}

function get_root_trace_id() {
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

function get_trace() : array {
	global $hm_platform_xray_start_time, $hm_platform_xray_errors;
	$xhprof_trace = get_xhprof_trace();
	$subsegments = $xhprof_trace;
	$error_numbers = wp_list_pluck( $hm_platform_xray_errors, 'errno' );
	$is_fatal = in_array( E_ERROR, $error_numbers, true );
	$has_non_fatal_errors = !! array_diff( [ E_ERROR ], $error_numbers );
	return [
		'name'       => HM_ENV,
		'id'         => bin2hex( random_bytes( 8 ) ),
		'trace_id'   => get_root_trace_id(),
		'start_time' => $hm_platform_xray_start_time,
		'end_time'   => microtime( true ),
		'service'    => [
			'version' => HM_DEPLOYMENT_REVISION,
		],
		'user'       => get_current_user_id(),
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
		'subsegments' => array_map( __NAMESPACE__ . '\\get_xray_segmant_for_xhprof_trace', $xhprof_trace ),
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
	];
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
	switch( $type ) {
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
