<?php
/**
 * Tools → Spintax Migration page.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\Migration;
use Spintax\Support\Capabilities;

/**
 * One-shot wizard for importing predecessor `nested-spintax-for-acf`
 * data into the new binding model.
 *
 * Shown under Tools → Spintax Migration when the user opens it
 * explicitly, plus a dismissible activation-time admin notice when
 * predecessor data is detected and not yet migrated.
 */
class MigrationPage {

	use AdminNotice;

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'spintax-migration';

	/**
	 * Option key for the dismissed-banner flag.
	 *
	 * @var string
	 */
	private const DISMISSED_OPTION = 'spintax_migration_banner_dismissed';

	/**
	 * Migration runner.
	 *
	 * @var Migration
	 */
	private Migration $migration;

	/**
	 * Constructor.
	 *
	 * @param Migration|null $migration Migration runner.
	 */
	public function __construct( ?Migration $migration = null ) {
		$this->migration = $migration ?? new Migration();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_banner' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_banner' ) );
	}

	/**
	 * Add the Tools submenu entry.
	 */
	public function register_menu(): void {
		$hook = add_management_page(
			__( 'Spintax Migration', 'spintax' ),
			__( 'Spintax Migration', 'spintax' ),
			Capabilities::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_actions' ) );
		}
	}

	/**
	 * Page URL.
	 */
	private function page_url(): string {
		return admin_url( 'tools.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Handle the Run import POST.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		if ( isset( $_POST['spintax_migration_run'] ) ) {
			check_admin_referer( 'spintax_migration_run' );
			$totals = $this->migration->execute();
			$this->redirect_with_notice(
				$this->page_url(),
				sprintf(
					/* translators: 1: created bindings, 2: skipped bindings, 3: errors, 4: posts seeded */
					__( 'Migration finished: %1$d created, %2$d skipped, %3$d errors. Seeded %4$d posts.', 'spintax' ),
					(int) $totals['created'],
					(int) $totals['skipped'],
					(int) $totals['errors'],
					(int) $totals['posts_seeded']
				)
			);
		}
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'spintax' ) );
		}

		$plan = $this->migration->build_plan();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spintax Migration', 'spintax' ); ?></h1>
			<?php $this->render_notice(); ?>

			<p>
				<?php
				printf(
					/* translators: %s: predecessor plugin name */
					esc_html__( 'This wizard imports data from the predecessor plugin %s into the new bindings model. Running the migration is safe: old data is never deleted; if you opt out of a particular binding, just don\'t check it.', 'spintax' ),
					'<code>nested-spintax-for-acf</code>'
				);
				?>
			</p>

			<?php if ( empty( $plan ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No predecessor data detected on this site. Nothing to migrate.', 'spintax' ); ?></p>
				</div>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=spintax_template&page=spintax-bindings' ) ); ?>" class="button">
						<?php esc_html_e( 'Go to Bindings', 'spintax' ); ?>
					</a>
				</p>
				<?php
				return;
			endif;
			?>

			<form method="post" action="<?php echo esc_url( $this->page_url() ); ?>">
				<?php wp_nonce_field( 'spintax_migration_run' ); ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post type', 'spintax' ); ?></th>
							<th><?php esc_html_e( 'Field', 'spintax' ); ?></th>
							<th><?php esc_html_e( 'Kind', 'spintax' ); ?></th>
							<th><?php esc_html_e( 'Posts affected', 'spintax' ); ?></th>
							<th><?php esc_html_e( 'Variables', 'spintax' ); ?></th>
							<th><?php esc_html_e( 'Status', 'spintax' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plan as $entry ) : ?>
							<tr>
								<td><code><?php echo esc_html( $entry['post_type'] ); ?></code></td>
								<td><code><?php echo esc_html( $entry['target_key'] ); ?></code></td>
								<td><?php echo esc_html( $entry['target_kind'] ); ?></td>
								<td><?php echo count( (array) $entry['affected_post_ids'] ); ?></td>
								<td>
									<?php
									switch ( $entry['variables_mode'] ) {
										case 'identical':
											esc_html_e( 'Identical → folded into binding overrides', 'spintax' );
											break;
										case 'divergent':
											esc_html_e( 'Divergent → inlined as #set into per-post sources', 'spintax' );
											break;
										default:
											esc_html_e( 'None', 'spintax' );
									}
									?>
								</td>
								<td>
									<?php if ( 'exists' === $entry['status'] ) : ?>
										<em><?php esc_html_e( 'Already migrated', 'spintax' ); ?></em>
									<?php else : ?>
										<strong><?php esc_html_e( 'Will be created', 'spintax' ); ?></strong>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="spintax_migration_run" class="button-primary" value="<?php esc_attr_e( 'Run migration', 'spintax' ); ?>" />
					<a href="<?php echo esc_url( admin_url() ); ?>" class="button"><?php esc_html_e( 'Cancel', 'spintax' ); ?></a>
				</p>
				<p class="description">
					<?php esc_html_e( 'Re-running the migration is safe: existing bindings are detected and not duplicated, and per-post sources that already exist are left untouched.', 'spintax' ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the dismissible activation-time banner pointing here.
	 */
	public function maybe_render_banner(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}
		if ( get_option( self::DISMISSED_OPTION ) ) {
			return;
		}
		if ( ! $this->migration->has_predecessor_data() ) {
			return;
		}

		$screen = get_current_screen();
		if ( $screen && self::PAGE_SLUG === substr( (string) $screen->id, -strlen( self::PAGE_SLUG ) ) ) {
			return; // already on the migration page.
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'spintax_migration_dismiss', '1', admin_url() ),
			'spintax_migration_dismiss'
		);

		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php esc_html_e( 'Spintax detected legacy data from the predecessor plugin. Open Tools → Spintax Migration to review and import it as bindings.', 'spintax' ); ?>
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button button-small" style="margin-left:8px;">
					<?php esc_html_e( 'Open migration', 'spintax' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button button-small button-link">
					<?php esc_html_e( 'Dismiss', 'spintax' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Honor the dismiss link from `maybe_render_banner`.
	 */
	public function maybe_dismiss_banner(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by wp_verify_nonce() below.
		if ( ! isset( $_GET['spintax_migration_dismiss'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by wp_verify_nonce() below.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'spintax_migration_dismiss' ) ) {
			return;
		}
		update_option( self::DISMISSED_OPTION, 1, false );
		wp_safe_redirect( admin_url() );
		exit;
	}
}
