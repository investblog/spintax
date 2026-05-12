<?php

namespace Spintax\Tests\Bindings\Triggers;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\Triggers\TemplateCascadeTrigger;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

class TemplateCascadeTriggerTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;
	private TemplateCascadeTrigger $trigger;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );

		// Strip the globally-registered cascade trigger so fixture
		// creations via wp_insert_post don't bump version stamps
		// before the test explicitly invokes the trigger.
		remove_all_actions( 'save_post_' . TemplatePostType::POST_TYPE );

		$this->repo    = new BindingsRepo();
		$this->trigger = new TemplateCascadeTrigger( $this->repo );
	}

	private function template_post(): \WP_Post {
		$id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Hello',
			)
		);
		return get_post( $id );
	}

	private function cache_version( string $binding_id ): int {
		return (int) get_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding_id, 0 );
	}

	public function test_bump_increments_only_bindings_referencing_template(): void {
		$tpl   = $this->template_post();
		$other = $this->template_post();

		$bound_a = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'a', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl->ID ),
			)
		);
		$bound_b = $this->repo->create(
			array(
				'post_type' => 'page',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'b', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl->ID ),
			)
		);
		$bound_c = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'c', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $other->ID ),
			)
		);

		// Sanity: the repo actually sees all three bindings before we bump.
		$this->assertCount( 3, $this->repo->all(), 'preconditions broken: fixtures did not persist' );
		$this->assertCount( 2, $this->repo->find_by_template_id( $tpl->ID ), 'preconditions broken: template lookup missed bindings' );

		$this->trigger->on_template_save( $tpl->ID, $tpl, true );

		$this->assertSame( 1, $this->cache_version( $bound_a['id'] ) );
		$this->assertSame( 1, $this->cache_version( $bound_b['id'] ) );
		$this->assertSame( 0, $this->cache_version( $bound_c['id'] ), 'unrelated template should not bump version' );
	}

	public function test_bump_accumulates_on_repeated_saves(): void {
		$tpl = $this->template_post();

		$binding = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'a', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => $tpl->ID ),
			)
		);

		$this->trigger->on_template_save( $tpl->ID, $tpl, true );
		$this->trigger->on_template_save( $tpl->ID, $tpl, true );
		$this->trigger->on_template_save( $tpl->ID, $tpl, true );

		$this->assertSame( 3, $this->cache_version( $binding['id'] ) );
	}

	public function test_per_post_bindings_never_bump(): void {
		$tpl = $this->template_post();

		$binding = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'a', 'field_key' => '' ),
				'source'    => array( 'mode' => 'per_post' ),
			)
		);

		$this->trigger->on_template_save( $tpl->ID, $tpl, true );

		$this->assertSame( 0, $this->cache_version( $binding['id'] ) );
	}

	public function test_bump_ignores_non_template_post_types(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$post    = get_post( $post_id );

		$binding = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'a', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => 999 ),
			)
		);

		$this->trigger->on_template_save( $post_id, $post, true );

		$this->assertSame( 0, $this->cache_version( $binding['id'] ) );
	}
}
