<?php

namespace HM\Platform\XRay; // @codingStandardsIgnoreLine inc directory ok.

use PHPUnit\Framework\TestCase;

class Test_Redact_Metadata extends TestCase {
	function test_redact_metadata() {
		$metadata = [
			'$_POST' => [
				'pwd' => 'foobar',
			],
		];

		$redacted = redact_metadata( $metadata );
		$this->assertEquals( 'REDACTED', $redacted['$_POST']['pwd'] );
	}
}
