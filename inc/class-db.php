<?php

namespace HM\Platform\XRay;

use wpdb;

class DB extends wpdb {
	public function query( $query ) {
		$start = microtime( true );
		$result = parent::query( $query );
		if ( function_exists( __NAMESPACE__ . '\\trace_wpdb_query' ) ) {
			trace_wpdb_query( $query, $start, microtime( true ), $result === false ? $this->last_error : null );
		}
		return $result;
	}
}
