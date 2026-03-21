<?php
/**
 * Custom Post Type registration for spintax templates.
 *
 * @package Spintax
 */

namespace Spintax\Core\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and configures the spintax_template CPT.
 */
class TemplatePostType {

	/**
	 * Custom post type identifier for spintax templates.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'spintax_template';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
	}

	/**
	 * Register the CPT.
	 */
	public function register(): void {
		$labels = array(
			'name'               => __( 'Spintax Templates', 'spintax' ),
			'singular_name'      => __( 'Spintax Template', 'spintax' ),
			'add_new'            => __( 'Add New', 'spintax' ),
			'add_new_item'       => __( 'Add New Template', 'spintax' ),
			'edit_item'          => __( 'Edit Template', 'spintax' ),
			'new_item'           => __( 'New Template', 'spintax' ),
			'view_item'          => __( 'View Template', 'spintax' ),
			'search_items'       => __( 'Search Templates', 'spintax' ),
			'not_found'          => __( 'No templates found.', 'spintax' ),
			'not_found_in_trash' => __( 'No templates found in Trash.', 'spintax' ),
			'all_items'          => __( 'All Templates', 'spintax' ),
			'menu_name'          => __( 'Spintax', 'spintax' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-randomize',
			'menu_position'       => 25,
			'supports'            => array( 'title', 'editor' ),
			'capability_type'     => 'spintax_template',
			'map_meta_cap'        => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'exclude_from_search' => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Force classic editor for spintax templates.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type        Post type being checked.
	 * @return bool
	 */
	public function disable_block_editor( bool $use_block_editor, string $post_type ): bool {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}
}
