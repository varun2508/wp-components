<?php
/**
 * Gutenberg Content component.
 *
 * @package WP_Components
 */

namespace WP_Components;

/**
 * Gutenberg Content.
 */
class Gutenberg_Content extends Component {

	use WP_Post;

	/**
	 * Unique component slug.
	 *
	 * @var string
	 */
	public $name = 'gutenberg-content';

	/**
	 * Callback to inject components in the component array
	 *
	 * @var callable
	 */
	private $components_callback = null;

	/**
	 * Fires after the post object has been set on this class.
	 *
	 * @return self
	 */
	public function post_has_set() : self {
		$components = $this->parse_and_convert_block_content( $this->post->post_content ?? '' );

		return $this->append_children( $components );
	}

	/**
	 * Provide a callback to inject a component
	 *
	 * @param callable $cb function to run during block processing.
	 * @return self
	 */
	public function set_components_callback( callable $cb ) {
		$this->components_callback = $cb;
		return $this;
	}

	/**
	 * Parse block content and return as components if Gutenberg is available,
	 * otherwise return content as a single raw HTML block.
	 *
	 * @param {string} $post_content Post content to parse.
	 * @return array
	 */
	public function parse_and_convert_block_content( $post_content ) : array {
		// If gutenberg is not enabled, return the post's content as a generic
		// HTML component to deliver the post content.
		if ( ! function_exists( 'parse_blocks' ) ) {
			return $this->append_child(
				( new HTML() )
					->set_config(
						'content',
						// phpcs:ignore
						apply_filters( 'the_content', $post_content )
					)
			);
		}

		// Parse blocks.
		$blocks = (array) parse_blocks( $post_content );

		// Filter any empty parsed blocks.
		$blocks = array_values(
			array_filter(
				$blocks,
				function ( $block ) {
					$block = (array) $block;

					// Validate if the innerBlocks are set.
					if ( ! empty( $block['innerBlocks'] ) ) {
						return true;
					}

					// Check if innerHTML is only whitespace.
					return ! preg_match( '/^\s+$/', $block['innerHTML'] );
				}
			)
		);

		$components = array_reduce( $blocks, [ $this, 'convert_block_to_component' ], [] );

		/**
		 * Filters the array of blocks after they're converted to components.
		 *
		 * @param array $components Array of compoents created from parsed Gutenberg blocks.
		 * @param array $this       Current component instance.
		 */
		$components = apply_filters(
			'wp_components_post_convert_blocks',
			$components,
			$this
		);

		return $components ?? [];
	}

	/**
	 * Map a block array to a Component instance.
	 *
	 * @param array $blocks         Accumulated array of blocks.
	 * @param array $current_block  Current block.
	 * @return object Component instance
	 */
	private function convert_block_to_component( $blocks, $current_block ) : array {
		$block = (array) $current_block;

		/**
		 * Filters array of non-dynamic blocks for which you'd like to bypass
		 * the render step (and any core markup) and render your own markup in
		 * React instead.
		 *
		 * @param array $exceptions Array of block render excepctions.
		 * @param array $block      Current block.
		 */
		$block_render_exceptions = apply_filters(
			'wp_components_block_render_exceptions',
			[
				'core/columns',
				'core/column',
			],
			$block
		);

		if ( is_callable( $this->components_callback ) ) {
			$blocks = call_user_func( $this->components_callback, $blocks );
		}

		// If there's no block name, but there is innerHTML.
		if (
			empty( $block['blockName'] )
			&& ! empty( $block['innerHTML'] )
		) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return $this->merge_or_create_html_block( $blocks, apply_filters( 'the_content', $block['innerHTML'] ) );
		}

		// Handle gutenberg embeds.
		if (
			strpos( $block['blockName'] ?? '', 'core-embed' ) === 0
			|| strpos( $block['blockName'] ?? '', 'core/embed' ) === 0
		) {
			$blocks[] = ( new Blocks\Core_Embed() )->set_from_block( $block );
			return $blocks;
		}

		// The presence of html means this is a non-dynamic block.
		if (
			! empty( trim( $block['innerHTML'] ) )
			&& ! in_array( $block['blockName'], $block_render_exceptions, true )
		) {
			// Render block and clean up extraneous whitespace characters.
			$content = render_block( $block );
			$content = do_shortcode( $content );
			$content = preg_replace( '/[\n\r\t]/', ' ', $content );

			return $this->merge_or_create_html_block( $blocks, $content );
		}

		// Reusable blocks.
		if ( ! empty( $block['attrs']['ref'] ) ) {
			$ref_post = get_post( $block['attrs']['ref'] );

			if ( ! empty( $ref_post ) && ! empty( $ref_post->post_content ) ) {
				$blocks = array_merge(
					$blocks,
					$this->parse_and_convert_block_content( $ref_post->post_content )
				);

				return $blocks;
			}
		}

		// Handle nested blocks.
		$children_blocks_as_components = array_reduce(
			(array) ( $block['innerBlocks'] ?? [] ),
			[ $this, 'convert_block_to_component' ],
			[]
		);

		// Any custom blocks mapped to components.
		/**
		 * Map of Gutenberg block names to PHP class names of Irving components.
		 *
		 * Keys are the Gutenberg block names.
		 *
		 * @param array[string]string PHP class names.
		 */
		$blocks_to_components = apply_filters(
			'wp_components_block_components',
			[]
		);

		if (
			isset( $block['blockName'] ) &&
			! empty( $blocks_to_components[ $block['blockName'] ] )
		) {
			$component = ( new $blocks_to_components[ $block['blockName'] ]() )
				->merge_config( $block['attrs'] ?? [] )
				->append_children( $children_blocks_as_components );
		} else {
			// A dynamic block. All attributes will be available.
			$component = ( new Component() )
				->set_name( $block['blockName'] ?? '' )
				->merge_config( $block['attrs'] ?? [] )
				->append_children( $children_blocks_as_components );
		}

		$blocks[] = apply_filters( 'wp_components_dynamic_block', $component, $block, $blocks, $this );

		return $blocks;
	}

	/**
	 * Consolidate HTML components to prevent markup issues on the frontend.
	 *
	 * @param array  $blocks  Array of block components to merge new HTML component into.
	 * @param string $content HTML content to be rendered.
	 *
	 * @return array
	 */
	public function merge_or_create_html_block( array $blocks, string $content ): array {
		$last_block = end( $blocks );

		// Merge rendered static blocks into a single HTML component.
		if ( $last_block instanceof HTML ) {
			$last_block->set_config(
				'content',
				$last_block->get_config( 'content' ) . $content
			);
		} else {
			$blocks[] = ( new HTML() )->set_config( 'content', $content );
		}

		return $blocks;
	}
}
