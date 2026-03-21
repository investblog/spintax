<?php

namespace Spintax\Tests\Support;

use Spintax\Support\Logging;
use Spintax\Support\OptionKeys;

class LoggingTest extends \WP_UnitTestCase {

	private Logging $logger;

	public function set_up(): void {
		parent::set_up();
		$this->logger = new Logging();
		delete_option( OptionKeys::LOGS );
	}

	public function test_push_and_all(): void {
		$this->logger->push( 'info', 'Test message' );
		$entries = $this->logger->all();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'info', $entries[0]['lvl'] );
		$this->assertSame( 'Test message', $entries[0]['msg'] );
	}

	public function test_push_with_context(): void {
		$this->logger->push( 'error', 'Failed', array( 'template_id' => '42' ) );
		$entries = $this->logger->all();
		$this->assertSame( '42', $entries[0]['ctx']['template_id'] );
	}

	public function test_invalid_level_defaults_to_info(): void {
		$this->logger->push( 'invalid', 'Test' );
		$entries = $this->logger->all();
		$this->assertSame( 'info', $entries[0]['lvl'] );
	}

	public function test_recent_returns_newest_first(): void {
		$this->logger->push( 'info', 'First' );
		$this->logger->push( 'info', 'Second' );
		$this->logger->push( 'info', 'Third' );

		$recent = $this->logger->recent( 2 );
		$this->assertCount( 2, $recent );
		$this->assertSame( 'Third', $recent[0]['msg'] );
		$this->assertSame( 'Second', $recent[1]['msg'] );
	}

	public function test_ring_buffer_trims_old_entries(): void {
		// Min logs_max is 10 (validator clamp), so use 10 and push 13.
		update_option( OptionKeys::SETTINGS, array( 'logs_max' => 10 ) );

		for ( $i = 1; $i <= 13; $i++ ) {
			$this->logger->push( 'info', "Entry {$i}" );
		}

		$entries = $this->logger->all();
		$this->assertCount( 10, $entries );
		$this->assertSame( 'Entry 4', $entries[0]['msg'] );
		$this->assertSame( 'Entry 13', $entries[9]['msg'] );
	}

	public function test_clear(): void {
		$this->logger->push( 'info', 'Will be cleared' );
		$this->logger->clear();
		$this->assertEmpty( $this->logger->all() );
	}
}
