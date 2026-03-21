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
		delete_option( OptionKeys::GLOBAL_VARIABLES );
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
}
