<?php

namespace HM\Platform\XRay; // @codingStandardsIgnoreLine inc directory ok.

use PHPUnit\Framework\TestCase;

class Test_Truncate_Query extends TestCase {

	function test_truncate_long_query() {
		$max_size = 5 * 1024;
		$long_text = $this->generate_random_string( $max_size * 2 );
		$super_long_query = sprintf(
			"INSERT INTO wp_postmeta(`post_id`,`meta_key`,`meta_values`) values('123', 'long_meta', '%s')",
			$long_text
		);

		$redacted = truncate_query( $super_long_query, $max_size );

		$this->assertTrue( mb_strlen( $redacted ) < $max_size );
	}

	// Test to s
	function test_truncate_short_query() {
		$max_size = 5 * 1024;
		$long_text = $this->generate_random_string( $max_size / 2 );
		$query = sprintf(
			"INSERT INTO wp_postmeta(`post_id`,`meta_key`,`meta_values`) values('123', 'long_meta', '%s')",
			$long_text
		);

		$redacted = truncate_query( $query, $max_size );

		$this->assertTrue( mb_strlen( $redacted ) < $max_size );
		$this->assertEquals( $query, $redacted );
	}

	/**
	 * Generate random string of given length
	 *
	 * @param int $length Length of string.
	 *
	 * @return false|string
	 */
	function generate_random_string( int $length ): string {
		$all_chars   = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$long_string = str_shuffle( str_repeat( $all_chars, ceil( $length / strlen( $all_chars ) ) ) );

		return substr( $long_string, 0, $length );
	}
}
