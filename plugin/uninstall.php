<?php
/**
 * Uninstall handler.
 *
 * @package Spintax
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 1. Delete all spintax_template posts and their meta.
$spintax_post_ids = get_posts(
	array(
		'post_type'      => 'spintax_template',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	)
);
foreach ( $spintax_post_ids as $spintax_pid ) {
	wp_delete_post( (int) $spintax_pid, true );
}

// 2. Delete plugin options.
$spintax_options = array(
	'spintax_settings',
	'spintax_global_variables',
	'spintax_global_variables_raw',
	'spintax_cache_salt',
	'spintax_logs',
	'spintax_bindings',
	'spintax_migration_banner_dismissed',
);
foreach ( $spintax_options as $spintax_option ) {
	delete_option( $spintax_option );
}

// 2a. Delete per-binding option families with prefix matches.
global $wpdb;
$spintax_option_prefixes = array(
	'_spintax_binding_cache_v_',
	'_spintax_binding_last_applied_v_',
);
foreach ( $spintax_option_prefixes as $spintax_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- bulk delete during uninstall is the documented WP pattern; caching is irrelevant on teardown.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $spintax_prefix ) . '%'
		)
	);
}

// 2b. Delete per-post sibling meta written by the bindings feature.
$spintax_meta_prefixes = array(
	'_spintax_source_',
	'_spintax_last_render_sig_',
);
foreach ( $spintax_meta_prefixes as $spintax_meta_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk delete during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( $spintax_meta_prefix ) . '%'
		)
	);
}

// 2c. Clear scheduled binding-cron events. The hooks are named
// `spintax_binding_cron_<binding_id>` — they live in wp-options as
// part of the `cron` array, so we walk and clear by prefix.
$spintax_cron_array = _get_cron_array();
if ( is_array( $spintax_cron_array ) ) {
	foreach ( $spintax_cron_array as $spintax_ts => $spintax_hooks ) {
		if ( ! is_array( $spintax_hooks ) ) {
			continue;
		}
		foreach ( array_keys( $spintax_hooks ) as $spintax_hook ) {
			if ( 0 === strpos( (string) $spintax_hook, 'spintax_binding_cron_' ) ) {
				wp_clear_scheduled_hook( (string) $spintax_hook );
			}
		}
	}
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

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
foreach ( wp_roles()->role_objects as $role ) {
	foreach ( $spintax_caps as $spintax_cap ) {
		$role->remove_cap( $spintax_cap );
	}
}

// 4. Flush object cache group (WP 6.1+).
if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'spintax' );
}
