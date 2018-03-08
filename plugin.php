<?php

/**
 * Plugin Name: AWS X-Ray
 * Description: HM Platform plugin for sending data to AWS X-Ray
 * Author: Human made
 */

namespace HM\Platform\XRay;

use function HM\Platform\get_aws_sdk;
use Exception;

add_action( 'shutdown', __NAMESPACE__ . '\\on_shutdown', 99 );

/**
 * Shutdown callback to process the trace once everything has finished.
 */
function on_shutdown() {
	// Only run on requests via PHP-FPM.
	if ( ! function_exists( 'fastcgi_finish_request' ) ) {
		return;
	}
	fastcgi_finish_request();
	send_trace_to_aws( get_trace() );
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

function get_trace() : array {
	global $hm_platform_xray_start_time;
	$xhprof_trace = get_xhprof_trace();
	$subsegments = $xhprof_trace;

	return [
		'name'       => HM_ENV,
		'id'         => bin2hex( random_bytes( 8 ) ),
		'trace_id'   => '1-' . dechex( time() ) . '-' . bin2hex( random_bytes( 12 ) ),
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
	$time = (int) ini_get( 'xhprof.sampling_interval' );
	$max_frames = 1000;

	// Trim to stack to have a theoretical maximum, essentially reducing the resolution.
	if ( count( $stack ) > $max_frames ) {
		$pluck_every_n = ceil( count( $stack ) / $max_frames );
		$stack = array_filter( $stack, function ( int $frame_number ) use ( $pluck_every_n ) : bool {
			return $frame_number % $pluck_every_n === 0;
		}, ARRAY_FILTER_USE_KEY );
	}
	$time_seconds = $time / 1000000;
	$nodes = [ (object) [
		'name'       => 'main()',
		'value'      => 1,
		'children'   => [],
		'start_time' => $hm_platform_xray_start_time,
		'end_time'   => $hm_platform_xray_start_time,
	] ];
	foreach ( $stack as $time => $call_stack ) {
		$call_stack = explode( '==>', $call_stack );
		$nodes = add_children_to_nodes( $nodes, $call_stack, (float) $time );
	}

	return $nodes;
}

/**
 * Accepts [ Node, Node ], [ main, wp-settings, sleep ]
 */
function add_children_to_nodes( array $nodes, array $children, float $sample_time ) : array {
	$last_node = $nodes ? $nodes[ count( $nodes ) - 1 ] : null;
	$this_child = $children[0];
	$time = (int) ini_get( 'xhprof.sampling_interval' );

	if ( ! $time ) {
		$time = 100000;
	}

	$time_seconds = $time / 1000000;

	if ( $last_node && $last_node->name === $this_child ) {
		$node = $last_node;
		$node->value += ( $time / 1000 );
		$node->end_time += $time_seconds;
	} else {
		$nodes[] = $node = (object) [
			'name'       => $this_child,
			'value'      => $time / 1000,
			'children'   => [],
			'start_time' => $sample_time,
			'end_time'   => $sample_time + $time_seconds,
		];
	}
	if ( count( $children ) > 1 ) {
		$node->children = add_children_to_nodes( $node->children, array_slice( $children, 1 ), $sample_time );
	}

	return $nodes;

}
