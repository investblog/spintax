<?php

namespace Spintax\Tests\Core\Variables;

use Spintax\Core\Variables\PostContextSource;
use Spintax\Support\SpintaxShield;

class PostContextSourceTest extends \WP_UnitTestCase {

	private PostContextSource $source;

	public function set_up(): void {
		parent::set_up();
		$this->source = new PostContextSource();
	}

	public function test_build_returns_expected_keys_for_real_post(): void {
		$user = self::factory()->user->create_and_get( array( 'display_name' => 'Alice' ) );
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'Hello',
				'post_status' => 'publish',
				'post_author' => $user->ID,
				'post_name'   => 'hello-world',
			)
		);

		$vars = $this->source->build( $post->ID );

		$this->assertSame( (string) $post->ID, $vars['post_id'] );
		$this->assertSame( 'Hello', $vars['post_title'] );
		$this->assertSame( 'hello-world', $vars['post_slug'] );
		$this->assertSame( get_permalink( $post ), $vars['post_url'] );
		$this->assertSame( (string) $user->ID, $vars['author_id'] );
		$this->assertSame( 'Alice', $vars['author_name'] );
		$this->assertNotEmpty( $vars['post_date'] );
		$this->assertNotEmpty( $vars['post_modified'] );
	}

	public function test_post_values_are_shielded_from_spintax_interpretation(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'Deal {50|60} [x] #inc',
				'post_status' => 'publish',
			)
		);

		$vars = $this->source->build( $post->ID );

		// Structural characters are entity-encoded so a post field can't be
		// re-interpreted as spintax by the render pipeline (ADR-0001, T2).
		$this->assertStringNotContainsString( '{', $vars['post_title'] );
		$this->assertStringNotContainsString( '[', $vars['post_title'] );
		$this->assertStringContainsString( '&#123;', $vars['post_title'] );
		$this->assertStringContainsString( '&#35;inc', $vars['post_title'] );
		$this->assertSame( SpintaxShield::neutralize( $post->post_title ), $vars['post_title'] );
	}

	public function test_build_returns_empty_for_unknown_post(): void {
		$this->assertSame( array(), $this->source->build( 999999 ) );
	}

	public function test_build_returns_empty_for_zero_or_negative(): void {
		$this->assertSame( array(), $this->source->build( 0 ) );
		$this->assertSame( array(), $this->source->build( -1 ) );
	}

	public function test_author_name_empty_when_author_missing(): void {
		$post_id = self::factory()->post->create( array( 'post_author' => 0 ) );
		$vars    = $this->source->build( $post_id );
		$this->assertSame( '0', $vars['author_id'] );
		$this->assertSame( '', $vars['author_name'] );
	}
}
