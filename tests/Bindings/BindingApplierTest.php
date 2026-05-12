<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\Defaults;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

class BindingApplierTest extends \WP_UnitTestCase {

	private BindingApplier $applier;
	private int $post_id;
	private int $template_id;

	public function set_up(): void {
		parent::set_up();

		$this->applier = new BindingApplier();

		$this->post_id     = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->template_id = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Tpl',
				'post_content' => 'Hello world',
			)
		);
	}

	/**
	 * Build a binding with sensible defaults plus per-test overrides.
	 *
	 * @param array<string, mixed> $overrides Deep-merge overrides.
	 */
	private function make_binding( array $overrides = array() ): array {
		$base = array_replace_recursive(
			Defaults::binding(),
			array(
				'id'        => 'bind_test01',
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'spintax_target',
					'field_key' => '',
				),
				'source'    => array(
					'mode'        => 'template',
					'template_id' => $this->template_id,
				),
				'variables' => array(
					'expose_post_context' => false,
				),
			)
		);
		return array_replace_recursive( $base, $overrides );
	}

	private function signature_key( array $binding ): string {
		return OptionKeys::META_BINDING_RENDER_SIG_PREFIX . $binding['id'];
	}

	// --- Path 1: auto-seed (regenerate_on_save=OFF) ---

	public function test_auto_seed_writes_to_empty_target(): void {
		$binding = $this->make_binding();
		$result  = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_SEEDED, $result );
		$this->assertSame( 'Hello world', get_post_meta( $this->post_id, 'spintax_target', true ) );
		$this->assertNotEmpty( get_post_meta( $this->post_id, $this->signature_key( $binding ), true ) );
	}

	public function test_auto_seed_skips_when_target_not_empty(): void {
		update_post_meta( $this->post_id, 'spintax_target', 'pre-existing' );

		$binding = $this->make_binding();
		$result  = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_TARGET_NONEMPTY, $result );
		$this->assertSame( 'pre-existing', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	public function test_auto_seed_skips_when_render_is_empty(): void {
		$blank = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Blank',
				'post_content' => '   ',
			)
		);
		$binding = $this->make_binding( array( 'source' => array( 'template_id' => $blank ) ) );
		$result  = $this->applier->apply( $binding, $this->post_id );

		// Whitespace-only template content is reported as SOURCE_NOT_FOUND
		// by the resolver (BindingResolver::TEMPLATE_EMPTY) — applier
		// short-circuits before reaching the render step.
		$this->assertSame( BindingApplier::SKIP_SOURCE_NOT_FOUND, $result );
	}

	// --- Path 2: regenerate-on-save (preserve_manual_edits=OFF) ---

	public function test_force_regenerate_overwrites_target(): void {
		update_post_meta( $this->post_id, 'spintax_target', 'old value' );

		$binding = $this->make_binding(
			array(
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => false,
				),
			)
		);
		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_REGENERATED, $result );
		$this->assertSame( 'Hello world', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	// --- Path 2: regenerate + preserve_manual_edits with signature ---

	public function test_regenerate_respects_unchanged_signature(): void {
		$binding = $this->make_binding(
			array(
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => true,
				),
			)
		);

		// Pre-seed signature matching current value (simulates a previous successful write).
		update_post_meta( $this->post_id, 'spintax_target', 'Hello world' );
		update_post_meta( $this->post_id, $this->signature_key( $binding ), sha1( 'Hello world' ) );

		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_REGENERATED, $result );
	}

	public function test_regenerate_preserves_manually_edited_target(): void {
		$binding = $this->make_binding(
			array(
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => true,
				),
			)
		);

		// Signature differs from current target → looks like a manual edit.
		update_post_meta( $this->post_id, 'spintax_target', 'human edited' );
		update_post_meta( $this->post_id, $this->signature_key( $binding ), sha1( 'previously rendered' ) );

		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_MANUAL_EDIT_DETECTED, $result );
		$this->assertSame( 'human edited', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	// --- Cold-start path: no signature yet ---

	public function test_cold_start_with_empty_target_writes_and_seeds_signature(): void {
		$binding = $this->make_binding(
			array(
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => true,
				),
			)
		);
		// No pre-existing target value, no signature.

		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_SEEDED, $result );
		$this->assertSame( 'Hello world', get_post_meta( $this->post_id, 'spintax_target', true ) );
		$this->assertNotEmpty( get_post_meta( $this->post_id, $this->signature_key( $binding ), true ) );
	}

	public function test_cold_start_with_existing_target_skips_with_manual_flag(): void {
		update_post_meta( $this->post_id, 'spintax_target', 'human content from before' );
		// No signature stored — first time this binding runs against a post with existing content.

		$binding = $this->make_binding(
			array(
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => true,
				),
			)
		);

		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_COLD_START_MANUAL, $result );
		$this->assertSame( 'human content from before', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	// --- clear_on_empty interaction ---

	/**
	 * Template that renders to empty: a `{?VAR?then|}` conditional with
	 * undefined `VAR` selects the empty else branch.
	 */
	private function empty_render_template_id(): int {
		return wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'EmptyRender',
				'post_content' => '{?undefined_var?content|}',
			)
		);
	}

	public function test_clear_on_empty_writes_blank_when_render_empty(): void {
		$blank_template = $this->empty_render_template_id();
		update_post_meta( $this->post_id, 'spintax_target', 'something to clear' );

		$binding = $this->make_binding(
			array(
				'source'   => array( 'template_id' => $blank_template ),
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => false,
					'clear_on_empty'        => true,
				),
			)
		);

		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_EMPTY, $result );
		$this->assertSame( '', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	public function test_empty_render_without_clear_flag_skips(): void {
		$blank_template = $this->empty_render_template_id();
		update_post_meta( $this->post_id, 'spintax_target', 'kept' );

		$binding = $this->make_binding(
			array(
				'source'   => array( 'template_id' => $blank_template ),
				'behavior' => array(
					'auto_seed_empty'       => false,
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => false,
					'clear_on_empty'        => false,
				),
			)
		);

		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_EMPTY_RENDER, $result );
		$this->assertSame( 'kept', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	// --- Source not found ---

	public function test_source_not_found_returns_short_circuit_code(): void {
		$binding = $this->make_binding( array( 'source' => array( 'template_id' => 999999 ) ) );
		$result  = $this->applier->apply( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_SOURCE_NOT_FOUND, $result );
	}

	// --- No write trigger ---

	public function test_no_write_trigger_returns_misconfig_code(): void {
		$binding = $this->make_binding(
			array(
				'behavior' => array(
					'auto_seed_empty'    => false,
					'regenerate_on_save' => false,
				),
			)
		);
		$result = $this->applier->apply( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_NO_WRITE_TRIGGER, $result );
	}

	// --- Post-context variable exposure ---

	public function test_post_context_variables_resolve_inside_render(): void {
		$template = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Ctx',
				'post_content' => 'Title is %post_title%.',
			)
		);
		wp_update_post(
			array(
				'ID'         => $this->post_id,
				'post_title' => 'My Demo',
			)
		);

		$binding = $this->make_binding(
			array(
				'source'    => array( 'template_id' => $template ),
				'variables' => array( 'expose_post_context' => true ),
			)
		);
		$result = $this->applier->apply( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_SEEDED, $result );
		$this->assertStringContainsString( 'My Demo', get_post_meta( $this->post_id, 'spintax_target', true ) );
	}

	// --- Scope filter (added in 2.0.1) ---

	public function test_plan_skips_out_of_scope_when_post_type_mismatches(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$binding = $this->make_binding(); // post_type=post by default.
		$plan    = $this->applier->plan( $binding, $page_id );

		$this->assertSame( BindingApplier::SKIP_OUT_OF_SCOPE_TYPE, $plan['result'] );
		$this->assertFalse( $plan['would_write'] );
	}

	public function test_apply_skips_out_of_scope_post_type_without_writing(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		// Force a target value the test can introspect; if scope-skip
		// didn't short-circuit, the seed path would overwrite this empty
		// meta to 'Hello world'.
		$binding = $this->make_binding();

		$result = $this->applier->apply( $binding, $page_id );

		$this->assertSame( BindingApplier::SKIP_OUT_OF_SCOPE_TYPE, $result );
		$this->assertSame( '', get_post_meta( $page_id, 'spintax_target', true ) );
	}

	public function test_plan_skips_out_of_scope_when_status_filter_excludes(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			)
		);

		$binding = $this->make_binding( array( 'status' => 'publish' ) );
		$plan    = $this->applier->plan( $binding, $draft_id );

		$this->assertSame( BindingApplier::SKIP_OUT_OF_SCOPE_STATUS, $plan['result'] );
		$this->assertFalse( $plan['would_write'] );
	}

	public function test_plan_allows_published_post_under_publish_filter(): void {
		// $this->post_id is created by the factory in publish status by default.
		$binding = $this->make_binding( array( 'status' => 'publish' ) );
		$plan    = $this->applier->plan( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_SEEDED, $plan['result'] );
		$this->assertTrue( $plan['would_write'] );
	}

	public function test_plan_status_any_allows_draft(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			)
		);

		$binding = $this->make_binding( array( 'status' => 'any' ) );
		$plan    = $this->applier->plan( $binding, $draft_id );

		$this->assertSame( BindingApplier::WROTE_SEEDED, $plan['result'] );
	}

	public function test_plan_returns_out_of_scope_for_unknown_post_id(): void {
		$binding = $this->make_binding();
		$plan    = $this->applier->plan( $binding, 99999999 );

		$this->assertSame( BindingApplier::SKIP_OUT_OF_SCOPE_TYPE, $plan['result'] );
		$this->assertFalse( $plan['would_write'] );
	}

	// --- plan() dry-run ---

	public function test_plan_returns_action_without_writing(): void {
		$binding = $this->make_binding();
		$plan    = $this->applier->plan( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_SEEDED, $plan['result'] );
		$this->assertTrue( $plan['would_write'] );
		$this->assertSame( 'Hello world', $plan['rendered'] );
		$this->assertSame( '', $plan['current'] );
		// No actual write happened.
		$this->assertSame( '', get_post_meta( $this->post_id, 'spintax_target', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, $this->signature_key( $binding ), true ) );
	}
}
