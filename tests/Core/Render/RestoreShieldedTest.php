<?php
/**
 * The host-construct restore — which of the two restores runs, and why it matters.
 *
 * `Renderer` shields `[spintax …]` shortcodes into `\x00NESTED_n\x00` placeholders and puts them
 * back at the end. There are two ways to do that and they are NOT interchangeable:
 *
 *   - SEQUENTIAL — `str_replace()` over arrays: every occurrence of the first key throughout the
 *     text, then the second, and so on. O(text x keys), which is what made the stage quadratic.
 *   - SINGLE PASS — `strtr()` with the map: one left-to-right scan, no rescanning of what it wrote.
 *
 * The engine picks between them by a guard: no NUL from outside the shield => single pass,
 * otherwise sequential. The shared golden corpus covers none of this (it is a host-seam divergence),
 * so it is pinned here — mirroring `spintax-php`'s `RestoreParityTest`. Every assertion fails if the
 * guard is dropped in either direction.
 *
 * @package Spintax
 */

namespace Spintax\Tests\Core\Render;

use Spintax\Core\Render\Renderer;

class RestoreShieldedTest extends \WP_UnitTestCase {

	/**
	 * Call a private static method on Renderer.
	 *
	 * @param string  $method Method name.
	 * @param mixed[] $args   Positional arguments.
	 * @return mixed
	 */
	private static function invoke( string $method, array $args ) {
		$ref = new \ReflectionMethod( Renderer::class, $method );
		$ref->setAccessible( true );

		return $ref->invoke( null, ...$args );
	}

	public function test_an_empty_map_returns_the_text_untouched(): void {
		$this->assertSame( 'nothing to do', self::invoke( 'restore_shielded', array( 'nothing to do', array(), true ) ) );
		$this->assertSame( 'nothing to do', self::invoke( 'restore_shielded', array( 'nothing to do', array(), false ) ) );
	}

	/**
	 * The two restores are observably different, and the flag chooses between them.
	 *
	 * The map's first replacement produces the second key. `str_replace()` then rewrites what it
	 * just wrote and reaches 'DONE'; `strtr()` never rescans its own output and stops at the first
	 * substitution. Both assertions fail if either branch is swapped for the other.
	 */
	public function test_the_flag_selects_the_restore(): void {
		$map = array(
			"\x00NESTED_0\x00" => "\x00NESTED_1\x00",
			"\x00NESTED_1\x00" => 'DONE',
		);

		// Single pass: NESTED_0 -> its value, and the emitted NESTED_1 is not rescanned.
		$this->assertSame(
			"\x00NESTED_1\x00",
			self::invoke( 'restore_shielded', array( "\x00NESTED_0\x00", $map, true ) )
		);

		// Sequential: NESTED_0 -> NESTED_1, then that NESTED_1 -> DONE.
		$this->assertSame(
			'DONE',
			self::invoke( 'restore_shielded', array( "\x00NESTED_0\x00", $map, false ) )
		);
	}

	/**
	 * The guard is true only when no NUL enters from outside the shield — body or variable value.
	 */
	public function test_the_guard_reads_body_and_variable_values(): void {
		$this->assertTrue(
			self::invoke( 'restore_is_unambiguous', array( 'clean body', array( 'v' => 'clean value' ) ) )
		);

		$this->assertFalse(
			self::invoke( 'restore_is_unambiguous', array( "body with a \x00 nul", array() ) )
		);

		// Expansion substitutes variable values in, so a NUL there counts exactly as a body NUL.
		$this->assertFalse(
			self::invoke( 'restore_is_unambiguous', array( 'clean body', array( 'v' => "value \x00 nul" ) ) )
		);
	}
}
