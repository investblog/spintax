<?php
/**
 * Post-context variable source for binding renders.
 *
 * @package Spintax
 */

namespace Spintax\Core\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes WP-post fields as `%var%` references inside binding sources.
 *
 * Mapped variables (see spec §4.3):
 *  - `%post_id%`
 *  - `%post_title%`
 *  - `%post_url%`
 *  - `%post_slug%`
 *  - `%post_date%`     (ISO 8601, post_date)
 *  - `%post_modified%` (ISO 8601, post_modified)
 *  - `%author_id%`
 *  - `%author_name%`   (display_name)
 *
 * Returns an empty array for non-existent post ids so the caller can
 * uniformly merge into the runtime-variable layer regardless of
 * whether the post survived between trigger fire and apply.
 */
class PostContextSource {

	/**
	 * Build the post-context variable map for a single post.
	 *
	 * @param int $post_id Target post id.
	 * @return array<string, string>
	 */
	public function build( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$author_id   = (int) $post->post_author;
		$author_name = '';
		if ( $author_id > 0 ) {
			$author = get_userdata( $author_id );
			if ( $author ) {
				$author_name = (string) $author->display_name;
			}
		}

		return array(
			'post_id'       => (string) $post->ID,
			'post_title'    => (string) $post->post_title,
			'post_url'      => (string) get_permalink( $post ),
			'post_slug'     => (string) $post->post_name,
			'post_date'     => (string) mysql2date( 'c', $post->post_date, false ),
			'post_modified' => (string) mysql2date( 'c', $post->post_modified, false ),
			'author_id'     => (string) $author_id,
			'author_name'   => $author_name,
		);
	}
}
