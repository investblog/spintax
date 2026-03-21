<?php
/**
 * Template rendering pipeline.
 *
 * @package Spintax
 */

namespace Spintax\Core\Render;

use Spintax\Core\Engine\Parser;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;

/**
 * Orchestrates the multi-stage rendering pipeline.
 *
 * Pipeline (spec section 7.1):
 *   1.  Resolve template by ID or slug
 *   2.  Load raw post_content
 *   3.  Strip comments
 *   4.  Parse #set definitions, strip from body
 *   5.  Build variable context (global → local → runtime)
 *   6.  Expand %var% references
 *   7.  Resolve enumerations {…}
 *   8.  Resolve permutations [...]
 *   9.  Resolve #include and nested [spintax] shortcodes
 *   10. Post-process (spacing, capitalisation)
 *   11. Sanitize HTML
 *
 * Caching is not implemented here — it will wrap this class in Phase 4.
 */
class Renderer {

	private Parser $parser;
	private SettingsRepository $settings;

	public function __construct( ?Parser $parser = null, ?SettingsRepository $settings = null ) {
		$this->parser   = $parser ?? new Parser();
		$this->settings = $settings ?? new SettingsRepository();
	}

	/**
	 * Render a template by ID or slug.
	 *
	 * @param int|string           $id_or_slug   Template post ID or slug.
	 * @param array<string, string> $runtime_vars Runtime variables (from shortcode/PHP).
	 * @param RenderContext|null   $parent_ctx    Parent context for nested renders.
	 * @return string Rendered HTML (sanitised) or empty string on failure.
	 */
	public function render( $id_or_slug, array $runtime_vars = array(), ?RenderContext $parent_ctx = null ): string {
		// --- Stage 1: Resolve template -------------------------------------
		$post = $this->resolve_template( $id_or_slug );
		if ( ! $post ) {
			$this->log_error( sprintf( 'Template not found: %s', $id_or_slug ) );
			return '';
		}

		// Only render published templates on the frontend.
		if ( ! is_admin() && 'publish' !== $post->post_status ) {
			return '';
		}

		// --- Circular reference check --------------------------------------
		$template_id = $post->ID;
		$context     = $parent_ctx ?? new RenderContext( $this->settings->get_global_variables() );

		if ( $context->has_template( $template_id ) ) {
			$this->log_error( sprintf(
				'Circular template reference detected: %s → %d',
				implode( ' → ', $context->get_call_stack() ),
				$template_id
			) );
			return '';
		}

		$context = $context->push_template( $template_id );

		// --- Stage 2: Load raw content -------------------------------------
		$raw = $post->post_content;
		if ( '' === trim( $raw ) ) {
			return '';
		}

		try {
			return $this->process_template( $raw, $runtime_vars, $context );
		} catch ( \RuntimeException $e ) {
			$this->log_error( sprintf( 'Render error for template %d: %s', $template_id, $e->getMessage() ) );
			return '';
		}
	}

	/**
	 * Process raw template content through the pipeline stages.
	 *
	 * Extracted so it can also be used for admin preview without post lookup.
	 *
	 * @param string               $raw          Raw spintax markup.
	 * @param array<string, string> $runtime_vars Runtime variables.
	 * @param RenderContext|null   $context      Render context (created if null).
	 * @return string Processed and sanitised HTML.
	 */
	public function process_template( string $raw, array $runtime_vars = array(), ?RenderContext $context = null ): string {
		$context = $context ?? new RenderContext( $this->settings->get_global_variables() );

		// --- Stage 3: Strip comments ---------------------------------------
		$text = $this->parser->strip_comments( $raw );

		// --- Stage 4: Parse #set, strip from body --------------------------
		$extracted = $this->parser->extract_set_directives( $text );
		$text      = $extracted['body'];

		// --- Stage 5: Build variable context --------------------------------
		$context  = $context->with_local( $extracted['variables'] );
		if ( ! empty( $runtime_vars ) ) {
			$context = $context->with_runtime( $runtime_vars );
		}
		$all_vars = $context->get_merged_variables();

		// --- Shield [spintax] and #include before spintax resolution ------
		// [spintax ...] shortcodes use square brackets which would be consumed
		// by the permutation resolver. Shield them with placeholders first,
		// then restore and resolve after enum/perm processing.
		$nested_placeholders = array();
		$nested_counter      = 0;
		$text = preg_replace_callback(
			'/\[spintax\s+[^\]]+\]/i',
			static function ( array $m ) use ( &$nested_placeholders, &$nested_counter ): string {
				$key                          = "\x00NESTED_{$nested_counter}\x00";
				$nested_placeholders[ $key ]  = $m[0];
				++$nested_counter;
				return $key;
			},
			$text
		);

		// --- Stage 6: Expand variables -------------------------------------
		$text = $this->parser->expand_variables( $text, $all_vars );

		// --- Stage 7: Resolve enumerations ---------------------------------
		$text = $this->parser->resolve_enumerations( $text );

		// --- Stage 8: Resolve permutations ---------------------------------
		$text = $this->parser->resolve_permutations( $text );

		// --- Restore [spintax] placeholders --------------------------------
		if ( ! empty( $nested_placeholders ) ) {
			$text = str_replace(
				array_keys( $nested_placeholders ),
				array_values( $nested_placeholders ),
				$text
			);
		}

		// --- Stage 9: Resolve #include and [spintax] -----------------------
		$text = $this->resolve_nested( $text, $context );

		// --- Stage 10: Post-process ----------------------------------------
		$text = $this->parser->post_process( $text );

		// --- Stage 11: Sanitize HTML ---------------------------------------
		$text = wp_kses_post( $text );

		return $text;
	}

	/**
	 * Resolve #include directives and [spintax] shortcodes in processed text.
	 *
	 * Only the `spintax` shortcode is executed inside template bodies (spec).
	 *
	 * @param string        $text    Processed text (after enum/perm resolution).
	 * @param RenderContext $context Current render context.
	 * @return string Text with nested templates resolved.
	 */
	private function resolve_nested( string $text, RenderContext $context ): string {
		// Resolve #include directives.
		$renderer = $this;
		$text     = $this->parser->resolve_includes(
			$text,
			static function ( string $slug_or_id ) use ( $renderer, $context ): string {
				return $renderer->render( $slug_or_id, array(), $context );
			}
		);

		// Resolve [spintax ...] shortcodes.
		$text = preg_replace_callback(
			'/\[spintax\s+([^\]]+)\]/i',
			function ( array $m ) use ( $context ): string {
				$attrs = $this->parse_shortcode_attrs( $m[1] );
				if ( empty( $attrs ) ) {
					return '';
				}

				$id_or_slug  = $attrs['id'] ?? $attrs['slug'] ?? '';
				if ( '' === $id_or_slug ) {
					return '';
				}

				// Remaining attributes become runtime variables.
				$nested_vars = $attrs;
				unset( $nested_vars['id'], $nested_vars['slug'] );

				return $this->render( $id_or_slug, $nested_vars, $context );
			},
			$text
		);

		return $text;
	}

	/**
	 * Resolve a template post by ID or slug.
	 *
	 * @param int|string $id_or_slug Post ID or slug.
	 * @return \WP_Post|null
	 */
	private function resolve_template( $id_or_slug ): ?\WP_Post {
		if ( is_numeric( $id_or_slug ) ) {
			$post = get_post( (int) $id_or_slug );
			if ( $post && TemplatePostType::POST_TYPE === $post->post_type ) {
				return $post;
			}
			return null;
		}

		$posts = get_posts( array(
			'post_type'      => TemplatePostType::POST_TYPE,
			'name'           => sanitize_title( (string) $id_or_slug ),
			'posts_per_page' => 1,
			'post_status'    => array( 'publish', 'draft', 'private' ),
		) );

		return $posts[0] ?? null;
	}

	/**
	 * Parse shortcode-style attributes from a string.
	 *
	 * @param string $attr_string e.g. 'id="123" city="Moscow"'
	 * @return array<string, string>
	 */
	private function parse_shortcode_attrs( string $attr_string ): array {
		$attrs = shortcode_parse_atts( $attr_string );
		if ( ! is_array( $attrs ) ) {
			return array();
		}
		// WordPress lowercases attribute names.
		return array_change_key_case( $attrs, CASE_LOWER );
	}

	/**
	 * Log an error if debug mode is enabled.
	 */
	private function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Spintax] ' . $message );
		}
	}
}
