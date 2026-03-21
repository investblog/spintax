<?php
/**
 * Rendering context — variable scopes and circular-reference protection.
 *
 * @package Spintax
 */

namespace Spintax\Core\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable context object passed through the rendering pipeline.
 *
 * Variable precedence: runtime > local > global.
 */
class RenderContext {

	/** @var array<string, string> */
	private array $global_vars;

	/** @var array<string, string> */
	private array $local_vars;

	/** @var array<string, string> */
	private array $runtime_vars;

	/** @var int[] Template IDs currently being rendered (for circular-ref detection). */
	private array $call_stack;

	/**
	 * @param array<string, string> $global_vars  Site-wide variables.
	 * @param array<string, string> $local_vars   From #set directives.
	 * @param array<string, string> $runtime_vars From shortcode/PHP call.
	 * @param int[]                 $call_stack   Template IDs already in the render chain.
	 */
	public function __construct(
		array $global_vars = array(),
		array $local_vars = array(),
		array $runtime_vars = array(),
		array $call_stack = array()
	) {
		$this->global_vars  = self::normalize_keys( $global_vars );
		$this->local_vars   = self::normalize_keys( $local_vars );
		$this->runtime_vars = self::normalize_keys( $runtime_vars );
		$this->call_stack   = $call_stack;
	}

	/**
	 * Get all variables merged with correct precedence: runtime > local > global.
	 *
	 * @return array<string, string>
	 */
	public function get_merged_variables(): array {
		return array_merge( $this->global_vars, $this->local_vars, $this->runtime_vars );
	}

	/**
	 * Deterministic hash of runtime variables — used as part of the cache key.
	 */
	public function get_context_hash(): string {
		if ( empty( $this->runtime_vars ) ) {
			return 'default';
		}

		$sorted = $this->runtime_vars;
		ksort( $sorted );
		return md5( wp_json_encode( $sorted ) );
	}

	/**
	 * Return a new context with additional local variables (from #set).
	 *
	 * @param array<string, string> $local_vars Parsed #set definitions.
	 * @return self
	 */
	public function with_local( array $local_vars ): self {
		return new self(
			$this->global_vars,
			array_merge( $this->local_vars, self::normalize_keys( $local_vars ) ),
			$this->runtime_vars,
			$this->call_stack
		);
	}

	/**
	 * Return a new context with additional/overridden runtime variables.
	 *
	 * @param array<string, string> $vars Extra runtime variables.
	 * @return self
	 */
	public function with_runtime( array $vars ): self {
		return new self(
			$this->global_vars,
			$this->local_vars,
			array_merge( $this->runtime_vars, self::normalize_keys( $vars ) ),
			$this->call_stack
		);
	}

	/**
	 * Return a clean context for nested template rendering.
	 *
	 * Nested templates inherit global and runtime variables but NOT
	 * the parent's local (#set) variables — they define their own.
	 * The call stack is preserved for circular reference detection.
	 *
	 * @return self
	 */
	public function for_child_render(): self {
		return new self(
			$this->global_vars,
			array(), // child has its own #set scope
			$this->runtime_vars,
			$this->call_stack
		);
	}

	/**
	 * Return a new context with a template ID pushed onto the call stack.
	 *
	 * @param int $template_id Template post ID.
	 * @return self
	 */
	public function push_template( int $template_id ): self {
		return new self(
			$this->global_vars,
			$this->local_vars,
			$this->runtime_vars,
			array_merge( $this->call_stack, array( $template_id ) )
		);
	}

	/**
	 * Check if a template is already in the render chain (circular reference).
	 */
	public function has_template( int $template_id ): bool {
		return in_array( $template_id, $this->call_stack, true );
	}

	/**
	 * Get the current call stack.
	 *
	 * @return int[]
	 */
	public function get_call_stack(): array {
		return $this->call_stack;
	}

	/**
	 * Normalise variable keys to lowercase.
	 *
	 * @param array<string, string> $vars
	 * @return array<string, string>
	 */
	private static function normalize_keys( array $vars ): array {
		$out = array();
		foreach ( $vars as $k => $v ) {
			$out[ strtolower( (string) $k ) ] = (string) $v;
		}
		return $out;
	}
}
