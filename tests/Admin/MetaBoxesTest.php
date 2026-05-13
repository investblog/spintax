<?php

namespace Spintax\Tests\Admin;

use Spintax\Admin\MetaBoxes;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

/**
 * Exercises the template meta-box TTL save path after the 2.0.4 swap
 * to the shared TtlField helper:
 *  - empty preset (Use global default) deletes the per-template meta
 *  - preset value persists as int
 *  - custom preset persists the user-supplied int
 */
class MetaBoxesTest extends \WP_UnitTestCase {

	private MetaBoxes $meta_boxes;
	private int $admin_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->post_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'X',
			)
		);

		$_POST = array(
			'spintax_meta_nonce' => wp_create_nonce( 'spintax_meta_save' ),
		);

		$this->meta_boxes = new MetaBoxes();
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	private function save_with(): void {
		$post = get_post( $this->post_id );
		$this->meta_boxes->save( $this->post_id, $post );
	}

	public function test_empty_preset_deletes_per_template_ttl(): void {
		update_post_meta( $this->post_id, OptionKeys::META_CACHE_TTL, 9999 );

		$_POST['spintax_cache_ttl_preset'] = '';
		$_POST['spintax_cache_ttl_custom'] = '';

		$this->save_with();

		$this->assertSame(
			'',
			get_post_meta( $this->post_id, OptionKeys::META_CACHE_TTL, true ),
			'Empty preset must clear the per-template override (use global default)'
		);
	}

	public function test_preset_value_persists_as_int(): void {
		$_POST['spintax_cache_ttl_preset'] = '604800';
		$_POST['spintax_cache_ttl_custom'] = '';

		$this->save_with();

		$this->assertSame(
			604800,
			(int) get_post_meta( $this->post_id, OptionKeys::META_CACHE_TTL, true )
		);
	}

	public function test_custom_value_persists_as_int(): void {
		$_POST['spintax_cache_ttl_preset'] = 'custom';
		$_POST['spintax_cache_ttl_custom'] = '4242';

		$this->save_with();

		$this->assertSame(
			4242,
			(int) get_post_meta( $this->post_id, OptionKeys::META_CACHE_TTL, true )
		);
	}

	public function test_negative_custom_is_clamped_to_zero(): void {
		$_POST['spintax_cache_ttl_preset'] = 'custom';
		$_POST['spintax_cache_ttl_custom'] = '-50';

		$this->save_with();

		$this->assertSame(
			0,
			(int) get_post_meta( $this->post_id, OptionKeys::META_CACHE_TTL, true )
		);
	}
}
