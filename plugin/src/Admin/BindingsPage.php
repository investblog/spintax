<?php
/**
 * Bindings admin page (Spintax > Bindings).
 *
 * Phase 1: data-layer + form CRUD only. No AJAX field discovery, no
 * Test panel, no Bulk Apply. Those land in Phases 3-4 per
 * `docs/spec-acf-bindings.md`.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingsRepo;
use Spintax\Bindings\BulkApply;
use Spintax\Bindings\Defaults;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Support\Capabilities;
use Spintax\Support\OptionKeys;
use Spintax\Support\Validators;
use WP_Error;

/**
 * Bindings list + form. Backed by `BindingsRepo` (single autoloaded option).
 */
class BindingsPage {

	use AdminNotice;

	/**
	 * Menu slug for this page.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'spintax-bindings';

	/**
	 * Repository instance.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo = new BindingsRepo();
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Add the submenu under the Spintax CPT top-level menu.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . TemplatePostType::POST_TYPE,
			__( 'Spintax Bindings', 'spintax' ),
			__( 'Bindings', 'spintax' ),
			Capabilities::CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_actions' ) );
			add_action(
				'admin_print_scripts-' . $hook,
				array( $this, 'enqueue_assets' )
			);
		}
	}

	/**
	 * Enqueue the Bindings form JS + localized config.
	 *
	 * Hooked on the Bindings admin page only (via `admin_print_scripts-<hook>`).
	 */
	public function enqueue_assets(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET params used to scope the JS payload.
		$action     = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		$binding_id = isset( $_GET['binding_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['binding_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_enqueue_script(
			'spintax-bindings',
			SPINTAX_PLUGIN_URL . 'assets/js/bindings.js',
			array( 'jquery' ),
			SPINTAX_VERSION,
			true
		);

		wp_localize_script(
			'spintax-bindings',
			'spintaxBindings',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'spintax_admin' ),
				'action'    => $action,
				'bindingId' => 'edit' === $action ? $binding_id : '',
				'i18n'      => array(
					'result'        => __( 'Result', 'spintax' ),
					'wouldWrite'    => __( 'Would write', 'spintax' ),
					'yes'           => __( 'yes', 'spintax' ),
					'no'            => __( 'no', 'spintax' ),
					'post'          => __( 'Post', 'spintax' ),
					'rendered'      => __( 'Rendered output', 'spintax' ),
					'currentTarget' => __( 'Current target value', 'spintax' ),
					'testing'       => __( 'Testing…', 'spintax' ),
					'enterPostId'   => __( 'Enter a post id to test against.', 'spintax' ),
					'error'         => __( 'Error running test.', 'spintax' ),
				),
			)
		);
	}

	/**
	 * Build the page-base URL used for redirects.
	 */
	private function page_url(): string {
		return admin_url( 'edit.php?post_type=' . TemplatePostType::POST_TYPE . '&page=' . self::PAGE_SLUG );
	}

	/**
	 * Handle POST/GET actions with the PRG pattern.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			return;
		}

		$redirect_url = $this->page_url();

		// Delete (GET with nonce).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by check_admin_referer() below.
		if ( isset( $_GET['action'], $_GET['binding_id'] ) && 'delete' === $_GET['action'] ) {
			$id = sanitize_text_field( wp_unslash( (string) $_GET['binding_id'] ) );
			if ( ! Validators::is_valid_binding_id( $id ) ) {
				$this->redirect_with_notice( $redirect_url, __( 'Invalid binding id.', 'spintax' ), 'error' );
			}
			check_admin_referer( 'spintax_delete_binding_' . $id );

			$result = $this->repo->delete( $id );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( $redirect_url, $result->get_error_message(), 'error' );
			}
			$this->redirect_with_notice( $redirect_url, __( 'Binding deleted.', 'spintax' ) );
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		// Save (POST).
		if ( isset( $_POST['spintax_save_binding'] ) ) {
			check_admin_referer( 'spintax_binding_save' );
			$result = $this->handle_save();
			$this->redirect_with_notice( $redirect_url, $result['message'], $result['type'] );
		}

		// Bulk Apply (POST).
		if ( isset( $_POST['spintax_bulk_apply'], $_POST['binding_id'] ) ) {
			$id = sanitize_text_field( wp_unslash( (string) $_POST['binding_id'] ) );
			if ( ! Validators::is_valid_binding_id( $id ) ) {
				$this->redirect_with_notice( $redirect_url, __( 'Invalid binding id.', 'spintax' ), 'error' );
			}
			check_admin_referer( 'spintax_bulk_apply_' . $id );

			$result = ( new BulkApply( $this->repo ) )->enqueue( $id );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( $redirect_url, $result->get_error_message(), 'error' );
			}
			$this->redirect_with_notice(
				$redirect_url,
				__( 'Bulk Apply enqueued. Check Logs for progress.', 'spintax' )
			);
		}
	}

	/**
	 * Render the page. Dispatches to form or table based on `action`.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'spintax' ) );
		}

		$bindings = $this->repo->all();
		$editing  = null;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET params for UI state.
		$action     = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : '';
		$show_form  = in_array( $action, array( 'new', 'edit' ), true );
		$binding_id = isset( $_GET['binding_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['binding_id'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action && Validators::is_valid_binding_id( $binding_id ) ) {
			$editing = $this->repo->find( $binding_id );
			if ( null === $editing ) {
				$show_form = false;
			}
		}

		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Spintax Bindings', 'spintax' ); ?>
				<?php if ( ! $show_form ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'action', 'new', $this->page_url() ) ); ?>" class="page-title-action">
						<?php esc_html_e( 'Add New', 'spintax' ); ?>
					</a>
				<?php endif; ?>
			</h1>

			<?php $this->render_notice(); ?>

			<?php if ( $show_form ) : ?>
				<?php $this->render_form( $editing ); ?>
			<?php else : ?>
				<?php $this->render_table( $bindings ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle a save submission. Nonce already verified by handle_actions().
	 *
	 * @return array{message: string, type: string}
	 */
	private function handle_save(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_actions().
		$existing_id = isset( $_POST['binding_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['binding_id'] ) ) : '';

		$kind = isset( $_POST['target_kind'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_kind'] ) ) : '';
		$key  = isset( $_POST['target_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_key'] ) ) : '';

		// Tier 1/2/3 guard runs here so we can produce specific messages.
		$guard_error = $this->run_target_guard( $kind, $key );
		if ( null !== $guard_error ) {
			return array(
				'message' => $guard_error,
				'type'    => 'error',
			);
		}

		$data = array(
			'post_type' => isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_type'] ) ) : '',
			'status'    => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : 'any',
			'target'    => array(
				'kind'      => $kind,
				'key'       => $key,
				'field_key' => isset( $_POST['target_field_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_field_key'] ) ) : '',
			),
			'source'    => array(
				'mode'        => isset( $_POST['source_mode'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_mode'] ) ) : 'template',
				'template_id' => isset( $_POST['source_template_id'] ) ? (int) $_POST['source_template_id'] : 0,
			),
			'variables' => array(
				'expose_post_context' => ! empty( $_POST['expose_post_context'] ),
				'expose_acf_siblings' => ! empty( $_POST['expose_acf_siblings'] ),
				'overrides'           => $this->sanitize_overrides_input(),
			),
			'triggers'  => array(
				'save_post' => ! empty( $_POST['trigger_save_post'] ),
				'cron'      => isset( $_POST['trigger_cron'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['trigger_cron'] ) ) : 'disabled',
			),
			'behavior'  => array(
				'auto_seed_empty'       => ! empty( $_POST['behavior_auto_seed_empty'] ),
				'regenerate_on_save'    => ! empty( $_POST['behavior_regenerate_on_save'] ),
				'preserve_manual_edits' => ! empty( $_POST['behavior_preserve_manual_edits'] ),
				'clear_on_empty'        => ! empty( $_POST['behavior_clear_on_empty'] ),
				'chunk_size'            => isset( $_POST['behavior_chunk_size'] ) ? (int) $_POST['behavior_chunk_size'] : Defaults::DEFAULT_CHUNK_SIZE,
			),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $data['post_type'] ) {
			return array(
				'message' => __( 'Select a post type.', 'spintax' ),
				'type'    => 'error',
			);
		}
		if ( '' === $data['target']['key'] ) {
			return array(
				'message' => __( 'Target field key is required.', 'spintax' ),
				'type'    => 'error',
			);
		}
		if ( 'template' === $data['source']['mode'] && $data['source']['template_id'] <= 0 ) {
			return array(
				'message' => __( 'Choose a template (or switch source mode to per-post).', 'spintax' ),
				'type'    => 'error',
			);
		}
		if ( ! $data['triggers']['save_post'] && 'disabled' === $data['triggers']['cron'] ) {
			return array(
				'message' => __( 'A binding with no triggers will never run. Enable "Fire on post save" or pick a cron schedule.', 'spintax' ),
				'type'    => 'error',
			);
		}

		if ( '' !== $existing_id && Validators::is_valid_binding_id( $existing_id ) ) {
			$result = $this->repo->update( $existing_id, $data );
		} else {
			$result = $this->repo->create( $data );
		}

		if ( $result instanceof WP_Error ) {
			return array(
				'message' => $result->get_error_message(),
				'type'    => 'error',
			);
		}

		return array(
			'message' => '' !== $existing_id
				? __( 'Binding updated.', 'spintax' )
				: __( 'Binding created.', 'spintax' ),
			'type'    => 'success',
		);
	}

	/**
	 * Read and sanitize the per-binding `#set` overrides textarea.
	 *
	 * Isolated into its own method so the PHPCS suppression for the
	 * spintax-aware sanitiser is single-line — `sanitize_textarea_field()`
	 * would destroy angle-bracket spintax syntax.
	 */
	private function sanitize_overrides_input(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_actions(); sanitized via Validators::sanitize_spintax() which is the spintax-aware sanitiser.
		$raw = isset( $_POST['variables_overrides'] ) ? wp_unslash( $_POST['variables_overrides'] ) : '';
		return Validators::sanitize_spintax( (string) $raw );
	}

	/**
	 * Apply Tiers 1-3 of the reserved-key guard (spec §4.6).
	 *
	 * @param string $kind Target kind.
	 * @param string $key  Target key.
	 * @return string|null Error message, or null if the target is allowed.
	 */
	private function run_target_guard( string $kind, string $key ): ?string {
		if ( '' === $key ) {
			return null; // empty key is caught by handle_save() — different message.
		}

		if ( Validators::is_reserved_meta_key( $key ) ) {
			return sprintf(
				/* translators: %s: target key */
				__( '"%s" is a WordPress-internal meta key and cannot be a binding target.', 'spintax' ),
				$key
			);
		}
		if ( Validators::is_plugin_internal_meta_key( $key ) ) {
			return sprintf(
				/* translators: %s: target key */
				__( '"%s" is reserved by Spintax itself and cannot be a binding target.', 'spintax' ),
				$key
			);
		}
		if ( 'post_meta' === $kind && Validators::is_post_column( $key ) ) {
			return sprintf(
				/* translators: %s: target key */
				__( '"%s" is a wp_posts column and cannot be written via post-meta. Pick a different target.', 'spintax' ),
				$key
			);
		}
		return null;
	}

	/**
	 * Render the list view as cards.
	 *
	 * @param array<string, array<string, mixed>> $bindings All bindings.
	 */
	private function render_table( array $bindings ): void {
		if ( empty( $bindings ) ) {
			echo '<p>' . esc_html__( 'No bindings yet. Click "Add New" to create one.', 'spintax' ) . '</p>';
			return;
		}

		foreach ( $bindings as $binding ) {
			$id           = (string) $binding['id'];
			$pt           = get_post_type_object( (string) $binding['post_type'] );
			$pt_label     = $pt ? $pt->labels->singular_name : (string) $binding['post_type'];
			$kind         = (string) ( $binding['target']['kind'] ?? '' );
			$key          = (string) ( $binding['target']['key'] ?? '' );
			$mode         = (string) ( $binding['source']['mode'] ?? '' );
			$template_id  = (int) ( $binding['source']['template_id'] ?? 0 );
			$source_label = 'template' === $mode
				? $this->describe_template( $template_id )
				: __( 'Per-post source', 'spintax' );

			$edit_url   = add_query_arg(
				array(
					'action'     => 'edit',
					'binding_id' => $id,
				),
				$this->page_url()
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'delete',
						'binding_id' => $id,
					),
					$this->page_url()
				),
				'spintax_delete_binding_' . $id
			);

			$stale = $this->is_stale( $id, $mode );

			?>
			<div class="spintax-binding-card" style="border:1px solid #c3c4c7;background:#fff;padding:12px 16px;margin:12px 0;border-radius:4px;">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px;">
					<strong><?php echo esc_html( $pt_label ); ?></strong>
					<?php if ( $stale ) : ?>
						<span style="background:#fff7e0;border:1px solid #dba617;color:#3b2c00;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">
							<?php esc_html_e( 'Stale: source template edited', 'spintax' ); ?>
						</span>
					<?php endif; ?>
					<code style="background:#f0f0f1;padding:2px 6px;border-radius:3px;font-size:11px;margin-left:auto;"><?php echo esc_html( $id ); ?></code>
				</div>
				<div style="display:flex;flex-wrap:wrap;gap:18px;margin-bottom:10px;font-size:13px;">
					<div>
						<span style="color:#646970;text-transform:uppercase;font-size:11px;font-weight:600;display:block;"><?php esc_html_e( 'Source', 'spintax' ); ?></span>
						<?php echo esc_html( $source_label ); ?>
					</div>
					<div style="color:#646970;">&rarr;</div>
					<div>
						<span style="color:#646970;text-transform:uppercase;font-size:11px;font-weight:600;display:block;"><?php esc_html_e( 'Target', 'spintax' ); ?></span>
						<code><?php echo esc_html( ( 'acf_field' === $kind ? 'acf:' : 'meta:' ) . $key ); ?></code>
					</div>
					<div>
						<span style="color:#646970;text-transform:uppercase;font-size:11px;font-weight:600;display:block;"><?php esc_html_e( 'Triggers', 'spintax' ); ?></span>
						<?php echo esc_html( $this->describe_triggers( $binding ) ); ?>
					</div>
				</div>
				<div style="display:flex;gap:6px;align-items:center;">
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'spintax' ); ?></a>
					<form method="post" style="display:inline;margin:0;">
						<?php wp_nonce_field( 'spintax_bulk_apply_' . $id ); ?>
						<input type="hidden" name="binding_id" value="<?php echo esc_attr( $id ); ?>" />
						<button type="submit" name="spintax_bulk_apply" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Apply binding to all matching posts? This may take a while.', 'spintax' ) ); ?>');">
							<?php esc_html_e( 'Bulk Apply', 'spintax' ); ?>
						</button>
					</form>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this binding?', 'spintax' ) ); ?>');"><?php esc_html_e( 'Delete', 'spintax' ); ?></a>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Render the form view.
	 *
	 * @param array<string, mixed>|null $binding Existing binding for edit, or null for create.
	 */
	private function render_form( ?array $binding ): void {
		$defaults   = Defaults::binding();
		$b          = is_array( $binding ) ? $binding : $defaults;
		$id         = (string) ( $b['id'] ?? '' );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$templates  = get_posts(
			array(
				'post_type'        => TemplatePostType::POST_TYPE,
				'numberposts'      => -1,
				'post_status'      => 'publish',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		?>
		<h2>
			<?php echo $binding ? esc_html__( 'Edit Binding', 'spintax' ) : esc_html__( 'New Binding', 'spintax' ); ?>
		</h2>

		<form method="post" id="spintax-binding-form">
			<?php wp_nonce_field( 'spintax_binding_save' ); ?>
			<input type="hidden" name="binding_id" value="<?php echo esc_attr( $id ); ?>" />

			<h3><?php esc_html_e( 'Scope', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="spintax-post-type"><?php esc_html_e( 'Post type', 'spintax' ); ?></label></th>
					<td>
						<select name="post_type" id="spintax-post-type" required>
							<option value=""><?php esc_html_e( '— Select —', 'spintax' ); ?></option>
							<?php foreach ( $post_types as $pt ) : ?>
								<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $b['post_type'], $pt->name ); ?>>
									<?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spintax-status"><?php esc_html_e( 'Post status filter', 'spintax' ); ?></label></th>
					<td>
						<select name="status" id="spintax-status">
							<option value="any" <?php selected( $b['status'], 'any' ); ?>><?php esc_html_e( 'Any', 'spintax' ); ?></option>
							<option value="publish" <?php selected( $b['status'], 'publish' ); ?>><?php esc_html_e( 'Published only', 'spintax' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Target field', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Kind', 'spintax' ); ?></th>
					<td>
						<label>
							<input type="radio" name="target_kind" value="acf_field" <?php checked( $b['target']['kind'] ?? '', 'acf_field' ); ?> />
							<?php esc_html_e( 'ACF field', 'spintax' ); ?>
						</label>
						&nbsp;
						<label>
							<input type="radio" name="target_kind" value="post_meta" <?php checked( $b['target']['kind'] ?? '', 'post_meta' ); ?> />
							<?php esc_html_e( 'Post meta', 'spintax' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spintax-target-key"><?php esc_html_e( 'Field name / meta key', 'spintax' ); ?></label></th>
					<td>
						<input type="text" name="target_key" id="spintax-target-key" class="regular-text" value="<?php echo esc_attr( $b['target']['key'] ?? '' ); ?>" autocomplete="off" required />
						<p class="description">
							<?php esc_html_e( 'Phase 3 will add a dropdown of detected fields. For now, type the name (e.g. hero_subtitle) or post-meta key (e.g. _my_meta).', 'spintax' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spintax-target-field-key"><?php esc_html_e( 'ACF field key', 'spintax' ); ?></label></th>
					<td>
						<input type="text" name="target_field_key" id="spintax-target-field-key" class="regular-text code" value="<?php echo esc_attr( $b['target']['field_key'] ?? '' ); ?>" placeholder="field_5f8a1234abcd" autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'Only used when Kind = ACF field. Field key (e.g. field_5f8a1234abcd) is the stable identifier ACF needs for the first write. Required for ACF targets — Phase 3 will autofill this from the field picker.', 'spintax' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Source', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'spintax' ); ?></th>
					<td>
						<label>
							<input type="radio" name="source_mode" value="template" <?php checked( $b['source']['mode'] ?? '', 'template' ); ?> />
							<?php esc_html_e( 'Bind to a Spintax template (DRY across posts)', 'spintax' ); ?>
						</label>
						<br/>
						<label>
							<input type="radio" name="source_mode" value="per_post" <?php checked( $b['source']['mode'] ?? '', 'per_post' ); ?> />
							<?php esc_html_e( 'Per-post template (authored inline on each post)', 'spintax' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spintax-source-template"><?php esc_html_e( 'Template', 'spintax' ); ?></label></th>
					<td>
						<select name="source_template_id" id="spintax-source-template">
							<option value="0"><?php esc_html_e( '— Select template —', 'spintax' ); ?></option>
							<?php foreach ( $templates as $tpl_id ) : ?>
								<option value="<?php echo esc_attr( (string) $tpl_id ); ?>" <?php selected( (int) ( $b['source']['template_id'] ?? 0 ), (int) $tpl_id ); ?>>
									<?php echo esc_html( get_the_title( $tpl_id ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Only used when Mode = template.', 'spintax' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Variables', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Expose context', 'spintax' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="expose_post_context" value="1" <?php checked( ! empty( $b['variables']['expose_post_context'] ) ); ?> />
							<?php esc_html_e( 'Expose post context as %vars%', 'spintax' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( '%post_id%, %post_title%, %post_url%, %post_slug%, %author_name%, %author_id%, %post_date%, %post_modified%', 'spintax' ); ?>
						</p>
						<br/>
						<label>
							<input type="checkbox" name="expose_acf_siblings" value="1" <?php checked( ! empty( $b['variables']['expose_acf_siblings'] ) ); ?> />
							<?php esc_html_e( 'Expose ACF sibling fields as %acf_<name>% (ACF targets only)', 'spintax' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spintax-variables-overrides"><?php esc_html_e( 'Per-binding #set overrides', 'spintax' ); ?></label></th>
					<td>
						<textarea name="variables_overrides" id="spintax-variables-overrides" rows="5" class="large-text code"><?php echo esc_textarea( $b['variables']['overrides'] ?? '' ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Raw #set block (one per line). Available inside the source template; overrides global Settings variables.', 'spintax' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Triggers', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'When to run', 'spintax' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="trigger_save_post" value="1" <?php checked( ! empty( $b['triggers']['save_post'] ) ); ?> />
							<?php esc_html_e( 'Fire on post save', 'spintax' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Hooks save_post priority 20. Runs after ACF persists its own field values, so sibling reads see fresh data.', 'spintax' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spintax-trigger-cron"><?php esc_html_e( 'Cron schedule', 'spintax' ); ?></label></th>
					<td>
						<select name="trigger_cron" id="spintax-trigger-cron">
							<?php foreach ( Defaults::cron_schedules() as $schedule ) : ?>
								<option value="<?php echo esc_attr( $schedule ); ?>" <?php selected( $b['triggers']['cron'] ?? 'disabled', $schedule ); ?>>
									<?php echo esc_html( $schedule ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Behavior', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Write semantics', 'spintax' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="behavior_auto_seed_empty" value="1" <?php checked( ! empty( $b['behavior']['auto_seed_empty'] ) ); ?> />
							<?php esc_html_e( 'Auto-seed empty fields', 'spintax' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Write target only if it is currently empty. Default.', 'spintax' ); ?></p>
						<br/>
						<label>
							<input type="checkbox" name="behavior_regenerate_on_save" value="1" <?php checked( ! empty( $b['behavior']['regenerate_on_save'] ) ); ?> />
							<?php esc_html_e( 'Regenerate on every save (overwrites target)', 'spintax' ); ?>
						</label>
						<br/>
						<label>
							<input type="checkbox" name="behavior_preserve_manual_edits" value="1" <?php checked( ! empty( $b['behavior']['preserve_manual_edits'] ) ); ?> />
							<?php esc_html_e( 'Preserve manual edits (hash-check before regenerating)', 'spintax' ); ?>
						</label>
						<br/>
						<label>
							<input type="checkbox" name="behavior_clear_on_empty" value="1" <?php checked( ! empty( $b['behavior']['clear_on_empty'] ) ); ?> />
							<?php esc_html_e( 'Clear target when template renders to empty', 'spintax' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Advanced', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="spintax-behavior-chunk-size"><?php esc_html_e( 'Bulk apply / cron chunk size', 'spintax' ); ?></label></th>
					<td>
						<input type="number" name="behavior_chunk_size" id="spintax-behavior-chunk-size" class="small-text" min="1" max="200" value="<?php echo esc_attr( (string) ( $b['behavior']['chunk_size'] ?? Defaults::DEFAULT_CHUNK_SIZE ) ); ?>" />
						<p class="description">
							<?php
							printf(
								/* translators: 1: minimum chunk size, 2: maximum chunk size, 3: default chunk size */
								esc_html__( 'How many posts to process per Action Scheduler job (range %1$d–%2$d, default %3$d). Lower this for heavy templates that take many seconds to render; raise it for trivial templates over large catalogs.', 'spintax' ),
								(int) Defaults::MIN_CHUNK_SIZE,
								(int) Defaults::MAX_CHUNK_SIZE,
								(int) Defaults::DEFAULT_CHUNK_SIZE
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php if ( $binding && '' !== $id ) : ?>
				<h3><?php esc_html_e( 'Test', 'spintax' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spintax-binding-test-post-id"><?php esc_html_e( 'Post ID', 'spintax' ); ?></label></th>
						<td>
							<input type="number" id="spintax-binding-test-post-id" class="small-text" min="1" placeholder="123" />
							<button type="button" id="spintax-binding-test-button" class="button"><?php esc_html_e( 'Test', 'spintax' ); ?></button>
							<p class="description">
								<?php esc_html_e( 'Dry-run this binding against a specific post. No writes happen.', 'spintax' ); ?>
							</p>
							<div id="spintax-binding-test-results" style="margin-top:12px;"></div>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<p class="submit">
				<input type="submit" name="spintax_save_binding" class="button-primary" value="<?php echo $binding ? esc_attr__( 'Update binding', 'spintax' ) : esc_attr__( 'Create binding', 'spintax' ); ?>" />
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'spintax' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * True when a template-mode binding's cache version is ahead of the
	 * last successful Bulk Apply / cron walk for that binding.
	 *
	 * @param string $binding_id Binding id.
	 * @param string $mode       Source mode.
	 */
	private function is_stale( string $binding_id, string $mode ): bool {
		if ( 'template' !== $mode ) {
			return false;
		}
		$current = (int) get_option( OptionKeys::OPTION_BINDING_CACHE_VERSION_PREFIX . $binding_id, 0 );
		$applied = (int) get_option( OptionKeys::OPTION_BINDING_LAST_APPLIED_VERSION_PREFIX . $binding_id, 0 );
		return $current > $applied;
	}

	/**
	 * Human description of a template-mode binding's source.
	 *
	 * @param int $template_id Template post id.
	 * @return string
	 */
	private function describe_template( int $template_id ): string {
		if ( $template_id <= 0 ) {
			return __( '(template not selected)', 'spintax' );
		}
		$title = get_the_title( $template_id );
		if ( '' === $title ) {
			return sprintf(
				/* translators: %d: template post ID */
				__( 'Template #%d (deleted?)', 'spintax' ),
				$template_id
			);
		}
		/* translators: %s: template title */
		return sprintf( __( 'Template: %s', 'spintax' ), $title );
	}

	/**
	 * Compact human summary of a binding's triggers.
	 *
	 * @param array<string, mixed> $binding Binding payload.
	 * @return string
	 */
	private function describe_triggers( array $binding ): string {
		$parts = array();
		if ( ! empty( $binding['triggers']['save_post'] ) ) {
			$parts[] = 'save_post';
		}
		$cron = (string) ( $binding['triggers']['cron'] ?? 'disabled' );
		if ( 'disabled' !== $cron ) {
			/* translators: %s: cron schedule slug */
			$parts[] = sprintf( __( 'cron %s', 'spintax' ), $cron );
		}
		return empty( $parts ) ? __( 'none', 'spintax' ) : implode( ', ', $parts );
	}
}
