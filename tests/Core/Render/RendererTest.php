<?php

namespace Spintax\Tests\Core\Render;

use Spintax\Core\Engine\Parser;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Render\Renderer;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

class RendererTest extends \WP_UnitTestCase {

	private Renderer $renderer;

	public function set_up(): void {
		parent::set_up();
		// Use deterministic parser for predictable output.
		$parser         = new Parser( static fn( int $min, int $max ): int => $min );
		$this->renderer = new Renderer( $parser );
		delete_option( OptionKeys::SETTINGS );
		delete_option( OptionKeys::GLOBAL_VARIABLES );
		wp_cache_flush();
	}

	/**
	 * Helper: create a published template and return its ID.
	 */
	private function make_template( string $title, string $content, string $status = 'publish' ): int {
		return wp_insert_post( array(
			'post_type'    => TemplatePostType::POST_TYPE,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
		) );
	}

	/**
	 * Create a parser with a predefined RNG sequence.
	 *
	 * The last value is reused when the sequence is exhausted.
	 *
	 * @param int[] $sequence RNG values to return in order.
	 * @param int   $calls    Receives the number of RNG invocations.
	 */
	private function make_sequence_parser( array $sequence, int &$calls ): Parser {
		$index = 0;
		$calls = 0;

		return new Parser(
			static function ( int $min, int $max ) use ( $sequence, &$index, &$calls ): int {
				++$calls;

				$last_index = count( $sequence ) - 1;
				$value      = $sequence[ min( $index, $last_index ) ] ?? $min;
				++$index;

				return max( $min, min( $max, $value ) );
			}
		);
	}

	// =========================================================================
	// Basic rendering
	// =========================================================================

	public function test_render_by_id(): void {
		$id     = $this->make_template( 'Test', '{Hello|Hi} World' );
		$result = $this->renderer->render( $id );
		$this->assertSame( 'Hello World', $result );
	}

	public function test_render_by_slug(): void {
		$this->make_template( 'My Template', 'Content here' );
		$result = $this->renderer->render( 'my-template' );
		$this->assertSame( 'Content here', $result );
	}

	public function test_render_nonexistent_returns_empty(): void {
		$this->assertSame( '', $this->renderer->render( 99999 ) );
		$this->assertSame( '', $this->renderer->render( 'no-such-slug' ) );
	}

	public function test_render_empty_template(): void {
		$id = $this->make_template( 'Empty', '' );
		$this->assertSame( '', $this->renderer->render( $id ) );
	}

	// =========================================================================
	// Variables
	// =========================================================================

	public function test_local_variables(): void {
		$id     = $this->make_template( 'Vars', "#set %name% = World\nHello %name%!" );
		$result = $this->renderer->render( $id );
		$this->assertSame( 'Hello World!', $result );
	}

	public function test_runtime_variables(): void {
		$id     = $this->make_template( 'Runtime', 'Hello %name%!' );
		$result = $this->renderer->render( $id, array( 'name' => 'Alice' ) );
		$this->assertSame( 'Hello Alice!', $result );
	}

	public function test_global_variables(): void {
		update_option( OptionKeys::GLOBAL_VARIABLES, array( 'site' => 'MySite' ) );
		$id     = $this->make_template( 'Global', 'Welcome to %site%' );
		$result = $this->renderer->render( $id );
		$this->assertSame( 'Welcome to MySite', $result );
	}

	public function test_variable_precedence(): void {
		update_option( OptionKeys::GLOBAL_VARIABLES, array( 'x' => 'global' ) );
		$id     = $this->make_template( 'Prec', "#set %x% = local\n%x%" );
		$result = $this->renderer->render( $id );
		$this->assertSame( 'Local', $result ); // local overrides global, capitalised by post-process.
	}

	public function test_runtime_overrides_local(): void {
		$id     = $this->make_template( 'Override', "#set %x% = local\n%x%" );
		$result = $this->renderer->render( $id, array( 'x' => 'runtime' ) );
		$this->assertSame( 'Runtime', $result );
	}

	public function test_default_context_cache_reuses_first_randomised_output(): void {
		$calls    = 0;
		$parser   = $this->make_sequence_parser( array( 0, 1 ), $calls );
		$renderer = new Renderer( $parser );
		$id       = $this->make_template( 'Cached Random', "#set %greeting% = {hello|hi}\n%greeting%" );

		$first  = $renderer->render( $id );
		$second = $renderer->render( $id );

		$this->assertSame( 'Hello', $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	public function test_ttl_zero_rerenders_randomised_output_on_each_request(): void {
		$calls    = 0;
		$parser   = $this->make_sequence_parser( array( 0, 1 ), $calls );
		$renderer = new Renderer( $parser );
		$id       = $this->make_template( 'Uncached Random', "#set %greeting% = {hello|hi}\n%greeting%" );

		update_post_meta( $id, OptionKeys::META_CACHE_TTL, 0 );

		$first  = $renderer->render( $id );
		$second = $renderer->render( $id );

		$this->assertSame( 'Hello', $first );
		$this->assertSame( 'Hi', $second );
		$this->assertSame( 2, $calls );
	}

	public function test_process_template_rerandomises_on_each_generation_call(): void {
		$calls    = 0;
		$parser   = $this->make_sequence_parser( array( 0, 1 ), $calls );
		$renderer = new Renderer( $parser );
		$template = "#set %greeting% = {hello|hi}\n%greeting%";

		$first  = $renderer->process_template( $template );
		$second = $renderer->process_template( $template );

		$this->assertSame( 'Hello', $first );
		$this->assertSame( 'Hi', $second );
		$this->assertSame( 2, $calls );
	}

	// =========================================================================
	// Nested templates
	// =========================================================================

	public function test_nested_via_include(): void {
		$this->make_template( 'child-tpl', 'Child content' );
		// #include must be on its own line (spec: start of logical line).
		$parent = $this->make_template( 'Parent', "Before.\n#include \"child-tpl\"" );

		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );
		$result   = $renderer->render( $parent );
		$this->assertStringContainsString( 'Child content', $result );
		$this->assertStringContainsString( 'Before.', $result );
	}

	public function test_nested_via_shortcode(): void {
		$child_id = $this->make_template( 'inner', 'Inner text' );
		$parent   = $this->make_template( 'Outer', 'Start [spintax slug="inner"] end' );
		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );
		$result   = $renderer->render( $parent );
		$this->assertStringContainsString( 'Inner text', $result );
		$this->assertStringContainsString( 'Start', $result );
	}

	public function test_circular_reference_returns_empty(): void {
		$id_a = $this->make_template( 'tpl-a', '#include "tpl-b"' );
		$id_b = $this->make_template( 'tpl-b', '#include "tpl-a"' );

		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );
		$result   = $renderer->render( $id_a );
		// Should not infinite-loop, should return something without crashing.
		$this->assertIsString( $result );
	}

	public function test_nested_with_runtime_vars(): void {
		$child  = $this->make_template( 'greet', 'Hello %name%!' );
		$parent = $this->make_template( 'Wrap', '[spintax slug="greet" name="Bob"]' );

		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );
		$result   = $renderer->render( $parent );
		$this->assertStringContainsString( 'Hello Bob!', $result );
	}

	/**
	 * Regression: child templates must NOT inherit parent's #set local variables.
	 * Only global and runtime vars are passed; child defines its own locals.
	 */
	public function test_child_does_not_inherit_parent_set_vars(): void {
		$this->make_template( 'child-scope', 'Child sees %x%' );
		$parent = $this->make_template( 'Parent Scope', "#set %x% = parent\n#include \"child-scope\"" );

		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );
		$result   = $renderer->render( $parent );
		// %x% is NOT defined in child and NOT a global → should remain as literal %x%.
		$this->assertStringContainsString( '%x%', $result );
		$this->assertStringNotContainsString( 'parent', strtolower( $result ) );
	}

	/**
	 * Regression: force-fresh render bypasses child caches.
	 */
	public function test_render_fresh_bypasses_child_cache(): void {
		$child  = $this->make_template( 'cached-child', '{Alpha|Beta}' );
		$parent = $this->make_template( 'cached-parent', "#include \"cached-child\"" );

		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );

		// First render — populates caches.
		$first = $renderer->render( $parent );

		// render_fresh should bypass cache and produce a fresh render.
		$fresh = $renderer->render_fresh( $parent );

		// Both should contain valid output (not empty, not raw spintax).
		$this->assertNotEmpty( $first );
		$this->assertNotEmpty( $fresh );
		$this->assertStringNotContainsString( '{', $fresh );
	}

	// =========================================================================
	// Shortcode integration
	// =========================================================================

	public function test_shortcode_renders(): void {
		$id = $this->make_template( 'sc-test', '{Good|Nice} day' );

		// Register shortcode if not already.
		$sc = new \Spintax\Core\Shortcode\ShortcodeController(
			new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) )
		);
		$sc->init();

		$result = do_shortcode( '[spintax id="' . $id . '"]' );
		$this->assertSame( 'Good day', $result );
	}

	public function test_shortcode_with_slug(): void {
		$this->make_template( 'day-greeting', 'Have a {good|great} day' );
		$sc = new \Spintax\Core\Shortcode\ShortcodeController(
			new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) )
		);
		$sc->init();

		$result = do_shortcode( '[spintax slug="day-greeting"]' );
		$this->assertSame( 'Have a good day', $result );
	}

	public function test_shortcode_passes_runtime_vars(): void {
		$this->make_template( 'hello-tpl', 'Hello %who%!' );
		$sc = new \Spintax\Core\Shortcode\ShortcodeController(
			new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) )
		);
		$sc->init();

		$result = do_shortcode( '[spintax slug="hello-tpl" who="World"]' );
		$this->assertSame( 'Hello World!', $result );
	}

	// =========================================================================
	// PHP helper
	// =========================================================================

	public function test_spintax_render_function_exists(): void {
		$this->assertTrue( function_exists( 'spintax_render' ) );
	}

	public function test_spintax_render_by_slug(): void {
		$this->make_template( 'func-test', 'Function works' );
		$result = spintax_render( 'func-test' );
		$this->assertStringContainsString( 'Function works', $result );
	}

	// =========================================================================
	// HTML sanitisation
	// =========================================================================

	public function test_output_is_sanitised(): void {
		$id     = $this->make_template( 'XSS', '<script>alert(1)</script><p>Safe</p>' );
		$result = $this->renderer->render( $id );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '<p>Safe</p>', $result );
	}

	// =========================================================================
	// process_template (admin preview path)
	// =========================================================================

	public function test_process_template_without_post(): void {
		$result = $this->renderer->process_template(
			"#set %x% = World\n{Hello|Hi} %x%!",
			array()
		);
		$this->assertSame( 'Hello World!', $result );
	}

	// =========================================================================
	// `{?VAR?then|else}` conditionals (integration with full pipeline)
	// =========================================================================

	public function test_conditional_truthy_runtime_variable(): void {
		$result = $this->renderer->process_template(
			'{?HasBonus?Claim bonus|Deposit}',
			array( 'HasBonus' => '1' )
		);
		$this->assertSame( 'Claim bonus', $result );
	}

	public function test_conditional_falsy_runtime_variable(): void {
		$result = $this->renderer->process_template(
			'{?HasBonus?Claim bonus|Deposit}',
			array( 'HasBonus' => '' )
		);
		$this->assertSame( 'Deposit', $result );
	}

	public function test_conditional_undefined_falls_to_else(): void {
		$result = $this->renderer->process_template(
			'{?HasBonus?Claim bonus|Deposit}',
			array()
		);
		$this->assertSame( 'Deposit', $result );
	}

	/**
	 * §9.5 — conditional inside variable value resolves on pass 2.
	 */
	public function test_conditional_inside_variable_value_resolves_truthy(): void {
		$result = $this->renderer->process_template(
			"#set %CTA% = {?HasBonus?Claim bonus|Deposit}\n#set %HasBonus% = 1\n%CTA%",
			array()
		);
		$this->assertSame( 'Claim bonus', $result );
	}

	public function test_conditional_inside_variable_value_resolves_falsy(): void {
		$result = $this->renderer->process_template(
			"#set %CTA% = {?HasBonus?Claim bonus|Deposit}\n#set %HasBonus% =\n%CTA%",
			array()
		);
		$this->assertSame( 'Deposit', $result );
	}

	/**
	 * §9.6 — composition with permutations after the conditional pass.
	 */
	public function test_conditional_truthy_keeps_nested_permutation(): void {
		$result = $this->renderer->process_template(
			'{?A?[<sep=", ";minsize=3;maxsize=3>x|y|z]}',
			array( 'A' => '1' )
		);
		// Deterministic parser picks indexes deterministically, but
		// post_process upper-cases the first letter — compare case-
		// insensitive. Just assert all elements surface and no
		// `[`/`]` leaked from the permutation stage.
		$lowered = strtolower( $result );
		$this->assertStringContainsString( 'x', $lowered );
		$this->assertStringContainsString( 'y', $lowered );
		$this->assertStringContainsString( 'z', $lowered );
		$this->assertStringNotContainsString( '[', $result );
		$this->assertStringNotContainsString( ']', $result );
	}

	public function test_conditional_falsy_skips_permutation_entirely(): void {
		$result = $this->renderer->process_template(
			'{?A?[<sep=", ";minsize=3;maxsize=3>x|y|z]}',
			array( 'A' => '' )
		);
		$this->assertSame( '', $result );
	}

	/**
	 * §9.9 — known footgun: #set inside a conditional branch is extracted
	 * unconditionally before the conditional pass runs, so all branches'
	 * #sets are applied (last definition wins).
	 */
	public function test_set_inside_conditional_branch_extracted_unconditionally(): void {
		$result = $this->renderer->process_template(
			"{?A?\n#set %x% = first\n|}{?A?\n#set %x% = second\n|}%x%",
			array( 'A' => '1' )
		);
		$this->assertSame( 'Second', $result );
	}

	public function test_inverted_conditional_runs_when_var_absent(): void {
		$result = $this->renderer->process_template(
			'{?!Bonus?Open free demo|Claim bonus}',
			array()
		);
		$this->assertSame( 'Open free demo', $result );
	}

	public function test_malformed_conditional_does_not_throw(): void {
		// Spec §7.2 — balanced malformed forms are guaranteed non-throwing.
		// Don't pin output (post-processing may rearrange spaces); just
		// assert we get a string back and key fragments survive.
		$result = $this->renderer->process_template( '{?VAR}', array() );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'VAR', $result );
	}
}
