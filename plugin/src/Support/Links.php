<?php
/**
 * Locale-aware deep links to spintax.net documentation and playground.
 *
 * @package Spintax
 */

namespace Spintax\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Builds canonical URLs to the spintax.net knowledge hub.
 *
 * Pages on spintax.net are served either in all 13 languages (full-lang)
 * or only in EN+RU (limited-lang for long-form authoring guides). The
 * helpers below pick the matching locale prefix from the WordPress site
 * locale and fall back to EN root for unsupported locales.
 */
class Links {

	/**
	 * Documentation hub root.
	 */
	private const BASE = 'https://spintax.net';

	/**
	 * Locales that have a full localised version on spintax.net.
	 */
	private const FULL_LANGS = array(
		'ru',
		'es',
		'fr',
		'de',
		'it',
		'pt',
		'nl',
		'ar',
		'zh',
		'ja',
		'ko',
		'tr',
	);

	/**
	 * Locales that have an EN+RU long-form authoring guide.
	 */
	private const LIMITED_LANGS = array( 'ru' );

	/**
	 * Documentation hub URL — full-lang page.
	 */
	public static function docs_hub(): string {
		return self::BASE . self::lang_prefix( true ) . '/docs/';
	}

	/**
	 * Compact syntax reference — full-lang page.
	 */
	public static function docs_syntax(): string {
		return self::BASE . self::lang_prefix( true ) . '/docs/syntax';
	}

	/**
	 * Plural agreement guide — EN+RU only.
	 */
	public static function docs_plural(): string {
		return self::BASE . self::lang_prefix( false ) . '/docs/plural-spintax/';
	}

	/**
	 * Conditional spintax guide — EN+RU only.
	 */
	public static function docs_conditional(): string {
		return self::BASE . self::lang_prefix( false ) . '/docs/conditional-spintax/';
	}

	/**
	 * Authoring mindset guide — EN+RU only.
	 */
	public static function docs_authoring(): string {
		return self::BASE . self::lang_prefix( false ) . '/docs/authoring-mindset/';
	}

	/**
	 * Browser playground — EN+RU only.
	 */
	public static function playground(): string {
		return self::BASE . self::lang_prefix( false ) . '/play/';
	}

	/**
	 * Resolve the locale prefix for a spintax.net URL.
	 *
	 * @param bool $allow_all_langs True for pages localised in all 13 languages;
	 *                              false for pages that exist only in EN + RU
	 *                              (long-form authoring guides).
	 * @return string Empty string for EN root, or `/<lang>` for non-EN.
	 */
	private static function lang_prefix( bool $allow_all_langs ): string {
		$base = self::base_lang();

		if ( '' === $base || 'en' === $base ) {
			return '';
		}

		$pool = $allow_all_langs ? self::FULL_LANGS : self::LIMITED_LANGS;

		return in_array( $base, $pool, true ) ? '/' . $base : '';
	}

	/**
	 * Strip region from the WordPress site locale (`ru_RU` → `ru`).
	 */
	private static function base_lang(): string {
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';
		if ( '' === $locale ) {
			return '';
		}
		$parts = preg_split( '/[-_]/', strtolower( $locale ), 2 );
		return $parts[0] ?? '';
	}
}
