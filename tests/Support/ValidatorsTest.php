<?php

namespace Spintax\Tests\Support;

use Spintax\Support\Validators;

class ValidatorsTest extends \WP_UnitTestCase {

	public function test_normalize_settings_defaults(): void {
		$result = Validators::normalize_settings( null );
		$this->assertSame( 3600, $result['default_ttl'] );
		$this->assertTrue( $result['editors_can_manage'] );
		$this->assertFalse( $result['debug'] );
		$this->assertSame( 200, $result['logs_max'] );
	}

	public function test_normalize_settings_type_coercion(): void {
		$result = Validators::normalize_settings( array(
			'default_ttl'        => '7200',
			'editors_can_manage' => 0,
			'debug'              => 1,
			'logs_max'           => '500',
		) );
		$this->assertSame( 7200, $result['default_ttl'] );
		$this->assertFalse( $result['editors_can_manage'] );
		$this->assertTrue( $result['debug'] );
		$this->assertSame( 500, $result['logs_max'] );
	}

	public function test_normalize_settings_clamps_ttl(): void {
		$result = Validators::normalize_settings( array( 'default_ttl' => 9999999 ) );
		$this->assertSame( 604800, $result['default_ttl'] );
	}

	public function test_normalize_settings_ignores_unknown_keys(): void {
		$result = Validators::normalize_settings( array( 'unknown_key' => 'value' ) );
		$this->assertArrayNotHasKey( 'unknown_key', $result );
	}

	public function test_normalize_global_variables(): void {
		$result = Validators::normalize_global_variables( array(
			'%CityName%' => 'Moscow',
			'count'      => 42,
			''           => 'empty',
		) );
		$this->assertSame( 'Moscow', $result['cityname'] );
		$this->assertSame( '42', $result['count'] );
		$this->assertArrayNotHasKey( '', $result );
	}

	public function test_normalize_global_variables_non_array(): void {
		$this->assertSame( array(), Validators::normalize_global_variables( 'not-array' ) );
	}

	public function test_clamp_int(): void {
		$this->assertSame( 5, Validators::clamp_int( 5, 1, 10 ) );
		$this->assertSame( 1, Validators::clamp_int( -5, 1, 10 ) );
		$this->assertSame( 10, Validators::clamp_int( 99, 1, 10 ) );
	}
}
