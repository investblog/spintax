<?php
/**
 * Cascade cache invalidation for nested templates.
 *
 * @package Spintax
 */

namespace Spintax\Core\Cache;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\OptionKeys;

/**
 * When a child template changes, all parent templates that embed it
 * must also have their caches invalidated.
 */
class DependencyInvalidator {

	/**
	 * Cache manager used to invalidate individual template caches.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Constructor.
	 *
	 * @param CacheManager|null $cache Optional cache manager instance.
	 */
	public function __construct( ?CacheManager $cache = null ) {
		$this->cache = $cache ?? new CacheManager();
	}

	/**
	 * Record which templates are embedded by a given template.
	 *
	 * Called after rendering to track the dependency graph.
	 *
	 * @param int   $template_id  The parent template.
	 * @param int[] $embedded_ids IDs of templates embedded via #include or [spintax].
	 */
	public function record_dependencies( int $template_id, array $embedded_ids ): void {
		$embedded_ids = array_unique( array_map( 'intval', $embedded_ids ) );
		$embedded_ids = array_values( array_filter( $embedded_ids, static fn( int $id ): bool => $id > 0 ) );

		update_post_meta( $template_id, OptionKeys::META_EMBEDS, $embedded_ids );
	}

	/**
	 * Invalidate all templates that embed the given child template.
	 *
	 * Walks up the dependency graph recursively.
	 *
	 * @param int   $child_id  The template that changed.
	 * @param int[] $visited   Already-invalidated IDs (circular guard).
	 */
	public function invalidate_dependents( int $child_id, array $visited = array() ): void {
		if ( in_array( $child_id, $visited, true ) ) {
			return;
		}
		$visited[] = $child_id;

		// Find all templates whose _spintax_embeds meta contains $child_id.
		$parent_ids = $this->find_parents( $child_id );

		foreach ( $parent_ids as $parent_id ) {
			$this->cache->invalidate_template( $parent_id );
			// Recurse: if grandparent embeds parent, it must be invalidated too.
			$this->invalidate_dependents( $parent_id, $visited );
		}
	}

	/**
	 * Find all templates that embed the given child ID.
	 *
	 * @param int $child_id Child template ID.
	 * @return int[] Parent template IDs.
	 */
	private function find_parents( int $child_id ): array {
		// WordPress serializes arrays: [42, 7] as a:2:{i:0;i:42;i:1;i:7;}.
		// Search for the integer value in serialized format: i:42;.
		$query = new \WP_Query(
			array(
				'post_type'      => TemplatePostType::POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => OptionKeys::META_EMBEDS,
						'value'   => sprintf( 'i:%d;', $child_id ),
						'compare' => 'LIKE',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}
}
