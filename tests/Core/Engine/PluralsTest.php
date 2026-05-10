<?php
/**
 * Tests for the spintax plural-agreement pass.
 *
 * Mirrors fixtures from `spintax-plurals.test.ts` (casino-platform) and
 * §6 of `docs/spintax-plurals-engine-plan.md` (v5) from the same project.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Core\Engine;

use Spintax\Core\Engine\PluralArityError;
use Spintax\Core\Engine\PluralFormError;
use Spintax\Core\Engine\Plurals;

class PluralsTest extends \WP_UnitTestCase {

	private Plurals $plurals;

	public function setUp(): void {
		parent::setUp();
		$this->plurals = new Plurals();
	}

	// =========================================================================
	// RU 3-bucket plural rule — boundary numbers
	// =========================================================================

	public function test_ru_one_form_for_n_eq_1(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'язык', $this->plurals->plural_for( 'ru', 1, $forms ) );
	}

	public function test_ru_few_form_for_n_eq_2(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языка', $this->plurals->plural_for( 'ru', 2, $forms ) );
	}

	public function test_ru_many_form_for_n_eq_5(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языков', $this->plurals->plural_for( 'ru', 5, $forms ) );
	}

	public function test_ru_many_form_for_n_eq_11_exception(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языков', $this->plurals->plural_for( 'ru', 11, $forms ) );
	}

	public function test_ru_many_form_for_n_eq_12_exception(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языков', $this->plurals->plural_for( 'ru', 12, $forms ) );
	}

	public function test_ru_many_form_for_n_eq_14_exception(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языков', $this->plurals->plural_for( 'ru', 14, $forms ) );
	}

	public function test_ru_one_form_for_n_eq_21_mod10_eq_1(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'язык', $this->plurals->plural_for( 'ru', 21, $forms ) );
	}

	public function test_ru_few_form_for_n_eq_22_mod10_eq_2(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языка', $this->plurals->plural_for( 'ru', 22, $forms ) );
	}

	public function test_ru_many_form_for_n_eq_0(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языков', $this->plurals->plural_for( 'ru', 0, $forms ) );
	}

	public function test_ru_one_form_for_n_eq_minus_1_via_abs(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'язык', $this->plurals->plural_for( 'ru', -1, $forms ) );
	}

	public function test_ru_few_form_for_n_eq_minus_22_via_abs(): void {
		$forms = array( 'язык', 'языка', 'языков' );
		$this->assertSame( 'языка', $this->plurals->plural_for( 'ru', -22, $forms ) );
	}

	// =========================================================================
	// EN 2-bucket plural rule
	// =========================================================================

	public function test_en_one_form_for_n_eq_1(): void {
		$this->assertSame( 'language', $this->plurals->plural_for( 'en', 1, array( 'language', 'languages' ) ) );
	}

	public function test_en_many_form_for_n_eq_2(): void {
		$this->assertSame( 'languages', $this->plurals->plural_for( 'en', 2, array( 'language', 'languages' ) ) );
	}

	public function test_en_many_form_for_n_eq_0(): void {
		$this->assertSame( 'languages', $this->plurals->plural_for( 'en', 0, array( 'language', 'languages' ) ) );
	}

	public function test_en_one_form_for_n_eq_minus_1_via_abs(): void {
		$this->assertSame( 'language', $this->plurals->plural_for( 'en', -1, array( 'language', 'languages' ) ) );
	}

	// =========================================================================
	// Locale normalisation
	// =========================================================================

	public function test_normalize_ru_to_ru(): void {
		$this->assertSame( 'ru', $this->plurals->normalize_base_lang( 'ru' ) );
	}

	public function test_normalize_uppercase_to_lowercase(): void {
		$this->assertSame( 'ru', $this->plurals->normalize_base_lang( 'RU' ) );
	}

	public function test_normalize_ru_RU_strips_region(): void {
		$this->assertSame( 'ru', $this->plurals->normalize_base_lang( 'ru-RU' ) );
	}

	public function test_normalize_uk_UA_strips_region(): void {
		$this->assertSame( 'uk', $this->plurals->normalize_base_lang( 'uk-UA' ) );
	}

	public function test_normalize_pt_BR_strips_region(): void {
		$this->assertSame( 'pt', $this->plurals->normalize_base_lang( 'pt-BR' ) );
	}

	public function test_normalize_es_419_strips_region(): void {
		$this->assertSame( 'es', $this->plurals->normalize_base_lang( 'es-419' ) );
	}

	public function test_normalize_uk_UA_underscore_form(): void {
		$this->assertSame( 'uk', $this->plurals->normalize_base_lang( 'uk_UA' ) );
	}

	public function test_normalize_empty_to_empty(): void {
		$this->assertSame( '', $this->plurals->normalize_base_lang( '' ) );
	}

	// =========================================================================
	// Plural arity per locale
	// =========================================================================

	public function test_arity_ru_is_3(): void {
		$this->assertSame( 3, $this->plurals->plural_arity( 'ru' ) );
	}

	public function test_arity_uk_is_3(): void {
		$this->assertSame( 3, $this->plurals->plural_arity( 'uk' ) );
	}

	public function test_arity_be_is_3(): void {
		$this->assertSame( 3, $this->plurals->plural_arity( 'be' ) );
	}

	public function test_arity_en_is_2(): void {
		$this->assertSame( 2, $this->plurals->plural_arity( 'en' ) );
	}

	public function test_arity_es_is_2(): void {
		$this->assertSame( 2, $this->plurals->plural_arity( 'es' ) );
	}

	public function test_arity_pt_is_2(): void {
		$this->assertSame( 2, $this->plurals->plural_arity( 'pt' ) );
	}

	public function test_arity_de_is_2(): void {
		$this->assertSame( 2, $this->plurals->plural_arity( 'de' ) );
	}

	// =========================================================================
	// apply — happy path
	// =========================================================================

	public function test_apply_en_n_eq_12_picks_many(): void {
		$this->assertSame(
			'supports languages',
			$this->plurals->apply( 'supports {plural 12: language|languages}', 'en' )
		);
	}

	public function test_apply_en_n_eq_1_picks_one(): void {
		$this->assertSame(
			'supports language',
			$this->plurals->apply( 'supports {plural 1: language|languages}', 'en' )
		);
	}

	public function test_apply_ru_n_eq_12_picks_many(): void {
		$this->assertSame(
			'поддерживает языков',
			$this->plurals->apply( 'поддерживает {plural 12: язык|языка|языков}', 'ru' )
		);
	}

	public function test_apply_ru_n_eq_1_picks_one(): void {
		$this->assertSame(
			'поддерживает язык',
			$this->plurals->apply( 'поддерживает {plural 1: язык|языка|языков}', 'ru' )
		);
	}

	public function test_apply_ru_n_eq_21_picks_one(): void {
		$this->assertSame(
			'поддерживает язык',
			$this->plurals->apply( 'поддерживает {plural 21: язык|языка|языков}', 'ru' )
		);
	}

	// =========================================================================
	// Strict numeric reject → empty construct
	// =========================================================================

	public function test_empty_count_resolves_to_empty(): void {
		$this->assertSame(
			'supports ',
			$this->plurals->apply( 'supports {plural : language|languages}', 'en' )
		);
	}

	public function test_unsubstituted_var_resolves_to_empty(): void {
		$this->assertSame(
			'supports ',
			$this->plurals->apply( 'supports {plural %CasinoFoo%: language|languages}', 'en' )
		);
	}

	public function test_comma_in_number_resolves_to_empty(): void {
		$this->assertSame(
			'supports ',
			$this->plurals->apply( 'supports {plural 1,200: language|languages}', 'en' )
		);
	}

	public function test_trailing_chars_resolve_to_empty(): void {
		$this->assertSame(
			'supports ',
			$this->plurals->apply( 'supports {plural 12abc: language|languages}', 'en' )
		);
	}

	public function test_trailing_h_resolves_to_empty(): void {
		$this->assertSame(
			'supports ',
			$this->plurals->apply( 'supports {plural 08h: language|languages}', 'en' )
		);
	}

	public function test_whitespace_around_count_is_trimmed_and_accepted(): void {
		$this->assertSame(
			'supports languages',
			$this->plurals->apply( 'supports {plural   12  : language|languages}', 'en' )
		);
	}

	public function test_negative_number_accepted_via_abs(): void {
		$this->assertSame(
			'supports languages',
			$this->plurals->apply( 'supports {plural -3: language|languages}', 'en' )
		);
	}

	public function test_zero_accepted_picks_many(): void {
		$this->assertSame(
			'supports languages',
			$this->plurals->apply( 'supports {plural 0: language|languages}', 'en' )
		);
	}

	// =========================================================================
	// Arity mismatch → PluralArityError (strict mode)
	// =========================================================================

	public function test_ru_2_form_construct_throws_arity_error(): void {
		$this->expectException( PluralArityError::class );
		$this->plurals->apply( 'supports {plural 5: язык|языка}', 'ru' );
	}

	public function test_ru_4_form_construct_throws_arity_error(): void {
		$this->expectException( PluralArityError::class );
		$this->plurals->apply( 'supports {plural 5: язык|языка|языков|пятый}', 'ru' );
	}

	public function test_en_3_form_construct_throws_arity_error(): void {
		$this->expectException( PluralArityError::class );
		$this->plurals->apply( 'supports {plural 5: language|few|many}', 'en' );
	}

	public function test_en_1_form_construct_throws_arity_error(): void {
		$this->expectException( PluralArityError::class );
		$this->plurals->apply( 'supports {plural 5: solo}', 'en' );
	}

	public function test_arity_error_carries_position_and_construct(): void {
		try {
			$this->plurals->apply( 'X {plural 5: only}', 'en' );
			$this->fail( 'Expected PluralArityError' );
		} catch ( PluralArityError $err ) {
			$this->assertSame( 2, $err->position );
			$this->assertSame( '{plural 5: only}', $err->construct );
		}
	}

	// =========================================================================
	// Form-slot brackets → PluralFormError (strict mode)
	// =========================================================================

	public function test_synonym_inside_form_throws_form_error(): void {
		$this->expectException( PluralFormError::class );
		$this->plurals->apply( 'X {plural 1: {a|b}|c}', 'en' );
	}

	public function test_conditional_inside_form_throws_form_error(): void {
		$this->expectException( PluralFormError::class );
		$this->plurals->apply( 'X {plural 1: {?Flag?then|else}|c}', 'en' );
	}

	public function test_permutation_inside_form_throws_form_error(): void {
		$this->expectException( PluralFormError::class );
		$this->plurals->apply( 'X {plural 1: [<and>day|days]}', 'en' );
	}

	public function test_stray_open_bracket_in_form_throws(): void {
		$this->expectException( PluralFormError::class );
		$this->plurals->apply( 'X {plural 1: day with [|days}', 'en' );
	}

	public function test_stray_close_bracket_in_form_throws(): void {
		$this->expectException( PluralFormError::class );
		$this->plurals->apply( 'X {plural 1: day|days with ]}', 'en' );
	}

	public function test_form_error_carries_position_and_construct(): void {
		try {
			$this->plurals->apply( 'XY {plural 1: [<and>day|days]}', 'en' );
			$this->fail( 'Expected PluralFormError' );
		} catch ( PluralFormError $err ) {
			$this->assertSame( 3, $err->position );
			$this->assertStringContainsString( '[<and>day|days]', $err->construct );
		}
	}

	// =========================================================================
	// Allowed characters in form (HTML, %, slash, numbers)
	// =========================================================================

	public function test_html_em_tags_allowed_in_forms(): void {
		$this->assertSame(
			'supports <em>languages</em>',
			$this->plurals->apply( 'supports {plural 12: <em>language</em>|<em>languages</em>}', 'en' )
		);
	}

	public function test_slash_in_form_text_allowed(): void {
		$this->assertSame(
			'supports языков',
			$this->plurals->apply( 'supports {plural 12: язык/наречие|языка|языков}', 'ru' )
		);
	}

	public function test_numbers_and_punctuation_in_form_allowed(): void {
		$this->assertSame(
			'supports 1 язык',
			$this->plurals->apply( 'supports {plural 1: 1 язык|2-4 языка|5+ языков}', 'ru' )
		);
	}

	// =========================================================================
	// find_plural_blocks scanner edge cases
	// =========================================================================

	public function test_find_blocks_no_prefix_returns_empty(): void {
		$this->assertSame( 0, count( $this->plurals->find_plural_blocks( 'plain text' ) ) );
	}

	public function test_find_blocks_single_block(): void {
		$this->assertSame( 1, count( $this->plurals->find_plural_blocks( '{plural 5: a|b}' ) ) );
	}

	public function test_find_blocks_two_blocks(): void {
		$this->assertSame(
			2,
			count( $this->plurals->find_plural_blocks( '{plural 1: a|b} and {plural 5: c|d}' ) )
		);
	}

	public function test_find_blocks_no_colon_skipped(): void {
		$this->assertSame(
			0,
			count( $this->plurals->find_plural_blocks( '{plural noun stuff with no colon}' ) )
		);
	}

	public function test_find_blocks_unclosed_skipped(): void {
		$this->assertSame(
			0,
			count( $this->plurals->find_plural_blocks( '{plural 5: a|b without close' ) )
		);
	}

	// =========================================================================
	// Passthrough — no prefix, plain synonyms ignored
	// =========================================================================

	public function test_plain_text_passthrough(): void {
		$this->assertSame(
			'plain text without plurals',
			$this->plurals->apply( 'plain text without plurals', 'ru' )
		);
	}

	public function test_vars_only_passthrough(): void {
		$this->assertSame(
			'text with %vars% but no plurals',
			$this->plurals->apply( 'text with %vars% but no plurals', 'ru' )
		);
	}

	public function test_plain_synonym_ignored_no_prefix(): void {
		$this->assertSame(
			'literal {a|b|c} synonym (not plural)',
			$this->plurals->apply( 'literal {a|b|c} synonym (not plural)', 'ru' )
		);
	}

	// =========================================================================
	// Multiple constructs in one template
	// =========================================================================

	public function test_two_ru_constructs_in_one_template(): void {
		$this->assertSame(
			'supports языков and бонуса',
			$this->plurals->apply(
				'supports {plural 12: язык|языка|языков} and {plural 3: бонус|бонуса|бонусов}',
				'ru'
			)
		);
	}

	// =========================================================================
	// Locale variant routing
	// =========================================================================

	public function test_ru_RU_resolves_as_ru(): void {
		$this->assertSame(
			'supports языков',
			$this->plurals->apply( 'supports {plural 5: язык|языка|языков}', 'ru-RU' )
		);
	}

	public function test_uk_UA_resolves_as_uk(): void {
		$this->assertSame(
			'supports мов',
			$this->plurals->apply( 'supports {plural 5: мова|мови|мов}', 'uk-UA' )
		);
	}

	public function test_es_419_resolves_as_es_2_form(): void {
		$this->assertSame(
			'supports languages',
			$this->plurals->apply( 'supports {plural 5: language|languages}', 'es-419' )
		);
	}

	public function test_pt_BR_resolves_as_pt_2_form(): void {
		$this->assertSame(
			'supports languages',
			$this->plurals->apply( 'supports {plural 5: language|languages}', 'pt-BR' )
		);
	}

	// =========================================================================
	// Lenient mode (production runtime) — fullwidth-brace verbatim on errors
	// =========================================================================

	public function test_lenient_arity_mismatch_renders_verbatim_with_fullwidth_braces(): void {
		$this->assertSame(
			"X \u{FF5B}plural 1: a|b|c|d\u{FF5D} Y",
			$this->plurals->apply(
				'X {plural 1: a|b|c|d} Y',
				'en',
				array( 'lenient' => true )
			)
		);
	}

	public function test_lenient_form_error_renders_verbatim_with_fullwidth_braces(): void {
		$this->assertSame(
			"X \u{FF5B}plural 1: [<and>day|days]\u{FF5D} Y",
			$this->plurals->apply(
				'X {plural 1: [<and>day|days]} Y',
				'en',
				array( 'lenient' => true )
			)
		);
	}

	public function test_lenient_mixed_block_results_bad_verbatim_good_resolved(): void {
		$errs = array();
		$out  = $this->plurals->apply(
			'{plural 1: a|b|c} and {plural 1: x|y}',
			'en',
			array(
				'lenient'  => true,
				'on_error' => function ( \RuntimeException $err ) use ( &$errs ): void {
					$errs[] = get_class( $err );
				},
			)
		);
		$this->assertSame(
			"\u{FF5B}plural 1: a|b|c\u{FF5D} and x",
			$out
		);
		$this->assertCount( 1, $errs );
		$this->assertSame( PluralArityError::class, $errs[0] );
	}

	// =========================================================================
	// Fast path — no prefix marker
	// =========================================================================

	public function test_fast_path_returns_template_unchanged_when_no_marker(): void {
		$this->assertSame(
			'plain text with no markers',
			$this->plurals->apply( 'plain text with no markers', 'ru' )
		);
	}
}
