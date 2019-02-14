<?php

namespace HM\Platform\XRay; // @codingStandardsIgnoreLine inc directory ok.

use PHPUnit\Framework\TestCase;

class Test_Segments extends TestCase {
	function test_get_flattened_segments_from_trace() {
		// Create 2 segments where the total adds up to more than 64KB data
		$segment = [
			'id' => 1,
			'payload' => str_repeat( 'a', 64 * 1024 ),
			'subsegments' => [
				[
					'id' => 2,
					'payload' => str_repeat( 'a', 64 * 1024 ),
				],
			],
		];

		$flattened = get_flattened_segments_from_trace( $segment );
		$this->assertEquals( 2, count( $flattened ) );
	}
}
