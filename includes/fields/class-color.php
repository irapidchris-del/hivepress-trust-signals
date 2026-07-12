<?php
/**
 * Color field.
 *
 * Extends the HivePress Text field; the display_type is derived from the
 * class name by core (verified in Field::init), so this renders a native
 * <input type="color"> browser colour picker.
 *
 * @package HivePress\Fields
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Colour picker field.
 */
class Color extends Text {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'      => esc_html__( 'Color', 'hivepress-trust-signals' ),
				'filterable' => false,
				'sortable'   => false,
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Renders the field HTML.
	 *
	 * A text input (not input[type=color]) so the core WordPress Iris colour
	 * picker can enhance it; without JavaScript it degrades to plain hex entry.
	 *
	 * @return string
	 */
	public function render() {
		$attributes = $this->attributes;

		if ( ! isset( $attributes['class'] ) || ! is_array( $attributes['class'] ) ) {
			$attributes['class'] = [];
		}

		$attributes['class'][] = 'hpts-color-field';

		return '<input type="text" name="' . esc_attr( $this->name ) . '" value="' . esc_attr( $this->value ) . '" ' . hp\html_attributes( $attributes ) . '>';
	}
}
