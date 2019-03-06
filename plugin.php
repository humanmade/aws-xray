<?php

/**
 * Plugin Name: AWS X-Ray
 * Description: HM Platform plugin for sending data to AWS X-Ray
 * Author: Human made
 * Version: 1.1.0
 */

namespace HM\Platform\XRay;

if ( ! function_exists( __NAMESPACE__ . '\\bootstrap' ) ) {
	// Exit early in the event functions are not available. This likely means X-Ray is
	// disabled in your environment.
	return;
}

add_filter( 'query', __NAMESPACE__ . '\\filter_mysql_query' );
add_action( 'requests-requests.before_request', __NAMESPACE__ . '\\trace_requests_request', 10, 5 );
add_filter( 'hm_platform_cloudwatch_error_handler_error', __NAMESPACE__ . '\\on_cloudwatch_error_handler_error' );
add_filter( 'hm_platform.aws_sdk.params', __NAMESPACE__ . '\\on_hm_platform_aws_sdk_params' );
