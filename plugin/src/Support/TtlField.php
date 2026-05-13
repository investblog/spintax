<?php
/**
 * Shared TTL form field renderer + sanitizer.
 *
 * @package Spintax
 */

namespace Spintax\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Preset-select + custom-number TTL input, reused by Settings and template MetaBox.
 *
 * Stored value is always int seconds (or null when allow_empty + empty selected).
 * The form POSTs two fields per instance:
 *   <name>_preset = "" | "0" | "3600" | … | "custom"
 *   <name>_custom = "12345"
 *
 * `sanitize()` resolves these to a single int (or null) to write back.
 */
final class TtlField {

	/**
	 * Preset value → label key. Keys are seconds.
	 *
	 * @return array<int, string>
	 */
	public static function presets(): array {
		return array(
			0       => __( 'No caching (0)', 'spintax' ),
			3600    => __( '1 hour', 'spintax' ),
			21600   => __( '6 hours', 'spintax' ),
			86400   => __( '1 day', 'spintax' ),
			604800  => __( '1 week', 'spintax' ),
			2592000 => __( '1 month (30 days)', 'spintax' ),
		);
	}

	/**
	 * Render the field. Outputs a select + a sibling number input toggled
	 * via the `.spintax-ttl-preset` change handler in admin.js.
	 *
	 * Expected keys on $args:
	 *   - name        (string, required)  Form field base name.
	 *   - id          (string, optional)  DOM id for the <select>.
	 *   - value       (int|string|null)   Current saved value in seconds.
	 *   - allow_empty (bool, optional)    Render an empty option (defaults inherited).
	 *   - description (string, optional)  Helper text below the field.
	 *   - empty_label (string, optional)  Label for the empty option.
	 *
	 * @param array<string, mixed> $args Render args (see above).
	 */
	public static function render( array $args ): void {
		$name        = (string) $args['name'];
		$id          = isset( $args['id'] ) ? (string) $args['id'] : 'spintax-ttl-' . sanitize_html_class( $name );
		$value       = $args['value'] ?? null;
		$allow_empty = ! empty( $args['allow_empty'] );
		$description = isset( $args['description'] ) ? (string) $args['description'] : '';
		$empty_label = isset( $args['empty_label'] ) ? (string) $args['empty_label'] : __( 'Use global default', 'spintax' );

		$presets   = self::presets();
		$is_empty  = ( '' === $value || null === $value );
		$value_int = $is_empty ? null : (int) $value;
		$is_custom = ( null !== $value_int ) && ! array_key_exists( $value_int, $presets );

		// Preset value to render in the <select>.
		if ( $allow_empty && $is_empty ) {
			$preset_selected = '';
		} elseif ( $is_custom ) {
			$preset_selected = 'custom';
		} else {
			$preset_selected = (string) ( $value_int ?? 0 );
		}

		// Number input shown when preset_selected === 'custom'.
		$custom_value = $is_custom ? (string) $value_int : '';
		$hidden_attr  = ( 'custom' !== $preset_selected ) ? ' style="display:none;"' : '';

		?>
		<span class="spintax-ttl-field" data-spintax-ttl-name="<?php echo esc_attr( $name ); ?>">
			<select
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name . '_preset' ); ?>"
				class="spintax-ttl-preset"
			>
				<?php if ( $allow_empty ) : ?>
					<option value="" <?php selected( $preset_selected, '' ); ?>>
						<?php echo esc_html( $empty_label ); ?>
					</option>
				<?php endif; ?>
				<?php foreach ( $presets as $seconds => $label ) : ?>
					<option value="<?php echo esc_attr( (string) $seconds ); ?>" <?php selected( $preset_selected, (string) $seconds ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
				<option value="custom" <?php selected( $preset_selected, 'custom' ); ?>>
					<?php esc_html_e( 'Custom…', 'spintax' ); ?>
				</option>
			</select>
			<input
				type="number"
				name="<?php echo esc_attr( $name . '_custom' ); ?>"
				class="spintax-ttl-custom small-text"
				min="0"
				step="1"
				value="<?php echo esc_attr( $custom_value ); ?>"
				aria-label="<?php esc_attr_e( 'Custom TTL in seconds', 'spintax' ); ?>"
				<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal. ?>
			/>
			<span class="spintax-ttl-custom-unit"<?php echo $hidden_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal. ?>>
				<?php esc_html_e( 'seconds', 'spintax' ); ?>
			</span>
		</span>
		<?php
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Resolve the posted (preset, custom) pair to an int (or null when
	 * `allow_empty` and the empty option was chosen).
	 *
	 * Negative custom values are clamped to 0. Non-numeric custom values
	 * fall back to 0. Unknown preset values fall back to the empty/null
	 * branch (if `allow_empty`) or 0.
	 *
	 * @param string|null $preset_raw  Posted preset value.
	 * @param string|null $custom_raw  Posted custom value.
	 * @param bool        $allow_empty Whether "" maps to null instead of 0.
	 * @return int|null
	 */
	public static function sanitize( ?string $preset_raw, ?string $custom_raw, bool $allow_empty = false ): ?int {
		$preset_raw = null === $preset_raw ? '' : trim( $preset_raw );
		$custom_raw = null === $custom_raw ? '' : trim( $custom_raw );

		if ( '' === $preset_raw ) {
			return $allow_empty ? null : 0;
		}

		if ( 'custom' === $preset_raw ) {
			if ( '' === $custom_raw ) {
				return $allow_empty ? null : 0;
			}
			return max( 0, (int) $custom_raw );
		}

		if ( ! is_numeric( $preset_raw ) ) {
			return $allow_empty ? null : 0;
		}

		$seconds = (int) $preset_raw;
		return max( 0, $seconds );
	}
}
