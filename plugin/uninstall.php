<?php
/**
 * Uninstall handler.
 *
 * @package Spintax
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Delete all spintax_template posts and their meta.
$post_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'spintax_template'"
);
if ( ! empty( $post_ids ) ) {
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

// 2. Delete plugin options.
$options = array(
	'spintax_settings',
	'spintax_global_variables',
	'spintax_cache_salt',
	'spintax_logs',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// 3. Remove custom capabilities from all roles.
$caps = array(
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
	foreach ( $caps as $cap ) {
		$role->remove_cap( $cap );
	}
}

// 4. Flush object cache group (WP 6.1+).
if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'spintax' );
}
