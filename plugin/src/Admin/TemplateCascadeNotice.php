<?php
/**
 * Admin notice on the Spintax template edit screen warning that
 * downstream bindings need a Bulk Apply to propagate the change.
 *
 * @package Spintax
 */

namespace Spintax\Admin;

defined( 'ABSPATH' ) || exit;

use Spintax\Bindings\BindingsRepo;
use Spintax\Core\PostType\TemplatePostType;

/**
 * Surfaces the template-edit cascade in the editor UI (spec §4.7a).
 *
 * Phase 4 visibility surface — the cache-version bump in
 * `TemplateCascadeTrigger` is internal; this notice tells the editor
 * that N existing posts won't reflect their template change until a
 * Bulk Apply / cron / save_post.
 */
class TemplateCascadeNotice {

	/**
	 * Bindings repository.
	 *
	 * @var BindingsRepo
	 */
	private BindingsRepo $repo;

	/**
	 * Constructor.
	 *
	 * @param BindingsRepo|null $repo Bindings repository.
	 */
	public function __construct( ?BindingsRepo $repo = null ) {
		$this->repo = $repo ?? new BindingsRepo();
	}

	/**
	 * Register the notice on Spintax template edit screens.
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
	}

	/**
	 * Render the notice when on a `spintax_template` edit screen that
	 * has matching template-mode bindings.
	 */
	public function maybe_render(): void {
		$screen = get_current_screen();
		if ( ! $screen || TemplatePostType::POST_TYPE !== $screen->post_type ) {
			return;
		}
		if ( 'post' !== $screen->base ) {
			return;
		}

		global $post;
		if ( ! $post || TemplatePostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$bindings = $this->repo->find_by_template_id( (int) $post->ID );
		if ( empty( $bindings ) ) {
			return;
		}

		$count        = count( $bindings );
		$bindings_url = admin_url( 'edit.php?post_type=' . TemplatePostType::POST_TYPE . '&page=spintax-bindings' );
		$message      = sprintf(
			/* translators: %d: number of bindings referencing this template */
			_n(
				'%d binding depends on this template. Edits here update the internal cache, but stored target fields keep their last-rendered value until you run Bulk Apply.',
				'%d bindings depend on this template. Edits here update the internal cache, but stored target fields keep their last-rendered value until you run Bulk Apply.',
				$count,
				'spintax'
			),
			$count
		);

		?>
		<div class="notice notice-warning">
			<p>
				<?php echo esc_html( $message ); ?>
				<a href="<?php echo esc_url( $bindings_url ); ?>" class="button button-small" style="margin-left:8px;">
					<?php esc_html_e( 'Open Bindings', 'spintax' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
