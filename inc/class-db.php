<?php

namespace HM\Platform\XRay;

use wpdb;

class DB extends wpdb {
	/**
	 * Track total time waiting for database responses;
	 *
	 * @var integer
	 */
	public $time_spent = 0;

	public function query( $query ) {
		$start = microtime( true );
		$result = parent::query( $query );
		$end = microtime( true );
		if ( function_exists( __NAMESPACE__ . '\\trace_wpdb_query' ) ) {
			trace_wpdb_query( $query, $start, $end, $result === false ? $this->last_error : null );
		}
		$this->time_spent += $end - $start;
		return $result;
	}

	public function get_caller() {
		return $this->wp_debug_backtrace_summary( __CLASS__ );
	}

	/**
	* Return a comma-separated string of functions that have been called to get
	* to the current point in code.
	*
	* @since 3.4.0
	*
	* @see https://core.trac.wordpress.org/ticket/19589
	*
	* @param string $ignore_class Optional. A class to ignore all function calls within - useful
	*                             when you want to just give info about the callee. Default null.
	* @param int    $skip_frames  Optional. A number of stack frames to skip - useful for unwinding
	*                             back to the source of the issue. Default 0.
	* @param bool   $pretty       Optional. Whether or not you want a comma separated string or raw
	*                             array returned. Default true.
	* @return string|array Either a string containing a reversed comma separated trace or an array
	*                      of individual calls.
	*/
	function wp_debug_backtrace_summary( $ignore_class = null, $skip_frames = 0, $pretty = true ) {
		if ( version_compare( PHP_VERSION, '5.2.5', '>=' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$trace = debug_backtrace( false );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$trace = debug_backtrace();
		}

		$caller      = [];
		$check_class = ! is_null( $ignore_class );
		$skip_frames++; // skip this function

		foreach ( $trace as $call ) {
			if ( $skip_frames > 0 ) {
				$skip_frames--;
			} elseif ( isset( $call['class'] ) ) {
				if ( $check_class && $ignore_class === $call['class'] ) {
					continue; // Filter out calls
				}

				$caller[] = "{$call['class']}{$call['type']}{$call['function']}";
			} else {
				if ( in_array( $call['function'], [ 'do_action', 'apply_filters' ], true ) ) {
					$caller[] = "{$call['function']}('{$call['args'][0]}')";
				} elseif ( in_array( $call['function'], [ 'include', 'include_once', 'require', 'require_once' ], true ) ) {
					$caller[] = $call['function'] . "('" . str_replace( [ WP_CONTENT_DIR, ABSPATH ], '', $call['args'][0] ) . "')";
				} else {
					$caller[] = $call['function'];
				}
			}
		}
		if ( $pretty ) {
			return join( ', ', array_reverse( $caller ) );
		} else {
			return $caller;
		}
	}
}
