<?php

namespace Spintax\Tests\Bindings\Triggers;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\Triggers\SavePostTrigger;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

class SavePostTriggerTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;
	private SavePostTrigger $trigger;
	private int $template_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );

		// The plugin bootstrap registers a global save_post listener.
		// Strip it for the duration of these tests so post fixture creation
		// (`wp_insert_post`, `wp_trash_post`, factory->post->create) does
		// not fire the trigger before we explicitly invoke it.
		remove_all_actions( 'save_post' );
		remove_all_actions( 'save_post_' . TemplatePostType::POST_TYPE );

		$this->repo        = new BindingsRepo();
		$this->template_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Hello',
			)
		);

		// Drive the trigger via on_save_post() manually in each test.
		$this->trigger = new SavePostTrigger( $this->repo, new BindingApplier() );
	}

	private function create_binding( array $overrides = array() ): array {
		$base    = array_replace_recursive(
			array(
				'post_type' => 'post',
				'status'    => 'any',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'target_field',
					'field_key' => '',
				),
				'source'    => array(
					'mode'        => 'template',
					'template_id' => $this->template_id,
				),
				'triggers'  => array(
					'save_post' => true,
				),
				'variables' => array(
					'expose_post_context' => false,
				),
			),
			$overrides
		);
		$created = $this->repo->create( $base );
		$this->assertIsArray( $created, 'binding fixture must persist' );
		return $created;
	}

	public function test_save_post_writes_target_on_matching_post_type(): void {
		$this->create_binding();
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );

		$this->assertSame( 'Hello', get_post_meta( $post_id, 'target_field', true ) );
	}

	public function test_save_post_ignores_mismatched_post_type(): void {
		$this->create_binding( array( 'post_type' => 'page' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );

		$this->assertSame( '', get_post_meta( $post_id, 'target_field', true ) );
	}

	public function test_save_post_ignores_draft_when_status_filter_is_publish(): void {
		$this->create_binding( array( 'status' => 'publish' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );

		$this->assertSame( '', get_post_meta( $post_id, 'target_field', true ) );
	}

	public function test_save_post_runs_for_draft_when_status_filter_is_any(): void {
		$this->create_binding();
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );

		$this->assertSame( 'Hello', get_post_meta( $post_id, 'target_field', true ) );
	}

	public function test_save_post_skips_spintax_templates(): void {
		$this->create_binding();
		// The template CPT save should not be processed by SavePostTrigger
		// (TemplateCascadeTrigger handles that path).
		$this->trigger->on_save_post( $this->template_id, get_post( $this->template_id ), true );

		$this->assertSame( '', get_post_meta( $this->template_id, 'target_field', true ) );
	}

	public function test_save_post_skips_bulk_edit(): void {
		// Bulk-edit signal is request-scoped and cleanable, unlike
		// DOING_AUTOSAVE (a PHP constant which would leak globally and
		// break sibling test files). The autosave branch shares the
		// exact same code path so testing one is sufficient.
		$this->create_binding();
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$_REQUEST['bulk_edit'] = '1';
		try {
			$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );
		} finally {
			unset( $_REQUEST['bulk_edit'] );
		}

		$this->assertSame( '', get_post_meta( $post_id, 'target_field', true ) );
	}

	public function test_save_post_skips_when_save_post_trigger_disabled(): void {
		$this->create_binding( array( 'triggers' => array( 'save_post' => false ) ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );

		$this->assertSame( '', get_post_meta( $post_id, 'target_field', true ) );
	}

	public function test_save_post_skips_trashed_posts(): void {
		$this->create_binding();
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		wp_trash_post( $post_id );

		$this->trigger->on_save_post( $post_id, get_post( $post_id ), true );

		$this->assertSame( '', get_post_meta( $post_id, 'target_field', true ) );
	}
}
