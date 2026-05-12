<?php

namespace Spintax\Tests\Bindings;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\Migration;
use Spintax\Support\OptionKeys;

class MigrationTest extends \WP_UnitTestCase {

	private BindingsRepo $repo;
	private Migration $migration;

	public function set_up(): void {
		parent::set_up();
		delete_option( OptionKeys::BINDINGS );

		// Strip global save_post listeners so factory fixtures don't trip
		// the live bindings flow during migration tests.
		remove_all_actions( 'save_post' );
		remove_all_actions( 'spintax_binding_saved' );
		remove_all_actions( 'spintax_binding_deleted' );

		$this->repo      = new BindingsRepo();
		$this->migration = new Migration( $this->repo );
	}

	private function seed_predecessor_post( array $fields, ?string $variables = null ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $post_id, Migration::META_SELECTED, $fields );
		foreach ( $fields as $f ) {
			update_post_meta( $post_id, Migration::META_SOURCE_PREFIX . $f, 'Source for ' . $f . ' on ' . $post_id );
		}
		if ( null !== $variables ) {
			update_post_meta( $post_id, Migration::META_VARS_PER_POST, $variables );
		}
		return $post_id;
	}

	public function test_has_predecessor_data_false_on_clean_site(): void {
		$this->assertFalse( $this->migration->has_predecessor_data() );
	}

	public function test_has_predecessor_data_true_when_meta_present(): void {
		$this->seed_predecessor_post( array( 'hero_text' ) );
		$this->assertTrue( $this->migration->has_predecessor_data() );
	}

	public function test_build_plan_dedupes_by_post_type_and_field(): void {
		$this->seed_predecessor_post( array( 'hero_text' ) );
		$this->seed_predecessor_post( array( 'hero_text', 'cta_label' ) );
		$this->seed_predecessor_post( array( 'cta_label' ) );

		$plan = $this->migration->build_plan();

		$this->assertCount( 2, $plan, 'two distinct (post_type, field) pairs' );

		$by_key = array();
		foreach ( $plan as $entry ) {
			$by_key[ $entry['target_key'] ] = $entry;
		}
		$this->assertSame( 2, count( $by_key['hero_text']['affected_post_ids'] ) );
		$this->assertSame( 2, count( $by_key['cta_label']['affected_post_ids'] ) );
	}

	public function test_execute_creates_one_binding_per_pair_and_seeds_sources(): void {
		$p1 = $this->seed_predecessor_post( array( 'hero_text' ) );
		$p2 = $this->seed_predecessor_post( array( 'hero_text' ) );

		$totals = $this->migration->execute();

		$this->assertSame( 1, $totals['created'], 'one binding for the deduped pair' );
		$this->assertSame( 2, $totals['posts_seeded'] );

		$bindings = $this->repo->all();
		$this->assertCount( 1, $bindings );

		$dest_key = OptionKeys::META_BINDING_SOURCE_PREFIX . 'hero_text';
		$this->assertNotEmpty( get_post_meta( $p1, $dest_key, true ) );
		$this->assertNotEmpty( get_post_meta( $p2, $dest_key, true ) );
	}

	public function test_execute_folds_identical_variables_into_overrides(): void {
		$shared = "#set %tone% = friendly\n";
		$this->seed_predecessor_post( array( 'hero_text' ), $shared );
		$this->seed_predecessor_post( array( 'hero_text' ), $shared );

		$this->migration->execute();

		$bindings = array_values( $this->repo->all() );
		// Migration trims the per-post value before comparison and folds the
		// trimmed string into binding.variables.overrides.
		$this->assertSame( trim( $shared ), $bindings[0]['variables']['overrides'] );
	}

	public function test_execute_inlines_divergent_variables_per_post(): void {
		$p1 = $this->seed_predecessor_post( array( 'hero_text' ), "#set %tone% = friendly\n" );
		$p2 = $this->seed_predecessor_post( array( 'hero_text' ), "#set %tone% = brisk\n" );

		$this->migration->execute();

		$bindings = array_values( $this->repo->all() );
		$this->assertSame( '', $bindings[0]['variables']['overrides'], 'overrides stays empty when divergent' );

		$dest_key = OptionKeys::META_BINDING_SOURCE_PREFIX . 'hero_text';
		$this->assertStringContainsString( '#set %tone% = friendly', (string) get_post_meta( $p1, $dest_key, true ) );
		$this->assertStringContainsString( '#set %tone% = brisk', (string) get_post_meta( $p2, $dest_key, true ) );
	}

	public function test_execute_is_idempotent(): void {
		$this->seed_predecessor_post( array( 'hero_text' ) );

		$first  = $this->migration->execute();
		$second = $this->migration->execute();

		$this->assertSame( 1, $first['created'] );
		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['skipped'] );

		// Bindings count is still 1, not 2.
		$this->assertCount( 1, $this->repo->all() );
	}

	public function test_skips_posts_with_non_array_selections(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Migration::META_SELECTED, 'not-an-array' );

		$plan = $this->migration->build_plan();
		$this->assertSame( array(), $plan );
	}

	public function test_skips_field_names_with_invalid_characters(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Migration::META_SELECTED, array( 'hero text', 'bad/name', 'good_one' ) );
		update_post_meta( $post_id, Migration::META_SOURCE_PREFIX . 'good_one', 'src' );

		$plan = $this->migration->build_plan();
		$names = array_column( $plan, 'target_key' );
		$this->assertContains( 'good_one', $names );
		$this->assertNotContains( 'hero text', $names );
		$this->assertNotContains( 'bad/name', $names );
	}

	public function test_does_not_touch_predecessor_meta(): void {
		$post_id = $this->seed_predecessor_post( array( 'hero_text' ), '#set %x% = y' );

		$this->migration->execute();

		// Original predecessor keys still in place.
		$this->assertSame( array( 'hero_text' ), get_post_meta( $post_id, Migration::META_SELECTED, true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, Migration::META_SOURCE_PREFIX . 'hero_text', true ) );
		$this->assertSame( '#set %x% = y', get_post_meta( $post_id, Migration::META_VARS_PER_POST, true ) );
	}

	public function test_no_predecessor_data_results_in_empty_plan(): void {
		$this->assertSame( array(), $this->migration->build_plan() );
		$totals = $this->migration->execute();
		$this->assertSame( array( 'created' => 0, 'skipped' => 0, 'errors' => 0, 'posts_seeded' => 0 ), $totals );
	}
}
