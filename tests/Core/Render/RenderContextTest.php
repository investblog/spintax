<?php

namespace Spintax\Tests\Core\Render;

use Spintax\Core\Render\RenderContext;

class RenderContextTest extends \WP_UnitTestCase {

	public function test_variable_precedence(): void {
		$ctx  = new RenderContext(
			array( 'color' => 'red', 'size' => 'big' ),
			array( 'color' => 'blue' ),
			array( 'color' => 'green' )
		);
		$vars = $ctx->get_merged_variables();
		// runtime > local > global
		$this->assertSame( 'green', $vars['color'] );
		$this->assertSame( 'big', $vars['size'] );
	}

	public function test_case_insensitive_keys(): void {
		$ctx  = new RenderContext( array( 'CityName' => 'Moscow' ) );
		$vars = $ctx->get_merged_variables();
		$this->assertArrayHasKey( 'cityname', $vars );
		$this->assertSame( 'Moscow', $vars['cityname'] );
	}

	public function test_context_hash_deterministic(): void {
		$ctx1 = new RenderContext( array(), array(), array( 'a' => '1', 'b' => '2' ) );
		$ctx2 = new RenderContext( array(), array(), array( 'b' => '2', 'a' => '1' ) );
		$this->assertSame( $ctx1->get_context_hash(), $ctx2->get_context_hash() );
	}

	public function test_context_hash_default_when_no_runtime(): void {
		$ctx = new RenderContext( array( 'x' => 'y' ) );
		$this->assertSame( 'default', $ctx->get_context_hash() );
	}

	public function test_context_hash_differs_with_different_vars(): void {
		$ctx1 = new RenderContext( array(), array(), array( 'city' => 'Moscow' ) );
		$ctx2 = new RenderContext( array(), array(), array( 'city' => 'London' ) );
		$this->assertNotSame( $ctx1->get_context_hash(), $ctx2->get_context_hash() );
	}

	public function test_with_local_adds_variables(): void {
		$ctx  = new RenderContext( array( 'a' => '1' ) );
		$ctx2 = $ctx->with_local( array( 'b' => '2' ) );
		$vars = $ctx2->get_merged_variables();
		$this->assertSame( '1', $vars['a'] );
		$this->assertSame( '2', $vars['b'] );
	}

	public function test_with_runtime_overrides(): void {
		$ctx  = new RenderContext( array( 'x' => 'global' ), array( 'x' => 'local' ) );
		$ctx2 = $ctx->with_runtime( array( 'x' => 'runtime' ) );
		$this->assertSame( 'runtime', $ctx2->get_merged_variables()['x'] );
	}

	public function test_push_template_and_has_template(): void {
		$ctx = new RenderContext();
		$this->assertFalse( $ctx->has_template( 42 ) );

		$ctx2 = $ctx->push_template( 42 );
		$this->assertTrue( $ctx2->has_template( 42 ) );
		$this->assertFalse( $ctx2->has_template( 99 ) );

		// Original context is immutable.
		$this->assertFalse( $ctx->has_template( 42 ) );
	}

	public function test_call_stack(): void {
		$ctx = new RenderContext();
		$ctx = $ctx->push_template( 1 )->push_template( 2 )->push_template( 3 );
		$this->assertSame( array( 1, 2, 3 ), $ctx->get_call_stack() );
	}
}
