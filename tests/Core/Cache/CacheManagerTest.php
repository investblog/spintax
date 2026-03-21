<?php

namespace Spintax\Tests\Core\Cache;

use Spintax\Core\Cache\CacheManager;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

class CacheManagerTest extends \WP_UnitTestCase {

	private CacheManager $cache;
	private SettingsRepository $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new SettingsRepository();
		$this->settings->init_cache_salt();
		$this->cache = new CacheManager( $this->settings );
	}

	private function make_template( string $content ): int {
		return wp_insert_post( array(
			'post_type'    => TemplatePostType::POST_TYPE,
			'post_title'   => 'Cache Test',
			'post_content' => $content,
			'post_status'  => 'publish',
		) );
	}

	public function test_get_returns_null_on_miss(): void {
		$id = $this->make_template( 'test' );
		$this->assertNull( $this->cache->get( $id, 'default' ) );
	}

	public function test_set_and_get_roundtrip(): void {
		$id = $this->make_template( 'test' );
		$this->cache->set( $id, 'default', 'cached output' );
		$this->assertSame( 'cached output', $this->cache->get( $id, 'default' ) );
	}

	public function test_different_context_hash_misses(): void {
		$id = $this->make_template( 'test' );
		$this->cache->set( $id, 'hash_a', 'output A' );
		$this->assertNull( $this->cache->get( $id, 'hash_b' ) );
		$this->assertSame( 'output A', $this->cache->get( $id, 'hash_a' ) );
	}

	public function test_invalidate_template_causes_miss(): void {
		$id = $this->make_template( 'test' );
		$this->cache->set( $id, 'default', 'old output' );
		$this->cache->invalidate_template( $id );
		$this->assertNull( $this->cache->get( $id, 'default' ) );
	}

	public function test_invalidate_all_causes_miss(): void {
		$id = $this->make_template( 'test' );
		$this->cache->set( $id, 'default', 'old output' );
		$this->cache->invalidate_all();
		// Need a new CacheManager to pick up the bumped salt.
		$new_cache = new CacheManager( $this->settings );
		$this->assertNull( $new_cache->get( $id, 'default' ) );
	}

	public function test_ttl_zero_disables_caching(): void {
		$id = $this->make_template( 'test' );
		update_post_meta( $id, OptionKeys::META_CACHE_TTL, 0 );
		$this->cache->set( $id, 'default', 'should not cache' );
		$this->assertNull( $this->cache->get( $id, 'default' ) );
	}

	public function test_effective_ttl_uses_template_override(): void {
		$id = $this->make_template( 'test' );
		update_post_meta( $id, OptionKeys::META_CACHE_TTL, 7200 );
		$this->assertSame( 7200, $this->cache->get_effective_ttl( $id ) );
	}

	public function test_effective_ttl_falls_back_to_global(): void {
		$id = $this->make_template( 'test' );
		$this->settings->update( array( 'default_ttl' => 1800 ) );
		$this->assertSame( 1800, $this->cache->get_effective_ttl( $id ) );
	}

	public function test_invalidate_template_updates_timestamp(): void {
		$id = $this->make_template( 'test' );
		$this->cache->invalidate_template( $id );
		$ts = (int) get_post_meta( $id, OptionKeys::META_LAST_REGENERATED, true );
		$this->assertGreaterThan( 0, $ts );
		$this->assertLessThanOrEqual( time(), $ts );
	}
}
