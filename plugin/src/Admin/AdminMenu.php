<?php
/**
 * Centralised admin menu and asset registration.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\PostType\TemplatePostType;

/**
 * Wires all admin components: settings page, editor customisation,
 * meta boxes, and admin assets.
 */
class AdminMenu {

	/**
	 * Register all admin hooks.
	 */
	public function init(): void {
		$settings = new SettingsPage();
		$settings->init();

		$editor = new TemplateEditor();
		$editor->init();

		$meta_boxes = new MetaBoxes();
		$meta_boxes->init();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue admin CSS and JS on plugin pages only.
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$screen = get_current_screen();

		$is_our_cpt = $screen && TemplatePostType::POST_TYPE === $screen->post_type;
		$is_settings = 'settings_page_spintax-settings' === $hook_suffix;

		if ( ! $is_our_cpt && ! $is_settings ) {
			return;
		}

		wp_enqueue_style(
			'spintax-admin',
			$this->asset_url( 'css/admin.css' ),
			array(),
			SPINTAX_VERSION
		);

		wp_enqueue_script(
			'spintax-admin',
			$this->asset_url( 'js/admin.js' ),
			array( 'jquery' ),
			SPINTAX_VERSION,
			true
		);

		wp_localize_script(
			'spintax-admin',
			'spintaxAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'spintax_admin' ),
				'i18n'    => array(
					'copied'       => __( 'Copied!', 'spintax' ),
					'regenerating' => __( 'Regenerating…', 'spintax' ),
					'regenerated'  => __( 'Done!', 'spintax' ),
					'error'        => __( 'Error', 'spintax' ),
				),
			)
		);
	}

	/**
	 * Build asset URL relative to the plugin's assets/ directory.
	 */
	private function asset_url( string $path ): string {
		return SPINTAX_PLUGIN_URL . 'assets/' . $path;
	}
}
