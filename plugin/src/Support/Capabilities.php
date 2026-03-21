<?php
/**
 * Role and capability management.
 *
 * @package Spintax
 */

namespace Spintax\Support;

/**
 * Manages the custom `manage_spintax_templates` capability
 * and maps it to CPT primitive capabilities.
 */
final class Capabilities {

	public const CAP = 'manage_spintax_templates';

	/**
	 * CPT primitive capabilities that must be granted to roles.
	 *
	 * @return string[]
	 */
	private static function cpt_caps(): array {
		return array(
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
	}

	/**
	 * Grant template capabilities to a role.
	 */
	private static function grant_to_role( string $role_name ): void {
		$role = get_role( $role_name );
		if ( ! $role ) {
			return;
		}
		$role->add_cap( self::CAP );
		foreach ( self::cpt_caps() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Revoke template capabilities from a role.
	 */
	private static function revoke_from_role( string $role_name ): void {
		$role = get_role( $role_name );
		if ( ! $role ) {
			return;
		}
		$role->remove_cap( self::CAP );
		foreach ( self::cpt_caps() as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	/**
	 * Register capabilities on plugin activation.
	 *
	 * @param bool $editors_can_manage Whether editors should have access.
	 */
	public static function register( bool $editors_can_manage = true ): void {
		self::grant_to_role( 'administrator' );

		if ( $editors_can_manage ) {
			self::grant_to_role( 'editor' );
		} else {
			self::revoke_from_role( 'editor' );
		}
	}

	/**
	 * Sync capabilities based on current settings.
	 *
	 * @param bool $editors_can_manage Whether editors should have access.
	 */
	public static function sync( bool $editors_can_manage ): void {
		self::register( $editors_can_manage );
	}

	/**
	 * Remove all custom capabilities from all roles (uninstall).
	 */
	public static function unregister(): void {
		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			self::revoke_from_role( $role_name );
		}
	}
}
