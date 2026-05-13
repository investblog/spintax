<?php

namespace Spintax\Tests\Admin;

use Spintax\Admin\SettingsPage;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

/**
 * Exercises SettingsPage:
 *  - dual menu registration (Settings → Spintax + Spintax → Settings, 2.0.4)
 *  - TTL preset / custom POST handling via TtlField (2.0.4)
 *  - Purge Cache button living inside the main settings form (2.0.4)
 */
class SettingsPageTest extends \WP_UnitTestCase {

	private SettingsPage $page;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( OptionKeys::SETTINGS );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$_POST   = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$this->page = new SettingsPage();
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	private function call_save_settings(): void {
		$reflection = new \ReflectionMethod( SettingsPage::class, 'save_settings' );
		$reflection->setAccessible( true );
		$reflection->invoke( $this->page );
	}

	public function test_submenu_registered_under_spintax_cpt(): void {
		global $submenu;
		$submenu = array();

		// Simulate the admin_menu hook fire for an admin user.
		set_current_screen( 'dashboard' );
		$this->page->register_menu();

		$cpt_parent = 'edit.php?post_type=' . TemplatePostType::POST_TYPE;
		$this->assertArrayHasKey(
			$cpt_parent,
			$submenu,
			'Spintax CPT should have a submenu registered for Settings (2.0.4)'
		);

		$slugs = array_column( $submenu[ $cpt_parent ], 2 );
		$this->assertContains(
			'spintax-settings',
			$slugs,
			'CPT submenu must include the spintax-settings page slug'
		);
	}

	public function test_settings_still_registered_under_options_menu(): void {
		global $submenu;
		$submenu = array();

		set_current_screen( 'dashboard' );
		$this->page->register_menu();

		$this->assertArrayHasKey( 'options-general.php', $submenu );
		$slugs = array_column( $submenu['options-general.php'], 2 );
		$this->assertContains(
			'spintax-settings',
			$slugs,
			'Existing Settings → Spintax entry must remain (WP convention)'
		);
	}

	public function test_ttl_preset_value_persists_as_int(): void {
		$_POST['default_ttl_preset'] = '86400';
		$_POST['default_ttl_custom'] = '';

		$this->call_save_settings();

		$saved = ( new SettingsRepository() )->get();
		$this->assertSame( 86400, (int) $saved['default_ttl'] );
	}

	public function test_ttl_custom_value_persists_as_int(): void {
		$_POST['default_ttl_preset'] = 'custom';
		$_POST['default_ttl_custom'] = '7777';

		$this->call_save_settings();

		$saved = ( new SettingsRepository() )->get();
		$this->assertSame( 7777, (int) $saved['default_ttl'] );
	}

	public function test_ttl_zero_preset_persists_as_zero(): void {
		$_POST['default_ttl_preset'] = '0';

		$this->call_save_settings();

		$saved = ( new SettingsRepository() )->get();
		$this->assertSame( 0, (int) $saved['default_ttl'] );
	}

	public function test_purge_cache_button_lives_inside_main_form(): void {
		// Snapshot the rendered settings page and assert that the
		// purge-cache submit button is INSIDE the spintax_settings_save
		// form and that there is no longer a second standalone form.
		set_current_screen( 'settings_page_spintax-settings' );

		ob_start();
		$this->page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="spintax_purge_cache"', $html );

		// Trim to the section spanning the first <form ...> to the last
		// </form> and confirm the purge button appears between them.
		$first_form = strpos( $html, '<form ' );
		$last_form  = strrpos( $html, '</form>' );
		$this->assertNotFalse( $first_form );
		$this->assertNotFalse( $last_form );

		$forms_region = substr( $html, $first_form, $last_form - $first_form );
		$this->assertStringContainsString( 'name="spintax_purge_cache"', $forms_region );

		// There should be exactly one <form> on the settings page now —
		// the Cache standalone form was removed in 2.0.4.
		$this->assertSame(
			1,
			substr_count( $html, '<form ' ),
			'Settings page must have a single form (2.0.4 collapsed the Cache form into the main form)'
		);
	}
}
