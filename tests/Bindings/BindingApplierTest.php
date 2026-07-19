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

	// --- plan() array shape: the rendered_hash empty-vs-sha1('') distinction ---

	/**
	 * A missing source returns the pre-render blank tuple: rendered_hash is the
	 * empty string, NOT sha1('').
	 */
	public function test_plan_rendered_hash_is_empty_for_source_not_found(): void {
		$binding = $this->make_binding( array( 'source' => array( 'template_id' => 0 ) ) );
		$plan    = $this->applier->plan( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_SOURCE_NOT_FOUND, $plan['result'] );
		$this->assertSame( '', $plan['rendered_hash'] );
		$this->assertFalse( $plan['would_write'] );
	}

	/**
	 * A found source that renders to '' returns SKIP_EMPTY_RENDER whose
	 * rendered_hash is sha1('') — distinct from the source-not-found blank.
	 */
	public function test_plan_rendered_hash_is_sha1_empty_for_empty_render(): void {
		$empty_tpl = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Empty',
				'post_content' => '{|}',
			)
		);
		$binding = $this->make_binding( array( 'source' => array( 'template_id' => $empty_tpl ) ) );
		$plan    = $this->applier->plan( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_EMPTY_RENDER, $plan['result'] );
		$this->assertSame( sha1( '' ), $plan['rendered_hash'] );
		$this->assertFalse( $plan['would_write'] );
	}

	/**
	 * WROTE_EMPTY (regen + clear_on_empty on an empty render) reports sha1('')
	 * and a blank write_value.
	 */
	public function test_plan_wrote_empty_has_sha1_empty_hash_and_blank_write_value(): void {
		$empty_tpl = wp_insert_post(
			array(
				'post_type'    => TemplatePostType::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'Empty2',
				'post_content' => '{|}',
			)
		);
		$binding = $this->make_binding(
			array(
				'source'   => array( 'template_id' => $empty_tpl ),
				'behavior' => array(
					'regenerate_on_save'    => true,
					'preserve_manual_edits' => false,
					'clear_on_empty'        => true,
				),
			)
		);
		$plan = $this->applier->plan( $binding, $this->post_id );

		$this->assertSame( BindingApplier::WROTE_EMPTY, $plan['result'] );
		$this->assertSame( sha1( '' ), $plan['rendered_hash'] );
		$this->assertSame( '', $plan['write_value'] );
		$this->assertTrue( $plan['would_write'] );
	}

	/**
	 * "A scope skip is cheap": an out-of-scope post must reject before any
	 * source resolution (no template get_post / per-post get_post_meta).
	 */
	public function test_out_of_scope_skip_does_not_resolve_source(): void {
		$spy = new class() extends \Spintax\Bindings\BindingResolver {
			public int $calls = 0;

			public function resolve_source( array $binding, int $post_id ): array {
				++$this->calls;
				return parent::resolve_source( $binding, $post_id );
			}
		};

		$applier = new BindingApplier( $spy );
		// Post created in set_up is post_type 'post'; bind for 'page' → out of scope.
		$binding = $this->make_binding( array( 'post_type' => 'page' ) );

		$plan = $applier->plan( $binding, $this->post_id );

		$this->assertSame( BindingApplier::SKIP_OUT_OF_SCOPE_TYPE, $plan['result'] );
		$this->assertSame( 0, $spy->calls );
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

	// --- Runtime ACF target validation (added in 2.0.3) ---

	public function test_plan_skips_acf_target_when_acf_not_loaded(): void {
		if ( function_exists( 'acf_get_field' ) ) {
			$this->markTestSkipped( 'ACF is loaded; cannot exercise the not-loaded branch.' );
		}

		$binding = $this->make_binding(
			array(
				'target' => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_subtitle',
					'field_key' => 'field_xxx',
				),
			)
		);

		$plan = $this->applier->plan( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_ACF_NOT_LOADED, $plan['result'] );
		$this->assertFalse( $plan['would_write'] );
	}

	public function test_apply_does_not_fall_back_to_post_meta_for_acf_without_acf(): void {
		if ( function_exists( 'update_field' ) ) {
			$this->markTestSkipped( 'ACF is loaded; cannot exercise the fallback-removal branch.' );
		}

		$binding = $this->make_binding(
			array(
				'target' => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_subtitle',
					'field_key' => 'field_xxx',
				),
			)
		);

		$result = $this->applier->apply( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_ACF_NOT_LOADED, $result );

		// Crucial: no fallback write to post_meta under the field name.
		// Pre-2.0.3, write_target silently called update_post_meta() when
		// ACF wasn't loaded, which masked the missing validation.
		$this->assertSame( '', (string) get_post_meta( $this->post_id, 'hero_subtitle', true ) );
	}

	public function test_plan_skips_when_field_key_unknown_in_acf(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'Requires ACF runtime helpers.' );
		}

		$binding = $this->make_binding(
			array(
				'target' => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_subtitle',
					'field_key' => 'field_does_not_exist_xxx',
				),
			)
		);

		$plan = $this->applier->plan( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_INVALID_ACF_FIELD, $plan['result'] );
		$this->assertFalse( $plan['would_write'] );
	}

	public function test_plan_skips_when_field_key_resolves_to_different_name(): void {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$this->markTestSkipped( 'Requires ACF runtime helpers.' );
		}

		// Register a local field group named "other_field" with a stable
		// key. The binding will claim that key but call the target "hero_subtitle".
		acf_add_local_field_group(
			array(
				'key'      => 'group_smoke_runtime_guard',
				'title'    => 'Smoke',
				'fields'   => array(
					array(
						'key'   => 'field_smoke_runtime_guard',
						'label' => 'Other field',
						'name'  => 'other_field',
						'type'  => 'text',
					),
				),
				'location' => array(
					array(
						array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ),
					),
				),
			)
		);

		$binding = $this->make_binding(
			array(
				'target' => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_subtitle',
					'field_key' => 'field_smoke_runtime_guard',
				),
			)
		);

		$plan = $this->applier->plan( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_INVALID_ACF_FIELD, $plan['result'] );
		$this->assertFalse( $plan['would_write'] );
	}

	public function test_plan_skips_when_field_key_blank_for_acf_kind(): void {
		if ( ! function_exists( 'acf_get_field' ) ) {
			$this->markTestSkipped( 'Requires ACF runtime helpers.' );
		}

		// Simulate CLI import payload that bypassed save-layer Tier 5.
		$binding = $this->make_binding(
			array(
				'target' => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_subtitle',
					'field_key' => '',
				),
			)
		);

		$plan = $this->applier->plan( $binding, $this->post_id );
		$this->assertSame( BindingApplier::SKIP_INVALID_ACF_FIELD, $plan['result'] );
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

	// --- Product context (2.4.0) ---
	//
	// A binding generating product copy needs the product's own facts. Without them a template can
	// vary its wording but cannot say anything TRUE about the thing it is describing — it would see
	// %post_title% and nothing else. The source itself is tested in its own file; these two assert
	// the applier honours the flag, and only the flag.

	/**
	 * A product-context source that answers without WooCommerce being installed.
	 */
	private function stub_product_context(): \Spintax\Core\Variables\WooCommerceProductContextSource {
		return new class() extends \Spintax\Core\Variables\WooCommerceProductContextSource {
			/**
			 * @param int $product_id Product id.
			 * @return array<string, string>
			 */
			public function build_for_binding( int $product_id ): array {
				return array( 'product_sku' => 'SKU-' . $product_id );
			}
		};
	}

	public function test_product_context_reaches_the_render_when_the_flag_is_on(): void {
		$applier = new BindingApplier( null, null, null, null, $this->stub_product_context() );

		wp_update_post(
			array(
				'ID'           => $this->template_id,
				'post_content' => 'Buy %product_sku% today',
			)
		);

		$binding = $this->make_binding(
			array( 'variables' => array( 'expose_product_context' => true ) )
		);

		$applier->apply( $binding, $this->post_id );

		$this->assertSame(
			'Buy SKU-' . $this->post_id . ' today',
			get_post_meta( $this->post_id, 'spintax_target', true )
		);
	}

	public function test_product_context_stays_out_when_the_flag_is_off(): void {
		$applier = new BindingApplier( null, null, null, null, $this->stub_product_context() );

		wp_update_post(
			array(
				'ID'           => $this->template_id,
				'post_content' => 'Buy %product_sku% today',
			)
		);

		$binding = $this->make_binding(
			array( 'variables' => array( 'expose_product_context' => false ) )
		);

		$applier->apply( $binding, $this->post_id );

		// Unresolved variables render literally — the proof that nothing was merged in.
		$this->assertSame(
			'Buy %product_sku% today',
			get_post_meta( $this->post_id, 'spintax_target', true )
		);
	}

	// =========================================================================
	// Locale + `#def` in the binding render path
	// =========================================================================

	public function test_binding_render_uses_the_source_templates_locale(): void {
		// The template declares a 3-form locale while the site stays 2-form. Rendering through the
		// site locale would make the block an arity error and persist fullwidth-braced wreckage
		// into the target field — and unlike a preview, this output is stored.
		wp_update_post(
			array(
				'ID'           => $this->template_id,
				'post_content' => '{plural 5: файл|файла|файлов}',
			)
		);
		update_post_meta( $this->template_id, OptionKeys::META_LOCALE, 'ru_RU' );

		$plan = $this->applier->plan( $this->make_binding(), $this->post_id );

		// Capitalised by the cosmetic post-process pass. What matters is the form: "файлов" is the
		// 3-form pick for 5 under ru. Under the site locale this would be an arity error and the
		// stored value would carry fullwidth braces.
		$this->assertSame( 'Файлов', trim( $plan['write_value'] ) );
	}

	public function test_a_def_in_per_binding_overrides_is_rolled_once(): void {
		// The overrides block is prepended to the source verbatim, so directives written there
		// behave exactly as they would in the template. Asserted rather than assumed: the same
		// assumption about the globals textarea turned out to be false.
		wp_update_post(
			array(
				'ID'           => $this->template_id,
				'post_content' => '%brand% and %brand%',
			)
		);

		$binding = $this->make_binding(
			array( 'variables' => array( 'overrides' => '#def %brand% = {Acme|Acme Group}' ) )
		);

		// The applier builds its own Renderer, so there is no RNG seam to inject here. Repeating the
		// render is what makes the assertion meaningful: under macro semantics the two halves are
		// independent coin flips, so twenty agreeing pairs would be a one-in-a-million fluke.
		for ( $i = 0; $i < 20; $i++ ) {
			$rendered = trim( $this->applier->plan( $binding, $this->post_id )['write_value'] );
			$halves   = explode( ' and ', $rendered );

			$this->assertCount( 2, $halves, "Unexpected render: {$rendered}" );
			$this->assertSame( $halves[0], $halves[1], 'A #def must read the same at every reference.' );
		}
	}

	public function test_a_set_in_per_binding_overrides_stays_a_macro(): void {
		wp_update_post(
			array(
				'ID'           => $this->template_id,
				'post_content' => '%brand%',
			)
		);

		$binding = $this->make_binding(
			array( 'variables' => array( 'overrides' => '#set %brand% = {Acme|Acme}' ) )
		);

		$plan = $this->applier->plan( $binding, $this->post_id );

		$this->assertSame( 'Acme', trim( $plan['write_value'] ) );
	}
}
