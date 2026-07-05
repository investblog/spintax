<?php
/**
 * Post-meta binding target kind.
 *
 * @package Spintax
 */

namespace Spintax\Bindings\Target;

defined( 'ABSPATH' ) || exit;

/**
 * `kind=post_meta` — plain `get_post_meta`/`update_post_meta` on `target.key`.
 * This is also the default for any unrecognised kind (matching the historical
 * `else` branch of the applier's read/write dispatch).
 *
 * Logic lifted verbatim from `BindingApplier`'s former inline branches.
 */
final class PostMetaTarget implements TargetKind {

	/**
	 * The kind identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'post_meta';
	}

	/**
	 * Read the current post-meta value.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return string
	 */
	public function read( array $binding, int $post_id ): string {
		return (string) get_post_meta( $post_id, (string) ( $binding['target']['key'] ?? '' ), true );
	}

	/**
	 * Write the post-meta value.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @param string               $value   Value to write.
	 */
	public function write( array $binding, int $post_id, string $value ): void {
		update_post_meta( $post_id, (string) ( $binding['target']['key'] ?? '' ), $value );
	}

	/**
	 * Post meta needs no runtime validation.
	 *
	 * @param array<string, mixed> $binding Binding payload (unused).
	 * @return string|null Always null.
	 */
	public function validate_runtime( array $binding ): ?string {
		unset( $binding );
		return null;
	}

	/**
	 * Sanitise the post-meta target sub-array (`field_key` forced empty).
	 *
	 * @param array<string, mixed> $target The raw `binding.target` array.
	 * @return array<string, mixed> Normalised `target` array.
	 */
	public function normalize_target( array $target ): array {
		return array(
			'kind'      => 'post_meta',
			'key'       => sanitize_text_field( (string) ( $target['key'] ?? '' ) ),
			'field_key' => '',
		);
	}
}
