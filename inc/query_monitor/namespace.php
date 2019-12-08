<?php

namespace HM\Platform\XRay\Query_Monitor;

use QM_Collectors;

function bootstrap() {
	add_filter( 'qm/outputter/html', __NAMESPACE__ . '\\register_qm_output_html' );
	add_filter( 'qm/collectors', __NAMESPACE__ . '\\register_qm_collector' );
}

/**
 * Register the Query Monitor collector for XRay
 *
 * @param array $collectors
 * @return array
 */
function register_qm_collector( array $collectors ) : array {
	// When the collector registration happens, also enquque the scripts.
	// There's no hook in Query Monitor to output the scripts / assets on
	// pages only where it is used. As a workaround, we enqueue them on any
	// page where the QM Collectors are initiated. This makes this function
	// have side-effects which is not good. But this is still better than
	// enqueueing them on all pages.
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
	add_action( 'login_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
	add_action( 'enqueue_embed_scripts', __NAMESPACE__ . '\\enqueue_assets' );

	// Disable Xray ending the request on processing data.
	add_filter( 'aws_xray.use_fastcgi_finish_request', '__return_false' );

	// Make sure the XRay shutdown function runs before the Query Monitor one.
	remove_filter( 'shutdown', 'HM\\Platform\\XRay\\on_shutdown' );
	add_filter( 'shutdown', 'HM\\Platform\\XRay\\on_shutdown', -1 );

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
	require_once __DIR__ . '/class-output-html.php';
	require_once __DIR__ . '/class-output-flamegraph-html.php';

	$output['aws-xray'] = new Output_Html( QM_Collectors::get( 'aws-xray' ) );
	$output['aws-xray-flamegraph'] = new Output_Flamegraph_Html( QM_Collectors::get( 'aws-xray' ) );

	return $output;
}

/**
 * Enqueue the assets for the Query Monitor custom panel.
 */
function enqueue_assets() {
	wp_enqueue_script( 'aws-xray-flamegraph', plugin_dir_url( dirname( __FILE__, 2 ) ) . 'assets/flamegraph.js', [], '2019-11-13' );
}
