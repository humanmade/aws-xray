<?php

/**
 * Plugin Name: AWS X-Ray
 * Description: HM Platform plugin for sending data to AWS X-Ray
 * Author: Human made
 * Version: 1.0.2
 */

namespace HM\Platform\XRay;

require_once __DIR__ . '/inc/namespace.php';

$GLOBALS['hm_platform_xray_errors'] = [];

global $hm_platform_xray_start_time;
if ( ! $hm_platform_xray_start_time ) {
	$hm_platform_xray_start_time = microtime( true );
}

if ( ! defined( 'AWS_XRAY_DAEMON_IP_ADDRESS' ) ) {
	define( 'AWS_XRAY_DAEMON_IP_ADDRESS', '127.0.0.1' );
}

bootstrap();
