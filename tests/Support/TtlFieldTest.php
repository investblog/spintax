<?php

namespace Spintax\Tests\Support;

use Spintax\Support\TtlField;

/**
 * Unit tests for the TtlField preset/custom POST resolver.
 *
 * The form posts two fields (`<name>_preset`, `<name>_custom`); the helper
 * collapses them to a single int (or null when allow_empty is on and the
 * empty option was chosen).
 */
class TtlFieldTest extends \WP_UnitTestCase {

	public function test_preset_value_resolves_to_seconds(): void {
		$this->assertSame( 3600, TtlField::sanitize( '3600', '' ) );
		$this->assertSame( 86400, TtlField::sanitize( '86400', '' ) );
		$this->assertSame( 0, TtlField::sanitize( '0', '' ) );
	}

	public function test_custom_preset_reads_custom_input(): void {
		$this->assertSame( 12345, TtlField::sanitize( 'custom', '12345' ) );
	}

	public function test_custom_with_empty_value_falls_back_to_zero(): void {
		$this->assertSame( 0, TtlField::sanitize( 'custom', '' ) );
	}

	public function test_custom_with_empty_value_returns_null_when_allow_empty(): void {
		$this->assertNull( TtlField::sanitize( 'custom', '', true ) );
	}

	public function test_negative_custom_is_clamped_to_zero(): void {
		$this->assertSame( 0, TtlField::sanitize( 'custom', '-100' ) );
	}

	public function test_non_numeric_custom_falls_back_to_zero(): void {
		$this->assertSame( 0, TtlField::sanitize( 'custom', 'abc' ) );
	}

	public function test_empty_preset_returns_zero_by_default(): void {
		$this->assertSame( 0, TtlField::sanitize( '', '' ) );
		$this->assertSame( 0, TtlField::sanitize( null, null ) );
	}

	public function test_empty_preset_returns_null_when_allow_empty(): void {
		$this->assertNull( TtlField::sanitize( '', '', true ) );
		$this->assertNull( TtlField::sanitize( null, null, true ) );
	}

	public function test_unknown_preset_falls_back_safely(): void {
		// Garbage values must not throw — they collapse to the "empty" branch.
		$this->assertSame( 0, TtlField::sanitize( 'gibberish', '' ) );
		$this->assertNull( TtlField::sanitize( 'gibberish', '', true ) );
	}

	public function test_render_outputs_preset_select_and_custom_input(): void {
		ob_start();
		TtlField::render(
			array(
				'name'  => 'default_ttl',
				'value' => 3600,
			)
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="default_ttl_preset"', $html );
		$this->assertStringContainsString( 'name="default_ttl_custom"', $html );
		// WP's `selected()` prints `selected='selected'` (single-quoted attr).
		$this->assertMatchesRegularExpression(
			'/<option value="3600"\s+selected=[\'"]selected[\'"]/',
			$html
		);
		// 3600 is a recognised preset — the custom input must be hidden.
		$this->assertMatchesRegularExpression( '/spintax-ttl-custom[^>]*style="display:none/', $html );
	}

	public function test_render_with_custom_value_shows_custom_input(): void {
		ob_start();
		TtlField::render(
			array(
				'name'  => 'default_ttl',
				'value' => 12345,
			)
		);
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<option value="custom"\s+selected=[\'"]selected[\'"]/',
			$html
		);
		$this->assertStringContainsString( 'value="12345"', $html );
		// Custom input must NOT have inline display:none in this branch.
		$this->assertDoesNotMatchRegularExpression(
			'/class="spintax-ttl-custom small-text"[^>]*style="display:none/',
			$html
		);
	}

	public function test_render_allow_empty_shows_empty_option(): void {
		ob_start();
		TtlField::render(
			array(
				'name'        => 'spintax_cache_ttl',
				'value'       => null,
				'allow_empty' => true,
			)
		);
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<option value=""\s+selected=[\'"]selected[\'"]/',
			$html
		);
	}

	public function test_render_allow_empty_omitted_when_disallowed(): void {
		ob_start();
		TtlField::render(
			array(
				'name'        => 'default_ttl',
				'value'       => 0,
				'allow_empty' => false,
			)
		);
		$html = ob_get_clean();

		// With allow_empty=false, the only "" option should be absent.
		$this->assertDoesNotMatchRegularExpression(
			'/<option value=""(?:>|\s)/',
			$html
		);
	}
}
