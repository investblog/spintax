<?php
/**
 * One-shot migration helper for the predecessor plugin
 * `nested-spintax-for-acf`.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Support\OptionKeys;
use WP_Error;
use WP_Query;

/**
 * Detects predecessor binding data and proposes an idempotent import.
 *
 * Predecessor shape (per spec §4.11):
 *  - `ns4acf_selected_spintax_fields`  → array of field names selected on a post
 *  - `spintax_<field>`                 → spintax source on that post for that field
 *  - `spintax_variables`               → raw `#set` block authored per-post
 *
 * Our binding model is global per `(post_type, target.kind, target.key)`,
 * so the migration dedupes selections across the entire site, creates one
 * binding per (post_type, field), and copies per-post sources into the
 * canonical `_spintax_source_<key>` slot.
 *
 * Per-post `spintax_variables`:
 *  - identical across affected posts → fold once into `binding.variables.overrides`
 *  - divergent → prepend each post's block as `#set` lines to that post's source
 *
 * The migration never deletes predecessor data — the user uninstalls the
 * old plugin manually once they've verified the imported bindings.
 */
class Migration {

	public const META_SELECTED      = 'ns4acf_selected_spintax_fields';
	public const META_SOURCE_PREFIX = 'spintax_';
	public const META_VARS_PER_POST = 'spintax_variables';

	/**
	 * Bindings repository.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null $repo Bindings repository.
	 */
	public function __construct( ?BindingsRepo $repo = null ) {
		$this->repo = $repo ?? new BindingsRepo();
	}

	/**
	 * Quick check: does this site have any predecessor data to migrate?
	 */
	public function has_predecessor_data(): bool {
		$q = new WP_Query(
			array(
				'post_type'      => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time admin probe; runs only on activation banner / Tools page.
				'meta_query'     => array(
					array(
						'key'     => self::META_SELECTED,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return ! empty( $q->posts );
	}

	/**
	 * Build the import plan without writing.
	 *
	 * @return array<int, array<string, mixed>> Each entry shaped like:
	 *     {
	 *       post_type, target_kind, target_key, target_field_key,
	 *       affected_post_ids:int[], status: 'new'|'exists',
	 *       variables_mode:'identical'|'divergent'|'none',
	 *       variables_overrides:string, errors:string[]
	 *     }
	 */
	public function build_plan(): array {
		$by_pair = $this->collect_selections();

		$plan = array();
		foreach ( $by_pair as $key => $entry ) {
			list( $post_type, $field_name ) = explode( "\0", $key );

			$resolved = $this->classify_field( $field_name, $entry['post_ids'] );
			$vars     = $this->classify_variables( $entry['post_ids'] );

			$existing = $this->repo->find_by_target( $post_type, $field_name );

			$plan[] = array(
				'post_type'           => $post_type,
				'target_kind'         => $resolved['kind'],
				'target_key'          => $field_name,
				'target_field_key'    => $resolved['field_key'],
				'affected_post_ids'   => $entry['post_ids'],
				'status'              => $existing ? 'exists' : 'new',
				'existing_binding_id' => $existing ? (string) $existing['id'] : '',
				'variables_mode'      => $vars['mode'],
				'variables_overrides' => $vars['overrides'],
				'variables_per_post'  => $vars['per_post'], // post_id => raw vars block.
				'errors'              => $entry['errors'],
			);
		}

		return $plan;
	}

	/**
	 * Execute an import plan.
	 *
	 * Pass either a previously-built plan or omit to scan + execute in
	 * one shot. The opt-out checkbox lives in MigrationPage; this layer
	 * processes whatever it's handed.
	 *
	 * @param array<int, array<string, mixed>>|null $plan Pre-built plan.
	 * @return array{created:int, skipped:int, errors:int, posts_seeded:int}
	 */
	public function execute( ?array $plan = null ): array {
		$plan = $plan ?? $this->build_plan();

		$totals = array(
			'created'      => 0,
			'skipped'      => 0,
			'errors'       => 0,
			'posts_seeded' => 0,
		);

		foreach ( $plan as $entry ) {
			if ( 'exists' === $entry['status'] ) {
				++$totals['skipped'];
				// Still copy per-post source meta when the binding exists
				// but a post hasn't had its source meta copied yet
				// (idempotent re-run case).
				$this->copy_per_post_sources( $entry );
				continue;
			}

			$binding_payload = array(
				'post_type' => $entry['post_type'],
				'target'    => array(
					'kind'      => $entry['target_kind'],
					'key'       => $entry['target_key'],
					'field_key' => $entry['target_field_key'],
				),
				'source'    => array(
					'mode' => 'per_post',
				),
				'variables' => array(
					'overrides' => $entry['variables_overrides'],
				),
			);

			$created = $this->repo->create( $binding_payload );
			if ( $created instanceof WP_Error ) {
				++$totals['errors'];
				continue;
			}
			++$totals['created'];

			$totals['posts_seeded'] += $this->copy_per_post_sources( $entry );
		}

		return $totals;
	}

	// ----- internal -----

	/**
	 * Walk every post that has the predecessor selections meta and group
	 * them by `(post_type, field_name)`.
	 *
	 * @return array<string, array{post_ids:int[], errors:string[]}>
	 */
	private function collect_selections(): array {
		$by_pair = array();

		$q = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time admin scan; runs only from Tools → Migration UI / activation banner check.
				'meta_query'     => array(
					array(
						'key'     => self::META_SELECTED,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $q->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$post_type = get_post_type( $post_id );
			if ( ! is_string( $post_type ) || '' === $post_type ) {
				continue;
			}

			$selections = get_post_meta( $post_id, self::META_SELECTED, true );
			if ( ! is_array( $selections ) ) {
				continue; // malformed predecessor data — skip silently.
			}

			foreach ( $selections as $field_name ) {
				$field_name = is_string( $field_name ) ? trim( $field_name ) : '';
				if ( '' === $field_name || ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $field_name ) ) {
					continue;
				}
				$pair_key                           = $post_type . "\0" . $field_name;
				$by_pair[ $pair_key ]               = $by_pair[ $pair_key ] ?? array(
					'post_ids' => array(),
					'errors'   => array(),
				);
				$by_pair[ $pair_key ]['post_ids'][] = $post_id;
			}
		}

		return $by_pair;
	}

	/**
	 * Decide whether a field name resolves to an ACF field or to plain
	 * post-meta. Falls back to post_meta when ACF can't see the field on
	 * any of the affected posts.
	 *
	 * @param string $field_name Predecessor field name.
	 * @param int[]  $post_ids   Posts that selected the field.
	 * @return array{kind:string, field_key:string}
	 */
	private function classify_field( string $field_name, array $post_ids ): array {
		if ( ! function_exists( 'acf_get_field_object' ) ) {
			return array(
				'kind'      => 'post_meta',
				'field_key' => '',
			);
		}

		foreach ( $post_ids as $post_id ) {
			$field = acf_get_field_object( $field_name, (int) $post_id );
			if ( is_array( $field ) && ! empty( $field['key'] ) ) {
				return array(
					'kind'      => 'acf_field',
					'field_key' => (string) $field['key'],
				);
			}
		}
		return array(
			'kind'      => 'post_meta',
			'field_key' => '',
		);
	}

	/**
	 * Classify per-post `spintax_variables` content.
	 *
	 * @param int[] $post_ids Posts that selected this field.
	 * @return array{
	 *     mode: 'none'|'identical'|'divergent',
	 *     overrides: string,
	 *     per_post: array<int, string>
	 * }
	 */
	private function classify_variables( array $post_ids ): array {
		$per_post = array();
		foreach ( $post_ids as $post_id ) {
			$raw = (string) get_post_meta( (int) $post_id, self::META_VARS_PER_POST, true );
			$raw = trim( $raw );
			if ( '' !== $raw ) {
				$per_post[ (int) $post_id ] = $raw;
			}
		}

		if ( empty( $per_post ) ) {
			return array(
				'mode'      => 'none',
				'overrides' => '',
				'per_post'  => array(),
			);
		}

		$unique = array_unique( array_values( $per_post ) );
		if ( count( $unique ) === 1 && count( $per_post ) === count( $post_ids ) ) {
			// Every affected post had identical variables — safe to fold once.
			return array(
				'mode'      => 'identical',
				'overrides' => $unique[0],
				'per_post'  => array(),
			);
		}

		return array(
			'mode'      => 'divergent',
			'overrides' => '',
			'per_post'  => $per_post,
		);
	}

	/**
	 * Copy per-post `spintax_<field>` content (and inline divergent
	 * variables, if any) into the canonical `_spintax_source_<field>`
	 * slot for every affected post. Idempotent — already-populated
	 * sibling meta isn't overwritten unless the source is empty.
	 *
	 * @param array<string, mixed> $entry Plan entry.
	 * @return int Number of posts where a source was copied.
	 */
	private function copy_per_post_sources( array $entry ): int {
		$field_name = (string) $entry['target_key'];
		$copied     = 0;

		foreach ( $entry['affected_post_ids'] as $post_id ) {
			$post_id  = (int) $post_id;
			$dest_key = OptionKeys::META_BINDING_SOURCE_PREFIX . $field_name;

			// Skip if a per-post source already exists for this binding
			// (idempotency — re-running the migration is safe).
			$existing_dest = (string) get_post_meta( $post_id, $dest_key, true );
			if ( '' !== $existing_dest ) {
				continue;
			}

			$src = (string) get_post_meta( $post_id, self::META_SOURCE_PREFIX . $field_name, true );

			// If variables are divergent, inline this post's #set block.
			if ( 'divergent' === ( $entry['variables_mode'] ?? '' ) && isset( $entry['variables_per_post'][ $post_id ] ) ) {
				$src = $entry['variables_per_post'][ $post_id ] . "\n" . $src;
			}

			if ( '' === trim( $src ) ) {
				continue; // nothing to seed.
			}

			update_post_meta( $post_id, $dest_key, $src );
			++$copied;
		}

		return $copied;
	}
}
