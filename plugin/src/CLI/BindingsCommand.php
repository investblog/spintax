<?php
/**
 * `wp spintax bindings ...` WP-CLI subcommands.
 *
 * @package Spintax
 */

namespace Spintax\CLI;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingApplier;
use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\BulkApply;
use Spintax\Support\Validators;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manage Spintax bindings from the CLI.
 *
 * Mirrors the admin Bindings page so staging→prod workflows can sync
 * bindings via `export` + `import` instead of manual recreate. The
 * `apply` subcommand is also the documented fallback when Action
 * Scheduler is not available (spec §4.10).
 */
class BindingsCommand extends WP_CLI_Command {

	/**
	 * List all bindings.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of table, json, csv, yaml, count, ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp spintax bindings list
	 *     wp spintax bindings list --format=json
	 *
	 * @subcommand list
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function cmd_list( $args, $assoc_args ): void {
		unset( $args );
		$repo     = new BindingsRepo();
		$bindings = array_values( $repo->all() );

		$rows = array();
		foreach ( $bindings as $b ) {
			$rows[] = array(
				'id'        => (string) ( $b['id'] ?? '' ),
				'post_type' => (string) ( $b['post_type'] ?? '' ),
				'kind'      => (string) ( $b['target']['kind'] ?? '' ),
				'key'       => (string) ( $b['target']['key'] ?? '' ),
				'source'    => (string) ( $b['source']['mode'] ?? '' ),
				'cron'      => (string) ( $b['triggers']['cron'] ?? 'disabled' ),
			);
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'post_type', 'kind', 'key', 'source', 'cron' ) );
	}

	/**
	 * Apply a binding to one or more posts.
	 *
	 * ## OPTIONS
	 *
	 * --binding=<binding_id>
	 * : The binding id (`bind_xxxxxx`).
	 *
	 * [--post=<post_id>]
	 * : Apply to a single post id.
	 *
	 * [--all]
	 * : Apply to every matching post (mutually exclusive with --post).
	 *
	 * ## EXAMPLES
	 *
	 *     wp spintax bindings apply --binding=bind_a1b2c3 --post=42
	 *     wp spintax bindings apply --binding=bind_a1b2c3 --all
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function apply( $args, $assoc_args ): void {
		unset( $args );
		$id   = isset( $assoc_args['binding'] ) ? (string) $assoc_args['binding'] : '';
		$post = isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0;
		$all  = ! empty( $assoc_args['all'] );

		if ( ! Validators::is_valid_binding_id( $id ) ) {
			WP_CLI::error( 'Provide a valid --binding=<id>.' );
		}
		if ( ! $all && $post <= 0 ) {
			WP_CLI::error( 'Provide either --post=<id> or --all.' );
		}
		if ( $all && $post > 0 ) {
			WP_CLI::error( '--post and --all are mutually exclusive.' );
		}

		$repo    = new BindingsRepo();
		$binding = $repo->find( $id );
		if ( null === $binding ) {
			WP_CLI::error( 'Binding not found: ' . $id );
		}

		if ( ! $all ) {
			$result = ( new BindingApplier() )->apply( $binding, $post );
			WP_CLI::log( $result );
			return;
		}

		$totals = ( new BulkApply( $repo ) )->run_synchronously( $id );
		if ( is_wp_error( $totals ) ) {
			WP_CLI::error( $totals->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'wrote=%d skipped=%d failed=%d cleared=%d',
				$totals['wrote'],
				$totals['skipped'],
				$totals['failed'],
				$totals['cleared']
			)
		);
	}

	/**
	 * Dry-run a binding against a specific post.
	 *
	 * ## OPTIONS
	 *
	 * --binding=<binding_id>
	 * : The binding id.
	 *
	 * --post=<post_id>
	 * : The target post id.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function test( $args, $assoc_args ): void {
		unset( $args );
		$id   = isset( $assoc_args['binding'] ) ? (string) $assoc_args['binding'] : '';
		$post = isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0;

		if ( ! Validators::is_valid_binding_id( $id ) ) {
			WP_CLI::error( 'Provide a valid --binding=<id>.' );
		}
		if ( $post <= 0 ) {
			WP_CLI::error( 'Provide --post=<id>.' );
		}

		$repo    = new BindingsRepo();
		$binding = $repo->find( $id );
		if ( null === $binding ) {
			WP_CLI::error( 'Binding not found: ' . $id );
		}

		$plan = ( new BindingApplier() )->plan( $binding, $post );
		WP_CLI::log( wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Export bindings to JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--binding=<binding_id>]
	 * : Export a single binding.
	 *
	 * [--all]
	 * : Export every binding.
	 *
	 * ## EXAMPLES
	 *
	 *     wp spintax bindings export --all > bindings.json
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function export( $args, $assoc_args ): void {
		unset( $args );
		$repo = new BindingsRepo();
		$id   = isset( $assoc_args['binding'] ) ? (string) $assoc_args['binding'] : '';
		$all  = ! empty( $assoc_args['all'] );

		if ( '' !== $id && ! Validators::is_valid_binding_id( $id ) ) {
			WP_CLI::error( 'Invalid --binding value.' );
		}
		if ( '' === $id && ! $all ) {
			WP_CLI::error( 'Provide --binding=<id> or --all.' );
		}

		$bindings = $all ? array_values( $repo->all() ) : array( $repo->find( $id ) );
		$bindings = array_filter( $bindings );

		// Strip volatile fields so the export is deterministic.
		$payload = array_map(
			static function ( array $b ): array {
				unset( $b['created_at'], $b['updated_at'] );
				return $b;
			},
			$bindings
		);

		WP_CLI::log( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Import bindings from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to a JSON file produced by `bindings export`.
	 *
	 * [--dry-run]
	 * : Show what would happen without writing.
	 *
	 * [--overwrite]
	 * : Replace bindings that collide on (post_type, target.kind, target.key).
	 *   Without this flag, colliding bindings are skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp spintax bindings import --file=bindings.json
	 *     wp spintax bindings import --file=bindings.json --overwrite
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function import( $args, $assoc_args ): void {
		unset( $args );
		$file = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( 'Provide a readable --file=<path>.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- CLI command reading a local user-supplied JSON path; WP_Filesystem is overkill and adds startup cost.
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			WP_CLI::error( 'Could not read the import file.' );
		}

		$payload = json_decode( $contents, true );
		if ( ! is_array( $payload ) ) {
			WP_CLI::error( 'Import file is not valid JSON.' );
		}

		$repo      = new BindingsRepo();
		$dry_run   = ! empty( $assoc_args['dry-run'] );
		$overwrite = ! empty( $assoc_args['overwrite'] );

		$stats = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		foreach ( $payload as $incoming ) {
			if ( ! is_array( $incoming ) ) {
				++$stats['errors'];
				continue;
			}
			$post_type = (string) ( $incoming['post_type'] ?? '' );
			$key       = (string) ( $incoming['target']['key'] ?? '' );

			$existing = $repo->find_by_target( $post_type, $key );

			if ( $existing ) {
				if ( ! $overwrite ) {
					++$stats['skipped'];
					continue;
				}
				if ( ! $dry_run ) {
					$result = $repo->update( (string) $existing['id'], $incoming );
					if ( is_wp_error( $result ) ) {
						++$stats['errors'];
						continue;
					}
				}
				++$stats['updated'];
				continue;
			}

			if ( ! $dry_run ) {
				$result = $repo->create( $incoming );
				if ( is_wp_error( $result ) ) {
					++$stats['errors'];
					continue;
				}
			}
			++$stats['created'];
		}

		$prefix = $dry_run ? '[dry-run] ' : '';
		WP_CLI::success(
			$prefix . sprintf(
				'created=%d updated=%d skipped=%d errors=%d',
				$stats['created'],
				$stats['updated'],
				$stats['skipped'],
				$stats['errors']
			)
		);
	}
}
