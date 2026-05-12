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

	// --- Bindings: id helpers + reserved-key guard ---

	public function test_is_valid_binding_id_accepts_well_formed_ids(): void {
		$this->assertTrue( Validators::is_valid_binding_id( 'bind_a1b2c3' ) );
		$this->assertTrue( Validators::is_valid_binding_id( 'bind_000000' ) );
		$this->assertTrue( Validators::is_valid_binding_id( 'bind_ffffff' ) );
	}

	public function test_is_valid_binding_id_rejects_malformed(): void {
		$this->assertFalse( Validators::is_valid_binding_id( 'bind_abc' ) );
		$this->assertFalse( Validators::is_valid_binding_id( 'BIND_A1B2C3' ) );
		$this->assertFalse( Validators::is_valid_binding_id( 'bind_xyz!23' ) );
		$this->assertFalse( Validators::is_valid_binding_id( 'a1b2c3' ) );
		$this->assertFalse( Validators::is_valid_binding_id( '' ) );
		$this->assertFalse( Validators::is_valid_binding_id( 123 ) );
		$this->assertFalse( Validators::is_valid_binding_id( null ) );
	}

	public function test_generate_binding_id_produces_valid_id(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$id = Validators::generate_binding_id();
			$this->assertTrue(
				Validators::is_valid_binding_id( $id ),
				"generate_binding_id produced an invalid id: {$id}"
			);
		}
	}

	public function test_is_reserved_meta_key_tier_1(): void {
		// Prefixes.
		$this->assertTrue( Validators::is_reserved_meta_key( '_wp_attachment_metadata' ) );
		$this->assertTrue( Validators::is_reserved_meta_key( '_edit_lock' ) );
		$this->assertTrue( Validators::is_reserved_meta_key( '_oembed_abc' ) );
		// Exact matches.
		$this->assertTrue( Validators::is_reserved_meta_key( '_pingme' ) );
		$this->assertTrue( Validators::is_reserved_meta_key( '_encloseme' ) );
		$this->assertTrue( Validators::is_reserved_meta_key( '_thumbnail_id' ) );
		// Negatives.
		$this->assertFalse( Validators::is_reserved_meta_key( 'my_custom_meta' ) );
		$this->assertFalse( Validators::is_reserved_meta_key( '_acf_field' ), '_acf is not on the WP-internal list' );
		$this->assertFalse( Validators::is_reserved_meta_key( 'post_title' ) );
	}

	public function test_is_plugin_internal_meta_key_tier_2(): void {
		$this->assertTrue( Validators::is_plugin_internal_meta_key( '_spintax_source_hero' ) );
		$this->assertTrue( Validators::is_plugin_internal_meta_key( '_spintax_last_render_sig_bind_a1b2c3' ) );
		$this->assertTrue( Validators::is_plugin_internal_meta_key( '_spintax_binding_cache_v_bind_a1b2c3' ) );
		$this->assertTrue( Validators::is_plugin_internal_meta_key( '_spintax_anything' ), 'umbrella _spintax_ prefix catches future keys' );
		// Negatives.
		$this->assertFalse( Validators::is_plugin_internal_meta_key( 'spintax_source_hero' ), 'no leading underscore — not internal' );
		$this->assertFalse( Validators::is_plugin_internal_meta_key( 'my_meta' ) );
	}

	public function test_is_post_column_tier_3(): void {
		$columns = array(
			'post_title',
			'post_content',
			'post_excerpt',
			'post_name',
			'post_status',
			'post_date',
			'post_date_gmt',
			'post_modified',
			'post_modified_gmt',
			'post_parent',
			'post_author',
			'post_type',
			'post_password',
			'post_content_filtered',
			'menu_order',
			'comment_status',
			'ping_status',
			'to_ping',
			'pinged',
			'guid',
		);
		foreach ( $columns as $col ) {
			$this->assertTrue(
				Validators::is_post_column( $col ),
				"{$col} must be treated as a wp_posts column"
			);
		}
		// Negatives.
		$this->assertFalse( Validators::is_post_column( 'my_subtitle' ) );
		$this->assertFalse( Validators::is_post_column( '_thumbnail_id' ), 'thumbnail_id is meta, caught by tier 1 not tier 3' );
		$this->assertFalse( Validators::is_post_column( 'POST_TITLE' ), 'comparison is case-sensitive' );
	}

	public function test_sanitize_spintax_strips_null_bytes_and_normalises_line_endings(): void {
		$raw     = "line1\r\nline2\r\x00line3";
		$cleaned = Validators::sanitize_spintax( $raw );
		$this->assertStringNotContainsString( "\x00", $cleaned );
		$this->assertStringNotContainsString( "\r", $cleaned );
		$this->assertSame( "line1\nline2\nline3", $cleaned );
	}
}
