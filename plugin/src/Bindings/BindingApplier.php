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
	 * Read the current target field value.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 */
	private function read_target( array $binding, int $post_id ): string {
		$kind = (string) ( $binding['target']['kind'] ?? '' );
		$key  = (string) ( $binding['target']['key'] ?? '' );

		if ( 'acf_field' === $kind && function_exists( 'get_field' ) ) {
			$field_key  = (string) ( $binding['target']['field_key'] ?? '' );
			$identifier = '' !== $field_key ? $field_key : $key;
			$value      = get_field( $identifier, $post_id );
			return is_string( $value ) ? $value : (string) ( null === $value ? '' : $value );
		}

		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Write a value to the target field.
	 *
	 * For ACF targets, prefer `update_field( $field_key, ... )` — see
	 * spec §4.5 and ACF docs: the field KEY (not name) is required on
	 * the first write so ACF can establish the reference meta.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param string               $value   Value to write.
	 */
	private function write_target( array $binding, int $post_id, string $value ): void {
		$kind = (string) ( $binding['target']['kind'] ?? '' );
		$key  = (string) ( $binding['target']['key'] ?? '' );

		if ( 'acf_field' === $kind && function_exists( 'update_field' ) ) {
			$field_key  = (string) ( $binding['target']['field_key'] ?? '' );
			$identifier = '' !== $field_key ? $field_key : $key;
			update_field( $identifier, $value, $post_id );
			return;
		}

		update_post_meta( $post_id, $key, $value );
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
