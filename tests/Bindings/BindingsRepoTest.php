<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\Defaults;
use Spintax\Support\OptionKeys;

class BindingsRepoTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );
		$this->repo = new BindingsRepo();
	}

	private function sample(): array {
		return array(
			'post_type' => 'post',
			'target'    => array(
				'kind'      => 'acf_field',
				'key'       => 'hero_subtitle',
				'field_key' => 'field_5f8a1234abcd',
			),
			'source'    => array(
				'mode'        => 'template',
				'template_id' => 42,
			),
			'triggers'  => array(
				'save_post' => true,
				'cron'      => 'disabled',
			),
		);
	}

	public function test_empty_store_returns_empty_array(): void {
		$this->assertSame( array(), $this->repo->all() );
		$this->assertSame( 0, $this->repo->count() );
	}

	public function test_create_stamps_id_and_timestamps(): void {
		$binding = $this->repo->create( $this->sample() );
		$this->assertIsArray( $binding );
		$this->assertMatchesRegularExpression( '/^bind_[a-z0-9]{6}$/', $binding['id'] );
		$this->assertGreaterThan( 0, $binding['created_at'] );
		$this->assertGreaterThan( 0, $binding['updated_at'] );
		$this->assertSame( 1, $this->repo->count() );
	}

	public function test_find_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->repo->find( 'bind_zzzzzz' ) );
	}

	public function test_create_normalises_partial_payload_against_defaults(): void {
		$binding = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'acf_field',
					'key'       => 'hero',
					'field_key' => 'field_aaa',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => 1 ),
				'triggers'  => array( 'save_post' => true ),
			)
		);
		$this->assertSame( 'any', $binding['status'] );
		$this->assertTrue( $binding['behavior']['auto_seed_empty'] );
		$this->assertFalse( $binding['triggers']['acf_save_post'] );
		$this->assertSame( 'disabled', $binding['triggers']['cron'] );
	}

	public function test_create_strips_field_key_for_post_meta_targets(): void {
		$binding = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'my_meta',
					'field_key' => 'field_should_be_ignored',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => 1 ),
			)
		);
		$this->assertSame( '', $binding['target']['field_key'] );
	}

	public function test_create_rejects_duplicates_on_same_target_triple(): void {
		$first = $this->repo->create( $this->sample() );
		$this->assertIsArray( $first );

		$second = $this->repo->create( $this->sample() );
		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'spintax_bindings_duplicate', $second->get_error_code() );
		$this->assertSame( 1, $this->repo->count() );
	}

	public function test_create_rejects_when_at_cap(): void {
		$store = array();
		for ( $i = 0; $i < Defaults::MAX_BINDINGS; $i++ ) {
			$store[ 'bind_' . sprintf( '%06x', $i ) ] = array(
				'id'        => 'bind_' . sprintf( '%06x', $i ),
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'key_' . $i,
					'field_key' => '',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => 1 ),
			);
		}
		update_option( OptionKeys::BINDINGS, $store, true );

		$attempt = $this->repo->create( $this->sample() );
		$this->assertInstanceOf( \WP_Error::class, $attempt );
		$this->assertSame( 'spintax_bindings_cap', $attempt->get_error_code() );
	}

	public function test_update_replaces_fields(): void {
		$binding = $this->repo->create( $this->sample() );

		$updated = $this->repo->update(
			$binding['id'],
			array(
				'status' => 'publish',
				'behavior' => array( 'regenerate_on_save' => true ),
			)
		);
		$this->assertIsArray( $updated );
		$this->assertSame( 'publish', $updated['status'] );
		$this->assertTrue( $updated['behavior']['regenerate_on_save'] );
		$this->assertTrue( $updated['behavior']['auto_seed_empty'], 'untouched flags survive' );
		$this->assertSame( $binding['created_at'], $updated['created_at'], 'created_at is sticky' );
		$this->assertGreaterThanOrEqual( $binding['updated_at'], $updated['updated_at'] );
	}

	public function test_update_rejects_unknown_id(): void {
		$result = $this->repo->update( 'bind_zzzzzz', array( 'status' => 'publish' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'spintax_bindings_not_found', $result->get_error_code() );
	}

	public function test_update_rejects_collision_with_another_binding(): void {
		$first  = $this->repo->create( $this->sample() );
		$second = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array(
					'kind'      => 'post_meta',
					'key'       => 'other_key',
					'field_key' => '',
				),
				'source'    => array( 'mode' => 'template', 'template_id' => 7 ),
			)
		);

		// Try to retarget $second onto $first's target — should fail.
		$attempt = $this->repo->update(
			$second['id'],
			array(
				'target' => array(
					'kind'      => 'acf_field',
					'key'       => 'hero_subtitle',
					'field_key' => 'field_5f8a1234abcd',
				),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $attempt );
		$this->assertSame( 'spintax_bindings_duplicate', $attempt->get_error_code() );
	}

	public function test_update_allows_no_op_on_same_binding(): void {
		$first   = $this->repo->create( $this->sample() );
		$updated = $this->repo->update( $first['id'], array( 'status' => 'publish' ) );
		$this->assertIsArray( $updated, 'updating the same binding without changing its target must not trip the dedup check' );
	}

	public function test_delete_removes_record(): void {
		$binding = $this->repo->create( $this->sample() );
		$this->assertTrue( $this->repo->delete( $binding['id'] ) );
		$this->assertNull( $this->repo->find( $binding['id'] ) );
		$this->assertSame( 0, $this->repo->count() );
	}

	public function test_delete_returns_wp_error_for_unknown_id(): void {
		$result = $this->repo->delete( 'bind_zzzzzz' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_find_for_post_type_filters(): void {
		$this->repo->create( $this->sample() );
		$this->repo->create(
			array(
				'post_type' => 'page',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'p1', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => 5 ),
			)
		);
		$only_posts = $this->repo->find_for_post_type( 'post' );
		$this->assertCount( 1, $only_posts );
		$only_pages = $this->repo->find_for_post_type( 'page' );
		$this->assertCount( 1, $only_pages );
	}

	public function test_find_by_target_uniqueness_lookup(): void {
		$created = $this->repo->create( $this->sample() );
		$found   = $this->repo->find_by_target( 'post', 'acf_field', 'hero_subtitle' );
		$this->assertNotNull( $found );
		$this->assertSame( $created['id'], $found['id'] );

		$missing = $this->repo->find_by_target( 'post', 'post_meta', 'something_else' );
		$this->assertNull( $missing );
	}

	public function test_find_by_template_id_for_cascade(): void {
		$first  = $this->repo->create( $this->sample() ); // template_id = 42
		$second = $this->repo->create(
			array(
				'post_type' => 'page',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'p1', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => 42 ),
			)
		);
		$third = $this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'p2', 'field_key' => '' ),
				'source'    => array( 'mode' => 'template', 'template_id' => 99 ),
			)
		);

		$bindings = $this->repo->find_by_template_id( 42 );
		$this->assertCount( 2, $bindings );
		$this->assertArrayHasKey( $first['id'], $bindings );
		$this->assertArrayHasKey( $second['id'], $bindings );
		$this->assertArrayNotHasKey( $third['id'], $bindings );
	}

	public function test_per_post_mode_does_not_show_up_in_template_cascade(): void {
		$this->repo->create(
			array(
				'post_type' => 'post',
				'target'    => array( 'kind' => 'post_meta', 'key' => 'my_field', 'field_key' => '' ),
				'source'    => array( 'mode' => 'per_post' ),
			)
		);
		$this->assertSame( array(), $this->repo->find_by_template_id( 0 ) );
		$this->assertSame( array(), $this->repo->find_by_template_id( 42 ) );
	}

	public function test_persistence_survives_fresh_repo_instance(): void {
		$created = $this->repo->create( $this->sample() );
		$fresh   = new BindingsRepo();
		$found   = $fresh->find( $created['id'] );
		$this->assertNotNull( $found );
		$this->assertSame( $created['id'], $found['id'] );
	}

	public function test_malformed_stored_option_is_ignored(): void {
		update_option( OptionKeys::BINDINGS, 'not-an-array', true );
		$this->assertSame( array(), $this->repo->all() );
	}
}
