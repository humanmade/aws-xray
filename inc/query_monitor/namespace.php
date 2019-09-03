<?php

namespace HM\Platform\XRay\Query_Monitor;

use QM_Collectors;

function bootstrap() {
	add_filter( 'qm/outputter/html', __NAMESPACE__ . '\\register_qm_output_html' );
	add_filter( 'qm/collectors', __NAMESPACE__ . '\\register_qm_collector' );

	// Disable Xray ending the request on processing data.
	add_filter( 'aws_xray.use_fastcgi_finish_request', '__return_false' );

	// Make sure the XRay shutdown function runs before the Query Monitor one.
	remove_filter( 'shutdown', 'HM\\Platform\\XRay\\on_shutdown' );
	add_filter( 'shutdown', 'HM\\Platform\\XRay\\on_shutdown', -1 );

	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
}

/**
 * Register the Query Monitor collector for XRay
 *
 * @param array $collectors
 * @return array
 */
function register_qm_collector( array $collectors ) : array {
	require_once __DIR__ . '/class-collector.php';
	$collectors['aws-xray'] = new Collector();
	return $collectors;
}
/**
 * Register the HTML outputter for the Xray panel
 *
 * @param array $output
 * @return array
 */
function register_qm_output_html( array $output ) : array {
	ini_set( 'display_errors', 'on' );
	error_reporting( E_ALL );
	require_once __DIR__ . '/class-output-html.php';
	require_once __DIR__ . '/class-output-flamegraph-html.php';

	$output['aws-xray'] = new Output_Html( QM_Collectors::get( 'aws-xray' ) );
	$output['aws-xray-flamegraph'] = new Output_Flamegraph_Html( QM_Collectors::get( 'aws-xray' ) );
	return $output;
}

function enqueue_assets() {
	wp_enqueue_script( 'aws-xray-flamegraph', plugin_dir_url( dirname( __FILE__, 2 ) ) . 'assets/flamegraph.js', [] );
}
