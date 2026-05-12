<?php
/**
 * Source resolver for bindings.
 *
 * @package Spintax
 */

namespace Spintax\Bindings;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

/**
 * Resolves a binding's source spintax text for a specific post.
 *
 * `template` mode pulls `post_content` from a `spintax_template` CPT
 * entry by id. `per_post` mode pulls from sibling post-meta
 * `_spintax_source_<target.key>` on the target post.
 *
 * Returns a structured result instead of a bare string so the caller
 * (`BindingApplier`) can distinguish "source legitimately empty" from
 * "source not found" — they take different decision-tree branches in
 * spec §4.4.
 */
class BindingResolver {

	public const FOUND            = 'found';
	public const TEMPLATE_EMPTY   = 'template_empty';
	public const TEMPLATE_MISSING = 'template_missing';
	public const PER_POST_EMPTY   = 'per_post_empty';
	public const UNKNOWN_MODE     = 'unknown_mode';

	/**
	 * Resolve the source text for a binding against a specific post.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @param int                  $post_id Target post id.
	 * @return array{found: bool, source: string, reason: string}
	 */
	public function resolve_source( array $binding, int $post_id ): array {
		$mode = (string) ( $binding['source']['mode'] ?? '' );

		if ( 'template' === $mode ) {
			return $this->resolve_template_source( (int) ( $binding['source']['template_id'] ?? 0 ) );
		}

		if ( 'per_post' === $mode ) {
			return $this->resolve_per_post_source(
				$post_id,
				(string) ( $binding['target']['key'] ?? '' )
			);
		}

		return array(
			'found'  => false,
			'source' => '',
			'reason' => self::UNKNOWN_MODE,
		);
	}

	/**
	 * Read `spintax_template` post content by id.
	 *
	 * @param int $template_id Template post id.
	 */
	private function resolve_template_source( int $template_id ): array {
		if ( $template_id <= 0 ) {
			return array(
				'found'  => false,
				'source' => '',
				'reason' => self::TEMPLATE_MISSING,
			);
		}

		$post = get_post( $template_id );
		if ( ! $post || TemplatePostType::POST_TYPE !== $post->post_type ) {
			return array(
				'found'  => false,
				'source' => '',
				'reason' => self::TEMPLATE_MISSING,
			);
		}

		// Treat draft/trash/private as "source unavailable" so the
		// binding skip-applies — the spec's "Source unavailable"
		// badge surface lands in Phase 4 UI work.
		if ( 'publish' !== $post->post_status ) {
			return array(
				'found'  => false,
				'source' => '',
				'reason' => self::TEMPLATE_MISSING,
			);
		}

		$source = (string) $post->post_content;
		if ( '' === trim( $source ) ) {
			return array(
				'found'  => false,
				'source' => '',
				'reason' => self::TEMPLATE_EMPTY,
			);
		}

		return array(
			'found'  => true,
			'source' => $source,
			'reason' => self::FOUND,
		);
	}

	/**
	 * Read sibling post-meta source for `per_post` mode.
	 *
	 * @param int    $post_id    Target post id.
	 * @param string $target_key Target meta key suffix.
	 */
	private function resolve_per_post_source( int $post_id, string $target_key ): array {
		if ( $post_id <= 0 || '' === $target_key ) {
			return array(
				'found'  => false,
				'source' => '',
				'reason' => self::PER_POST_EMPTY,
			);
		}

		$meta_key = OptionKeys::META_BINDING_SOURCE_PREFIX . $target_key;
		$source   = (string) get_post_meta( $post_id, $meta_key, true );

		if ( '' === trim( $source ) ) {
			return array(
				'found'  => false,
				'source' => '',
				'reason' => self::PER_POST_EMPTY,
			);
		}

		return array(
			'found'  => true,
			'source' => $source,
			'reason' => self::FOUND,
		);
	}
}
