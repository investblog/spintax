<?php
/**
 * Uninstall handler.
 *
 * @package Spintax
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Delete all spintax_template posts and their meta.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$spintax_post_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'spintax_template'"
);
if ( ! empty( $spintax_post_ids ) ) {
	foreach ( $spintax_post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

// 2. Delete plugin options.
$spintax_options = array(
	'spintax_settings',
	'spintax_global_variables',
	'spintax_cache_salt',
	'spintax_logs',
);
foreach ( $spintax_options as $spintax_option ) {
	delete_option( $spintax_option );
}

// 3. Remove custom capabilities from all roles.
$spintax_caps = array(
	'manage_spintax_templates',
	'edit_spintax_template',
	'read_spintax_template',
	'delete_spintax_template',
	'edit_spintax_templates',
	'edit_others_spintax_templates',
	'publish_spintax_templates',
	'read_private_spintax_templates',
	'delete_spintax_templates',
	'delete_private_spintax_templates',
	'delete_published_spintax_templates',
	'delete_others_spintax_templates',
	'edit_private_spintax_templates',
	'edit_published_spintax_templates',
);

foreach ( wp_roles()->role_objects as $role ) {
	foreach ( $spintax_caps as $spintax_cap ) {
		$role->remove_cap( $spintax_cap );
	}
}

// 4. Flush object cache group (WP 6.1+).
if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'spintax' );
}
