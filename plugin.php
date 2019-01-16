<?php

/**
 * Plugin Name: AWS X-Ray
 * Description: HM Platform plugin for sending data to AWS X-Ray
 * Author: Human made
 * Version: 1.1.0
 */

namespace HM\Platform\XRay;

require_once __DIR__ . '/plugins/aws-xray/inc/namespace.php';

if ( ! is_bootstrapped() ) {
	error_log( "AWS X-Ray has not been bootstrapped yet. Run HM\Platform\XRay\bootstrap() early in your application's boot to bootstrap." );
	return;
}

add_filter( 'query', __NAMESPACE__ . '\\filter_mysql_query' );
add_action( 'requests-requests.before_request', __NAMESPACE__ . '\\trace_requests_request', 10, 5 );
add_filter( 'hm_platform_cloudwatch_error_handler_error', __NAMESPACE__ . '\\on_cloudwatch_error_handler_error' );
