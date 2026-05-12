<?php
/**
 * CRUD repository for Spintax bindings.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Support\OptionKeys;
use Spintax\Support\Validators;
use WP_Error;

/**
 * Stores all bindings in a single autoloaded WordPress option
 * (`spintax_bindings`) keyed by binding id (see spec §4.1).
 *
 * Validation responsibilities are split:
 *  - Tiers 1-3 of the reserved-key guard live as pure functions on
 *    `Spintax\Support\Validators` and are expected to be called by
 *    `BindingsPage` before invoking `create()` / `update()`.
 *  - Tier 4 (uniqueness on `post_type + target.kind + target.key`) and
 *    the per-site cap live here, where the existing store is the
 *    authoritative source of truth.
 */
class BindingsRepo {

	/**
	 * Read all bindings, keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$raw = get_option( OptionKeys::BINDINGS, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return $raw;
	}

	/**
	 * Find a binding by id.
	 *
	 * @param string $id Binding id.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Count bindings.
	 */
	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * Create a new binding.
	 *
	 * Stamps `id`, `created_at`, and `updated_at`. Enforces the
	 * per-site cap (Defaults::MAX_BINDINGS) and Tier 4 uniqueness
	 * on `(post_type, target.kind, target.key)`.
	 *
	 * @param array<string, mixed> $data Partial binding payload.
	 * @return array<string, mixed>|WP_Error Created binding or error.
	 */
	public function create( array $data ) {
		$all = $this->all();

		if ( count( $all ) >= Defaults::MAX_BINDINGS ) {
			return new WP_Error(
				'spintax_bindings_cap',
				sprintf(
					/* translators: %d: max bindings per site */
					__( 'Cannot create more than %d bindings per site. Delete an existing binding to add another.', 'spintax' ),
					Defaults::MAX_BINDINGS
				)
			);
		}

		$normalised = $this->normalize( $data );

		$dup = $this->find_by_target(
			$normalised['post_type'],
			$normalised['target']['kind'],
			$normalised['target']['key']
		);
		if ( null !== $dup ) {
			return new WP_Error(
				'spintax_bindings_duplicate',
				__( 'Another binding already targets this field on this post type.', 'spintax' )
			);
		}

		$now                      = time();
		$normalised['id']         = $this->fresh_id( $all );
		$normalised['created_at'] = $now;
		$normalised['updated_at'] = $now;

		$all[ $normalised['id'] ] = $normalised;
		update_option( OptionKeys::BINDINGS, $all, true );

		return $normalised;
	}

	/**
	 * Update an existing binding by id.
	 *
	 * Does NOT permit changing `id` or `created_at`. Enforces Tier 4
	 * uniqueness against the new `(post_type, target.kind, target.key)`
	 * triple — ignoring the binding being updated itself.
	 *
	 * @param string               $id   Binding id to update.
	 * @param array<string, mixed> $data Patch payload (full or partial).
	 * @return array<string, mixed>|WP_Error Updated binding or error.
	 */
	public function update( string $id, array $data ) {
		$all = $this->all();

		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error(
				'spintax_bindings_not_found',
				__( 'Binding not found.', 'spintax' )
			);
		}

		$existing   = $all[ $id ];
		$merged     = array_replace_recursive( $existing, $data );
		$normalised = $this->normalize( $merged );

		$dup = $this->find_by_target(
			$normalised['post_type'],
			$normalised['target']['kind'],
			$normalised['target']['key']
		);
		if ( null !== $dup && $dup['id'] !== $id ) {
			return new WP_Error(
				'spintax_bindings_duplicate',
				__( 'Another binding already targets this field on this post type.', 'spintax' )
			);
		}

		$normalised['id']         = $id;
		$normalised['created_at'] = isset( $existing['created_at'] ) ? (int) $existing['created_at'] : time();
		$normalised['updated_at'] = time();

		$all[ $id ] = $normalised;
		update_option( OptionKeys::BINDINGS, $all, true );

		return $normalised;
	}

	/**
	 * Delete a binding by id.
	 *
	 * Returns true on success, WP_Error if not found. Does NOT delete
	 * sibling post-meta (`_spintax_source_<key>`, `_spintax_last_render_sig_<id>`)
	 * — that is the responsibility of the uninstall handler (spec §9
	 * Phase 5) and of the deletion confirmation flow when wired in
	 * later phases.
	 *
	 * @param string $id Binding id.
	 * @return true|WP_Error
	 */
	public function delete( string $id ) {
		$all = $this->all();
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error(
				'spintax_bindings_not_found',
				__( 'Binding not found.', 'spintax' )
			);
		}
		unset( $all[ $id ] );
		update_option( OptionKeys::BINDINGS, $all, true );
		return true;
	}

	/**
	 * All bindings that apply to a given post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string, array<string, mixed>>
	 */
	public function find_for_post_type( string $post_type ): array {
		$result = array();
		foreach ( $this->all() as $id => $binding ) {
			if ( ( $binding['post_type'] ?? '' ) === $post_type ) {
				$result[ $id ] = $binding;
			}
		}
		return $result;
	}

	/**
	 * Tier 4 uniqueness lookup: find the binding (if any) that targets
	 * a specific `(post_type, target.kind, target.key)` triple.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $kind      'acf_field' | 'post_meta'.
	 * @param string $key       Target field name or meta key.
	 * @return array<string, mixed>|null
	 */
	public function find_by_target( string $post_type, string $kind, string $key ): ?array {
		foreach ( $this->all() as $binding ) {
			if (
				( $binding['post_type'] ?? '' ) === $post_type
				&& ( $binding['target']['kind'] ?? '' ) === $kind
				&& ( $binding['target']['key'] ?? '' ) === $key
			) {
				return $binding;
			}
		}
		return null;
	}

	/**
	 * All `template`-mode bindings referencing a given Spintax template
	 * post id. Used by the Phase 2+ template-edit cascade (spec §4.7a).
	 *
	 * @param int $template_id Spintax template CPT post id.
	 * @return array<string, array<string, mixed>>
	 */
	public function find_by_template_id( int $template_id ): array {
		$result = array();
		foreach ( $this->all() as $id => $binding ) {
			if (
				( $binding['source']['mode'] ?? '' ) === 'template'
				&& (int) ( $binding['source']['template_id'] ?? 0 ) === $template_id
			) {
				$result[ $id ] = $binding;
			}
		}
		return $result;
	}

	// --- internal ---

	/**
	 * Merge a partial payload with `Defaults::binding()` and coerce
	 * scalar types. Ensures `target.field_key` exists only when
	 * `target.kind === 'acf_field'`.
	 *
	 * @param array<string, mixed> $data Partial payload.
	 * @return array<string, mixed>
	 */
	private function normalize( array $data ): array {
		$defaults = Defaults::binding();
		$merged   = array_replace_recursive( $defaults, $data );

		$merged['post_type'] = isset( $merged['post_type'] ) ? sanitize_key( (string) $merged['post_type'] ) : '';
		$merged['status']    = in_array( $merged['status'] ?? '', Defaults::statuses(), true )
			? $merged['status']
			: 'any';

		$kind                          = in_array( $merged['target']['kind'] ?? '', Defaults::target_kinds(), true )
			? $merged['target']['kind']
			: 'acf_field';
		$merged['target']['kind']      = $kind;
		$merged['target']['key']       = sanitize_text_field( (string) ( $merged['target']['key'] ?? '' ) );
		$merged['target']['field_key'] = 'acf_field' === $kind
			? sanitize_text_field( (string) ( $merged['target']['field_key'] ?? '' ) )
			: '';

		$mode                            = in_array( $merged['source']['mode'] ?? '', Defaults::source_modes(), true )
			? $merged['source']['mode']
			: 'template';
		$merged['source']['mode']        = $mode;
		$merged['source']['template_id'] = 'template' === $mode
			? max( 0, (int) ( $merged['source']['template_id'] ?? 0 ) )
			: 0;

		$merged['variables']['expose_post_context'] = ! empty( $merged['variables']['expose_post_context'] );
		$merged['variables']['expose_acf_siblings'] = ! empty( $merged['variables']['expose_acf_siblings'] );
		$merged['variables']['overrides']           = Validators::sanitize_spintax( (string) ( $merged['variables']['overrides'] ?? '' ) );

		$merged['triggers']['save_post']     = ! empty( $merged['triggers']['save_post'] );
		$merged['triggers']['acf_save_post'] = false; // V1 ignores; pinned to false until V2.
		$merged['triggers']['cron']          = in_array( $merged['triggers']['cron'] ?? '', Defaults::cron_schedules(), true )
			? $merged['triggers']['cron']
			: 'disabled';

		$merged['behavior']['auto_seed_empty']       = ! empty( $merged['behavior']['auto_seed_empty'] );
		$merged['behavior']['regenerate_on_save']    = ! empty( $merged['behavior']['regenerate_on_save'] );
		$merged['behavior']['preserve_manual_edits'] = ! empty( $merged['behavior']['preserve_manual_edits'] );
		$merged['behavior']['clear_on_empty']        = ! empty( $merged['behavior']['clear_on_empty'] );

		return $merged;
	}

	/**
	 * Get a fresh id that does not collide with existing bindings.
	 *
	 * @param array<string, array<string, mixed>> $existing Current store.
	 * @return string
	 */
	private function fresh_id( array $existing ): string {
		// Up to 5 retries on collision (probability vanishingly small at the cap).
		for ( $i = 0; $i < 5; $i++ ) {
			$candidate = Validators::generate_binding_id();
			if ( ! isset( $existing[ $candidate ] ) ) {
				return $candidate;
			}
		}
		// Fallback — incorporate microtime to guarantee uniqueness.
		return 'bind_' . substr( md5( (string) microtime( true ) ), 0, 6 );
	}
}
