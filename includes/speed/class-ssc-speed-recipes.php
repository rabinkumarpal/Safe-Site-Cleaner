<?php
/**
 * Recipes: plugin-specific knowledge a plain rule can't express, because what is safe to drop depends
 * on how the site uses that plugin.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Recipes {

	const SUREFORMS_CACHE = 'ssc_speed_sureforms_unused';

	/** Scripts and styles behind each SureForms Pro feature. */
	const SUREFORMS_ASSETS = array(
		'signature'         => array( array( 'srfm-pro-signature-pad-lib', 'sureforms-pro-signature-frontend' ), array( 'sureforms-pro-signature' ) ),
		'repeater'          => array( array( 'sureforms-pro-repeater-frontend' ), array( 'sureforms-pro-repeater' ) ),
		'user-registration' => array( array( 'sureforms-pro-user-registration-frontend', 'srfm-pro-zxcvbn' ), array( 'sureforms-pro-user-registration', 'sureforms-pro-password' ) ),
		'conditional-logic' => array( array( 'sureforms-pro-conditional-logic' ), array() ),
	);

	const LIST = array(
		'sureforms_unused'   => 'Drops SureForms Pro files for field types no published form uses. They come back if a form starts using them.',
		'wp_emoji'           => 'Removes the script WordPress adds to draw emoji on old browsers. Current browsers show emoji without it.',
		'jquery_migrate'     => 'Stops loading jQuery Migrate, which only helps code written for old versions of jQuery.',
		'dashicons_visitors' => 'Stops loading the Dashicons icon font for logged-out visitors, unless another stylesheet needs it. Logged-in users keep it for the admin bar.',
	);

	public static function exists( $name ) {
		return isset( self::LIST[ $name ] );
	}

	public static function what( $name ) {
		return self::LIST[ $name ] ?? '';
	}

	/** False when the plugin a recipe is for isn't active. WordPress's own recipes always apply. */
	public static function available( $name ) {
		return self::exists( $name ) && ( 'sureforms_unused' !== $name || defined( 'SRFM_PRO_VER' ) );
	}

	/** Runs on every request type. Forms are saved from the admin and REST, where no rule runs. */
	public static function watch() {
		add_action( 'save_post_sureforms_form', array( __CLASS__, 'forget_sureforms' ) );
	}

	public static function forget_sureforms() {
		delete_transient( self::SUREFORMS_CACHE );
	}

	/** Hooks an enabled recipe into the front end. */
	public static function start( $name ) {
		if ( 'sureforms_unused' === $name ) {
			// Forms render inside page content, the footer and popups, so check again before each print.
			add_action( 'wp_print_styles', array( __CLASS__, 'drop_sureforms' ), 1 );
			add_action( 'wp_print_scripts', array( __CLASS__, 'drop_sureforms' ), 1 );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'drop_sureforms' ), 1 );
		} elseif ( 'wp_emoji' === $name ) {
			add_action( 'wp', array( __CLASS__, 'drop_emoji' ) );
		} elseif ( 'jquery_migrate' === $name ) {
			add_action( 'wp', array( __CLASS__, 'drop_migrate' ) );
		} elseif ( 'dashicons_visitors' === $name ) {
			add_action( 'wp_print_styles', array( __CLASS__, 'drop_dashicons' ), 1 );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'drop_dashicons' ), 1 );
		}
	}

	/* These wait for 'wp' because the page builder check isn't reliable until the theme has loaded. */

	public static function drop_emoji() {
		if ( SSC_Speed::skip() ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
	}

	public static function drop_migrate() {
		if ( SSC_Speed::skip() ) {
			return;
		}
		$jquery = wp_scripts()->registered['jquery'] ?? null;
		if ( $jquery ) {
			$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
		}
	}

	public static function drop_dashicons() {
		if ( ! is_user_logged_in() && ! SSC_Speed::skip() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	public static function drop_sureforms() {
		if ( ! defined( 'SRFM_PRO_VER' ) || SSC_Speed::skip() ) {
			return;
		}
		foreach ( self::sureforms_unused() as $feature ) {
			list( $scripts, $styles ) = self::SUREFORMS_ASSETS[ $feature ];
			array_map( 'wp_dequeue_script', $scripts );
			array_map( 'wp_dequeue_style', $styles );
		}
	}

	/**
	 * SureForms Pro features no published form uses. SureForms loads every Pro field's scripts for any
	 * form placed with a page builder. Saving a form clears the cache, so adding such a field to a form
	 * brings its assets back.
	 */
	private static function sureforms_unused() {
		$cached = get_transient( self::SUREFORMS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$forms   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sureforms_form' AND post_status = 'publish'" );
		$content = implode(
			"\n",
			array_map(
				static function ( $id ) {
					return get_post_field( 'post_content', $id );
				},
				$forms
			)
		);
		$logic   = array_filter(
			$forms,
			static function ( $id ) {
				return ! empty( array_filter( (array) get_post_meta( $id, '_srfm_conditional_logic', true ) ) );
			}
		);

		$unused = array();
		foreach ( array(
			'signature'         => '#wp:srfm/signature\b#',
			'repeater'          => '#wp:srfm/repeater\b#',
			'user-registration' => '#wp:srfm/(password|login|register|lost-password|reset-password|link)\b#',
		) as $feature => $pattern ) {
			if ( ! preg_match( $pattern, $content ) ) {
				$unused[] = $feature;
			}
		}
		if ( ! $logic ) {
			$unused[] = 'conditional-logic';
		}
		set_transient( self::SUREFORMS_CACHE, $unused, DAY_IN_SECONDS );
		return $unused;
	}
}
