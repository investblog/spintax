<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingResolver;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

class BindingResolverTest extends \WP_UnitTestCase {

	private BindingResolver $resolver;
	private int $post_id;
	private int $template_id;

	public function set_up(): void {
		parent::set_up();
		$this->resolver = new BindingResolver();

		$this->post_id     = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->template_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Hello {world|there}.',
			)
		);
	}

	public function test_template_mode_returns_template_content(): void {
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'template', 'template_id' => $this->template_id ),
				'target' => array( 'key' => 'irrelevant' ),
			),
			$this->post_id
		);
		$this->assertTrue( $result['found'] );
		$this->assertSame( 'Hello {world|there}.', $result['source'] );
		$this->assertSame( BindingResolver::FOUND, $result['reason'] );
	}

	public function test_template_mode_returns_not_found_for_missing_template(): void {
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'template', 'template_id' => 999999 ),
			),
			$this->post_id
		);
		$this->assertFalse( $result['found'] );
		$this->assertSame( BindingResolver::TEMPLATE_MISSING, $result['reason'] );
	}

	public function test_template_mode_rejects_non_template_post_id(): void {
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'template', 'template_id' => $this->post_id ),
			),
			$this->post_id
		);
		$this->assertFalse( $result['found'] );
		$this->assertSame( BindingResolver::TEMPLATE_MISSING, $result['reason'] );
	}

	public function test_template_mode_skips_unpublished_templates(): void {
		$draft = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Draft',
				'post_content' => 'Hidden',
			)
		);
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'template', 'template_id' => $draft ),
			),
			$this->post_id
		);
		$this->assertFalse( $result['found'] );
		$this->assertSame( BindingResolver::TEMPLATE_MISSING, $result['reason'] );
	}

	public function test_template_mode_treats_whitespace_only_content_as_empty(): void {
		$blank = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Blank',
				'post_content' => "   \n   ",
			)
		);
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'template', 'template_id' => $blank ),
			),
			$this->post_id
		);
		$this->assertFalse( $result['found'] );
		$this->assertSame( BindingResolver::TEMPLATE_EMPTY, $result['reason'] );
	}

	public function test_per_post_mode_returns_sibling_meta(): void {
		update_post_meta(
			$this->post_id,
			OptionKeys::META_BINDING_SOURCE_PREFIX . 'hero_subtitle',
			'Hello %name%'
		);
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'per_post' ),
				'target' => array( 'key' => 'hero_subtitle' ),
			),
			$this->post_id
		);
		$this->assertTrue( $result['found'] );
		$this->assertSame( 'Hello %name%', $result['source'] );
	}

	public function test_per_post_mode_missing_meta_returns_not_found(): void {
		$result = $this->resolver->resolve_source(
			array(
				'source' => array( 'mode' => 'per_post' ),
				'target' => array( 'key' => 'hero_subtitle' ),
			),
			$this->post_id
		);
		$this->assertFalse( $result['found'] );
		$this->assertSame( BindingResolver::PER_POST_EMPTY, $result['reason'] );
	}

	public function test_unknown_mode_returns_not_found(): void {
		$result = $this->resolver->resolve_source(
			array( 'source' => array( 'mode' => 'totally_bogus' ) ),
			$this->post_id
		);
		$this->assertFalse( $result['found'] );
		$this->assertSame( BindingResolver::UNKNOWN_MODE, $result['reason'] );
	}
}
