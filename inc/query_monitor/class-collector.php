<?php

namespace HM\Platform\XRay\Query_Monitor;

use function HM\Platform\XRay\get_in_progress_trace;
use QM_Collector;

class Collector extends QM_Collector {
	public $id = 'aws-xray';
	public $traces;

	public function __construct() {
		add_action( 'aws_xray.send_trace_to_daemon', [ $this, 'trace_sent_to_daemon' ] );
		// The XRay start trace will already have been sent, so we have to backfill it.
		$this->trace_sent_to_daemon( get_in_progress_trace() );
	}

	/**
	 * Track all traces in memory that are send to the X Ray daemon.
	 *
	 * @param array $trace
	 */
	public function trace_sent_to_daemon( array $trace ) {
		$this->traces[] = $trace;
	}
}
