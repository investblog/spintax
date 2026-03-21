<?php

namespace Spintax\Tests\Core\PostType;

use Spintax\Core\PostType\TemplatePostType;

class TemplatePostTypeTest extends \WP_UnitTestCase {

	public function test_post_type_is_registered(): void {
		$this->assertTrue( post_type_exists( TemplatePostType::POST_TYPE ) );
	}

	public function test_post_type_is_not_public(): void {
		$obj = get_post_type_object( TemplatePostType::POST_TYPE );
		$this->assertFalse( $obj->public );
	}

	public function test_post_type_has_ui(): void {
		$obj = get_post_type_object( TemplatePostType::POST_TYPE );
		$this->assertTrue( $obj->show_ui );
	}

	public function test_block_editor_disabled(): void {
		$cpt = new TemplatePostType();
		$this->assertFalse( $cpt->disable_block_editor( true, TemplatePostType::POST_TYPE ) );
		$this->assertTrue( $cpt->disable_block_editor( true, 'post' ) );
	}

	public function test_can_create_template_post(): void {
		$post_id = wp_insert_post( array(
			'post_type'    => TemplatePostType::POST_TYPE,
			'post_title'   => 'Test Template',
			'post_content' => '{Hello|Hi} World',
			'post_status'  => 'publish',
		) );

		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertSame( TemplatePostType::POST_TYPE, $post->post_type );
		$this->assertSame( '{Hello|Hi} World', $post->post_content );
	}
}
