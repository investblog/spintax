<?php
/**
 * Decision-tree applier for binding writes.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\Plan\PlanCode;
use Spintax\Bindings\Plan\PlanInput;
use Spintax\Bindings\Plan\Planner;
use Spintax\Bindings\Target\TargetKind;
use Spintax\Bindings\Target\TargetRegistry;
use Spintax\Core\Render\Renderer;
use Spintax\Core\Variables\AcfSiblingsSource;
use Spintax\Core\Variables\PostContextSource;
use Spintax\Core\Variables\WooCommerceProductContextSource;
use Spintax\Support\OptionKeys;

/**
 * Implements the section-4.4 decision tree for binding writes.
 *
 * Since 2.3.0 the *decision* is a pure function (`Spintax\Bindings\Plan\Planner`)
 * fed a `PlanInput` DTO; this class only resolves the I/O facts, calls the
 * planner, and assembles the return array. Target-kind read/write/validation is
 * dispatched through `Spintax\Bindings\Target\TargetRegistry` rather than inline
 * `kind` branches. The return codes (13 through 2.3.x, 15 since the 2.4.0
 * WooCommerce guards), `apply()`→string and `plan()`→array shapes are unchanged.
 *
 * Three paths:
 *  - When `regenerate_on_save` is ON: overwrite the target on every
 *    trigger (subject to `preserve_manual_edits`).
 *  - Else when `auto_seed_empty` is ON: write only if the target is
 *    currently empty.
 *  - Else: no-op (form-save validation should have caught this).
 *
 * Both write paths honour `clear_on_empty` for empty renders and update
 * the per-binding signature meta (`_spintax_last_render_sig_<id>`)
 * after every successful write.
 */
class BindingApplier {

	// Decision-tree return codes (spec §4.4). Aliased to the single source of
	// truth in PlanCode so external consumers/tests keep working unchanged.
	public const WROTE_SEEDED              = PlanCode::WROTE_SEEDED;
	public const WROTE_REGENERATED         = PlanCode::WROTE_REGENERATED;
	public const WROTE_EMPTY               = PlanCode::WROTE_EMPTY;
	public const SKIP_MANUAL_EDIT_DETECTED = PlanCode::SKIP_MANUAL_EDIT_DETECTED;
	public const SKIP_TARGET_NONEMPTY      = PlanCode::SKIP_TARGET_NONEMPTY;
	public const SKIP_EMPTY_RENDER         = PlanCode::SKIP_EMPTY_RENDER;
	public const SKIP_NO_WRITE_TRIGGER     = PlanCode::SKIP_NO_WRITE_TRIGGER;
	public const SKIP_SOURCE_NOT_FOUND     = PlanCode::SKIP_SOURCE_NOT_FOUND;
	public const SKIP_COLD_START_MANUAL    = PlanCode::SKIP_COLD_START_MANUAL;
	public const SKIP_OUT_OF_SCOPE_TYPE    = PlanCode::SKIP_OUT_OF_SCOPE_TYPE;
	public const SKIP_OUT_OF_SCOPE_STATUS  = PlanCode::SKIP_OUT_OF_SCOPE_STATUS;
	public const SKIP_ACF_NOT_LOADED       = PlanCode::SKIP_ACF_NOT_LOADED;
	public const SKIP_INVALID_ACF_FIELD    = PlanCode::SKIP_INVALID_ACF_FIELD;
	public const SKIP_WC_NOT_LOADED        = PlanCode::SKIP_WC_NOT_LOADED;
	public const SKIP_INVALID_WC_FIELD     = PlanCode::SKIP_INVALID_WC_FIELD;

	/**
	 * Source resolver.
	 *
	 * @var BindingResolver
	 */
	private BindingResolver $resolver;

	/**
	 * Post-context variable source.
	 *
	 * @var PostContextSource
	 */
	private PostContextSource $post_context;

	/**
	 * ACF sibling-field variable source.
	 *
	 * @var AcfSiblingsSource
	 */
	private AcfSiblingsSource $acf_siblings;

	/**
	 * WooCommerce product-context variable source.
	 *
	 * @var WooCommerceProductContextSource
	 */
	private WooCommerceProductContextSource $product_context;

	/**
	 * Render pipeline.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Pure decision function.
	 *
	 * @var Planner
	 */
	private Planner $planner;

	/**
	 * Constructor — accepts optional collaborators for unit tests.
	 *
	 * @param BindingResolver|null                 $resolver     Source resolver.
	 * @param PostContextSource|null               $post_context Post-context variable source.
	 * @param Renderer|null                        $renderer        Render pipeline.
	 * @param AcfSiblingsSource|null               $acf_siblings    ACF sibling-field variable source.
	 * @param WooCommerceProductContextSource|null $product_context Product-context variable source.
	 */
	public function __construct(
		?BindingResolver $resolver = null,
		?PostContextSource $post_context = null,
		?Renderer $renderer = null,
		?AcfSiblingsSource $acf_siblings = null,
		?WooCommerceProductContextSource $product_context = null
	) {
		$this->resolver        = $resolver ?? new BindingResolver();
		$this->post_context    = $post_context ?? new PostContextSource();
		$this->renderer        = $renderer ?? new Renderer();
		$this->acf_siblings    = $acf_siblings ?? new AcfSiblingsSource();
		$this->product_context = $product_context ?? new WooCommerceProductContextSource();
		$this->planner         = new Planner();
	}

	/**
	 * Execute the decision tree against a single post.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return string One of the SELF::* return codes.
	 */
	public function apply( array $binding, int $post_id ): string {
		$plan = $this->plan( $binding, $post_id );
		if ( $plan['would_write'] ) {
			$this->commit( $binding, $post_id, $plan );
		}
		return $plan['result'];
	}

	/**
	 * Compute the planned action without side effects (dry-run).
	 *
	 * Returns the same return code the live `apply()` would produce
	 * plus the rendered preview and current target value, for use by
	 * the Test panel endpoint.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return array{
	 *     result: string,
	 *     rendered: string,
	 *     rendered_hash: string,
	 *     current: string,
	 *     would_write: bool,
	 *     write_value: string
	 * }
	 */
	public function plan( array $binding, int $post_id ): array {
		$blank = array(
			'result'        => PlanCode::SKIP_SOURCE_NOT_FOUND,
			'rendered'      => '',
			'rendered_hash' => '',
			'current'       => '',
			'would_write'   => false,
			'write_value'   => '',
		);

		$target = $this->target_for( $binding );

		// Resolve facts lazily in the historical gate order (spec §4.4 scope
		// filter → §4.4.1 runtime ACF guard → source resolution), rejecting via
		// the pure Planner after each stage. A later stage's read is skipped
		// once an earlier gate rejects, so "a scope skip is cheap" — an
		// out-of-scope post never pays for source resolution or the render.
		// Each staged PlanInput leaves not-yet-resolved facts at their passing
		// defaults, so scope_reject isolates that stage's gate.
		$post              = get_post( $post_id );
		$post_exists       = ( null !== $post );
		$expected_type     = (string) ( $binding['post_type'] ?? '' );
		$post_type_matches = $post_exists && ( '' === $expected_type || $post->post_type === $expected_type );
		$status_filter     = (string) ( $binding['status'] ?? 'any' );
		$status_in_scope   = $post_exists && ( 'publish' !== $status_filter || 'publish' === $post->post_status );

		// Stage 1: scope (post / type / status).
		$reject = $this->planner->scope_reject(
			new PlanInput(
				post_exists: $post_exists,
				post_type_matches: $post_type_matches,
				status_in_scope: $status_in_scope
			)
		);
		if ( null !== $reject ) {
			return array_merge( $blank, array( 'result' => $reject ) );
		}

		// Stage 2: target runtime validity — only after scope clears.
		$runtime_code = $target->validate_runtime( $binding, $post_id );
		$reject       = $this->planner->scope_reject(
			new PlanInput(
				target_runtime_valid: ( null === $runtime_code ),
				target_runtime_code: $runtime_code
			)
		);
		if ( null !== $reject ) {
			return array_merge( $blank, array( 'result' => $reject ) );
		}

		// Stage 3: source resolution — only after runtime clears.
		$source       = $this->resolver->resolve_source( $binding, $post_id );
		$source_found = ! empty( $source['found'] );
		$reject       = $this->planner->scope_reject( new PlanInput( source_found: $source_found ) );
		if ( null !== $reject ) {
			return array_merge( $blank, array( 'result' => $reject ) );
		}

		// Expensive facts (only after all cheap gates clear).
		$rendered   = $this->render_source( $binding, $post_id, (string) $source['source'] );
		$current    = $target->read( $binding, $post_id );
		$stored_sig = (string) get_post_meta( $post_id, $this->signature_key( $binding ), true );

		$full_input = new PlanInput(
			post_exists: $post_exists,
			post_type_matches: $post_type_matches,
			status_in_scope: $status_in_scope,
			target_runtime_valid: true,
			target_runtime_code: null,
			source_found: $source_found,
			rendered: $rendered,
			current_target: $current,
			stored_signature: ( '' === $stored_sig ) ? null : $stored_sig,
			regenerate_on_save: ! empty( $binding['behavior']['regenerate_on_save'] ),
			auto_seed_empty: ! empty( $binding['behavior']['auto_seed_empty'] ),
			preserve_manual_edits: ! empty( $binding['behavior']['preserve_manual_edits'] ),
			clear_on_empty: ! empty( $binding['behavior']['clear_on_empty'] )
		);

		return $this->assemble( $this->planner->plan( $full_input ), $rendered, $current );
	}

	/**
	 * Build the `plan()` return array from a decided code plus resolved facts.
	 *
	 * Pure. Reproduces the exact per-branch field values of the historical
	 * decision tree: empty-render outcomes (`WROTE_EMPTY`, `SKIP_EMPTY_RENDER`)
	 * report an empty rendered value hashed as `sha1('')`; all other post-render
	 * outcomes carry the real rendered string and `sha1($rendered)`; writes set
	 * `write_value` (empty for `WROTE_EMPTY`).
	 *
	 * @param string $code     Decided PlanCode.
	 * @param string $rendered Rendered output.
	 * @param string $current  Current target value.
	 * @return array<string, mixed>
	 */
	private function assemble( string $code, string $rendered, string $current ): array {
		$would_write = PlanCode::is_write( $code );

		if ( PlanCode::WROTE_EMPTY === $code || PlanCode::SKIP_EMPTY_RENDER === $code ) {
			return array(
				'result'        => $code,
				'rendered'      => '',
				'rendered_hash' => sha1( '' ),
				'current'       => $current,
				'would_write'   => $would_write,
				'write_value'   => '',
			);
		}

		return array(
			'result'        => $code,
			'rendered'      => $rendered,
			'rendered_hash' => sha1( $rendered ),
			'current'       => $current,
			'would_write'   => $would_write,
			'write_value'   => $would_write ? $rendered : '',
		);
	}

	/**
	 * Execute a planned write and update the signature meta.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param array<string, mixed> $plan    Output of `plan()` for this run.
	 */
	private function commit( array $binding, int $post_id, array $plan ): void {
		$this->target_for( $binding )->write( $binding, $post_id, (string) $plan['write_value'] );
		update_post_meta( $post_id, $this->signature_key( $binding ), $plan['rendered_hash'] );
	}

	/**
	 * Resolve the target-kind descriptor for a binding.
	 *
	 * Unknown kinds fall back to post-meta, matching the historical `else`
	 * branch of the inline read/write dispatch (kinds are validated to
	 * `acf_field`/`post_meta` by `BindingsRepo::normalize`, so the fallback is
	 * defensive only).
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @return TargetKind
	 */
	private function target_for( array $binding ): TargetKind {
		$kind = (string) ( $binding['target']['kind'] ?? '' );
		return TargetRegistry::get( $kind ) ?? TargetRegistry::get( 'post_meta' );
	}

	/**
	 * Render binding source against the post's variable context.
	 *
	 * Binding `variables.overrides` is prepended to the source as a
	 * `#set` block so the existing renderer picks them up as locals
	 * (sitting between global settings and post-context runtime vars).
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param string               $source  Resolved source text.
	 */
	private function render_source( array $binding, int $post_id, string $source ): string {
		$overrides = (string) ( $binding['variables']['overrides'] ?? '' );
		if ( '' !== trim( $overrides ) ) {
			$source = $overrides . "\n" . $source;
		}

		$runtime_vars = array();
		if ( ! empty( $binding['variables']['expose_post_context'] ) ) {
			$runtime_vars = $this->post_context->build( $post_id );
		}
		if ( ! empty( $binding['variables']['expose_product_context'] ) ) {
			// Layered over post context on purpose: `%product_name%` is the more specific fact when
			// both describe the same thing. Without this, a template generating a product
			// description could only see `%post_title%` — it could vary its wording, but it could
			// not say anything true about the product it was describing.
			$runtime_vars = array_merge( $runtime_vars, $this->product_context->build_for_binding( $post_id ) );
		}
		if ( ! empty( $binding['variables']['expose_acf_siblings'] ) ) {
			// ACF siblings layer over post-context vars: later layers win
			// per the variable resolution order in spec §4.3.
			$runtime_vars = array_merge( $runtime_vars, $this->acf_siblings->build( $binding, $post_id ) );
		}

		return $this->renderer->process_template( $source, $runtime_vars );
	}

	/**
	 * Meta key for this binding's manual-edit signature.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 */
	private function signature_key( array $binding ): string {
		$id = (string) ( $binding['id'] ?? '' );
		return OptionKeys::META_BINDING_RENDER_SIG_PREFIX . $id;
	}
}
