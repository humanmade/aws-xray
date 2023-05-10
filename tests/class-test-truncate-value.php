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

		$redacted = truncate_value( $super_long_query, $max_size );

		$this->assertTrue( mb_strlen( $redacted ) <= $max_size );
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

		$redacted = truncate_value( $query, $max_size );

		$this->assertTrue( mb_strlen( $redacted ) <= $max_size );
		$this->assertEquals( $query, $redacted );

	}

	/**
	 * Test truncate long metadata
	 */
	function test_truncate_long_metadata() {
		$max_size  = 5 * 1024;

		$metadata = [
			'$_GET' => $this->generate_random_array( 5, $max_size * 2 ),
			'$_POST' => $this->generate_random_array( 50, $max_size * 2 ),
			'$_COOKIE' => $this->generate_random_array( 40, $max_size * 2 ),
			'$_SERVER' => $_SERVER,
			'response' => [
				'headers' => headers_list(),
			],
		];

		$size = mb_strlen( serialize( $metadata ) );
		$truncated = truncate_metadata( $metadata );
		$truncated_size = mb_strlen( serialize( $truncated ) );

		$this->assertTrue( $truncated_size < $size );
	}

	/**
	 * Test truncate short metadata
	 */
	function test_truncate_short_metadata() {
		$max_size  = 5 * 1024;

		$metadata = [
			'$_GET' => $this->generate_random_array( 5, $max_size / 2 ),
			'$_POST' => $this->generate_random_array( 50, $max_size ),
			'$_COOKIE' => $this->generate_random_array( 40, $max_size / 2 ),
			'$_SERVER' => $_SERVER,
			'response' => [
				'headers' => headers_list(),
			],
		];

		$size = mb_strlen( serialize( $metadata ) );
		$truncated = truncate_metadata( $metadata );
		$truncated_size = mb_strlen( serialize( $truncated ) );

		$this->assertEquals( $truncated_size, $size );
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
		$keys = array_fill( 0, $length, $this->generate_random_string( 32 ) );
		return array_fill_keys( $keys, $this->generate_random_string( $value_length ) );
	}
}
