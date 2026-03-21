<?php
/**
 * Debug logging with a ring buffer in wp_options.
 *
 * @package Spintax
 */

namespace Spintax\Support;

/**
 * Ring-buffer logger stored in a single WordPress option.
 */
class Logging {

	/**
	 * Append a log entry.
	 *
	 * @param string $level   One of: info, warning, error, debug.
	 * @param string $message Log message.
	 * @param array  $context Optional context (template_id, etc.).
	 */
	public function push( string $level, string $message, array $context = array() ): void {
		$allowed = Defaults::log_levels();
		if ( ! in_array( $level, $allowed, true ) ) {
			$level = 'info';
		}

		$entry = array(
			't'   => time(),
			'lvl' => $level,
			'msg' => sanitize_text_field( $message ),
		);

		if ( ! empty( $context ) ) {
			$entry['ctx'] = array_map( 'sanitize_text_field', array_slice( $context, 0, 10 ) );
		}

		$data            = $this->load();
		$data['items'][] = $entry;

		// Ring buffer: trim from front.
		$max = $data['max'];
		if ( count( $data['items'] ) > $max ) {
			$data['items'] = array_slice( $data['items'], -$max );
		}

		$this->save( $data );
	}

	/**
	 * Get all log entries.
	 *
	 * @return array
	 */
	public function all(): array {
		$data = $this->load();
		return $data['items'];
	}

	/**
	 * Get recent log entries (newest first).
	 *
	 * @param int $limit Max entries to return.
	 * @return array
	 */
	public function recent( int $limit = 20 ): array {
		$items = array_reverse( $this->all() );
		return array_slice( $items, 0, $limit );
	}

	/**
	 * Clear all log entries.
	 */
	public function clear(): void {
		delete_option( OptionKeys::LOGS );
	}

	/**
	 * Load log data from option.
	 */
	private function load(): array {
		$raw      = get_option( OptionKeys::LOGS, array() );
		$settings = Validators::normalize_settings( get_option( OptionKeys::SETTINGS, array() ) );
		$items    = ( is_array( $raw ) && isset( $raw['items'] ) ) ? $raw['items'] : array();

		return array(
			'items' => $items,
			'max'   => $settings['logs_max'],
		);
	}

	/**
	 * Save log data to option.
	 */
	private function save( array $data ): void {
		update_option( OptionKeys::LOGS, $data, false );
	}
}
