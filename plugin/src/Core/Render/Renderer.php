<?php
/**
 * Template rendering pipeline.
 *
 * @package Spintax
 */

namespace Spintax\Core\Render;

defined( 'ABSPATH' ) || exit;

use Spintax\Core\Cache\CacheManager;
use Spintax\Core\Cache\DependencyInvalidator;
use Spintax\Core\Engine\Conditionals;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Engine\Plurals;
use Spintax\Core\PostType\TemplatePostType;
use Spintax\Core\Settings\SettingsRepository;
use Spintax\Support\OptionKeys;

/**
 * Orchestrates the multi-stage rendering pipeline with object cache.
 *
 * Pipeline (spec section 7.1):
 *   1.  Resolve template by ID or slug
 *   2.  Check cache → return on hit
 *   3.  Load raw post_content
 *   4.  Strip comments
 *   5.  Parse #set definitions, strip from body
 *   6.  Build variable context (global → local → runtime)
 *   7a. Resolve {?VAR?then|else} conditionals (pre-expand pass)
 *   7b. Expand %var% references
 *   7c. Resolve {?VAR?then|else} conditionals (post-expand pass)
 *   7d. Resolve {plural <count>: form|…} plural agreement (lenient)
 *   8.  Resolve enumerations {…}
 *   9.  Resolve permutations [...]
 *   10. Resolve #include and nested [spintax] shortcodes
 *   11. Post-process (spacing, capitalisation)
 *   12. Sanitize HTML
 *   13. Store in cache
 */
class Renderer {

	/**
	 * Spintax template parser for syntax processing.
	 *
	 * @var Parser
	 */
	private Parser $parser;

	/**
	 * `{?VAR?then|else}` conditional resolver.
	 *
	 * @var Conditionals
	 */
	private Conditionals $conditionals;

	/**
	 * `{plural <count>: forms}` plural-agreement resolver.
	 *
	 * @var Plurals
	 */
	private Plurals $plurals;

	/**
	 * Settings repository for reading global variables and TTL.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Cache manager for reading and storing rendered output.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Handles cascade invalidation for nested template dependencies.
	 *
	 * @var DependencyInvalidator
	 */
	private DependencyInvalidator $deps;

	/**
	 * Template IDs rendered during the current top-level render.
	 *
	 * @var int[]
	 */
	private array $rendered_ids = array();

	/**
	 * When true, skip cache reads for the entire subtree.
	 *
	 * @var bool
	 */
	private bool $bypass_cache = false;

	/**
	 * Constructor.
	 *
	 * @param Parser|null                $parser   Optional parser instance.
	 * @param SettingsRepository|null    $settings Optional settings repository.
	 * @param CacheManager|null          $cache    Optional cache manager.
	 * @param DependencyInvalidator|null $deps     Optional dependency invalidator.
	 */
	public function __construct(
		?Parser $parser = null,
		?SettingsRepository $settings = null,
		?CacheManager $cache = null,
		?DependencyInvalidator $deps = null
	) {
		$this->parser       = $parser ?? new Parser();
		$this->conditionals = new Conditionals();
		$this->plurals      = new Plurals();
		$this->settings     = $settings ?? new SettingsRepository();
		$this->cache        = $cache ?? new CacheManager( $this->settings );
		$this->deps         = $deps ?? new DependencyInvalidator( $this->cache );
	}

	/**
	 * Render a template with cache bypass — fresh full subtree render.
	 *
	 * Used by the "Regenerate Public Cache" button: skips cache reads
	 * for the entire subtree so nested templates are also re-rendered.
	 * The result IS stored in cache after rendering.
	 *
	 * @param int|string $id_or_slug Template post ID or slug.
	 * @return string Rendered HTML.
	 */
	public function render_fresh( $id_or_slug ): string {
		$this->bypass_cache = true;
		try {
			return $this->render( $id_or_slug );
		} finally {
			$this->bypass_cache = false;
		}
	}

	/**
	 * Render a template by ID or slug.
	 *
	 * @param int|string            $id_or_slug   Template post ID or slug.
	 * @param array<string, string> $runtime_vars Runtime variables (from shortcode/PHP).
	 * @param RenderContext|null    $parent_ctx    Parent context for nested renders.
	 * @return string Rendered HTML (sanitised) or empty string on failure.
	 */
	public function render( $id_or_slug, array $runtime_vars = array(), ?RenderContext $parent_ctx = null ): string {
		// Stage 1: Resolve template.
		$post = $this->resolve_template( $id_or_slug );
		if ( ! $post ) {
			$this->log_error( sprintf( 'Template not found: %s', $id_or_slug ) );
			return '';
		}

		// Only render published templates on the frontend.
		if ( ! is_admin() && 'publish' !== $post->post_status ) {
			return '';
		}

		// Circular reference check.
		$template_id = $post->ID;
		$context     = $parent_ctx ?? new RenderContext( $this->settings->get_global_variables() );

		if ( $context->has_template( $template_id ) ) {
			$this->log_error(
				sprintf(
					'Circular template reference detected: %s → %d',
					implode( ' → ', $context->get_call_stack() ),
					$template_id
				)
			);
			return '';
		}

		$context = $context->push_template( $template_id );

		// Cache check.
		$context_hash = $context->with_runtime( $runtime_vars )->get_context_hash();
		if ( ! $this->bypass_cache ) {
			$cached = $this->cache->get( $template_id, $context_hash );
			if ( null !== $cached ) {
				return $cached;
			}
		}

		// Stage 2: Load raw content.
		$raw = $post->post_content;
		if ( '' === trim( $raw ) ) {
			return '';
		}

		// Resolve plural locale: per-template post meta `_spintax_locale`
		// (e.g. "ru", "en", "ru_RU") wins; fall back to the WP site locale.
		// Plurals::normalize_base_lang strips region suffix downstream.
		$locale = (string) get_post_meta( $template_id, OptionKeys::META_LOCALE, true );
		if ( '' === $locale ) {
			$locale = (string) get_locale();
		}

		// Track which template IDs are nested (for dependency graph).
		$is_top_level = empty( $parent_ctx );
		if ( $is_top_level ) {
			$this->rendered_ids = array();
		}

		try {
			$output = $this->process_template( $raw, $runtime_vars, $context, $locale );

			// Cache store.
			$this->cache->set( $template_id, $context_hash, $output );

			// Record dependency graph for the top-level template.
			if ( $is_top_level && ! empty( $this->rendered_ids ) ) {
				$this->deps->record_dependencies( $template_id, $this->rendered_ids );
			}

			return $output;
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
	 * @param string                $raw          Raw spintax markup.
	 * @param array<string, string> $runtime_vars Runtime variables.
	 * @param RenderContext|null    $context      Render context (created if null).
	 * @param string                $locale       Render locale for plural agreement (raw, e.g. "ru" / "ru_RU"). Defaults to WP site locale when empty.
	 * @return string Processed and sanitised HTML.
	 */
	public function process_template( string $raw, array $runtime_vars = array(), ?RenderContext $context = null, string $locale = '' ): string {
		$context = $context ?? new RenderContext( $this->settings->get_global_variables() );

		// Stage 3: Strip comments.
		$text = $this->parser->strip_comments( $raw );

		// Stage 4: Parse #set and #def, strip both from body.
		$extracted = $this->parser->extract_directives( $text );
		$text      = $extracted['body'];

		// Resolve the locale here rather than at Stage 6d: the `#def` roll below runs the plural
		// pass over definition values, and it needs the same locale the body will use.
		if ( '' === $locale ) {
			$locale = (string) get_locale();
		}

		// Stage 5: Build variable context. Precedence is runtime > local > global, enforced by
		// `get_merged_variables()`'s merge order rather than by the order of these calls.
		$context = $context->with_local( $extracted['set'] );
		if ( ! empty( $runtime_vars ) ) {
			$context = $context->with_runtime( $runtime_vars );
		}

		// Stage 5b: Roll `#def` values ONCE — and only now, because the full context has to exist
		// first. A `#def` value is rendered as if it were a miniature body and the result is frozen
		// for every reference; a `#set` value is substituted verbatim and its brackets re-roll at
		// each reference. Rolling before Stage 5 (where the old collapse-once pass sat) would hand
		// the roll a context with no globals and no runtime variables, so
		// `#def %x% = %product_name% {a|b}` would freeze the literal text `%product_name%`.
		if ( ! empty( $extracted['def'] ) ) {
			$context = $context->with_local(
				$this->roll_definitions( $extracted['def'], $context, $runtime_vars, $locale )
			);
		}

		$all_vars = $context->get_merged_variables();

		// Shield [spintax] and #include before spintax resolution.
		// Shortcodes use square brackets which would be consumed
		// by the permutation resolver. Shield them with placeholders first,
		// then restore and resolve after enum/perm processing.
		//
		// Read the restore's guard BEFORE the shield mints a NUL of its own — afterwards a
		// caller-borne NUL and a shield-minted one are indistinguishable.
		$unambiguous         = self::restore_is_unambiguous( $text, $all_vars );
		$nested_placeholders = array();
		$nested_counter      = 0;
		$text                = $this->shield_nested_constructs( $text, $nested_placeholders, $nested_counter );

		// Stage 6a: Resolve `{?VAR?then|else}` conditionals (pre-expand pass).
		// Catches conditionals authored directly in the template body so
		// only the surviving branch is fed into variable expansion.
		$text = $this->conditionals->apply( $text, $all_vars );

		// Stage 6b: Expand variables.
		$text = $this->parser->expand_variables( $text, $all_vars );

		// Shield again. Expansion is the only way a `[spintax]` can enter the document after the
		// first pass — carried in by a `#set`, a global, a runtime variable or a frozen `#def`,
		// none of whose values were part of the body when it ran. Without this, Stage 8 reads
		// `[spintax slug="x"]` as a single-element permutation, strips the brackets, and Stage 9
		// receives inert text. That has been true of `#set` and globals since both existed.
		$text = $this->shield_nested_constructs( $text, $nested_placeholders, $nested_counter );

		// Stage 6c: Resolve `{?VAR?then|else}` conditionals (post-expand pass).
		// Catches conditionals introduced via substituted variable values
		// (e.g., %CTA% expanding to `{?HasBonus?Claim|Deposit}`).
		$text = $this->conditionals->apply( $text, $all_vars );

		// Stage 6d: Resolve `{plural <count>: form|…}` plural agreement.
		// Runs AFTER variable expansion so `%CasinoLanguagesCount%` inside
		// the count slot is already a literal integer string. Lenient mode
		// so a single broken construct (wrong arity, nested brackets in a
		// form) renders verbatim with fullwidth braces instead of crashing
		// the whole render. The locale was resolved before Stage 5b.
		$text = $this->plurals->apply( $text, $locale, array( 'lenient' => true ) );

		// Stage 7: Resolve enumerations.
		$text = $this->parser->resolve_enumerations( $text );

		// Stage 8: Resolve permutations.
		$text = $this->parser->resolve_permutations( $text );

		// Restore [spintax] placeholders.
		$text = self::restore_shielded( $text, $nested_placeholders, $unambiguous );

		// Stage 9: Resolve #include and [spintax].
		$text = $this->resolve_nested( $text, $context );

		// Stage 10: Post-process.
		$text = $this->parser->post_process( $text );

		// Stage 11: Sanitize HTML.
		$text = wp_kses_post( $text );

		return $text;
	}

	/**
	 * Render each `#def` value once and return the frozen results.
	 *
	 * Values are rendered in dependency order so a `#def` built out of another `#def` sees the
	 * resolved text rather than the raw template. `Parser::order_definitions()` works that order
	 * out, following aliases as well as direct references — the dependency in `#def %b% = %s%` with
	 * `#set %s% = %a%` and `#def %a% = …` is real but invisible in `%b%`'s own text.
	 *
	 * A name a runtime variable also defines is skipped: runtime outranks locals, so rolling it
	 * would be work nothing can read.
	 *
	 * @param array<string, string> $definitions  Raw `#def` values, name => value.
	 * @param RenderContext         $context      Context with globals, `#set` locals and runtime.
	 * @param array<string, string> $runtime_vars Runtime variables, which outrank every local.
	 * @param string                $locale       Plural locale, already resolved.
	 * @return array<string, string> Frozen values, name => rendered text.
	 */
	private function roll_definitions( array $definitions, RenderContext $context, array $runtime_vars, string $locale ): array {
		$vars      = $context->get_merged_variables();
		$outranked = array_change_key_case( $runtime_vars, CASE_LOWER );
		$resolved  = array();

		// The alias map is every macro value a definition can actually see — globals and runtime
		// variables as well as local `#set`. Passing only the local map would miss a dependency
		// routed through a global.
		//
		// Excluded from it are the definitions that will actually be rolled, because a `#def`
		// shadows a global of the same name and hopping through the shadowed value would compute
		// the wrong graph. A definition a runtime variable outranks is NOT excluded: it is never
		// rolled, so the runtime value is the one that will really be substituted, and the graph
		// has to follow it. Excluding those too made a dependency reached through such a name
		// invisible, and declaration order leaked back into the result.
		$aliases = array_diff_key( $vars, array_diff_key( $definitions, $outranked ) );

		foreach ( $this->parser->order_definitions( $definitions, $aliases ) as $name ) {
			if ( array_key_exists( $name, $outranked ) ) {
				continue;
			}

			$resolved[ $name ] = $this->render_definition_value(
				$definitions[ $name ],
				array_merge( $vars, $resolved ),
				$locale
			);
		}

		return $resolved;
	}

	/**
	 * Render one `#def` value through the same passes the body gets, in the same order.
	 *
	 * Stage 9 (`#include` / `[spintax]`) is deliberately absent: those resolve after everything
	 * here and cannot be frozen into a value, which is why the validator rejects an `#include`
	 * inside a definition. A `[spintax]` shortcode is shielded for the length of the roll so the
	 * permutation resolver cannot eat its brackets, and handed back whole.
	 *
	 * @param string                $value  Raw directive value.
	 * @param array<string, string> $vars   Variables visible to this value.
	 * @param string                $locale Plural locale.
	 * @return string
	 */
	private function render_definition_value( string $value, array $vars, string $locale ): string {
		// Read the restore's guard before the shield mints a NUL of its own, exactly as the body does.
		$unambiguous = self::restore_is_unambiguous( $value, $vars );
		$shielded    = array();
		$counter     = 0;
		$value       = $this->shield_nested_constructs( $value, $shielded, $counter );

		$value = $this->conditionals->apply( $value, $vars );
		$value = $this->parser->expand_variables( $value, $vars );

		// Shield again, for the same reason the body does: expansion is the one place a shortcode
		// can enter after the first pass. `#def %frag% = %s%` with `#set %s% = [spintax slug="x"]`
		// pulls the shortcode in here, and without this the permutation resolver below reads it as
		// a single-element permutation and strips the brackets.
		$value = $this->shield_nested_constructs( $value, $shielded, $counter );

		$value = $this->conditionals->apply( $value, $vars );
		$value = $this->plurals->apply( $value, $locale, array( 'lenient' => true ) );
		$value = $this->parser->resolve_enumerations( $value );
		$value = $this->parser->resolve_permutations( $value );

		$value = self::restore_shielded( $value, $shielded, $unambiguous );

		return $value;
	}

	/**
	 * Put the shielded `[spintax]` placeholders back.
	 *
	 * Two restores, and the choice between them is behaviour, not tuning. `str_replace()` over
	 * arrays is SEQUENTIAL — every occurrence of the first key throughout the text, then the second,
	 * and so on — which is O(text x keys) and is what made this stage quadratic. It is also
	 * observable: a replacement can rewrite text an earlier one produced, and an unpaired NUL that
	 * came in with the template can pair with the opening NUL of a real placeholder to name a key
	 * the shield never minted. One left-to-right pass reproduces neither.
	 *
	 * `$unambiguous` is the caller's promise that no NUL entered from outside — see
	 * {@see self::restore_is_unambiguous()}. Under it every NUL in the working text is one this
	 * shield placed, so the keys are well formed and no shielded value can hold a NUL to forge
	 * another, and `strtr()` — which takes the one key that can match at each position and never
	 * rescans what it wrote — is the single pass.
	 *
	 * The promise removes the NUL-borne disagreements; it does not make the two restores identical.
	 * Caller text sitting between two adjacent shielded shortcodes can spell a key this shield really
	 * minted, using the neighbours' delimiters and no NUL of its own, and the sequential restore
	 * substitutes it. `[spintax slug="a"] NESTED_0 [spintax slug="b"]` is such a body. The single
	 * pass is the answer taken there, matching `@spintax/core`; both directions are pinned by the
	 * shared cross-engine corpus.
	 *
	 * @param string                $text        Working text.
	 * @param array<string, string> $shielded    Placeholder => original.
	 * @param bool                  $unambiguous Whether the single-pass restore is known to agree.
	 * @return string
	 */
	private static function restore_shielded( string $text, array $shielded, bool $unambiguous ): string {
		if ( empty( $shielded ) ) {
			return $text;
		}

		return $unambiguous
			? strtr( $text, $shielded )
			: str_replace( array_keys( $shielded ), array_values( $shielded ), $text );
	}

	/**
	 * Can the single-pass restore stand in for the sequential one?
	 *
	 * Only when no NUL reaches the working text from outside the shield. Two doors: the template
	 * body, and the variable values expansion substitutes into it — the second shield pass runs
	 * after expansion precisely because that pass is the one way new text enters, so a `#set`,
	 * a global, a runtime variable or a frozen `#def` carrying a NUL counts just as the body does.
	 *
	 * @param string                $text Body text about to be shielded.
	 * @param array<string, string> $vars Every variable value that expansion can substitute.
	 * @return bool
	 */
	private static function restore_is_unambiguous( string $text, array $vars ): bool {
		if ( str_contains( $text, "\x00" ) ) {
			return false;
		}

		foreach ( $vars as $value ) {
			if ( str_contains( $value, "\x00" ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Replace `[spintax …]` shortcodes with opaque placeholders.
	 *
	 * The placeholders are `\x00NESTED_n\x00`, which no template syntax can produce and no resolver
	 * reads. Callers share `$placeholders` and `$counter` across successive calls, so one restore
	 * at the end covers every pass.
	 *
	 * @param string                $text         Text to shield.
	 * @param array<string, string> $placeholders Placeholder => original, accumulated by reference.
	 * @param int                   $counter      Placeholder counter, advanced by reference.
	 * @return string
	 */
	private function shield_nested_constructs( string $text, array &$placeholders, int &$counter ): string {
		return (string) preg_replace_callback(
			'/\[spintax\s+[^\]]+\]/i',
			static function ( array $m ) use ( &$placeholders, &$counter ): string {
				$key                  = "\x00NESTED_{$counter}\x00";
				$placeholders[ $key ] = $m[0];
				++$counter;

				return $key;
			},
			$text
		);
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
		// Child context: inherits global + runtime but NOT parent's #set locals.
		$child_ctx = $context->for_child_render();

		$text = $this->parser->resolve_includes(
			$text,
			function ( string $slug_or_id ) use ( $child_ctx ): string {
				$this->track_nested_id( $slug_or_id );
				return $this->render( $slug_or_id, array(), $child_ctx );
			}
		);

		// Resolve [spintax ...] shortcodes.
		$text = preg_replace_callback(
			'/\[spintax\s+([^\]]+)\]/i',
			function ( array $m ) use ( $child_ctx ): string {
				$attrs = $this->parse_shortcode_attrs( $m[1] );
				if ( empty( $attrs ) ) {
					return '';
				}

				$id_or_slug = $attrs['id'] ?? $attrs['slug'] ?? '';
				if ( '' === $id_or_slug ) {
					return '';
				}

				$this->track_nested_id( $id_or_slug );

				$nested_vars = $attrs;
				unset( $nested_vars['id'], $nested_vars['slug'] );

				return $this->render( $id_or_slug, $nested_vars, $child_ctx );
			},
			$text
		);

		return $text;
	}

	/**
	 * Track a nested template ID for dependency recording.
	 *
	 * @param int|string $id_or_slug Template ID or slug.
	 */
	private function track_nested_id( $id_or_slug ): void {
		$post = $this->resolve_template( $id_or_slug );
		if ( $post ) {
			$this->rendered_ids[] = $post->ID;
		}
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

		$posts = get_posts(
			array(
				'post_type'      => TemplatePostType::POST_TYPE,
				'name'           => sanitize_title( (string) $id_or_slug ),
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
			)
		);

		return $posts[0] ?? null;
	}

	/**
	 * Parse shortcode-style attributes from a string.
	 *
	 * @param string $attr_string Attribute string, e.g. 'id="123" city="Moscow"'.
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
	 *
	 * @param string $message Error message to log.
	 */
	private function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Spintax] ' . $message );
		}
	}
}
