<?php
/**
 * Test coverage for truncate_value function
 */

namespace HM\Platform\XRay;

use PHPUnit\Framework\TestCase;

/**
 * Class Test_Truncate_Value
 *
 * @package HM\Platform\XRay
 */
class Test_Truncate_Value extends TestCase {

	/**
	 * Test truncate long query
	 */
	function test_truncate_long_value() {
		$max_size         = 5 * 1024;
		$long_text        = $this->generate_random_string( $max_size * 2 );
		$super_long_query = sprintf(
			"INSERT INTO wp_postmeta(`post_id`,`meta_key`,`meta_values`) values('123', 'long_meta', '%s')",
			$long_text
		);

		$truncated = truncate_value( $super_long_query, $max_size );

		$this->assertTrue( mb_strlen( $truncated ) <= $max_size );
	}

	/**
	 * Test truncate short query
	 */
	function test_truncate_short_value() {
		$max_size  = 5 * 1024;
		$long_text = $this->generate_random_string( $max_size / 2 );
		$query     = sprintf(
			"INSERT INTO wp_postmeta(`post_id`,`meta_key`,`meta_values`) values('123', 'long_meta', '%s')",
			$long_text
		);

		$truncated = truncate_value( $query, $max_size );

		$this->assertTrue( mb_strlen( $truncated ) <= $max_size );
		$this->assertEquals( $query, $truncated );

	}

	/**
	 * Test truncate long trace
	 */
	function test_truncate_long_trace() {
		$max_size = 63 * 1024; // 63 KB, leaving room for UDP headers etc.
		$max_value_size = 1024; // 1 KB values.

		$metadata = [
			'$_GET' => $this->generate_random_array( 5, $max_value_size ),
			'$_POST' => $this->generate_random_array( 50, $max_value_size * 2 ),
			'$_COOKIE' => $this->generate_random_array( 20, 128 ),
			'response' => [
				'headers' => headers_list(),
			],
		];

		$trace = [
			'metadata' => $metadata,
		];

		$size = mb_strlen( json_encode( $trace ) );
		$truncated = truncate_trace( $trace );
		$truncated_size = mb_strlen( json_encode( $truncated ) );

		$this->assertGreaterThan( $max_size, $size, 'Trace size is greater than max packet size' );
		$this->assertGreaterThan( $truncated_size, $max_size, 'Truncated size is greater than max packet size' );
	}

	/**
	 * Test truncate short trace
	 */
	function test_truncate_short_trace() {
		$max_size = 63 * 1024; // 63 KB, leaving room for UDP headers etc.
		$max_value_size = 512; // 512B values.

		$metadata = [
			'$_GET' => $this->generate_random_array( 3, $max_value_size ),
			'$_POST' => $this->generate_random_array( 8, $max_value_size ),
			'$_COOKIE' => $this->generate_random_array( 3, $max_value_size ),
			'response' => [
				'headers' => headers_list(),
			],
		];

		$trace = [
			'metadata' => $metadata,
		];

		$size = mb_strlen( json_encode( $trace ) );
		$truncated = truncate_trace( $trace );
		$truncated_size = mb_strlen( json_encode( $truncated ) );

		$this->assertEquals( json_encode( $trace ), json_encode( $truncated ), 'A small truncated trace is not the same as the original trace' );
		$this->assertEquals( $truncated_size, $size, 'Truncated trace size is not equal to original trace size' );
		$this->assertGreaterThan( $truncated_size, $max_size, 'Truncated size is greater than the max packet size' );
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

	/**
	 * Generate an array of random values with a given length.
	 *
	 * @param integer $length Number of items in the array.
	 * @param integer $value_length Length of item values.
	 * @return array
	 */
	function generate_random_array( int $length, int $value_length ): array {
		$out = [];
		for ( $i = 0; $i < $length; $i++ ) {
			$out[ $this->generate_random_string( 24 ) ] = $this->generate_random_string( $value_length );
		}
		return $out;
	}
}
