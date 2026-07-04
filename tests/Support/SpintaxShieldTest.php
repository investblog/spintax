<?php

namespace Spintax\Tests\Support;

use Spintax\Support\SpintaxShield;

class SpintaxShieldTest extends \WP_UnitTestCase {

	public function test_encodes_each_structural_character(): void {
		$this->assertSame( '&#123;', SpintaxShield::neutralize( '{' ) );
		$this->assertSame( '&#125;', SpintaxShield::neutralize( '}' ) );
		$this->assertSame( '&#91;', SpintaxShield::neutralize( '[' ) );
		$this->assertSame( '&#93;', SpintaxShield::neutralize( ']' ) );
		$this->assertSame( '&#37;', SpintaxShield::neutralize( '%' ) );
		$this->assertSame( '&#35;', SpintaxShield::neutralize( '#' ) );
	}

	public function test_leaves_safe_content_unchanged(): void {
		$this->assertSame( '', SpintaxShield::neutralize( '' ) );
		$this->assertSame( 'Plain text 123', SpintaxShield::neutralize( 'Plain text 123' ) );
		$this->assertSame( 'a & b < c > d', SpintaxShield::neutralize( 'a & b < c > d' ) );
	}

	public function test_neutralizes_every_construct_class(): void {
		// Enumeration / conditional / plural braces, permutation brackets,
		// variable percent, and #include hash — all in one value.
		$in  = "{a|b} [x] {?v?y} {plural %n%: i|is} %var% \n#include \"s\"";
		$out = SpintaxShield::neutralize( $in );

		$this->assertStringNotContainsString( '{', $out );
		$this->assertStringNotContainsString( '}', $out );
		$this->assertStringNotContainsString( '[', $out );
		$this->assertStringNotContainsString( ']', $out );
		$this->assertStringNotContainsString( '%', $out );
		// `#` itself survives inside the produced entities, but the directive
		// form is broken; assert the live `#include` is gone and `#` encoded.
		$this->assertStringNotContainsString( '#include', $out );
		$this->assertStringContainsString( '&#35;include', $out );
		// Non-structural characters (pipes, quotes, words) survive.
		$this->assertStringContainsString( 'a|b', $out );
	}

	public function test_does_not_double_encode_produced_entities(): void {
		// strtr is a single pass over the input; the '#' inside a produced
		// entity like &#123; must not be re-encoded.
		$this->assertSame( '&#123;', SpintaxShield::neutralize( '{' ) );
		$this->assertStringNotContainsString( '&#35;123', SpintaxShield::neutralize( '{' ) );
	}

	public function test_neutralize_map_encodes_values_not_keys(): void {
		$map = array(
			'product_name' => 'Deal {50}',
			'product_id'   => '42',
		);

		$result = SpintaxShield::neutralize_map( $map );

		$this->assertSame( 'Deal &#123;50&#125;', $result['product_name'] );
		$this->assertSame( '42', $result['product_id'] );
		$this->assertArrayHasKey( 'product_name', $result ); // keys untouched.
	}
}
