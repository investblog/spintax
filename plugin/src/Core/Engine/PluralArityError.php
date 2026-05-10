<?php
/**
 * Plural form count does not match the locale's arity.
 *
 * @package Spintax
 */

namespace Spintax\Core\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Plurals when a `{plural ...}` construct has the wrong number
 * of forms for the active locale family (e.g. 2 forms under RU which
 * expects 3, or 3 forms under EN which expects 2).
 */
class PluralArityError extends \RuntimeException {

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
