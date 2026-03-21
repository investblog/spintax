<?php
/**
 * Plugin settings page (Settings > Spintax).
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Cache\CacheManager;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\Capabilities;

/**
 * Renders and handles the settings page.
 *
 * Fields: global variables, default TTL, editor access, debug mode, purge cache.
 */
class SettingsPage {

	use AdminNotice;

	/** @var SettingsRepository Settings repository instance. */
	private SettingsRepository $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo = new SettingsRepository();
	}

	/**
	 * Register the settings page.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Add settings page under Settings menu.
	 */
	public function register_menu(): void {
		$hook = add_options_page(
			__( 'Spintax Settings', 'spintax' ),
			__( 'Spintax', 'spintax' ),
			'manage_options',
			'spintax-settings',
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_actions' ) );
		}
	}

	/**
	 * Handle POST actions (PRG pattern).
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$redirect_url = admin_url( 'options-general.php?page=spintax-settings' );

		// Save settings.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() below.
		if ( isset( $_POST['spintax_save_settings'] ) ) {
			check_admin_referer( 'spintax_settings_save' );
			$this->save_settings();
			$this->save_global_variables();
			$this->redirect_with_notice( $redirect_url, __( 'Settings saved.', 'spintax' ) );
		}

		// Purge all cache.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by check_admin_referer() below.
		if ( isset( $_POST['spintax_purge_cache'] ) ) {
			check_admin_referer( 'spintax_settings_save' );
			$cache = new CacheManager( $this->repo );
			$cache->invalidate_all();
			$this->redirect_with_notice( $redirect_url, __( 'All template caches purged.', 'spintax' ) );
		}
	}

	/**
	 * Render the settings page.
	 */
	public function render(): void {
		$settings  = $this->repo->get();
		$variables = $this->repo->get_global_variables();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Spintax Settings', 'spintax' ); ?></h1>

			<?php $this->render_notice(); ?>

			<form method="post">
				<?php wp_nonce_field( 'spintax_settings_save' ); ?>

				<h2><?php esc_html_e( 'General', 'spintax' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="spintax-default-ttl"><?php esc_html_e( 'Default Cache TTL', 'spintax' ); ?></label>
						</th>
						<td>
							<input type="number" id="spintax-default-ttl" name="default_ttl"
								value="<?php echo esc_attr( $settings['default_ttl'] ); ?>"
								min="0" step="1" class="regular-text">
							<p class="description">
								<?php esc_html_e( 'Seconds. 0 = no caching. Templates can override this value.', 'spintax' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Access Control', 'spintax' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="editors_can_manage" value="1"
									<?php checked( $settings['editors_can_manage'] ); ?>>
								<?php esc_html_e( 'Allow editors to manage templates', 'spintax' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug Mode', 'spintax' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="debug" value="1"
									<?php checked( $settings['debug'] ); ?>>
								<?php esc_html_e( 'Log rendering errors and diagnostics', 'spintax' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Global Variables', 'spintax' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Available to all templates as %name%. Variable names are case-insensitive.', 'spintax' ); ?>
				</p>

				<table class="widefat spintax-variables-table" id="spintax-variables-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'spintax' ); ?></th>
							<th><?php esc_html_e( 'Value', 'spintax' ); ?></th>
							<th class="spintax-col-actions"></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $variables ) ) : ?>
							<?php foreach ( $variables as $name => $value ) : ?>
								<tr>
									<td><input type="text" name="spintax_var_names[]" value="<?php echo esc_attr( $name ); ?>" class="regular-text"></td>
									<td><input type="text" name="spintax_var_values[]" value="<?php echo esc_attr( $value ); ?>" class="large-text"></td>
									<td><button type="button" class="button spintax-remove-row">&times;</button></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<td colspan="3">
								<button type="button" class="button spintax-add-row">
									<?php esc_html_e( '+ Add Variable', 'spintax' ); ?>
								</button>
							</td>
						</tr>
					</tfoot>
				</table>

				<?php submit_button( __( 'Save Settings', 'spintax' ), 'primary', 'spintax_save_settings' ); ?>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Cache', 'spintax' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'spintax_settings_save' ); ?>
				<?php submit_button( __( 'Purge All Template Caches', 'spintax' ), 'secondary', 'spintax_purge_cache' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings from POST data.
	 */
	private function save_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_actions().
		$patch = array(
			'default_ttl'        => isset( $_POST['default_ttl'] ) ? (int) $_POST['default_ttl'] : 3600,
			'editors_can_manage' => ! empty( $_POST['editors_can_manage'] ),
			'debug'              => ! empty( $_POST['debug'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->repo->update( $patch );
		Capabilities::sync( $patch['editors_can_manage'] );
	}

	/**
	 * Save global variables from POST data.
	 */
	private function save_global_variables(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_actions().
		$names  = isset( $_POST['spintax_var_names'] ) && is_array( $_POST['spintax_var_names'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['spintax_var_names'] ) )
			: array();
		$values = isset( $_POST['spintax_var_values'] ) && is_array( $_POST['spintax_var_values'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['spintax_var_values'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$vars = array();
		foreach ( $names as $i => $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				continue;
			}
			$vars[ $name ] = $values[ $i ] ?? '';
		}

		$this->repo->set_global_variables( $vars );
	}
}
