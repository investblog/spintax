<?php

namespace Spintax\Tests\Core\Cache;

use Spintax\Core\Cache\CacheManager;
use Spintax\Core\Cache\DependencyInvalidator;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

class DependencyInvalidatorTest extends \WP_UnitTestCase {

	private CacheManager $cache;
	private DependencyInvalidator $deps;

	public function set_up(): void {
		parent::set_up();
		$settings = new SettingsRepository();
		$settings->init_cache_salt();
		$this->cache = new CacheManager( $settings );
		$this->deps  = new DependencyInvalidator( $this->cache );
	}

	private function make_template( string $title ): int {
		return wp_insert_post( array(
			'post_type'    => TemplatePostType::POST_TYPE,
			'post_title'   => $title,
			'post_content' => 'content',
			'post_status'  => 'publish',
		) );
	}

	public function test_record_and_invalidate_parent(): void {
		$parent = $this->make_template( 'Parent' );
		$child  = $this->make_template( 'Child' );

		// Record: parent embeds child.
		$this->deps->record_dependencies( $parent, array( $child ) );

		// Cache parent.
		$this->cache->set( $parent, 'default', 'parent output' );
		$this->assertSame( 'parent output', $this->cache->get( $parent, 'default' ) );

		// Invalidate child → should cascade to parent.
		$this->deps->invalidate_dependents( $child );

		// Parent cache should now miss (version bumped).
		$this->assertNull( $this->cache->get( $parent, 'default' ) );
	}

	public function test_grandparent_cascade(): void {
		$gp    = $this->make_template( 'Grandparent' );
		$parent = $this->make_template( 'Parent' );
		$child  = $this->make_template( 'Child' );

		$this->deps->record_dependencies( $gp, array( $parent ) );
		$this->deps->record_dependencies( $parent, array( $child ) );

		$v_before = $this->cache->get_template_version( $gp );
		$this->deps->invalidate_dependents( $child );
		$v_after = $this->cache->get_template_version( $gp );

		$this->assertGreaterThan( $v_before, $v_after );
	}

	public function test_no_infinite_loop_on_circular(): void {
		$a = $this->make_template( 'A' );
		$b = $this->make_template( 'B' );

		$this->deps->record_dependencies( $a, array( $b ) );
		$this->deps->record_dependencies( $b, array( $a ) );

		// Should not hang or crash.
		$this->deps->invalidate_dependents( $a );
		$this->assertTrue( true );
	}
}
