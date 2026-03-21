<?php

namespace Spintax\Tests\Support;

use Spintax\Support\Capabilities;

class CapabilitiesTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		// Clean up after each test.
		Capabilities::unregister();
		parent::tear_down();
	}

	public function test_register_grants_admin_cap(): void {
		Capabilities::register( true );
		$admin = get_role( 'administrator' );
		$this->assertTrue( $admin->has_cap( Capabilities::CAP ) );
		$this->assertTrue( $admin->has_cap( 'edit_spintax_templates' ) );
	}

	public function test_register_grants_editor_cap_when_allowed(): void {
		Capabilities::register( true );
		$editor = get_role( 'editor' );
		$this->assertTrue( $editor->has_cap( Capabilities::CAP ) );
	}

	public function test_register_revokes_editor_cap_when_disallowed(): void {
		Capabilities::register( true );
		Capabilities::register( false );
		$editor = get_role( 'editor' );
		$this->assertFalse( $editor->has_cap( Capabilities::CAP ) );
	}

	public function test_unregister_removes_all_caps(): void {
		Capabilities::register( true );
		Capabilities::unregister();

		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );
		$this->assertFalse( $admin->has_cap( Capabilities::CAP ) );
		$this->assertFalse( $editor->has_cap( Capabilities::CAP ) );
	}
}
