<?php
/**
 * Block Bindings source for videogame meta.
 *
 * Registers the `mvb/videogame` block bindings source so core blocks
 * (Paragraph, Heading, Image, Button) can bind attributes to videogame
 * post meta without shortcodes or custom blocks.
 *
 * Requires WordPress 6.5+ (Block Bindings API). Degrades silently on older
 * versions so the plugin keeps working on the declared minimum.
 *
 * @package MVB
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class MVB_Block_Bindings
 */
class MVB_Block_Bindings {

	/**
	 * Source name used in block markup: metadata.bindings.*.source.
	 */
	const SOURCE_NAME = 'mvb/videogame';

	/**
	 * Meta keys allowed through the binding, mapped to human labels.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_keys() {
		return array(
			'videogame_completion_date' => __( 'Completion Date', 'mvb' ),
			'videogame_release_date'    => __( 'Release Date', 'mvb' ),
			'hltb_main_story'           => __( 'HLTB Main Story (hours)', 'mvb' ),
			'igdb_id'                   => __( 'IGDB ID', 'mvb' ),
		);
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_source' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_panel' ) );
	}

	/**
	 * Register the block bindings source.
	 */
	public static function register_source() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			self::SOURCE_NAME,
			array(
				'label'              => __( 'Videogame Meta', 'mvb' ),
				'get_value_callback' => array( __CLASS__, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Enqueue the editor sidebar panel for videogame posts.
	 */
	public static function enqueue_editor_panel() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'videogame' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'mvb-bindings-panel',
			MVB_PLUGIN_URL . 'assets/js/editor-bindings-panel.js',
			array(
				'wp-plugins',
				'wp-editor',
				'wp-element',
				'wp-components',
				'wp-data',
				'wp-i18n',
			),
			MVB_VERSION,
			true
		);

		wp_localize_script(
			'mvb-bindings-panel',
			'MVBBindingsData',
			array(
				'postType' => 'videogame',
				'keys'     => self::allowed_keys(),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'mvb-bindings-panel', 'mvb' );
		}
	}

	/**
	 * Resolve a binding to its meta value.
	 *
	 * @param array    $source_args    Arguments declared on the block binding.
	 * @param WP_Block $block_instance Block instance.
	 * @param string   $attribute_name Bound attribute name.
	 * @return string|null
	 */
	public static function get_value( $source_args, $block_instance, $attribute_name ) {
		unset( $attribute_name );

		if ( empty( $source_args['key'] ) ) {
			return null;
		}

		$key = (string) $source_args['key'];
		if ( ! array_key_exists( $key, self::allowed_keys() ) ) {
			return null;
		}

		$post_id = isset( $block_instance->context['postId'] )
			? (int) $block_instance->context['postId']
			: 0;

		if ( $post_id <= 0 ) {
			return null;
		}

		if ( 'videogame' !== get_post_type( $post_id ) ) {
			return null;
		}

		$value = get_post_meta( $post_id, $key, true );
		if ( '' === $value || null === $value ) {
			return null;
		}

		return self::format_value( $key, $value );
	}

	/**
	 * Normalize meta values for display.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Raw meta value.
	 * @return string
	 */
	protected static function format_value( $key, $value ) {
		switch ( $key ) {
			case 'videogame_completion_date':
			case 'videogame_release_date':
				return self::format_date( (string) $value );
			case 'hltb_main_story':
				return (string) (float) $value;
			case 'igdb_id':
				return (string) (int) $value;
			default:
				return (string) $value;
		}
	}

	/**
	 * Best-effort date normalization to the site's date format.
	 *
	 * SCF stores dates in its configured display format (e.g. `d/m/Y`), which
	 * is not ISO. We try common formats, then fall back to the raw string.
	 *
	 * @param string $raw Raw meta value.
	 * @return string
	 */
	protected static function format_date( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$formats = array( 'Y-m-d', 'd/m/Y', 'Ymd', 'F j, Y' );
		foreach ( $formats as $format ) {
			$date = DateTime::createFromFormat( $format, $raw );
			if ( $date instanceof DateTime ) {
				return wp_date( get_option( 'date_format' ), $date->getTimestamp() );
			}
		}

		$timestamp = strtotime( $raw );
		if ( false !== $timestamp ) {
			return wp_date( get_option( 'date_format' ), $timestamp );
		}

		return $raw;
	}
}
