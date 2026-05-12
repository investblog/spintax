<?php
/**
 * Decision-tree applier for binding writes.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Render\Renderer;
use Spintax\Core\Variables\AcfSiblingsSource;
use Spintax\Core\Variables\PostContextSource;
use Spintax\Support\OptionKeys;

/**
 * Implements the section-4.4 decision tree for binding writes.
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

	// Decision-tree return codes (spec §4.4).
	public const WROTE_SEEDED              = 'wrote_seeded';
	public const WROTE_REGENERATED         = 'wrote_regenerated';
	public const WROTE_EMPTY               = 'wrote_empty';
	public const SKIP_MANUAL_EDIT_DETECTED = 'skip_manual_edit_detected';
	public const SKIP_TARGET_NONEMPTY      = 'skip_target_nonempty';
	public const SKIP_EMPTY_RENDER         = 'skip_empty_render';
	public const SKIP_NO_WRITE_TRIGGER     = 'skip_no_write_trigger';
	public const SKIP_SOURCE_NOT_FOUND     = 'skip_source_not_found';
	public const SKIP_COLD_START_MANUAL    = 'skip_cold_start_manual';
	// Scope-filter codes (added in 2.0.1, spec §4.4 scope filter).
	public const SKIP_OUT_OF_SCOPE_TYPE   = 'skip_out_of_scope_type';
	public const SKIP_OUT_OF_SCOPE_STATUS = 'skip_out_of_scope_status';
	// Runtime ACF target validation (added in 2.0.3, spec §4.4.1).
	public const SKIP_ACF_NOT_LOADED    = 'skip_acf_not_loaded';
	public const SKIP_INVALID_ACF_FIELD = 'skip_invalid_acf_field';

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
	 * Render pipeline.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Constructor — accepts optional collaborators for unit tests.
	 *
	 * @param BindingResolver|null   $resolver     Source resolver.
	 * @param PostContextSource|null $post_context Post-context variable source.
	 * @param Renderer|null          $renderer     Render pipeline.
	 * @param AcfSiblingsSource|null $acf_siblings ACF sibling-field variable source.
	 */
	public function __construct(
		?BindingResolver $resolver = null,
		?PostContextSource $post_context = null,
		?Renderer $renderer = null,
		?AcfSiblingsSource $acf_siblings = null
	) {
		$this->resolver     = $resolver ?? new BindingResolver();
		$this->post_context = $post_context ?? new PostContextSource();
		$this->renderer     = $renderer ?? new Renderer();
		$this->acf_siblings = $acf_siblings ?? new AcfSiblingsSource();
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
			'result'        => self::SKIP_SOURCE_NOT_FOUND,
			'rendered'      => '',
			'rendered_hash' => '',
			'current'       => '',
			'would_write'   => false,
			'write_value'   => '',
		);

		// Scope filter (spec §4.4 — added in 2.0.1). Runs BEFORE source
		// resolution and rendering so the Test panel and Bulk Apply
		// surface the same skip reason live triggers would, without
		// paying the render cost. Without this gate, test_binding could
		// claim `would_write=true` for a post the real save_post path
		// would never touch.
		$post = get_post( $post_id );
		if ( null === $post ) {
			return array_merge( $blank, array( 'result' => self::SKIP_OUT_OF_SCOPE_TYPE ) );
		}
		$expected_type = (string) ( $binding['post_type'] ?? '' );
		if ( '' !== $expected_type && $post->post_type !== $expected_type ) {
			return array_merge( $blank, array( 'result' => self::SKIP_OUT_OF_SCOPE_TYPE ) );
		}
		$status_filter = (string) ( $binding['status'] ?? 'any' );
		if ( 'publish' === $status_filter && 'publish' !== $post->post_status ) {
			return array_merge( $blank, array( 'result' => self::SKIP_OUT_OF_SCOPE_STATUS ) );
		}

		// Runtime ACF target validation (spec §4.4.1 — added in 2.0.3).
		// Save-time Tier 5 guard is bypass-able: (a) the form-save layer
		// allows ACF-target bindings while ACF is inactive (so the binding
		// survives an ACF deactivation/reactivation), and (b) CLI
		// `bindings import` writes raw JSON through BindingsRepo::create
		// without re-running the save-layer guard. Plus the field could
		// have been renamed or deleted in ACF since the binding was saved.
		// We re-verify on every apply so read_target/write_target never
		// trust an unverified field_key.
		$acf_skip = $this->validate_acf_target_runtime( $binding );
		if ( null !== $acf_skip ) {
			return array_merge( $blank, array( 'result' => $acf_skip ) );
		}

		$source = $this->resolver->resolve_source( $binding, $post_id );
		if ( ! $source['found'] ) {
			return $blank;
		}

		$rendered      = $this->render_source( $binding, $post_id, $source['source'] );
		$rendered_hash = sha1( $rendered );
		$current       = $this->read_target( $binding, $post_id );
		$current_hash  = sha1( $current );
		$target_empty  = ( '' === $current );

		$regen         = ! empty( $binding['behavior']['regenerate_on_save'] );
		$auto_seed     = ! empty( $binding['behavior']['auto_seed_empty'] );
		$preserve      = ! empty( $binding['behavior']['preserve_manual_edits'] );
		$clear_empty   = ! empty( $binding['behavior']['clear_on_empty'] );
		$stored_sig    = (string) get_post_meta( $post_id, $this->signature_key( $binding ), true );
		$has_signature = '' !== $stored_sig;

		// Path 1: regenerate-on-save supersedes auto-seed.
		if ( $regen ) {
			if ( $preserve ) {
				// Cold start: no signature yet.
				if ( ! $has_signature ) {
					if ( $target_empty ) {
						// Empty target + cold start → safe to seed.
						return $this->write_plan( self::WROTE_SEEDED, $rendered, $rendered_hash, $current );
					}
					// Non-empty target + cold start → treat as manual edit.
					return array_merge(
						$blank,
						array(
							'result'        => self::SKIP_COLD_START_MANUAL,
							'rendered'      => $rendered,
							'rendered_hash' => $rendered_hash,
							'current'       => $current,
						)
					);
				}
				// Has signature → compare.
				if ( $current_hash !== $stored_sig ) {
					return array_merge(
						$blank,
						array(
							'result'        => self::SKIP_MANUAL_EDIT_DETECTED,
							'rendered'      => $rendered,
							'rendered_hash' => $rendered_hash,
							'current'       => $current,
						)
					);
				}
			}

			if ( '' === $rendered ) {
				if ( $clear_empty ) {
					return $this->write_plan( self::WROTE_EMPTY, '', sha1( '' ), $current );
				}
				return array_merge(
					$blank,
					array(
						'result'        => self::SKIP_EMPTY_RENDER,
						'rendered'      => '',
						'rendered_hash' => sha1( '' ),
						'current'       => $current,
					)
				);
			}

			return $this->write_plan( self::WROTE_REGENERATED, $rendered, $rendered_hash, $current );
		}

		// Path 2: auto-seed only writes when target is empty.
		if ( $auto_seed ) {
			if ( ! $target_empty ) {
				return array_merge(
					$blank,
					array(
						'result'        => self::SKIP_TARGET_NONEMPTY,
						'rendered'      => $rendered,
						'rendered_hash' => $rendered_hash,
						'current'       => $current,
					)
				);
			}
			if ( '' === $rendered ) {
				return array_merge(
					$blank,
					array(
						'result'        => self::SKIP_EMPTY_RENDER,
						'rendered'      => '',
						'rendered_hash' => sha1( '' ),
						'current'       => $current,
					)
				);
			}
			return $this->write_plan( self::WROTE_SEEDED, $rendered, $rendered_hash, $current );
		}

		// Path 3: neither trigger flag — form-save validation should have warned.
		return array_merge(
			$blank,
			array(
				'result'        => self::SKIP_NO_WRITE_TRIGGER,
				'rendered'      => $rendered,
				'rendered_hash' => $rendered_hash,
				'current'       => $current,
			)
		);
	}

	/**
	 * Build a write-plan tuple shaped like the SKIP variants.
	 *
	 * @param string $code          Result code from the decision tree.
	 * @param string $rendered      Rendered output to be written.
	 * @param string $rendered_hash sha1 of $rendered.
	 * @param string $current       Current value of the target field.
	 * @return array<string, mixed>
	 */
	private function write_plan( string $code, string $rendered, string $rendered_hash, string $current ): array {
		return array(
			'result'        => $code,
			'rendered'      => $rendered,
			'rendered_hash' => $rendered_hash,
			'current'       => $current,
			'would_write'   => true,
			'write_value'   => $rendered,
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
		$this->write_target( $binding, $post_id, $plan['write_value'] );
		update_post_meta( $post_id, $this->signature_key( $binding ), $plan['rendered_hash'] );
	}

	/**
	 * Verify the ACF target on every apply (spec §4.4.1, added 2.0.3).
	 *
	 * Returns one of the SKIP_* codes (or null when valid).
	 *
	 * - When `kind=acf_field` and ACF isn't loaded → `SKIP_ACF_NOT_LOADED`.
	 *   The save layer accepts ACF-target bindings while ACF is inactive
	 *   (so they survive a deactivation/reactivation cycle); the applier
	 *   short-circuits during such intervals rather than falling back to
	 *   raw post-meta writes against an unverified key.
	 * - When ACF is loaded but `acf_get_field( $field_key )` returns null
	 *   (field deleted) or the resolved `name` doesn't match `target.key`
	 *   (key was reassigned to a different field) → `SKIP_INVALID_ACF_FIELD`.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @return string|null SKIP_* code or null when valid (also null for non-ACF kinds).
	 */
	private function validate_acf_target_runtime( array $binding ): ?string {
		$kind = (string) ( $binding['target']['kind'] ?? '' );
		if ( 'acf_field' !== $kind ) {
			return null;
		}
		if ( ! function_exists( 'acf_get_field' ) ) {
			return self::SKIP_ACF_NOT_LOADED;
		}
		$field_key = (string) ( $binding['target']['field_key'] ?? '' );
		$key       = (string) ( $binding['target']['key'] ?? '' );
		if ( '' === $field_key ) {
			return self::SKIP_INVALID_ACF_FIELD;
		}
		$field = acf_get_field( $field_key );
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return self::SKIP_INVALID_ACF_FIELD;
		}
		if ( (string) $field['name'] !== $key ) {
			return self::SKIP_INVALID_ACF_FIELD;
		}
		return null;
	}

	/**
	 * Read the current target field value.
	 *
	 * Post-2.0.3: for `kind=acf_field` this is only ever called after
	 * `validate_acf_target_runtime()` has confirmed `field_key` resolves
	 * to a field whose name matches `target.key`. No silent fallback to
	 * `get_post_meta` — that would dilute the runtime guard's invariant.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 */
	private function read_target( array $binding, int $post_id ): string {
		$kind = (string) ( $binding['target']['kind'] ?? '' );

		if ( 'acf_field' === $kind ) {
			$value = get_field( (string) $binding['target']['field_key'], $post_id );
			return is_string( $value ) ? $value : (string) ( null === $value ? '' : $value );
		}

		return (string) get_post_meta( $post_id, (string) ( $binding['target']['key'] ?? '' ), true );
	}

	/**
	 * Write a value to the target field.
	 *
	 * For ACF targets, `update_field( $field_key, ... )` is required
	 * (spec §4.5) — the field KEY (not name) lets ACF establish the
	 * reference meta on first write. Post-2.0.3: this method is the
	 * sole writer for verified targets — `validate_acf_target_runtime()`
	 * has already short-circuited if the key is stale or foreign, so no
	 * fallback to `update_post_meta` is needed (or wanted: it would mask
	 * the runtime guard's intent).
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param string               $value   Value to write.
	 */
	private function write_target( array $binding, int $post_id, string $value ): void {
		$kind = (string) ( $binding['target']['kind'] ?? '' );

		if ( 'acf_field' === $kind ) {
			update_field( (string) $binding['target']['field_key'], $value, $post_id );
			return;
		}

		update_post_meta( $post_id, (string) ( $binding['target']['key'] ?? '' ), $value );
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
