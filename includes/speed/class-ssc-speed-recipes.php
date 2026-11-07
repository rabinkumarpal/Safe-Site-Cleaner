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

	public static function exists( $name ) {
		return 'sureforms_unused' === $name;
	}

	public static function available( $name ) {
		return 'sureforms_unused' === $name && defined( 'SRFM_PRO_VER' );
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
