<?php
/**
 * Tests for the spintax Validator.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Core\Engine;

use Spintax\Core\Engine\Validator;

class ValidatorTest extends \WP_UnitTestCase {

	private function validator(): Validator {
		return new Validator();
	}

	// =========================================================================
	// Bracket matching
	// =========================================================================

	public function test_valid_brackets_pass(): void {
		$result = $this->validator()->validate( '{a|{b|c}} and [x|y]' );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_unclosed_brace(): void {
		$result = $this->validator()->validate( '{a|b' );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Unclosed', $result['errors'][0]['message'] );
	}

	public function test_unclosed_bracket(): void {
		$result = $this->validator()->validate( '[a|b' );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Unclosed', $result['errors'][0]['message'] );
	}

	public function test_mismatched_brackets(): void {
		$result = $this->validator()->validate( '{a|b]' );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Mismatched', $result['errors'][0]['message'] );
	}

	public function test_extra_closing(): void {
		$result = $this->validator()->validate( 'text}' );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Unexpected', $result['errors'][0]['message'] );
	}

	public function test_nested_brackets_valid(): void {
		$result = $this->validator()->validate( '{a|{b|[c|d]}}' );
		$this->assertEmpty( $result['errors'] );
	}

	// =========================================================================
	// #set validation
	// =========================================================================

	public function test_valid_set_passes(): void {
		$result = $this->validator()->validate( '#set %name% = value' );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_malformed_set_missing_value(): void {
		$result = $this->validator()->validate( '#set %name%' );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'Malformed #set', $result['errors'][0]['message'] );
	}

	public function test_malformed_set_missing_percent(): void {
		$result = $this->validator()->validate( '#set name = value' );
		$this->assertNotEmpty( $result['errors'] );
	}

	// =========================================================================
	// Variable references
	// =========================================================================

	public function test_self_referencing_variable(): void {
		$result = $this->validator()->validate( '#set %a% = %a%' );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'references itself', $result['errors'][0]['message'] );
	}

	public function test_circular_variable_reference(): void {
		$result = $this->validator()->validate( "#set %a% = %b%\n#set %b% = %a%" );
		$errors = array_filter(
			$result['errors'],
			static fn( array $e ): bool => str_contains( $e['message'], 'Circular' )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_undefined_variable_warning(): void {
		$result = $this->validator()->validate( 'Hello %unknown%!' );
		$this->assertEmpty( $result['errors'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertStringContainsString( 'unknown', $result['warnings'][0]['message'] );
	}

	public function test_defined_variable_no_warning(): void {
		$result = $this->validator()->validate( "#set %name% = World\nHello %name%!" );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_global_variable_no_warning(): void {
		$result = $this->validator()->validate( 'Hello %name%!', array(), array( 'name' ) );
		$this->assertEmpty( $result['warnings'] );
	}

	// =========================================================================
	// Permutation config validation
	// =========================================================================

	public function test_valid_config_passes(): void {
		$result = $this->validator()->validate( '[<minsize=2;maxsize=3;sep=", ";lastsep=" and "> a|b|c]' );
		$this->assertEmpty( $result['errors'] );
	}

	public function test_unknown_config_key(): void {
		$result = $this->validator()->validate( '[<foo=bar> a|b|c]' );
		$errors = array_filter(
			$result['errors'],
			static fn( array $e ): bool => str_contains( $e['message'], 'Unknown permutation config key' )
		);
		$this->assertNotEmpty( $errors );
	}

	public function test_non_numeric_minsize(): void {
		$result = $this->validator()->validate( '[<minsize=abc> a|b|c]' );
		$errors = array_filter(
			$result['errors'],
			static fn( array $e ): bool => str_contains( $e['message'], 'positive integer' )
		);
		$this->assertNotEmpty( $errors );
	}

	// =========================================================================
	// #include validation
	// =========================================================================

	public function test_include_known_slug_passes(): void {
		$result = $this->validator()->validate(
			'#include "footer"',
			array( 'footer' )
		);
		$this->assertEmpty( $result['errors'] );
	}

	public function test_include_unknown_slug_fails(): void {
		$result = $this->validator()->validate(
			'#include "nonexistent"',
			array( 'footer' )
		);
		$errors = array_filter(
			$result['errors'],
			static fn( array $e ): bool => str_contains( $e['message'], 'nonexistent' )
		);
		$this->assertNotEmpty( $errors );
	}

	// =========================================================================
	// Full template validation
	// =========================================================================

	public function test_valid_template_no_errors(): void {
		$template = <<<'TPL'
#set %name% = {World|Earth}
#set %greeting% = {Hello|Hi}

%greeting% %name%! We have [<sep=", ";lastsep=" and "> apples|oranges|bananas].
TPL;
		$result = $this->validator()->validate( $template );
		$this->assertEmpty( $result['errors'] );
		$this->assertEmpty( $result['warnings'] );
	}

	/**
	 * Smoke test: validate the real production template.
	 */
	public function test_real_template_validates(): void {
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/review-casino.txt';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'Fixture file not found.' );
		}

		$template = file_get_contents( $fixture );
		$result   = $this->validator()->validate( $template );

		// Should have no blocking errors.
		$this->assertEmpty(
			$result['errors'],
			'Real template should have no validation errors: ' .
			( ! empty( $result['errors'] ) ? $result['errors'][0]['message'] : '' )
		);
	}
}
