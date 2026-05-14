<?php
/**
 * Admin Logs viewer for the spintax ring-buffer logger.
 *
 * Bulk Apply, cron walks, and binding triggers push progress / failure
 * lines into `Spintax\Support\Logging`. Prior to 2.1.0 there was no UI
 * to read those entries — admin notices pointed users at "logs" that
 * didn't exist anywhere viewable. This page closes that gap.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\Capabilities;
use Spintax\Support\Logging;

/**
 * Spintax → Logs admin page.
 *
 * - View capability: `manage_spintax_templates` (content-manager).
 * - Clear capability: `manage_options` (site admin only).
 *
 * Pagination cap is `min( self::PER_PAGE, settings.logs_max )` so the
 * page size never exceeds what's actually retained by the ring buffer.
 */
class LogsPage {

	use AdminNotice;

	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'spintax-logs';

	/**
	 * Default UI page size. Effective size is clamped to `logs_max` from
	 * settings (so a site with `logs_max=10` never tries to render 50).
	 *
	 * @var int
	 */
	private const PER_PAGE = 50;

	/**
	 * Allowed log-level filter values (plus the sentinel 'all').
	 *
	 * @var string[]
	 */
	private const LEVELS = array( 'all', 'info', 'warning', 'error', 'debug' );

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the submenu page under the spintax CPT (priority 30, after
	 * Bindings and Settings).
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . TemplatePostType::POST_TYPE,
			__( 'Spintax Logs', 'spintax' ),
			__( 'Logs', 'spintax' ),
			Capabilities::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			30
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_actions' ) );
		}
	}

	/**
	 * URL of this page (used by redirects + cross-page CTAs).
	 */
	public static function page_url(): string {
		return admin_url(
			'edit.php?post_type=' . TemplatePostType::POST_TYPE . '&page=' . self::PAGE_SLUG
		);
	}

	/**
	 * Handle POST actions (PRG pattern).
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		// Clear logs is gated separately so editors with view-only access
		// can't wipe the buffer (spec §4.x — operational guard).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() below.
		if ( isset( $_POST['spintax_logs_clear'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to clear logs.', 'spintax' ) );
			}
			check_admin_referer( 'spintax_logs_clear' );
			( new Logging() )->clear();
			$this->redirect_with_notice( self::page_url(), __( 'Logs cleared.', 'spintax' ) );
		}
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'spintax' ) );
		}

		$filters = $this->read_filters();
		$entries = $this->collect_entries( $filters );
		$total   = count( $entries );

		$per_page = $this->effective_per_page();
		$page     = max( 1, $filters['paged'] );
		$offset   = ( $page - 1 ) * $per_page;
		$items    = array_slice( $entries, $offset, $per_page );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spintax Logs', 'spintax' ); ?></h1>

			<?php $this->render_notice(); ?>

			<?php $this->render_filter_form( $filters ); ?>

			<?php if ( 0 === $total ) : ?>
				<?php $this->render_empty_state( $filters ); ?>
			<?php else : ?>
				<?php $this->render_table( $items ); ?>
				<?php $this->render_pagination( $total, $per_page, $page, $filters ); ?>
			<?php endif; ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" style="margin-top:20px;">
					<?php wp_nonce_field( 'spintax_logs_clear' ); ?>
					<button
						type="submit"
						name="spintax_logs_clear"
						class="button button-secondary"
						onclick="return confirm('<?php echo esc_js( __( 'Clear all log entries? This cannot be undone.', 'spintax' ) ); ?>');"
					>
						<?php esc_html_e( 'Clear all logs', 'spintax' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Read + sanitize the filter / pagination query params.
	 *
	 * @return array{level: string, q: string, paged: int}
	 */
	private function read_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter GET params, no state change.
		$level_raw = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['level'] ) ) : 'all';
		$q_raw     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$paged_raw = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$level = in_array( $level_raw, self::LEVELS, true ) ? $level_raw : 'all';

		return array(
			'level' => $level,
			'q'     => $q_raw,
			'paged' => max( 1, $paged_raw ),
		);
	}

	/**
	 * Pull all log entries, newest-first, then apply level + substring filters.
	 *
	 * Filtering happens in PHP rather than at storage time because the
	 * ring buffer is small (≤ logs_max, default 200) so an in-memory
	 * scan stays cheap, and `Logging` doesn't expose query helpers.
	 *
	 * @param array{level: string, q: string, paged: int} $filters Filter state.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_entries( array $filters ): array {
		$logger = new Logging();
		$all    = $logger->all();
		// `all()` is oldest-first; surface newest-first to the editor.
		$newest = array_reverse( $all );

		$level   = $filters['level'];
		$q_lower = '' === $filters['q'] ? '' : mb_strtolower( $filters['q'] );

		if ( 'all' === $level && '' === $q_lower ) {
			return $newest;
		}

		$out = array();
		foreach ( $newest as $entry ) {
			$entry_level = (string) ( $entry['lvl'] ?? '' );
			if ( 'all' !== $level && $entry_level !== $level ) {
				continue;
			}
			if ( '' !== $q_lower ) {
				$haystack = $this->entry_haystack( $entry );
				if ( false === mb_strpos( $haystack, $q_lower ) ) {
					continue;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Build a lowercase searchable haystack from a log entry — message
	 * plus any context values, joined.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 */
	private function entry_haystack( array $entry ): string {
		$parts   = array();
		$parts[] = (string) ( $entry['msg'] ?? '' );
		if ( isset( $entry['ctx'] ) && is_array( $entry['ctx'] ) ) {
			foreach ( $entry['ctx'] as $value ) {
				if ( is_scalar( $value ) ) {
					$parts[] = (string) $value;
				}
			}
		}
		return mb_strtolower( implode( ' ', $parts ) );
	}

	/**
	 * Effective page size, clamped to settings.logs_max.
	 */
	private function effective_per_page(): int {
		$settings = ( new SettingsRepository() )->get();
		$logs_max = max( 1, (int) ( $settings['logs_max'] ?? self::PER_PAGE ) );
		return min( self::PER_PAGE, $logs_max );
	}

	/**
	 * Render the filter form (level select + substring search).
	 *
	 * @param array{level: string, q: string, paged: int} $filters Current filter state.
	 */
	private function render_filter_form( array $filters ): void {
		$labels = array(
			'all'     => __( 'All levels', 'spintax' ),
			'info'    => __( 'Info', 'spintax' ),
			'warning' => __( 'Warning', 'spintax' ),
			'error'   => __( 'Error', 'spintax' ),
			'debug'   => __( 'Debug', 'spintax' ),
		);
		?>
		<form method="get" class="spintax-logs-filter" style="margin:12px 0;">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( TemplatePostType::POST_TYPE ); ?>" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

			<label for="spintax-logs-level"><?php esc_html_e( 'Level', 'spintax' ); ?></label>
			<select id="spintax-logs-level" name="level">
				<?php foreach ( self::LEVELS as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['level'], $value ); ?>>
						<?php echo esc_html( $labels[ $value ] ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="spintax-logs-q" style="margin-left:8px;"><?php esc_html_e( 'Search', 'spintax' ); ?></label>
			<input
				type="search"
				id="spintax-logs-q"
				name="q"
				value="<?php echo esc_attr( $filters['q'] ); ?>"
				placeholder="<?php esc_attr_e( 'message or context…', 'spintax' ); ?>"
			/>

			<button type="submit" class="button">
				<?php esc_html_e( 'Filter', 'spintax' ); ?>
			</button>

			<?php if ( 'all' !== $filters['level'] || '' !== $filters['q'] ) : ?>
				<a href="<?php echo esc_url( self::page_url() ); ?>" class="button">
					<?php esc_html_e( 'Reset', 'spintax' ); ?>
				</a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render the log entries table.
	 *
	 * @param array<int, array<string, mixed>> $items Entries to render.
	 */
	private function render_table( array $items ): void {
		?>
		<table class="widefat striped spintax-logs-table">
			<thead>
				<tr>
					<th scope="col" style="width:170px;"><?php esc_html_e( 'Time', 'spintax' ); ?></th>
					<th scope="col" style="width:90px;"><?php esc_html_e( 'Level', 'spintax' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'spintax' ); ?></th>
					<th scope="col" style="width:200px;"><?php esc_html_e( 'Context', 'spintax' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $entry ) : ?>
					<tr>
						<td>
							<code><?php echo esc_html( $this->format_time( (int) ( $entry['t'] ?? 0 ) ) ); ?></code>
						</td>
						<td>
							<?php $this->render_level_badge( (string) ( $entry['lvl'] ?? 'info' ) ); ?>
						</td>
						<td><?php echo esc_html( (string) ( $entry['msg'] ?? '' ) ); ?></td>
						<td>
							<?php $this->render_context( $entry['ctx'] ?? array() ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Format a unix timestamp using the site's locale + WP timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function format_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}
		return wp_date( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Render a level pill (info/warning/error/debug) with semantic colour.
	 *
	 * @param string $level Level slug.
	 */
	private function render_level_badge( string $level ): void {
		$colours = array(
			'info'    => array(
				'bg'     => '#e5f3ff',
				'border' => '#1d6fb8',
				'fg'     => '#0a4b86',
			),
			'warning' => array(
				'bg'     => '#fff7e0',
				'border' => '#dba617',
				'fg'     => '#3b2c00',
			),
			'error'   => array(
				'bg'     => '#fdecea',
				'border' => '#cc1818',
				'fg'     => '#7a0d0d',
			),
			'debug'   => array(
				'bg'     => '#f0f0f1',
				'border' => '#8c8f94',
				'fg'     => '#2c3338',
			),
		);
		$style   = $colours[ $level ] ?? $colours['info'];
		printf(
			'<span style="background:%s;border:1px solid %s;color:%s;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase;">%s</span>',
			esc_attr( $style['bg'] ),
			esc_attr( $style['border'] ),
			esc_attr( $style['fg'] ),
			esc_html( $level )
		);
	}

	/**
	 * Render an entry's context (sanitised key:value pairs).
	 *
	 * @param mixed $context Context payload (expected array, defensive against junk).
	 */
	private function render_context( $context ): void {
		if ( ! is_array( $context ) || empty( $context ) ) {
			echo '<span style="color:#646970;">—</span>';
			return;
		}
		echo '<code style="font-size:11px;white-space:pre-wrap;word-break:break-all;">';
		$lines = array();
		foreach ( $context as $key => $value ) {
			$lines[] = esc_html( (string) $key ) . '=' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}
		echo implode( "\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped per-fragment above.
		echo '</code>';
	}

	/**
	 * Render WP-style pagination links if there is more than one page.
	 *
	 * @param int                                         $total    Total filtered entry count.
	 * @param int                                         $per_page Effective page size.
	 * @param int                                         $page     Current page number (1-indexed).
	 * @param array{level: string, q: string, paged: int} $filters  Filter state (preserved across pagination links).
	 */
	private function render_pagination( int $total, int $per_page, int $page, array $filters ): void {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages <= 1 ) {
			return;
		}

		$base = add_query_arg(
			array(
				'level' => $filters['level'],
				'q'     => $filters['q'],
			),
			self::page_url()
		);

		$links = paginate_links(
			array(
				'base'      => $base . '%_%',
				'format'    => '&paged=%#%',
				'total'     => $pages,
				'current'   => $page,
				'show_all'  => false,
				'prev_text' => '«',
				'next_text' => '»',
				'add_args'  => false,
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			// `paginate_links()` returns markup it has already escaped.
			echo $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div></div>';
		}
	}

	/**
	 * Render the empty-state placeholder differentiated by whether the
	 * editor has filters applied (no matches) vs an empty buffer.
	 *
	 * @param array{level: string, q: string, paged: int} $filters Filter state.
	 */
	private function render_empty_state( array $filters ): void {
		$has_filters = ( 'all' !== $filters['level'] ) || ( '' !== $filters['q'] );
		echo '<p style="margin-top:16px;color:#646970;">';
		if ( $has_filters ) {
			esc_html_e( 'No log entries match the current filter.', 'spintax' );
		} else {
			esc_html_e( 'No log entries yet. Bulk Apply walks, cron triggers, and binding errors will appear here.', 'spintax' );
		}
		echo '</p>';
	}
}
