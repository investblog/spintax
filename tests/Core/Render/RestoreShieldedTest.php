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

use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\RenderContext;
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

	// ── the guard across a roll ──────────────────────────────────────────────

	/**
	 * Roll `#def` values through the shipping private method.
	 *
	 * @param array<string, string> $definitions Directive values, name => raw value.
	 * @param array<string, string> $globals     Variables the context starts with.
	 * @return array<string, string> Frozen values.
	 */
	private function roll( array $definitions, array $globals ): array {
		$renderer = new Renderer( new Parser( static fn( int $min, int $max ): int => $min ) );
		$ref      = new \ReflectionMethod( Renderer::class, 'roll_definitions' );
		$ref->setAccessible( true );

		return $ref->invoke( $renderer, $definitions, new RenderContext( $globals ), array(), 'en' );
	}

	/**
	 * A NUL a rolled definition carries keeps the NEXT definition on the sequential restore.
	 *
	 * The map a roll reads is built once and grown by each frozen value, so what a definition adds
	 * to it has to be watched as closely as what the caller put there. `%a%` freezes an unpaired
	 * NUL followed by the NAME of a key `%b%` goes on to mint, and in `%b%`'s working text the two
	 * spell `\x00NESTED_1\x00` — a key the shield never minted for it.
	 */
	public function test_a_nul_frozen_into_a_definition_keeps_the_sequential_restore(): void {
		$rolled = $this->roll(
			array(
				'a' => "\x00NESTED_1",
				'b' => '%a%[spintax slug="A"][spintax slug="B"]',
			),
			array()
		);

		$this->assertSame( "\x00NESTED_1[spintax slug=\"A\"][spintax slug=\"B\"]", $rolled['b'] );
	}

	/**
	 * A definition that SHADOWS the last NUL-bearing value puts the single pass back.
	 *
	 * The mirror of the test above, and the direction an on-only flag gets wrong. The global `%a%`
	 * carries a NUL, `#def %a%` replaces it with clean text, and by the time `%b%` rolls the map
	 * holds no NUL at all — so `%b%` is restored in one pass and its forged key survives. A roll
	 * can take the last NUL out of the map as easily as it can add one.
	 */
	public function test_a_definition_that_shadows_the_last_nul_restores_in_one_pass(): void {
		$forged = '[spintax slug="0"] [spintax slug="1"]NESTED_0[spintax slug="2"]';

		$rolled = $this->roll(
			array(
				'a' => 'clean',
				'b' => $forged,
			),
			array( 'a' => "dirty\x00value" )
		);

		$this->assertSame( $forged, $rolled['b'] );
	}
}
