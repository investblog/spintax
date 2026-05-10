<?php
/**
 * Plural form contains nested spintax brackets.
 *
 * @package Spintax
 */

namespace Spintax\Core\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Plurals when a `{plural ...}` form slot contains nested
 * spintax brackets (`{`, `}`, `[`, `]`).
 *
 * Authoring synonyms / conditionals / permutations inside a form is
 * unsupported in v1 — extract the dynamic content via `#set` first and
 * reference the resulting `%Var%` in plain form text.
 */
class PluralFormError extends \RuntimeException {

	/**
	 * Offset of the offending block's opening `{` in the original text.
	 *
	 * @var int
	 */
	public int $position;

	/**
	 * Verbatim construct text for diagnostics.
	 *
	 * @var string
	 */
	public string $construct;

	/**
	 * Constructor.
	 *
	 * @param string $message   Error description.
	 * @param int    $position  Offset of opening `{` in the original text.
	 * @param string $construct Verbatim construct text.
	 */
	public function __construct( string $message, int $position = 0, string $construct = '' ) {
		parent::__construct( $message );
		$this->position  = $position;
		$this->construct = $construct;
	}
}
