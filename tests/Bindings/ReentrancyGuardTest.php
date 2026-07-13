<?php
/**
 * The re-entrancy guard, and the loop it exists to prevent.
 *
 * Every target before WooCommerce wrote through `update_post_meta()` or `update_field()`, neither of
 * which re-enters WordPress's save cycle. A product field does: `$product->save()` is WooCommerce's
 * canonical writer, and it fires `save_post` — the very hook the binding trigger listens on. Without
 * the guard, a regenerate-on-save product binding applies, saves, re-enters, applies, saves, until
 * the request dies.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\ReentrancyGuard;
use Spintax\Bindings\Triggers\SavePostTrigger;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

class ReentrancyGuardTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;
	private int $template_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );
		ReentrancyGuard::reset();

		// The bootstrap registers a global save_post listener; strip it so creating fixtures does
		// not fire the trigger before the test invokes it deliberately.
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
	}

	public function tear_down(): void {
		ReentrancyGuard::reset();
		parent::tear_down();
	}

	public function test_a_post_is_unguarded_until_a_target_enters(): void {
		$this->assertFalse( ReentrancyGuard::is_active( 7 ) );

		ReentrancyGuard::enter( 7 );
		$this->assertTrue( ReentrancyGuard::is_active( 7 ) );

		ReentrancyGuard::leave( 7 );
		$this->assertFalse( ReentrancyGuard::is_active( 7 ) );
	}

	public function test_the_guard_is_per_post_not_global(): void {
		// A walk writes many products in one request. Guarding post 7 must not silence post 8, or
		// Bulk Apply would skip every product after the first.
		ReentrancyGuard::enter( 7 );

		$this->assertTrue( ReentrancyGuard::is_active( 7 ) );
		$this->assertFalse( ReentrancyGuard::is_active( 8 ) );
	}

	public function test_the_save_post_trigger_stands_down_while_a_target_is_writing(): void {
		$this->repo->create(
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
				'triggers'  => array( 'save_post' => true ),
			)
		);

		$spy = new class() extends BindingApplier {
			/** @var int */
			public int $calls = 0;

			/**
			 * @param array<string, mixed> $binding Binding payload.
			 * @param int                  $post_id Post id.
			 * @return string
			 */
			public function apply( array $binding, int $post_id ): string {
				unset( $binding, $post_id );
				++$this->calls;
				return 'skip_no_write_trigger';
			}
		};

		$trigger = new SavePostTrigger( $this->repo, $spy );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$post    = get_post( $post_id );

		// Baseline: an ordinary save applies the binding.
		$trigger->on_save_post( $post_id, $post, true );
		$this->assertSame( 1, $spy->calls );

		// Now the state a WooCommerce write is in: the target has entered the guard and is calling
		// `$product->save()`, which fires save_post again. The trigger must stand down.
		ReentrancyGuard::enter( $post_id );
		$trigger->on_save_post( $post_id, $post, true );
		$this->assertSame( 1, $spy->calls, 'the applier must not re-enter while a write is in flight' );

		// And once the write finishes, the post is a normal post again.
		ReentrancyGuard::leave( $post_id );
		$trigger->on_save_post( $post_id, $post, true );
		$this->assertSame( 2, $spy->calls, 'releasing the guard must restore the trigger' );
	}
}
