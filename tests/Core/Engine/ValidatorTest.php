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
	// `{?VAR?then|else}` conditional references
	// =========================================================================

	public function test_conditional_with_known_global_var_no_warning(): void {
		$result = $this->validator()->validate( '{?HasBonus?Claim|Skip}', array(), array( 'HasBonus' ) );
		$this->assertEmpty( $result['errors'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_conditional_with_local_var_no_warning(): void {
		$result = $this->validator()->validate(
			"#set %HasBonus% = 1\n{?HasBonus?Claim|Skip}"
		);
		$this->assertEmpty( $result['errors'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_conditional_with_undefined_var_warns(): void {
		$result = $this->validator()->validate( '{?Undeclared?Claim|Skip}' );
		$this->assertEmpty( $result['errors'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertStringContainsString( 'Undeclared', $result['warnings'][0]['message'] );
	}

	public function test_inverted_conditional_extracts_var_name(): void {
		$result = $this->validator()->validate( '{?!Undeclared?Hide me}' );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertStringContainsString( 'Undeclared', $result['warnings'][0]['message'] );
	}

	public function test_balanced_template_with_conditionals_no_bracket_errors(): void {
		// Bracket balancing must not false-positive on the outer { } of a
		// conditional, even when the body has nested {} or [].
		$result = $this->validator()->validate(
			'{?A?{a|b}|fallback} and {?B?[<sep=", "> x|y]|none}',
			array(),
			array( 'A', 'B' )
		);
		$this->assertEmpty( $result['errors'] );
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

	// =========================================================================
	// `#set` / `#def` directives
	// =========================================================================

	private function assert_clean( string $template, string $locale = '' ): void {
		$result = $this->validator()->validate( $template, array(), array(), $locale );
		$this->assertEmpty(
			$result['errors'],
			! empty( $result['errors'] ) ? $result['errors'][0]['message'] : ''
		);
	}

	private function assert_rejected( string $template, string $locale = '' ): void {
		$this->assertNotEmpty( $this->validator()->validate( $template, array(), array(), $locale )['errors'] );
	}

	public function test_an_empty_value_validates_for_both_directives(): void {
		// The parser accepts an empty value and ParserTest locks that. The validator used to
		// disagree and call it malformed, unless a trailing space happened to be present.
		$this->assert_clean( "#set %x% =
%x%" );
		$this->assert_clean( "#def %y% =
%y%" );
	}

	public function test_a_directive_without_an_equals_sign_is_malformed(): void {
		$this->assert_rejected( '#set %v% hello' );
		$this->assert_rejected( '#def %v% hello' );
	}

	public function test_a_def_defined_name_is_not_reported_as_unknown(): void {
		$this->assertEmpty( $this->validator()->validate( "#def %x% = a
%x%" )['warnings'] );
	}

	public function test_a_def_can_self_reference_and_is_caught(): void {
		$this->assert_rejected( '#def %a% = x %a% y' );
	}

	public function test_a_cycle_is_caught_even_when_it_crosses_directive_kinds(): void {
		$this->assert_rejected( "#set %a% = %b%
#def %b% = %a%" );
	}

	/**
	 * @dataProvider duplicate_definitions
	 */
	public function test_a_name_defined_twice_is_rejected( string $template ): void {
		$this->assert_rejected( $template );
	}

	public function duplicate_definitions(): array {
		return array(
			'set then def' => array( "#set %x% = a
#def %x% = b" ),
			'set then set' => array( "#set %x% = a
#set %x% = b" ),
			'def then def' => array( "#def %x% = a
#def %x% = b" ),
		);
	}

	public function test_the_duplicate_is_reported_on_its_own_line(): void {
		$errors = $this->validator()->validate( "body
#set %x% = a
#def %x% = b" )['errors'];
		$this->assertSame( 3, $errors[0]['line'] );
	}

	public function test_include_in_a_def_value_is_rejected(): void {
		$this->assert_rejected( "#def %x% = #include \"y\"
%x%" );
	}

	public function test_include_in_a_set_value_is_allowed(): void {
		// A macro is substituted verbatim, so its #include reaches the include stage in the body.
		$this->assert_clean( "#set %x% = #include \"y\"
%x%" );
	}

	/**
	 * @dataProvider tainted_counts
	 */
	public function test_a_macro_count_is_rejected( string $template ): void {
		$this->assert_rejected( $template );
	}

	public function tainted_counts(): array {
		return array(
			'direct enumeration'      => array( "#set %n% = {1|4|9}
{plural %n%: a|b}" ),
			'direct permutation'      => array( "#set %n% = [1|2]
{plural %n%: a|b}" ),
			'one hop'                 => array( "#set %m% = {1|4|9}
#set %n% = %m%
{plural %n%: a|b}" ),
			'three hops'              => array( "#set %a% = {1|2}
#set %b% = %a%
#set %c% = %b%
{plural %c%: x|y}" ),
			// The conditional resolves in time; the enumeration it uncovers does not.
			'enumeration in a branch' => array( "#set %flag% = 1
#set %n% = {?flag?{1|4}|2}
{plural %n%: a|b}" ),
			// A nested plural resolves in the SAME pass as the outer block, not before it.
			'a nested plural'         => array( "#set %n% = {plural 1:1|2}
{plural %n%: a|b}" ),
		);
	}

	/**
	 * @dataProvider sound_counts
	 */
	public function test_a_sound_count_is_accepted( string $template ): void {
		$this->assert_clean( $template );
	}

	public function sound_counts(): array {
		return array(
			'def holds a literal by the time plurals run' => array( "#def %n% = {1|4|9}
{plural %n%: a|b}" ),
			'a literal #set'                             => array( "#set %n% = 5
{plural %n%: a|b}" ),
			'no variable at all'                         => array( '{plural 5: a|b}' ),
			// Conditionals resolve at 6c, before plurals at 6d — this renders correctly.
			'a conditional'                              => array( "#set %flag% = 1
#set %n% = {?flag?1|2}
{plural %n%: a|b}" ),
		);
	}

	public function test_a_self_referential_macro_does_not_hang_the_taint_walk(): void {
		$this->assert_rejected( "#set %a% = {1|2} %a%
{plural %a%: x|y}" );
	}
}
