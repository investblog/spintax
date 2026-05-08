<?php
/**
 * Tests for the spintax conditional pre-pass.
 *
 * Mirrors fixtures from `spintax-conditionals.test.ts` (casino-platform)
 * and §9 of `docs/spintax-conditionals-spec.md` from the same project.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Core\Engine;

use Spintax\Core\Engine\Conditionals;

class ConditionalsTest extends \WP_UnitTestCase {

	private Conditionals $cond;

	public function setUp(): void {
		parent::setUp();
		$this->cond = new Conditionals();
	}

	// =========================================================================
	// §9.1 Basic forms
	// =========================================================================

	public function test_truthy_then_only_returns_then(): void {
		$this->assertSame( 'yes', $this->cond->apply( '{?VAR?yes}', array( 'var' => '1' ) ) );
	}

	public function test_empty_value_then_only_returns_empty(): void {
		$this->assertSame( '', $this->cond->apply( '{?VAR?yes}', array( 'var' => '' ) ) );
	}

	public function test_undefined_var_then_only_returns_empty(): void {
		$this->assertSame( '', $this->cond->apply( '{?VAR?yes}', array() ) );
	}

	public function test_truthy_with_else_returns_then(): void {
		$this->assertSame( 'yes', $this->cond->apply( '{?VAR?yes|no}', array( 'var' => '1' ) ) );
	}

	public function test_falsy_with_else_returns_else(): void {
		$this->assertSame( 'no', $this->cond->apply( '{?VAR?yes|no}', array( 'var' => '' ) ) );
	}

	public function test_inverted_on_falsy_returns_then(): void {
		$this->assertSame( 'absent', $this->cond->apply( '{?!VAR?absent}', array( 'var' => '' ) ) );
	}

	public function test_inverted_on_truthy_returns_empty(): void {
		$this->assertSame( '', $this->cond->apply( '{?!VAR?absent}', array( 'var' => '1' ) ) );
	}

	public function test_inverted_with_else_truthy_returns_else(): void {
		$this->assertSame(
			'present',
			$this->cond->apply( '{?!VAR?absent|present}', array( 'var' => '1' ) )
		);
	}

	public function test_inverted_with_else_falsy_returns_then(): void {
		$this->assertSame(
			'absent',
			$this->cond->apply( '{?!VAR?absent|present}', array( 'var' => '' ) )
		);
	}

	public function test_lookup_is_case_insensitive(): void {
		$this->assertSame(
			'yes',
			$this->cond->apply( '{?CasinoHasCrypto?yes}', array( 'casinohascrypto' => '1' ) )
		);
	}

	// =========================================================================
	// §9.2 Truthy edge cases
	// =========================================================================

	public function test_string_zero_is_truthy(): void {
		$this->assertSame( 'y', $this->cond->apply( '{?V?y}', array( 'v' => '0' ) ) );
	}

	public function test_string_false_is_truthy(): void {
		$this->assertSame( 'y', $this->cond->apply( '{?V?y}', array( 'v' => 'false' ) ) );
	}

	public function test_spaces_only_is_falsy(): void {
		$this->assertSame( '', $this->cond->apply( '{?V?y}', array( 'v' => '   ' ) ) );
	}

	public function test_newline_and_tab_only_is_falsy(): void {
		$this->assertSame( '', $this->cond->apply( '{?V?y}', array( 'v' => "\n\t" ) ) );
	}

	public function test_plain_string_is_truthy(): void {
		$this->assertSame( 'y', $this->cond->apply( '{?V?y}', array( 'v' => 'x' ) ) );
	}

	// =========================================================================
	// §9.3 Top-level `|` rule
	// =========================================================================

	public function test_truthy_with_multiple_pipes_first_branch_only(): void {
		$this->assertSame( 'x', $this->cond->apply( '{?A?x|y|z}', array( 'a' => '1' ) ) );
	}

	public function test_falsy_second_pipe_is_literal_in_else(): void {
		$this->assertSame( 'y|z', $this->cond->apply( '{?A?x|y|z}', array( 'a' => '' ) ) );
	}

	public function test_inner_pipe_inside_braces_stays_raw(): void {
		$this->assertSame(
			'{a|b}',
			$this->cond->apply( '{?A?{a|b}}', array( 'a' => '1' ) )
		);
	}

	public function test_truthy_keeps_nested_enum(): void {
		$this->assertSame(
			'{a|b}',
			$this->cond->apply( '{?A?{a|b}|fallback}', array( 'a' => '1' ) )
		);
	}

	public function test_falsy_with_nested_enum_falls_back(): void {
		$this->assertSame(
			'fallback',
			$this->cond->apply( '{?A?{a|b}|fallback}', array( 'a' => '' ) )
		);
	}

	public function test_inner_pipe_inside_brackets_stays_raw(): void {
		$this->assertSame(
			'[<sep=, >a|b]',
			$this->cond->apply( '{?A?[<sep=, >a|b]|none}', array( 'a' => '1' ) )
		);
	}

	public function test_falsy_with_nested_perm_falls_back(): void {
		$this->assertSame(
			'none',
			$this->cond->apply( '{?A?[<sep=, >a|b]|none}', array( 'a' => '' ) )
		);
	}

	// =========================================================================
	// §9.4 Nested conditionals
	// =========================================================================

	public function test_nested_both_truthy(): void {
		$this->assertSame(
			'both',
			$this->cond->apply( '{?A?{?B?both}}', array( 'a' => '1', 'b' => '1' ) )
		);
	}

	public function test_outer_truthy_inner_falsy_returns_empty(): void {
		$this->assertSame(
			'',
			$this->cond->apply( '{?A?{?B?both}}', array( 'a' => '1', 'b' => '' ) )
		);
	}

	public function test_outer_falsy_short_circuits_inner(): void {
		$this->assertSame(
			'',
			$this->cond->apply( '{?A?{?B?both}}', array( 'a' => '', 'b' => '1' ) )
		);
	}

	public function test_nested_with_else_outer_truthy_inner_falsy_returns_inner_else(): void {
		$this->assertSame(
			'no',
			$this->cond->apply( '{?A?{?B?yes|no}|out}', array( 'a' => '1', 'b' => '' ) )
		);
	}

	public function test_outer_falsy_returns_outer_else_inner_ignored(): void {
		$this->assertSame(
			'out',
			$this->cond->apply( '{?A?{?B?yes|no}|out}', array( 'a' => '', 'b' => '1' ) )
		);
	}

	public function test_triple_nested_all_truthy(): void {
		$this->assertSame(
			'triple',
			$this->cond->apply( '{?A?{?B?{?C?triple}}}', array( 'a' => '1', 'b' => '1', 'c' => '1' ) )
		);
	}

	public function test_triple_nested_middle_falsy_returns_empty(): void {
		$this->assertSame(
			'',
			$this->cond->apply( '{?A?{?B?{?C?triple}}}', array( 'a' => '1', 'b' => '', 'c' => '1' ) )
		);
	}

	// =========================================================================
	// §9.7 Idempotency
	// =========================================================================

	public function test_apply_twice_yields_same_result(): void {
		$tpl  = '{?A?yes|no}{?B?ok}';
		$vars = array( 'a' => '1', 'b' => '' );
		$out1 = $this->cond->apply( $tpl, $vars );
		$out2 = $this->cond->apply( $out1, $vars );
		$this->assertSame( $out1, $out2 );
	}

	// =========================================================================
	// §9.8 Malformed input — left literal, never throws
	// =========================================================================

	public function test_unclosed_conditional_left_literal(): void {
		$this->assertSame( '{?VAR?yes', $this->cond->apply( '{?VAR?yes', array() ) );
	}

	public function test_empty_name_left_literal(): void {
		$this->assertSame( '{??yes}', $this->cond->apply( '{??yes}', array() ) );
	}

	public function test_missing_separator_after_name_left_literal(): void {
		$this->assertSame( '{?VAR}', $this->cond->apply( '{?VAR}', array() ) );
	}

	public function test_bare_question_marks_in_prose_untouched(): void {
		$this->assertSame(
			'How? Like this?',
			$this->cond->apply( 'How? Like this?', array() )
		);
	}

	public function test_dollar_brace_question_with_no_close_left_literal(): void {
		$this->assertSame(
			'price: ${?',
			$this->cond->apply( 'price: ${?', array() )
		);
	}

	// =========================================================================
	// Fast path
	// =========================================================================

	public function test_fast_path_returns_template_unchanged_when_no_marker(): void {
		$this->assertSame(
			'plain text with no markers',
			$this->cond->apply( 'plain text with no markers', array( 'x' => '1' ) )
		);
	}
}
