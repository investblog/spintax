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

	/**
	 * Settings repository instance.
	 *
	 * @var SettingsRepository
	 */
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
		$settings      = $this->repo->get();
		$variables_raw = $this->repo->get_global_variables_raw();
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
					<?php
					esc_html_e(
						'One variable per line using #set syntax. Available to all templates. Local #set in a template overrides globals with the same name.',
						'spintax'
					);
					?>
				</p>
				<p class="description"><code>#set %name% = value</code></p>

				<?php
				$this->render_variable_errors();

				$placeholder = "#set %company% = {Acme Corp|Acme Inc}\n"
					. "#set %year% = 2026\n"
					. "#set %products% = [<minsize=2;maxsize=3;sep=\", \";lastsep=\" and \"> widgets|gadgets|tools|services]\n"
					. '#set %slogan% = {Quality|Reliable|Trusted} {solutions|products} since %year%';
				?>

				<textarea name="spintax_global_variables_raw" id="spintax-global-variables"
					class="large-text code" rows="16"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
				><?php echo esc_textarea( $variables_raw ); ?></textarea>

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
	 * Save global variables from raw #set textarea.
	 */
	private function save_global_variables(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_actions().
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw spintax syntax must be preserved; sanitize_text_field would strip bracket expressions.
		$raw = isset( $_POST['spintax_global_variables_raw'] )
			? wp_unslash( $_POST['spintax_global_variables_raw'] )
			: '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Validate syntax before saving.
		$errors = $this->validate_global_variables( $raw );
		if ( ! empty( $errors ) ) {
			// Store errors for display after redirect.
			set_transient(
				'spintax_global_vars_errors_' . get_current_user_id(),
				$errors,
				120
			);
		}

		// Parse #set directives into name => value pairs.
		$parser    = new \Spintax\Core\Engine\Parser();
		$extracted = $parser->extract_set_directives( $raw );

		// Save both raw text (for the editor) and parsed variables (for rendering).
		$this->repo->set_global_variables_raw( $raw );
		$this->repo->set_global_variables( $extracted['variables'] );
	}

	/**
	 * Validate global variables raw text.
	 *
	 * @param string $raw Raw #set text.
	 * @return array<array{message: string, line: int}> Validation errors.
	 */
	private function validate_global_variables( string $raw ): array {
		$errors = array();
		$lines  = explode( "\n", $raw );

		foreach ( $lines as $line_num => $line_text ) {
			$trimmed = trim( $line_text );
			if ( '' === $trimmed ) {
				continue;
			}

			// Every non-empty line must be a valid #set directive.
			if ( ! preg_match( '/^#set\s+%(\w+)%\s*=\s*(.+)$/u', $trimmed ) ) {
				$errors[] = array(
					'message' => sprintf(
						/* translators: %1$d: line number, %2$s: line content. */
						__( 'Line %1$d: invalid syntax. Expected: #set %%name%% = value. Got: %2$s', 'spintax' ),
						$line_num + 1,
						mb_substr( $trimmed, 0, 60 )
					),
					'line'    => $line_num + 1,
				);
			}
		}

		// Check bracket balance in values.
		$validator = new \Spintax\Core\Engine\Validator();
		$result    = $validator->validate( $raw );
		foreach ( $result['errors'] as $err ) {
			$errors[] = array(
				'message' => $err['message'],
				'line'    => $err['line'] ?? 0,
			);
		}

		return $errors;
	}

	/**
	 * Render global variable validation errors if any.
	 */
	private function render_variable_errors(): void {
		$key    = 'spintax_global_vars_errors_' . get_current_user_id();
		$errors = get_transient( $key );
		if ( ! $errors || ! is_array( $errors ) ) {
			return;
		}
		delete_transient( $key );

		echo '<div class="notice notice-error"><ul>';
		foreach ( $errors as $error ) {
			printf( '<li>%s</li>', esc_html( $error['message'] ) );
		}
		echo '</ul></div>';
	}
}
