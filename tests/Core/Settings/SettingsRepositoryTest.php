<?php

namespace Spintax\Tests\Core\Settings;

use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

class SettingsRepositoryTest extends \WP_UnitTestCase {

	private SettingsRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = new SettingsRepository();
		delete_option( OptionKeys::SETTINGS );
		delete_option( OptionKeys::GLOBAL_VARIABLES );
		delete_option( OptionKeys::CACHE_SALT );
	}

	public function test_get_returns_defaults_when_empty(): void {
		$settings = $this->repo->get();
		$this->assertSame( 3600, $settings['default_ttl'] );
		$this->assertTrue( $settings['editors_can_manage'] );
	}

	public function test_update_persists_values(): void {
		$this->repo->update( array( 'default_ttl' => 7200, 'debug' => true ) );
		$settings = $this->repo->get();
		$this->assertSame( 7200, $settings['default_ttl'] );
		$this->assertTrue( $settings['debug'] );
	}

	public function test_update_ignores_unknown_keys(): void {
		$this->repo->update( array( 'evil_key' => 'pwned' ) );
		$settings = $this->repo->get();
		$this->assertArrayNotHasKey( 'evil_key', $settings );
	}

	public function test_reset_restores_defaults(): void {
		$this->repo->update( array( 'default_ttl' => 9999 ) );
		$this->repo->reset();
		$this->assertSame( 3600, $this->repo->get()['default_ttl'] );
	}

	// --- Global variables ---

	public function test_global_variables_crud(): void {
		$this->repo->set_global_variables( array( 'city' => 'Moscow', 'country' => 'RU' ) );
		$vars = $this->repo->get_global_variables();
		$this->assertSame( 'Moscow', $vars['city'] );
		$this->assertSame( 'RU', $vars['country'] );
	}

	public function test_set_global_variables_bumps_cache_salt(): void {
		$this->repo->init_cache_salt();
		$salt_before = $this->repo->get_cache_salt();
		$this->repo->set_global_variables( array( 'x' => 'y' ) );
		$this->assertSame( $salt_before + 1, $this->repo->get_cache_salt() );
	}

	// --- Cache salt ---

	public function test_init_cache_salt(): void {
		$this->repo->init_cache_salt();
		$this->assertSame( 1, $this->repo->get_cache_salt() );
	}

	public function test_bump_cache_salt(): void {
		$this->repo->init_cache_salt();
		$this->repo->bump_cache_salt();
		$this->assertSame( 2, $this->repo->get_cache_salt() );
	}
}
