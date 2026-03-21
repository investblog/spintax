<?php
/**
 * Tests for the spintax Parser.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Core\Engine;

use Spintax\Core\Engine\Parser;

class ParserTest extends \WP_UnitTestCase {

	/**
	 * Create a parser that always picks the first option (index 0).
	 */
	private function make_first(): Parser {
		return new Parser( static fn( int $min, int $max ): int => $min );
	}

	/**
	 * Create a parser that always picks the last option.
	 */
	private function make_last(): Parser {
		return new Parser( static fn( int $min, int $max ): int => $max );
	}

	// =========================================================================
	// Comments
	// =========================================================================

	public function test_strip_comments_removes_block_comments(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hello  world', $parser->strip_comments( 'Hello /# comment #/ world' ) );
	}

	public function test_strip_comments_multiline(): void {
		$parser = $this->make_first();
		$input  = "Before\n/#\nMulti\nline\n#/\nAfter";
		$this->assertSame( "Before\n\nAfter", $parser->strip_comments( $input ) );
	}

	public function test_strip_comments_preserves_html_comments(): void {
		$parser = $this->make_first();
		$input  = '<--// Title //-->';
		$this->assertSame( $input, $parser->strip_comments( $input ) );
	}

	// =========================================================================
	// #set directives
	// =========================================================================

	public function test_extract_set_simple(): void {
		$parser = $this->make_first();
		$result = $parser->extract_set_directives( "#set %name% = World\nHello %name%!" );
		$this->assertArrayHasKey( 'name', $result['variables'] );
		$this->assertSame( 'World', $result['variables']['name'] );
		$this->assertStringNotContainsString( '#set', $result['body'] );
	}

	public function test_extract_set_with_spintax_value(): void {
		$parser = $this->make_first();
		$result = $parser->extract_set_directives( '#set %greeting% = {Hello|Hi}' );
		$this->assertSame( '{Hello|Hi}', $result['variables']['greeting'] );
	}

	public function test_extract_set_case_insensitive_name(): void {
		$parser = $this->make_first();
		$result = $parser->extract_set_directives( '#set %CasinoName% = Test' );
		$this->assertArrayHasKey( 'casinoname', $result['variables'] );
	}

	public function test_set_not_extracted_if_not_at_line_start(): void {
		$parser = $this->make_first();
		$result = $parser->extract_set_directives( 'text #set %var% = value' );
		$this->assertEmpty( $result['variables'] );
		$this->assertStringContainsString( '#set', $result['body'] );
	}

	// =========================================================================
	// Variable expansion
	// =========================================================================

	public function test_expand_simple_variable(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hello World!', $parser->expand_variables( 'Hello %name%!', array( 'name' => 'World' ) ) );
	}

	public function test_expand_case_insensitive(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hi', $parser->expand_variables( '%Greeting%', array( 'greeting' => 'Hi' ) ) );
	}

	public function test_expand_nested_variables(): void {
		$parser = $this->make_first();
		$vars   = array(
			'a' => '%b%',
			'b' => 'resolved',
		);
		$this->assertSame( 'resolved', $parser->expand_variables( '%a%', $vars ) );
	}

	public function test_expand_leaves_undefined_as_is(): void {
		$parser = $this->make_first();
		$this->assertSame( '%unknown%', $parser->expand_variables( '%unknown%', array() ) );
	}

	public function test_expand_circular_throws(): void {
		$parser = $this->make_first();
		$vars   = array(
			'a' => '%b%',
			'b' => '%a%',
		);
		$this->expectException( \RuntimeException::class );
		$parser->expand_variables( '%a%', $vars );
	}

	// =========================================================================
	// Enumerations {a|b|c}
	// =========================================================================

	public function test_enum_picks_first(): void {
		$parser = $this->make_first();
		$this->assertSame( 'a', $parser->resolve_enumerations( '{a|b|c}' ) );
	}

	public function test_enum_picks_last(): void {
		$parser = $this->make_last();
		$this->assertSame( 'c', $parser->resolve_enumerations( '{a|b|c}' ) );
	}

	public function test_enum_nested(): void {
		$parser = $this->make_first();
		$this->assertSame( 'a', $parser->resolve_enumerations( '{a|{b|c}}' ) );
	}

	public function test_enum_nested_inner_picked(): void {
		$parser = $this->make_last();
		// Inner {b|c} → c, then outer {a|c} → c
		$this->assertSame( 'c', $parser->resolve_enumerations( '{a|{b|c}}' ) );
	}

	public function test_enum_empty_option(): void {
		$parser = $this->make_first();
		// First option is empty.
		$this->assertSame( '', $parser->resolve_enumerations( '{|a|b}' ) );
	}

	public function test_enum_empty_option_last(): void {
		$parser = $this->make_last();
		$this->assertSame( 'b', $parser->resolve_enumerations( '{|a|b}' ) );
	}

	public function test_enum_single_option(): void {
		$parser = $this->make_first();
		$this->assertSame( 'only', $parser->resolve_enumerations( '{only}' ) );
	}

	public function test_enum_adjacent_to_text(): void {
		$parser = $this->make_first();
		$this->assertSame( 'YooMoney', $parser->resolve_enumerations( '{Yoo|Ю}Money' ) );
	}

	public function test_enum_deeply_nested(): void {
		$parser = $this->make_first();
		// {1X{S|s}lots} → inner {S|s} → S → 1XSlots
		$this->assertSame( '1XSlots', $parser->resolve_enumerations( '{1X{S|s}lots}' ) );
	}

	public function test_enum_preserves_permutation_brackets(): void {
		$parser = $this->make_first();
		// {a|[b|c]} → picks first option 'a', permutation untouched.
		$this->assertSame( 'a', $parser->resolve_enumerations( '{a|[b|c]}' ) );
	}

	public function test_enum_multiple_in_text(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hello World', $parser->resolve_enumerations( '{Hello|Hi} {World|Earth}' ) );
	}

	// =========================================================================
	// Permutations [<config>a|b|c]
	// =========================================================================

	public function test_perm_simple_all_elements(): void {
		$parser = $this->make_first();
		// Fisher-Yates with always-min: [a|b|c] → identity shuffle → "a b c"
		$result = $parser->resolve_permutations( '[a|b|c]' );
		// All three elements present.
		$parts = explode( ' ', $result );
		sort( $parts );
		$this->assertSame( array( 'a', 'b', 'c' ), $parts );
	}

	public function test_perm_single_separator(): void {
		$parser = $this->make_first();
		$result = $parser->resolve_permutations( '[< and > a|b]' );
		$this->assertStringContainsString( ' and ', $result );
	}

	public function test_perm_configured_minmax(): void {
		$parser = $this->make_first();
		$result = $parser->resolve_permutations( '[<minsize=1;maxsize=1> a|b|c]' );
		// Only one element selected.
		$this->assertThat(
			$result,
			$this->logicalOr(
				$this->equalTo( 'a' ),
				$this->equalTo( 'b' ),
				$this->equalTo( 'c' )
			)
		);
		$this->assertStringNotContainsString( ' ', trim( $result ) );
	}

	public function test_perm_sep_and_lastsep(): void {
		$parser = $this->make_first();
		$result = $parser->resolve_permutations(
			'[<minsize=3;maxsize=3;sep=", ";lastsep=" and "> a|b|c]'
		);
		// All 3 elements selected, joined with ", " and " and " before last.
		$this->assertStringContainsString( ', ', $result );
		$this->assertStringContainsString( ' and ', $result );
		// Verify all elements present.
		$this->assertMatchesRegularExpression( '/\ba\b/', $result );
		$this->assertMatchesRegularExpression( '/\bb\b/', $result );
		$this->assertMatchesRegularExpression( '/\bc\b/', $result );
	}

	public function test_perm_nested_in_enum(): void {
		$parser = $this->make_first();
		// Enum resolved first (tested separately), permutation after.
		$input  = '[x|y]';
		$result = $parser->resolve_permutations( $input );
		$parts  = explode( ' ', $result );
		sort( $parts );
		$this->assertSame( array( 'x', 'y' ), $parts );
	}

	public function test_perm_empty_elements_filtered(): void {
		$parser = $this->make_first();
		$result = $parser->resolve_permutations( '[a||b]' );
		$parts  = explode( ' ', $result );
		sort( $parts );
		$this->assertSame( array( 'a', 'b' ), $parts );
	}

	// =========================================================================
	// Post-processing
	// =========================================================================

	public function test_post_process_collapse_spaces(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hello world', $parser->post_process( 'Hello   world' ) );
	}

	public function test_post_process_space_before_punctuation(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hello, world!', $parser->post_process( 'Hello , world !' ) );
	}

	public function test_post_process_capitalize_first(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Hello', $parser->post_process( 'hello' ) );
	}

	public function test_post_process_capitalize_after_sentence(): void {
		$parser = $this->make_first();
		$this->assertSame( 'One. Two.', $parser->post_process( 'one. two.' ) );
	}

	public function test_post_process_cyrillic_capitalization(): void {
		$parser = $this->make_first();
		$this->assertSame( 'Привет', $parser->post_process( 'привет' ) );
	}

	// =========================================================================
	// #include directives
	// =========================================================================

	public function test_find_include_directives(): void {
		$parser   = $this->make_first();
		$text     = "Line 1\n#include \"hero-text\"\nLine 3";
		$includes = $parser->find_include_directives( $text );
		$this->assertCount( 1, $includes );
		$this->assertSame( 'hero-text', $includes[0]['slug'] );
		$this->assertSame( 2, $includes[0]['line'] );
	}

	public function test_resolve_includes(): void {
		$parser = $this->make_first();
		$text   = "Before\n#include \"footer\"\nAfter";
		$result = $parser->resolve_includes(
			$text,
			static fn( string $slug ): string => '[INCLUDED:' . $slug . ']'
		);
		$this->assertStringContainsString( '[INCLUDED:footer]', $result );
		$this->assertStringNotContainsString( '#include', $result );
	}

	// =========================================================================
	// Full pipeline: process()
	// =========================================================================

	public function test_process_full_pipeline(): void {
		$parser   = $this->make_first();
		$template = "#set %name% = {World|Earth}\n{Hello|Hi} %name%!";
		$result   = $parser->process( $template );
		$this->assertSame( 'Hello World!', $result );
	}

	public function test_process_variable_with_permutation(): void {
		$parser   = $this->make_first();
		$template = "#set %items% = [<sep=\", \";lastsep=\" and \";minsize=3;maxsize=3> a|b|c]\nI like %items%.";
		$result   = $parser->process( $template );
		// All 3 elements present with correct separators.
		$this->assertStringContainsString( ' and ', $result );
		$this->assertStringStartsWith( 'I like ', $result );
		$this->assertStringEndsWith( '.', $result );
	}

	public function test_process_enum_inside_permutation(): void {
		$parser   = $this->make_first();
		$template = '[<sep=", ";minsize=2;maxsize=2> {red|blue} apple|{big|small} orange]';
		$result   = $parser->process( $template );
		// Enums resolve first: {red|blue} → red, {big|small} → big
		// Then permutation with 2 elements, shuffled.
		// Post-processing may capitalize the first word.
		$lower = strtolower( $result );
		$this->assertStringContainsString( 'red apple', $lower );
		$this->assertStringContainsString( 'big orange', $lower );
		$this->assertStringContainsString( ', ', $result );
	}

	public function test_process_preserves_html(): void {
		$parser   = $this->make_first();
		$template = '<h1>{Hello|Hi}</h1>';
		$result   = $parser->process( $template );
		$this->assertSame( '<h1>Hello</h1>', $result );
	}

	/**
	 * Smoke test with the real production template.
	 */
	public function test_process_real_template_does_not_throw(): void {
		$fixture = dirname( __DIR__, 2 ) . '/fixtures/review-casino.txt';
		if ( ! file_exists( $fixture ) ) {
			$this->markTestSkipped( 'Fixture file not found.' );
		}

		$template = file_get_contents( $fixture );
		$parser   = new Parser(); // Real random.

		// Should complete without exception.
		$result = $parser->process( $template );
		$this->assertNotEmpty( $result );

		// Should not contain unresolved enumerations or permutations.
		$this->assertStringNotContainsString( '{', $result );
		$this->assertStringNotContainsString( '}', $result );
		// Permutation brackets should be resolved (except HTML attributes like href).
		$this->assertStringNotContainsString( '#set ', $result );
	}
}
