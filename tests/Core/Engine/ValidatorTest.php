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

	// =========================================================================
	// The circular-reference walk — emission shape and the non-hang canaries.
	//
	// The b2924f3 rewrite (references_of / names_that_reach_a_cycle /
	// walk_cycles_from) was gated by a 464-document differential external to the
	// repository; these are its CI-runnable half. Exact messages pin emission
	// order, count and path text (the corpus asserts codes and verdicts, never
	// counts); the big silent shapes pin "returns at all" — the depth-30 diamond
	// used to never return, so a complexity regression fails by timeout. The
	// per-path emission itself is under discussion in spintax-js#59; this pins
	// what ships.
	// =========================================================================

	/**
	 * @return string[] Circular-reference messages, in emission order.
	 */
	private function circular_messages( string $template ): array {
		$result   = $this->validator()->validate( $template );
		$messages = array_column( $result['errors'], 'message' );
		return array_values(
			array_filter(
				$messages,
				static fn( string $m ): bool => str_starts_with( $m, 'Circular' )
			)
		);
	}

	public function test_a_three_cycle_reports_once_per_root_with_the_full_path(): void {
		$this->assertSame(
			array(
				'Circular variable reference detected: c0 → c1 → c2 → c0.',
				'Circular variable reference detected: c1 → c2 → c0 → c1.',
				'Circular variable reference detected: c2 → c0 → c1 → c2.',
			),
			$this->circular_messages( "#set %c0% = %c1%\n#set %c1% = %c2%\n#set %c2% = %c0%" )
		);
	}

	public function test_a_duplicated_edge_reports_per_occurrence(): void {
		$this->assertSame(
			array(
				'Circular variable reference detected: a → b → a.',
				'Circular variable reference detected: a → b → a.',
				'Circular variable reference detected: b → a → b.',
			),
			$this->circular_messages( "#set %a% = %b% %b%\n#set %b% = %a%" )
		);
	}

	public function test_a_diamond_feeding_a_cycle_reports_per_path(): void {
		$template = "#set %a2% = %p%\n#set %a1% = %a2% %a2%\n#set %a0% = %a1% %a1%\n"
			. "#set %p% = %q%\n#set %q% = %p%";
		$this->assertCount( 9, $this->circular_messages( $template ) );
	}

	public function test_a_silent_chain_of_2000_definitions_is_clean(): void {
		$lines = array( '#set %v0% = x' );
		for ( $i = 1; $i < 2000; $i++ ) {
			$lines[] = "#set %v{$i}% = %v" . ( $i - 1 ) . '%';
		}
		$result = $this->validator()->validate( implode( "\n", $lines ) );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_a_silent_diamond_of_depth_30_is_clean(): void {
		$n     = 30;
		$lines = array( "#set %a{$n}% = leaf" );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$lines[] = "#set %a{$i}% = %a" . ( $i + 1 ) . '% %a' . ( $i + 1 ) . '%';
		}
		$result = $this->validator()->validate( implode( "\n", $lines ) );
		$this->assertSame( array(), $result['errors'] );
	}

	public function test_taint_walks_a_300_macro_chain_into_the_count(): void {
		$lines = array( '#set %t0% = {x|y}' );
		for ( $i = 1; $i < 300; $i++ ) {
			$lines[] = "#set %t{$i}% = %t" . ( $i - 1 ) . '%';
		}
		$template = implode( "\n", $lines ) . "\n{plural %t299%: one|many}";
		$result   = $this->validator()->validate( $template, array(), array(), 'en' );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'is a #set macro', $result['errors'][0]['message'] );

		$lines[0] = '#set %t0% = plain';
		$control  = implode( "\n", $lines ) . "\n{plural %t299%: one|many}";
		$result   = $this->validator()->validate( $control, array(), array(), 'en' );
		$this->assertSame( array(), $result['errors'] );
	}

	// =========================================================================
	// No locale: no verdict, but not silence either (spintax-js#65)
	//
	// The shared corpus cannot gate this. Its PHP runner asserts the VERDICT only —
	// these diagnostics carry human messages, not machine codes — and a warning does
	// not move a verdict by definition. So the warning, and above all its ABSENCE on a
	// 2-form block, are pinned here or nowhere.
	// =========================================================================

	public function test_no_locale_warns_when_the_form_count_is_not_the_render_default(): void {
		$result = $this->validator()->validate( '{plural 3: a|b|c}' );

		$this->assertSame( array(), $result['errors'], 'no locale means no VERDICT: the template may be right for the locale it will be rendered with' );
		$this->assertCount( 1, $result['warnings'] );
		// Deliberately weaker wording than the sibling engines use, and the reason is this
		// plugin's own render path: Renderer::process_template() replaces an empty locale
		// with get_locale(), so "no locale" does not mean the engine's two-form default on
		// a WordPress site. Claiming it did would report a false problem on every ru_RU
		// install — a three-form template that renders perfectly.
		$this->assertStringContainsString( 'no locale was passed to the validator', $result['warnings'][0]['message'] );
		$this->assertStringContainsString( 'the site locale when the caller supplies none', $result['warnings'][0]['message'] );
		$this->assertStringNotContainsString( 'defaults to', $result['warnings'][0]['message'] );
	}

	public function test_no_locale_stays_silent_on_a_two_form_block(): void {
		$result = $this->validator()->validate( '{plural 3: one|many}' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'], 'the render default resolves a 2-form block, so there is nothing to warn about' );
	}

	public function test_the_warning_agrees_with_what_the_plural_stage_actually_does(): void {
		// Checked against the engine rather than trusting the wording — and against the
		// plural stage, which is what the message is about.
		$plurals = new \Spintax\Core\Engine\Plurals();
		$lenient = array( 'lenient' => true );

		$this->assertStringContainsString( "\u{FF5B}", $plurals->apply( '{plural 3: a|b|c}', '', $lenient ) );
		$this->assertSame( 'many', $plurals->apply( '{plural 3: one|many}', '', $lenient ) );
	}

	public function test_supplying_a_locale_replaces_the_warning_with_the_real_verdict(): void {
		$ru = $this->validator()->validate( '{plural 3: a|b|c}', array(), array(), 'ru' );
		$this->assertSame( array(), $ru['errors'] );
		$this->assertSame( array(), $ru['warnings'] );

		$en = $this->validator()->validate( '{plural 3: a|b|c}', array(), array(), 'en' );
		$this->assertCount( 1, $en['errors'], 'three forms are an arity ERROR for a 2-form locale' );
		$this->assertSame( array(), $en['warnings'] );
	}

	public function test_a_structurally_broken_block_reports_only_that(): void {
		$result = $this->validator()->validate( '{plural 3: {a|b}|c|d}' );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'nested spintax brackets', $result['errors'][0]['message'] );
		$this->assertSame( array(), $result['warnings'], 'no second, invented problem on a block that is already malformed' );
	}

	// ── Plural forms are counted as the RENDERER sees them (spintax-js#66) ─────
	//
	// Rendering expands `%variables%` and only THEN splits the form list, while this
	// validator split the raw source — so a reference inside a form list was judged on
	// the wrong number, in both directions. The property these tests hold to is that the
	// verdict agrees with what rendering does.

	private function validate( string $template, string $locale = '' ): array {
		return $this->validator()->validate( $template, array(), array(), $locale );
	}

	public function test_a_def_holding_extra_forms_no_longer_fails_a_correct_template(): void {
		$result = $this->validate( "#def %tail% = few|many\n{plural 2: one|%tail%}", 'ru' );

		$this->assertSame( array(), $result['errors'], 'three forms after expansion is right for ru' );
	}

	public function test_a_def_holding_extra_forms_still_fails_a_wrong_locale(): void {
		$result = $this->validate( "#def %tail% = few|many\n{plural 2: one|%tail%}", 'en' );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'expected', $result['errors'][0]['message'] );
	}

	public function test_a_def_holding_the_whole_list_stops_inventing_a_count(): void {
		$result = $this->validate( "#def %forms% = one|many\n{plural 2: %forms%}", 'en' );

		$this->assertSame( array(), $result['errors'], 'one raw pipe, two real forms' );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_a_def_whose_value_carries_a_construct_is_not_counted(): void {
		// A first version of this fix predicted the roll — counting pipes at bracket depth
		// zero, on the theory that a construct always collapses to one form. Review found
		// two shapes where it does not, so the rule retreated to counting only values that
		// are invariant. These two are the reason.
		$synonym_en = $this->validate( "#def %x% = {a|b}\n{plural 1: one|%x%}", 'en' );
		$this->assertSame( array(), $synonym_en['errors'] );

		$synonym_ru = $this->validate( "#def %x% = {a|b}\n{plural 1: one|%x%}", 'ru' );
		$this->assertSame( array(), $synonym_ru['errors'], 'the roll is not knowable, so no verdict' );

		// A conditional's branches can differ in top-level pipes: `b|c` on the false one.
		$conditional = $this->validate( "#set %flag% =\n#def %x% = {?flag?a|b|c}\n{plural 1: one|%x%}", 'ru' );
		$this->assertSame( array(), $conditional['errors'] );
	}

	public function test_a_def_that_rolls_a_set_is_not_reported_as_nested_brackets(): void {
		// The #def roll consumes the macro's `{a|b}` before the plural is decided, so the
		// block renders normally. Following the reference into the raw macro reported it.
		$result = $this->validate( "#set %s% = {a|b}\n#def %x% = %s%\n{plural 1: one|%x%}", 'en' );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_every_reference_is_expanded_per_pass(): void {
		// Replacing one occurrence per iteration spent the whole budget on a list that
		// merely repeats a name, and the completed expansion was then called unresolvable.
		$result = $this->validate( "#set %x% = a|b\n{plural 1: " . str_repeat( '%x%', 51 ) . '}', 'en' );

		$this->assertCount( 1, $result['errors'], 'fifty-two forms is an arity error for en' );
	}

	public function test_a_set_carrying_spintax_is_reported_not_silently_broken(): void {
		$result = $this->validate( "#set %x% = {a|b}\n{plural 2: one|%x%}" );

		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'nested spintax brackets', $result['errors'][0]['message'] );
	}

	public function test_a_set_of_plain_text_counts_its_pipes(): void {
		$result = $this->validate( "#set %x% = a|b\n{plural 1: one|%x%}", 'ru' );

		$this->assertSame( array(), $result['errors'], 'substitution really does make three forms' );
	}

	public function test_an_undefined_reference_suppresses_the_count_verdicts(): void {
		// A host variable has no static form count; judging it would file a verdict on a
		// fact the caller never claimed.
		$result = $this->validate( '{plural 2: one|%host%}', 'ru' );

		$this->assertSame( array(), $result['errors'] );
		foreach ( $result['warnings'] as $warning ) {
			$this->assertStringNotContainsString( 'forms', $warning['message'] );
		}
	}

	public function test_a_cyclic_reference_stops_at_the_budget(): void {
		$result = $this->validate( "#set %a% = %b%\n#set %b% = %a%\n{plural 2: one|%a%}", 'ru' );

		foreach ( $result['errors'] as $error ) {
			$this->assertStringNotContainsString( 'expected', $error['message'], 'no arity verdict on an unresolvable list' );
		}
	}
}
