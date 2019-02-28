<?php
/**
 * Component class.
 *
 * @package WP_Components
 */

namespace WP_Components;

/**
 * Component.
 */
class Component implements \JsonSerializable {

	/**
	 * Unique component slug.
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Component config.
	 *
	 * @var array
	 */
	public $config = [];

	/**
	 * Component children.
	 *
	 * @var array
	 */
	public $children = [];

	/**
	 * Determine which config keys should be passed into result.
	 *
	 * @var array
	 */
	public $whitelist = [];

	/**
	 * Determine which config keys should not be transformed into camelCase.
	 *
	 * NOTE: this will not prevent the key itself from being camelcased,
	 * only the keys of the config value if it is an associative array.
	 *
	 * @var array
	 */
	public $preserve_inner_keys = [];

	/**
	 * Component constructor.
	 */
	public function __construct() {
		$this->config   = $this->default_config();
		$this->children = $this->default_children();
	}

	/**
	 * Helper to change a components name.
	 *
	 * @param  string $name New component name.
	 * @return mixed An instance of this class.
	 */
	public function set_name( string $name ) {
		$this->name = $name;
		return $this;
	}

	/**
	 * Define a default config.
	 *
	 * @return array Default config.
	 */
	public function default_config() {
		return [];
	}

	/**
	 * Helper to set a top level config value.
	 *
	 * @param array|string $key   Config key or entire config array.
	 * @param mixed        $value Config value.
	 * @return self
	 */
	public function set_config( $key, $value = null ) {
		if ( is_array( $key ) && is_null( $value ) ) {
			$this->config = $key;
		} else {
			$this->config[ $key ] = $value;
		}
		return $this;
	}

	/**
	 * Helper to set a top level config value.
	 *
	 * @param  string $key   Config key.
	 * @return mixed An instance of this class.
	 */
	public function get_config( $key ) {
		if ( array_key_exists( $key, $this->config ) ) {
			return $this->config[ $key ];
		}
		return null;
	}

	/**
	 * Merge new values into the current config.
	 *
	 * @param  array $new_config Config array to merge in.
	 * @return self
	 */
	public function merge_config( array $new_config ) {
		$this->config = wp_parse_args( $new_config, $this->config );
		return $this;
	}

	/**
	 * Define default children.
	 *
	 * @return array Default children.
	 */
	public function default_children() {
		return [];
	}

	/**
	 * Helper to set children components.
	 *
	 * @param  array   $children Children for this component.
	 * @param  boolean $append   Append children to existing children.
	 * @return mixed An instance of this class.
	 */
	public function set_children( array $children, $append = false ) {
		if ( $append ) {
			$this->children = array_merge(
				$this->children,
				array_filter( $children )
			);
		} else {
			$this->children = array_filter( $children );
		}
		return $this;
	}

	/**
	 * Append an array of components to the children array.
	 *
	 * @param array $children Array of components.
	 * @return mixed An instance of this class.
	 */
	public function append_children( array $children ) {
		if ( ! empty( $children ) ) {
			$children = array_filter( $children );
			$this->children = array_merge( $this->children, $children );
		}
		return $this;
	}

	/**
	 * Prepend an array of components to the children array.
	 *
	 * @param array $children Array of components.
	 * @return mixed An instance of this class.
	 */
	public function prepend_children( array $children ) {
		if ( ! empty( $children ) ) {
			$children = array_filter( $children );
			$this->children = array_merge( $children, $this->children );
		}
		return $this;
	}

	/**
	 * Append a component to the children array.
	 *
	 * @param Component $child Child component.
	 * @return mixed An instance of this class.
	 */
	public function append_child( $child ) {
		if ( ! empty( $child ) ) {
			if ( is_array( $child ) ) {
				$child = $child[0];
			}
			array_push( $this->children, $child );
		}
		return $this;
	}

	/**
	 * Prepend a component to the children array.
	 *
	 * @param Component $child Child component.
	 * @return mixed An instance of this class.
	 */
	public function prepend_child( $child ) {
		if ( ! empty( $child ) ) {
			if ( is_array( $child ) ) {
				$child = $child[0];
			}
			array_unshift( $this->children, $child );
		}
		return $this;
	}

	/**
	 * Render the frontend component.
	 */
	public function render() {
		// Override me.
	}

	/**
	 * Execute a function on each child of this component.
	 *
	 * @param callable $callback Callback function.
	 * @return mixed An instance of this class.
	 */
	public function children_callback( $callback ) {
		$this->children = array_map( $callback, $this->children );

		return $this;
	}

	/**
	 * Helper to output this class as an array.
	 *
	 * @return array
	 */
	public function to_array() : array {
		return [
			'name'     => $this->name,
			'config'   => (object) $this->camel_case_keys( $this->config ),
			'children' => array_filter( $this->children ),
		];
	}

	/**
	 * Convert all array keys to camel case.
	 *
	 * @param array $array        Array to convert.
	 * @param array $array_holder Parent array holder for recursive array.
	 * @return array Updated array with camel-cased keys.
	 */
	public function camel_case_keys( $array, $array_holder = [] ) {
		// Setup for recursion.
		$camel_case_array = ! empty( $array_holder ) ? $array_holder : [];

		// Loop through each key.
		foreach ( $array as $key => $value ) {
			// Only return keys that are white-listed. Leave $whitelist empty
			// to disable.
			if (
				! empty( $this->whitelist )
				&& ! in_array( $key, $this->whitelist, true )
			) {
				unset( $array[ $key ] );
				continue;
			}

			// Explode each part by underscore.
			$words = explode( '_', $key );

			// Capitalize each key part.
			array_walk(
				$words,
				function( &$word ) {
					$word = ucwords( $word );
				}
			);

			// Reassemble key.
			$new_key = implode( '', $words );

			// Lowercase the first character.
			$new_key[0] = strtolower( $new_key[0] );

			if (
				! is_array( $value )
				// Don't recursively camelCase if this key is in the $preserve_inner_keys property.
				|| ( ! empty( $this->preserve_inner_keys ) && in_array( $key, $this->preserve_inner_keys, true ) )
			) {
				// Set new key value.
				$camel_case_array[ $new_key ] = $value;
			} else {
				// Set new key value, but process the nested array.
				$camel_case_array[ $new_key ] = $this->camel_case_keys( $value, $camel_case_array[ $new_key ] );
			}
		}

		return $camel_case_array;
	}

	/**
	 * Use custom to_array method when component is serialized for API response.
	 *
	 * @return array
	 */
	public function jsonSerialize() {
		return $this->to_array();
	}
}
