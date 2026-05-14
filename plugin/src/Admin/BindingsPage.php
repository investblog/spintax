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
use Spintax\Core\Settings\SettingsRepository;
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
	 * Tab slug for the "Source & Target" panel (scope, target, source).
	 *
	 * @var string
	 */
	public const TAB_SOURCE_TARGET = 'source-target';

	/**
	 * Tab slug for the "Behavior" panel (variables, triggers, behavior,
	 * advanced).
	 *
	 * @var string
	 */
	public const TAB_BEHAVIOR = 'behavior';

	/**
	 * Tab slug for the "Test" panel (edit-only dry-run dispatcher).
	 *
	 * @var string
	 */
	public const TAB_TEST = 'test';

	/**
	 * Whitelist of accepted tab slugs. Anything else falls back to the
	 * default (`TAB_SOURCE_TARGET`) at read time so URL tampering can't
	 * leave the form in an undefined state.
	 *
	 * @return string[]
	 */
	private static function tab_slugs(): array {
		return array( self::TAB_SOURCE_TARGET, self::TAB_BEHAVIOR, self::TAB_TEST );
	}

	/**
	 * Default tab when nothing is requested (fresh form / unknown slug).
	 *
	 * @var string
	 */
	private const DEFAULT_TAB = self::TAB_SOURCE_TARGET;

	/**
	 * Repository instance.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Lazy-initialised Bulk Apply runner. Injected via the constructor
	 * for tests so the bulk-apply notice path can be exercised without
	 * Action Scheduler installed in wp-env's tests container.
	 *
	 * @var BulkApply|null
	 */
	private ?BulkApply $bulk;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null $repo Optional bindings repository (DI for tests).
	 * @param BulkApply|null    $bulk Optional Bulk Apply runner (DI for tests).
	 */
	public function __construct( ?BindingsRepo $repo = null, ?BulkApply $bulk = null ) {
		$this->repo = $repo ?? new BindingsRepo();
		$this->bulk = $bulk;
	}

	/**
	 * Resolve the Bulk Apply runner — uses the injected instance when
	 * tests provided one, else lazy-creates the production runner. Always
	 * returns the same instance within a request.
	 */
	private function bulk_apply(): BulkApply {
		if ( null === $this->bulk ) {
			$this->bulk = new BulkApply( $this->repo );
		}
		return $this->bulk;
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
			// On validation error, redirect back to the form (not the
			// list) so the user can fix the field without retyping every
			// other input. `handle_save()` has stashed the submitted
			// values in a transient — `render_form()` picks them up.
			if ( 'error' === $result['type'] && ! empty( $result['form_redirect_action'] ) ) {
				$args = array( 'action' => $result['form_redirect_action'] );
				if ( ! empty( $result['existing_id'] ) ) {
					$args['binding_id'] = $result['existing_id'];
				}
				if ( ! empty( $result['active_tab'] ) ) {
					$args['active_tab'] = $result['active_tab'];
				}
				$form_url = add_query_arg( $args, $this->page_url() );
				$this->redirect_with_notice( $form_url, $result['message'], $result['type'] );
			}
			$this->redirect_with_notice( $redirect_url, $result['message'], $result['type'] );
		}

		// Bulk Apply (POST).
		if ( isset( $_POST['spintax_bulk_apply'], $_POST['binding_id'] ) ) {
			$id = sanitize_text_field( wp_unslash( (string) $_POST['binding_id'] ) );
			if ( ! Validators::is_valid_binding_id( $id ) ) {
				$this->redirect_with_notice( $redirect_url, __( 'Invalid binding id.', 'spintax' ), 'error' );
			}
			check_admin_referer( 'spintax_bulk_apply_' . $id );

			$result = $this->bulk_apply()->enqueue( $id );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( $redirect_url, $result->get_error_message(), 'error' );
			}
			$this->redirect_with_notice(
				$redirect_url,
				array(
					'text'         => __( 'Bulk Apply enqueued.', 'spintax' ),
					'action_url'   => LogsPage::page_url(),
					'action_label' => __( 'View progress in Logs →', 'spintax' ),
				)
			);
		}

		// Bulk Apply — Run now (synchronous, 2.1.0).
		//
		// Gated tightly because run_synchronously() walks the entire
		// catalogue in the request; on large sites it would PHP-FPM
		// timeout. Visible only when:
		// 1. user has `manage_options`, AND
		// 2. either the debug flag is on, OR Action Scheduler isn't
		// available (so async dispatch isn't possible anyway).
		if ( isset( $_POST['spintax_bulk_apply_now'], $_POST['binding_id'] ) ) {
			$id = sanitize_text_field( wp_unslash( (string) $_POST['binding_id'] ) );
			if ( ! Validators::is_valid_binding_id( $id ) ) {
				$this->redirect_with_notice( $redirect_url, __( 'Invalid binding id.', 'spintax' ), 'error' );
			}
			check_admin_referer( 'spintax_bulk_apply_now_' . $id );
			if ( ! current_user_can( 'manage_options' ) || ! self::run_now_available() ) {
				// Route back to the binding's edit form so the editor
				// can see context (same surface that exposed Run now
				// in the first place), not the silent list view.
				$form_url = add_query_arg(
					array(
						'action'     => 'edit',
						'binding_id' => $id,
					),
					$this->page_url()
				);
				$this->redirect_with_notice(
					$form_url,
					__( 'Run-now is restricted to administrators on dev / no-Action-Scheduler sites.', 'spintax' ),
					'error'
				);
			}

			$result = $this->bulk_apply()->run_synchronously( $id );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( $redirect_url, $result->get_error_message(), 'error' );
			}
			$totals = is_array( $result ) ? $result : array(
				'wrote'   => 0,
				'skipped' => 0,
				'failed'  => 0,
			);
			$this->redirect_with_notice(
				$redirect_url,
				array(
					'text'         => sprintf(
						/* translators: 1: wrote count, 2: skipped count, 3: failed count */
						__( 'Bulk Apply finished synchronously. Wrote %1$d, skipped %2$d, failed %3$d.', 'spintax' ),
						(int) ( $totals['wrote'] ?? 0 ),
						(int) ( $totals['skipped'] ?? 0 ),
						(int) ( $totals['failed'] ?? 0 )
					),
					'action_url'   => LogsPage::page_url(),
					'action_label' => __( 'View details in Logs →', 'spintax' ),
				)
			);
		}
	}

	/**
	 * Whether the synchronous "Run now" action should be exposed.
	 *
	 * Two-part gate (spec §4.10 + 2.1.0): the caller must have
	 * `manage_options`, AND the environment must be one where async
	 * dispatch is impractical — either `debug=true` (dev / staging) or
	 * Action Scheduler is absent. Production sites with AS installed
	 * never see the button.
	 */
	public static function run_now_available(): bool {
		if ( BulkApply::action_scheduler_available() ) {
			$settings = ( new SettingsRepository() )->get();
			$debug    = ! empty( $settings['debug'] );
			if ( ! $debug ) {
				return false;
			}
		}
		return true;
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
			<?php $this->render_action_scheduler_notice(); ?>

			<?php if ( $show_form ) : ?>
				<?php $this->render_form( $editing ); ?>
			<?php else : ?>
				<?php $this->render_table( $bindings ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Info notice on the Bindings page when Action Scheduler is missing.
	 *
	 * The plugin works without Action Scheduler, but two features degrade:
	 *
	 *  - **Admin "Bulk Apply" button** returns a `WP_Error 'no_action_scheduler'`
	 *    and points the user at the WP-CLI fallback (`wp spintax bindings
	 *    apply --binding=<id> --all`). Without AS there is no async
	 *    chunked admin walk — the CLI is the only chunked path.
	 *  - **Per-binding cron schedules** still fire, but the callback runs
	 *    the walk synchronously on the cron tick (`BulkApply::run_synchronously`)
	 *    instead of dispatching it as an Action Scheduler job. On large
	 *    sites that risks PHP-FPM timeouts on the cron worker.
	 *
	 * Many WP shops already ship Action Scheduler bundled with WooCommerce
	 * or other plugins; check before recommending a separate install. The
	 * notice does not show when AS is loaded by anything — directly,
	 * bundled by another plugin, or as a mu-plugin.
	 *
	 * Added in 2.0.2.
	 */
	private function render_action_scheduler_notice(): void {
		if ( BulkApply::action_scheduler_available() ) {
			return;
		}
		// Per-user dismissal (added in 2.1.0): once an editor has read
		// the notice they don't need to see it on every Bindings page
		// visit. Dismissal is keyed on `as-v210` so a future release
		// (e.g. 2.2.0) can re-surface the notice by minting a new id.
		if ( BindingsAjax::is_notice_dismissed( 'as-v210' ) ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible" data-spintax-dismiss-notice="as-v210">
			<p>
				<strong><?php esc_html_e( 'Action Scheduler is not installed.', 'spintax' ); ?></strong>
				<?php
				esc_html_e(
					'Spintax Bindings work without it, but the admin "Bulk Apply" button needs Action Scheduler to dispatch chunked async jobs. Use the "Run now" button on each binding (synchronous, blocks until the walk finishes) or the WP-CLI fallback "wp spintax bindings apply --binding=<id> --all" instead. Per-binding cron schedules still fire, but the walk runs synchronously on the cron tick — risk of PHP timeouts on large catalogues.',
					'spintax'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=action+scheduler&tab=search&type=term' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Install Action Scheduler', 'spintax' ); ?>
				</a>
				<?php /* translators: external link to the Action Scheduler plugin on WordPress.org */ ?>
				<a href="https://wordpress.org/plugins/action-scheduler/" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">
					<?php esc_html_e( 'About Action Scheduler →', 'spintax' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle a save submission. Nonce already verified by handle_actions().
	 *
	 * Returns a result array; the `form_redirect_action` key (added in
	 * 2.0.1) tells `handle_actions()` to send the user back to the form
	 * instead of the list view on validation error, preserving their
	 * input via a short-lived transient (see spec §4.8.1).
	 *
	 * @return array{
	 *   message: string,
	 *   type: string,
	 *   form_redirect_action?: string,
	 *   existing_id?: string
	 * }
	 */
	private function handle_save(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in handle_actions().
		$existing_id = isset( $_POST['binding_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['binding_id'] ) ) : '';

		// NB. The `post_type` form field is intentionally `spintax_post_type`
		// (not `post_type`) so it doesn't clobber `$_REQUEST['post_type']` —
		// WP's admin.php uses that to set `$typenow`, which in turn drives
		// the parent-slug lookup for `get_plugin_page_hook()`. A
		// `name="post_type"` form field would route the menu hook to the
		// wrong parent on POST and produce "Cannot load spintax-bindings".
		$data = array(
			'post_type' => isset( $_POST['spintax_post_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['spintax_post_type'] ) ) : '',
			'status'    => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : 'any',
			'target'    => array(
				'kind'      => isset( $_POST['target_kind'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_kind'] ) ) : '',
				'key'       => isset( $_POST['target_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_key'] ) ) : '',
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

		$kind = $data['target']['kind'];
		$key  = $data['target']['key'];

		// Tier 1/2/3 guard runs here so we can produce specific messages.
		$guard_error = $this->run_target_guard( $kind, $key );
		if ( null !== $guard_error ) {
			return $this->form_error( $data, $existing_id, $guard_error, self::TAB_SOURCE_TARGET );
		}

		if ( '' === $data['post_type'] ) {
			return $this->form_error( $data, $existing_id, __( 'Select a post type.', 'spintax' ), self::TAB_SOURCE_TARGET );
		}
		if ( '' === $key ) {
			return $this->form_error( $data, $existing_id, __( 'Target field key is required.', 'spintax' ), self::TAB_SOURCE_TARGET );
		}
		$acf_error = $this->validate_acf_field_key( $kind, $key, $data['target']['field_key'] );
		if ( null !== $acf_error ) {
			return $this->form_error( $data, $existing_id, $acf_error, self::TAB_SOURCE_TARGET );
		}
		if ( 'template' === $data['source']['mode'] && $data['source']['template_id'] <= 0 ) {
			return $this->form_error(
				$data,
				$existing_id,
				__( 'Choose a template (or switch source mode to per-post).', 'spintax' ),
				self::TAB_SOURCE_TARGET
			);
		}
		if ( ! $data['triggers']['save_post'] && 'disabled' === $data['triggers']['cron'] ) {
			return $this->form_error(
				$data,
				$existing_id,
				__( 'A binding with no triggers will never run. Enable "Fire on post save" or pick a cron schedule.', 'spintax' ),
				self::TAB_BEHAVIOR
			);
		}

		if ( '' !== $existing_id && Validators::is_valid_binding_id( $existing_id ) ) {
			$result = $this->repo->update( $existing_id, $data );
		} else {
			$result = $this->repo->create( $data );
		}

		if ( $result instanceof WP_Error ) {
			// Repo-level errors (e.g. cross-kind dedup) live on the
			// Source & Target tab because they're target-shape conflicts.
			return $this->form_error( $data, $existing_id, $result->get_error_message(), self::TAB_SOURCE_TARGET );
		}

		return array(
			'message' => '' !== $existing_id
				? __( 'Binding updated.', 'spintax' )
				: __( 'Binding created.', 'spintax' ),
			'type'    => 'success',
		);
	}

	/**
	 * Build a validation-error result, stashing the submitted form data
	 * in a transient so `render_form()` can repopulate the form on the
	 * next request (spec §4.8.1, added in 2.0.1).
	 *
	 * The `$tab` argument (2.1.0) records which tab the offending field
	 * lives on so the redirect can re-open the right panel. Falls back
	 * to `DEFAULT_TAB` for callers that don't yet care.
	 *
	 * @param array<string, mixed> $data        Form payload as built by handle_save().
	 * @param string               $existing_id Binding id when editing, '' when creating.
	 * @param string               $message     Error message to surface.
	 * @param string               $tab         Active tab on which the error should display.
	 * @return array{message: string, type: string, form_redirect_action: string, existing_id: string, active_tab: string}
	 */
	private function form_error( array $data, string $existing_id, string $message, string $tab = self::DEFAULT_TAB ): array {
		$tab = in_array( $tab, self::tab_slugs(), true ) ? $tab : self::DEFAULT_TAB;
		$this->flash_form_state( $data, $existing_id, $tab );
		return array(
			'message'              => $message,
			'type'                 => 'error',
			'form_redirect_action' => '' !== $existing_id ? 'edit' : 'new',
			'existing_id'          => $existing_id,
			'active_tab'           => $tab,
		);
	}

	/**
	 * Stash the in-progress form values in a per-user transient.
	 *
	 * TTL 60s — enough to survive the PRG redirect, short enough to
	 * prevent stale state from leaking across sessions or browser tabs.
	 *
	 * @param array<string, mixed> $data        Form payload.
	 * @param string               $existing_id Binding id when editing.
	 * @param string               $active_tab  Tab the user was on (carries the active panel through the PRG).
	 */
	private function flash_form_state( array $data, string $existing_id, string $active_tab = self::DEFAULT_TAB ): void {
		set_transient(
			$this->form_flash_key(),
			array(
				'data'        => $data,
				'existing_id' => $existing_id,
				'active_tab'  => in_array( $active_tab, self::tab_slugs(), true ) ? $active_tab : self::DEFAULT_TAB,
			),
			60
		);
	}

	/**
	 * Read and clear the form-flash transient.
	 *
	 * @return array{data: array<string, mixed>, existing_id: string, active_tab: string}|null
	 */
	private function consume_form_flash(): ?array {
		$flash = get_transient( $this->form_flash_key() );
		if ( ! is_array( $flash ) || ! isset( $flash['data'] ) || ! is_array( $flash['data'] ) ) {
			return null;
		}
		delete_transient( $this->form_flash_key() );
		$tab = (string) ( $flash['active_tab'] ?? self::DEFAULT_TAB );
		return array(
			'data'        => $flash['data'],
			'existing_id' => (string) ( $flash['existing_id'] ?? '' ),
			'active_tab'  => in_array( $tab, self::tab_slugs(), true ) ? $tab : self::DEFAULT_TAB,
		);
	}

	/**
	 * Transient key for the form-flash payload (per-user).
	 */
	private function form_flash_key(): string {
		return 'spintax_binding_form_flash_' . get_current_user_id();
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
	 * Apply Tier 5 of the reserved-key guard: ACF field_key validation
	 * (spec §4.6, added in 2.0.1).
	 *
	 * When `kind = acf_field`:
	 *  - `field_key` must be non-empty (UI hint: "Required for ACF
	 *    targets").
	 *  - If ACF is loaded, `acf_get_field( $field_key )` must resolve
	 *    to a field whose `name` matches `$key` exactly. A mismatched
	 *    key/name pair would silently route `update_field()` writes to
	 *    whatever field the key actually belongs to.
	 *
	 * @param string $kind      Target kind.
	 * @param string $key       Target key (field name).
	 * @param string $field_key Stable ACF field key (e.g. field_xxx).
	 * @return string|null Error message, or null if the target is valid.
	 */
	private function validate_acf_field_key( string $kind, string $key, string $field_key ): ?string {
		if ( 'acf_field' !== $kind ) {
			return null;
		}
		if ( '' === $field_key ) {
			return __( 'ACF field key is required for ACF targets. Pick a field from the dropdown or paste the field key (e.g. field_5f8a1234abcd).', 'spintax' );
		}
		if ( ! function_exists( 'acf_get_field' ) ) {
			// ACF inactive at save time. Phase 2 applier re-checks at write
			// time and skips if ACF can't find the field. Allow the save so
			// the configuration survives an ACF deactivation/reactivation.
			return null;
		}
		$field = acf_get_field( $field_key );
		if ( ! is_array( $field ) || empty( $field['name'] ) ) {
			return sprintf(
				/* translators: %s: ACF field key entered by the user */
				__( 'ACF field key "%s" was not found. Confirm the field exists in an ACF field group.', 'spintax' ),
				$field_key
			);
		}
		$resolved_name = (string) $field['name'];
		if ( $resolved_name !== $key ) {
			return sprintf(
				/* translators: 1: ACF field key, 2: actual field name behind that key, 3: field name the user typed */
				__( 'ACF field key "%1$s" points to field "%2$s", not "%3$s". The field name and field key must match.', 'spintax' ),
				$field_key,
				$resolved_name,
				$key
			);
		}
		return null;
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

			$stale         = $this->is_stale( $id, $mode );
			$walk_state    = $this->walk_state( $id );
			$show_runnow   = current_user_can( 'manage_options' ) && self::run_now_available();
			$as_available  = BulkApply::action_scheduler_available();
			$bulk_disabled = $walk_state['running'] || ! $as_available;
			$bulk_title    = $as_available
				? __( 'Apply this binding to every matching post via Action Scheduler (chunked async).', 'spintax' )
				: __( 'Action Scheduler is not installed. Use "Run now" (synchronous) or `wp spintax bindings apply --binding=<id> --all` instead.', 'spintax' );

			?>
			<div class="spintax-binding-card" style="border:1px solid #c3c4c7;background:#fff;padding:12px 16px;margin:12px 0;border-radius:4px;">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px;">
					<strong><?php echo esc_html( $pt_label ); ?></strong>
					<?php if ( $walk_state['running'] ) : ?>
						<span class="spintax-binding-walk-badge" style="background:#e5f3ff;border:1px solid #1d6fb8;color:#0a4b86;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">
							<?php
							printf(
								/* translators: %d: seconds since the walk started */
								esc_html__( 'Running (started %ds ago)', 'spintax' ),
								(int) $walk_state['elapsed']
							);
							?>
						</span>
					<?php elseif ( $stale ) : ?>
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
						<button type="submit" name="spintax_bulk_apply" class="button button-small" <?php disabled( $bulk_disabled ); ?> title="<?php echo esc_attr( $bulk_title ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Apply binding to all matching posts? This may take a while.', 'spintax' ) ); ?>');">
							<?php esc_html_e( 'Bulk Apply', 'spintax' ); ?>
						</button>
					</form>
					<?php if ( $show_runnow ) : ?>
						<form method="post" style="display:inline;margin:0;">
							<?php wp_nonce_field( 'spintax_bulk_apply_now_' . $id ); ?>
							<input type="hidden" name="binding_id" value="<?php echo esc_attr( $id ); ?>" />
							<button type="submit" name="spintax_bulk_apply_now" class="button button-small" <?php disabled( $walk_state['running'] ); ?> title="<?php esc_attr_e( 'Run synchronously in this request — useful when Action Scheduler is missing or in dev environments without cron traffic.', 'spintax' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Run synchronously? This blocks until every matching post has been processed.', 'spintax' ) ); ?>');">
								<?php esc_html_e( 'Run now', 'spintax' ); ?>
							</button>
						</form>
					<?php endif; ?>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this binding?', 'spintax' ) ); ?>');"><?php esc_html_e( 'Delete', 'spintax' ); ?></a>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Read the walk-lock state for a binding (2.1.0).
	 *
	 * Returns `{running: bool, elapsed: int}` where `running` is true when
	 * a non-expired lock is in place (`< LOCK_TTL_SECONDS` from
	 * `BulkApply`). Anything older is treated as orphaned and the card
	 * shows the stale badge as if no walk were in progress.
	 *
	 * @param string $binding_id Binding id.
	 * @return array{running: bool, elapsed: int}
	 */
	private function walk_state( string $binding_id ): array {
		$lock_ts = (int) get_option( OptionKeys::OPTION_BINDING_WALK_LOCK_PREFIX . $binding_id, 0 );
		if ( $lock_ts <= 0 ) {
			return array(
				'running' => false,
				'elapsed' => 0,
			);
		}
		$elapsed = max( 0, time() - $lock_ts );
		// Anything past the lock TTL (one hour) is treated as orphaned —
		// match BulkApply::LOCK_TTL_SECONDS to keep the threshold in lockstep.
		if ( $elapsed > 3600 ) {
			return array(
				'running' => false,
				'elapsed' => 0,
			);
		}
		return array(
			'running' => true,
			'elapsed' => $elapsed,
		);
	}

	/**
	 * Render the form view.
	 *
	 * When the previous save attempt failed validation, the user's
	 * submitted values live in a short-lived transient (see spec §4.8.1).
	 * They take precedence over the saved binding so the editor can
	 * correct the field that errored without retyping the rest.
	 *
	 * @param array<string, mixed>|null $binding Existing binding for edit, or null for create.
	 */
	private function render_form( ?array $binding ): void {
		$defaults = Defaults::binding();
		$flash    = $this->consume_form_flash();

		if ( null !== $flash ) {
			// Flash values supersede the saved binding so the editor can
			// fix the error without losing context. Merge over defaults
			// so any missing nested array keys (added in future versions)
			// still render with safe values.
			$b  = array_replace_recursive( $defaults, $flash['data'] );
			$id = $flash['existing_id'];
		} else {
			$b  = is_array( $binding ) ? $binding : $defaults;
			$id = (string) ( $b['id'] ?? '' );
		}

		// Resolve the active tab. Priority: explicit `?active_tab=…`
		// query param (used by validation-error redirects), then the
		// flash-restored value, then the default. URL values are
		// whitelisted to keep tampered links from leaving the form in
		// an undefined state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state from GET.
		$tab_from_get = isset( $_GET['active_tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['active_tab'] ) ) : '';
		if ( in_array( $tab_from_get, self::tab_slugs(), true ) ) {
			$active_tab = $tab_from_get;
		} elseif ( null !== $flash && isset( $flash['active_tab'] ) ) {
			$active_tab = (string) $flash['active_tab'];
		} else {
			$active_tab = self::DEFAULT_TAB;
		}

		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$templates  = get_posts(
			array(
				'post_type'     => TemplatePostType::POST_TYPE,
				'numberposts'   => -1,
				'post_status'   => 'publish',
				'orderby'       => 'title',
				'order'         => 'ASC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		$has_test_tab = ( $binding && '' !== $id );
		$tabs         = array(
			self::TAB_SOURCE_TARGET => __( 'Source & Target', 'spintax' ),
			self::TAB_BEHAVIOR      => __( 'Behavior', 'spintax' ),
		);
		if ( $has_test_tab ) {
			$tabs[ self::TAB_TEST ] = __( 'Test', 'spintax' );
		}
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = self::DEFAULT_TAB;
		}

		?>
		<h2 class="spintax-binding-form-header">
			<?php echo $binding ? esc_html__( 'Edit Binding', 'spintax' ) : esc_html__( 'New Binding', 'spintax' ); ?>
			<a href="<?php echo esc_url( $this->page_url() ); ?>" class="page-title-action">
				<?php esc_html_e( '← Back to bindings', 'spintax' ); ?>
			</a>
		</h2>

		<?php $this->render_form_status_banner( $binding ); ?>

		<form method="post" id="spintax-binding-form" class="spintax-binding-form">
			<?php wp_nonce_field( 'spintax_binding_save' ); ?>
			<input type="hidden" name="binding_id" value="<?php echo esc_attr( $id ); ?>" />
			<input type="hidden" name="active_tab" id="spintax-active-tab" value="<?php echo esc_attr( $active_tab ); ?>" />

			<div class="spintax-binding-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Binding sections', 'spintax' ); ?>">
				<?php
				foreach ( $tabs as $slug => $label ) :
					$is_active = ( $slug === $active_tab );
					?>
					<button
						type="button"
						role="tab"
						id="spintax-tab-<?php echo esc_attr( $slug ); ?>"
						aria-controls="spintax-panel-<?php echo esc_attr( $slug ); ?>"
						aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>"
						tabindex="<?php echo $is_active ? '0' : '-1'; ?>"
						data-spintax-tab="<?php echo esc_attr( $slug ); ?>"
					>
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<div
				id="spintax-panel-<?php echo esc_attr( self::TAB_SOURCE_TARGET ); ?>"
				class="spintax-binding-panel"
				role="tabpanel"
				aria-labelledby="spintax-tab-<?php echo esc_attr( self::TAB_SOURCE_TARGET ); ?>"
				<?php echo self::TAB_SOURCE_TARGET === $active_tab ? '' : 'hidden'; ?>
			>
				<h3 class="screen-reader-text"><?php esc_html_e( 'Scope', 'spintax' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="spintax-post-type"><?php esc_html_e( 'Post type', 'spintax' ); ?></label></th>
					<td>
						<select name="spintax_post_type" id="spintax-post-type" required>
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
				<?php
				$current_kind  = (string) ( $b['target']['kind'] ?? '' );
				$current_key   = (string) ( $b['target']['key'] ?? '' );
				$current_fkey  = (string) ( $b['target']['field_key'] ?? '' );
				$is_acf        = ( 'acf_field' === $current_kind );
				$is_post_meta  = ( 'post_meta' === $current_kind );
				$display_label = '' !== $current_key
					? $current_key . ( '' !== $current_fkey ? ' (' . $current_fkey . ')' : '' )
					: '';
				?>
				<tr>
					<th scope="row">
						<label for="spintax-target-key"><?php esc_html_e( 'Field name / meta key', 'spintax' ); ?></label>
					</th>
					<td>
						<div class="spintax-acf-combobox" data-spintax-acf-combobox <?php echo $is_acf ? '' : 'hidden'; ?>>
							<input
								type="search"
								class="regular-text spintax-acf-combobox-input"
								id="spintax-acf-combobox-input"
								placeholder="<?php esc_attr_e( 'Search ACF fields by name, label, or group…', 'spintax' ); ?>"
								autocomplete="off"
								role="combobox"
								aria-expanded="false"
								aria-autocomplete="list"
								aria-controls="spintax-acf-combobox-list"
								value="<?php echo esc_attr( $display_label ); ?>"
							/>
							<ul
								class="spintax-acf-combobox-list"
								id="spintax-acf-combobox-list"
								role="listbox"
								hidden
							></ul>
							<p class="description">
								<?php esc_html_e( 'Pick a top-level text / textarea / wysiwyg ACF field. Group → field name appears next to each option; selecting one autofills the stable field key.', 'spintax' ); ?>
							</p>
						</div>

						<input
							type="text"
							name="target_key"
							id="spintax-target-key"
							class="regular-text"
							value="<?php echo esc_attr( $current_key ); ?>"
							autocomplete="off"
							<?php echo $is_acf ? 'hidden' : ''; ?>
						/>
						<p class="description spintax-target-key-help" <?php echo $is_acf ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'Start typing to pick from detected post-meta keys, or paste an exact key (e.g. _my_meta).', 'spintax' ); ?>
						</p>
					</td>
				</tr>
				<tr class="spintax-target-field-key-row" <?php echo $is_acf ? '' : 'hidden'; ?>>
					<th scope="row"><label for="spintax-target-field-key"><?php esc_html_e( 'ACF field key', 'spintax' ); ?></label></th>
					<td>
						<input type="text" name="target_field_key" id="spintax-target-field-key" class="regular-text code" value="<?php echo esc_attr( $current_fkey ); ?>" placeholder="field_5f8a1234abcd" autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'Stable ACF field identifier — picking a field above autofills this. Edit only if you need to override a manually-entered field name.', 'spintax' ); ?>
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
							<?php esc_html_e( 'Shared template — render the same source on every matching post', 'spintax' ); ?>
						</label>
						<br/>
						<label>
							<input type="radio" name="source_mode" value="per_post" <?php checked( $b['source']['mode'] ?? '', 'per_post' ); ?> />
							<?php esc_html_e( 'Per-post template — each post supplies its own source inline', 'spintax' ); ?>
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

			</div>

			<div
				id="spintax-panel-<?php echo esc_attr( self::TAB_BEHAVIOR ); ?>"
				class="spintax-binding-panel"
				role="tabpanel"
				aria-labelledby="spintax-tab-<?php echo esc_attr( self::TAB_BEHAVIOR ); ?>"
				<?php echo self::TAB_BEHAVIOR === $active_tab ? '' : 'hidden'; ?>
			>
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

			<?php
			$triggers_save_post = ! empty( $b['triggers']['save_post'] );
			$triggers_cron      = (string) ( $b['triggers']['cron'] ?? 'disabled' );
			$triggers_inactive  = ( ! $triggers_save_post && 'disabled' === $triggers_cron );
			?>
			<h3><?php esc_html_e( 'Triggers', 'spintax' ); ?></h3>
			<div
				class="spintax-trigger-warning notice notice-warning inline"
				role="status"
				<?php echo $triggers_inactive ? '' : 'hidden'; ?>
			>
				<p>
					<strong><?php esc_html_e( 'This binding will never run.', 'spintax' ); ?></strong>
					<?php esc_html_e( 'Save will be rejected with the same message until you enable "Fire on post save" or pick a cron schedule below.', 'spintax' ); ?>
				</p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'When to run', 'spintax' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="trigger_save_post" value="1" <?php checked( $triggers_save_post ); ?> />
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
								<option value="<?php echo esc_attr( $schedule ); ?>" <?php selected( $triggers_cron, $schedule ); ?>>
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

			</div>

			<?php if ( $has_test_tab ) : ?>
				<div
					id="spintax-panel-<?php echo esc_attr( self::TAB_TEST ); ?>"
					class="spintax-binding-panel"
					role="tabpanel"
					aria-labelledby="spintax-tab-<?php echo esc_attr( self::TAB_TEST ); ?>"
					<?php echo self::TAB_TEST === $active_tab ? '' : 'hidden'; ?>
				>
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
				</div>
			<?php endif; ?>

			<div class="spintax-binding-footer">
				<input type="submit" name="spintax_save_binding" class="button-primary" value="<?php echo $binding ? esc_attr__( 'Update binding', 'spintax' ) : esc_attr__( 'Create binding', 'spintax' ); ?>" />
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button">
					<?php esc_html_e( 'Cancel', 'spintax' ); ?>
				</a>
			</div>
		</form>
		<?php
	}

	/**
	 * Render a status banner above the binding form when the persisted
	 * binding (NOT the flash-restored draft) is in a state the editor
	 * should know about before they start editing.
	 *
	 * Currently surfaces one signal — the stale badge. Operates on
	 * `$binding`, not the flash-merged `$b`, so a draft-mode swap of
	 * `source.mode` mid-edit doesn't mislead the editor about the
	 * persisted state of the binding (P2 reviewer finding for 2.1.0).
	 *
	 * @param array<string, mixed>|null $binding Persisted binding, or null when creating a new one.
	 */
	private function render_form_status_banner( ?array $binding ): void {
		if ( null === $binding ) {
			return;
		}
		$id   = (string) ( $binding['id'] ?? '' );
		$mode = (string) ( $binding['source']['mode'] ?? '' );
		if ( '' === $id || ! $this->is_stale( $id, $mode ) ) {
			return;
		}

		// Mirror the list-view's Bulk Apply / Run-now pair so the stale
		// banner doesn't dead-end editors on sites without Action
		// Scheduler. When AS is missing, Bulk Apply is disabled (with
		// the same tooltip) and Run-now becomes the primary CTA — when
		// AS is present, Bulk Apply stays primary and Run-now is the
		// secondary escape hatch.
		$walk_state    = $this->walk_state( $id );
		$as_available  = BulkApply::action_scheduler_available();
		$show_runnow   = current_user_can( 'manage_options' ) && self::run_now_available();
		$bulk_disabled = $walk_state['running'] || ! $as_available;
		$bulk_title    = $as_available
			? __( 'Apply this binding to every matching post via Action Scheduler (chunked async).', 'spintax' )
			: __( 'Action Scheduler is not installed. Use "Run now" (synchronous) or `wp spintax bindings apply --binding=<id> --all` instead.', 'spintax' );
		$bulk_class    = $as_available ? 'button button-primary' : 'button';
		$runnow_class  = $as_available ? 'button' : 'button button-primary';
		?>
		<div class="spintax-binding-stale-banner notice notice-warning" style="margin:12px 0;">
			<p>
				<strong><?php esc_html_e( 'Source template edited since the last walk.', 'spintax' ); ?></strong>
				<?php esc_html_e( 'Existing target fields still hold output from the previous template version. Run Bulk Apply to re-render every matching post.', 'spintax' ); ?>
			</p>
			<p style="margin:0 0 8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
				<form method="post" style="display:inline;margin:0;">
					<?php wp_nonce_field( 'spintax_bulk_apply_' . $id ); ?>
					<input type="hidden" name="binding_id" value="<?php echo esc_attr( $id ); ?>" />
					<button
						type="submit"
						name="spintax_bulk_apply"
						class="<?php echo esc_attr( $bulk_class ); ?>"
						<?php disabled( $bulk_disabled ); ?>
						title="<?php echo esc_attr( $bulk_title ); ?>"
					>
						<?php esc_html_e( 'Bulk Apply now', 'spintax' ); ?>
					</button>
				</form>
				<?php if ( $show_runnow ) : ?>
					<form method="post" style="display:inline;margin:0;">
						<?php wp_nonce_field( 'spintax_bulk_apply_now_' . $id ); ?>
						<input type="hidden" name="binding_id" value="<?php echo esc_attr( $id ); ?>" />
						<button
							type="submit"
							name="spintax_bulk_apply_now"
							class="<?php echo esc_attr( $runnow_class ); ?>"
							<?php disabled( $walk_state['running'] ); ?>
							title="<?php esc_attr_e( 'Run synchronously in this request — useful when Action Scheduler is missing or in dev environments without cron traffic.', 'spintax' ); ?>"
						>
							<?php esc_html_e( 'Run now', 'spintax' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</p>
		</div>
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
