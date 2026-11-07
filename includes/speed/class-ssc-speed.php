<?php
/**
 * Applies enabled speed rules to front-end pages. This is the only part of the plugin that runs for
 * visitors, so it reads the saved rules and nothing else: no scanning, and no hooks if no rule is on.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed {

	private static $skip = null;
	private static $deferred = null;
	private static $delayed = array();

	public static function boot() {
		SSC_Speed_Recipes::watch();
		if ( is_admin() || 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		$rules = SSC_Speed_Rules::enabled();
		if ( ! $rules ) {
			return;
		}
		$types = array_column( $rules, 'type' );

		if ( in_array( 'preload_font', $types, true ) ) {
			add_action( 'wp_head', array( __CLASS__, 'preload_fonts' ), 2 );
		}
		if ( in_array( 'eager_first_image', $types, true ) ) {
			// Page builders filter after priority 5 and leave images marked eager alone.
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'eager_first_image' ), 5 );
		}
		if ( in_array( 'defer_style', $types, true ) ) {
			add_filter( 'style_loader_tag', array( __CLASS__, 'defer_style' ), 10, 2 );
		}
		foreach ( SSC_Speed_Rules::enabled( 'delay_script' ) as $rule ) {
			self::$delayed = array_merge( self::$delayed, $rule['handles'] );
		}
		if ( self::$delayed ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'delay_script' ), 10, 2 );
			add_action( 'wp_footer', array( __CLASS__, 'delay_loader' ), 100 );
		}
		foreach ( SSC_Speed_Rules::enabled( 'recipe' ) as $rule ) {
			SSC_Speed_Recipes::start( $rule['recipe'] );
		}
	}

	/** True where rules must stay out of the way: page builders and the Customizer. */
	public static function skip() {
		if ( null === self::$skip ) {
			$builder = ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
				|| ( function_exists( 'bricks_is_builder_iframe' ) && bricks_is_builder_iframe() )
				|| is_customize_preview();
			foreach ( array( 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'vc_editable' ) as $key ) {
				$builder = $builder || isset( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			/** Filters whether speed rules stay off for this request. */
			self::$skip = (bool) apply_filters( 'ssc_speed_skip', $builder );
		}
		return self::$skip;
	}

	public static function preload_fonts() {
		if ( self::skip() ) {
			return;
		}
		$base = wp_get_upload_dir()['baseurl'] . '/';
		foreach ( SSC_Speed_Rules::enabled( 'preload_font' ) as $rule ) {
			foreach ( $rule['files'] as $file ) {
				$type = '.woff' === substr( $file, -5 ) ? 'font/woff' : 'font/woff2';
				printf( '<link rel="preload" href="%s" as="font" type="%s" crossorigin>' . "\n", esc_url( $base . $file ), esc_attr( $type ) );
			}
		}
	}

	public static function eager_first_image( $attr ) {
		if ( ! self::skip() && 'high' === ( $attr['fetchpriority'] ?? '' ) ) {
			$attr['loading'] = 'eager';
		}
		return $attr;
	}

	/** Stylesheets keep their place in the document, so what they override doesn't change. */
	public static function defer_style( $tag, $handle ) {
		if ( self::skip() || ! in_array( $handle, self::deferred_handles(), true ) ) {
			return $tag;
		}
		$deferred = preg_replace( '/media=([\'"])[^\'"]*\1/', 'media="print" onload="this.media=\'all\'"', $tag, 1, $count );
		return $count ? $deferred . '<noscript>' . trim( $tag ) . "</noscript>\n" : $tag;
	}

	private static function deferred_handles() {
		if ( ! did_action( 'wp' ) ) {
			return array(); // Page exceptions can't be checked yet.
		}
		if ( null === self::$deferred ) {
			self::$deferred = array();
			foreach ( SSC_Speed_Rules::enabled( 'defer_style' ) as $rule ) {
				if ( ! $rule['except_pages'] || ! is_page( $rule['except_pages'] ) ) {
					self::$deferred = array_merge( self::$deferred, $rule['handles'] );
				}
			}
		}
		return self::$deferred;
	}

	public static function delay_script( $tag, $handle ) {
		if ( self::skip() || ! in_array( $handle, self::$delayed, true ) ) {
			return $tag;
		}
		return str_replace( ' src=', ' data-ssc-delay-src=', $tag );
	}

	public static function delay_loader() {
		if ( self::skip() ) {
			return;
		}
		$printed = array_filter(
			self::$delayed,
			static function ( $handle ) {
				return wp_script_is( $handle, 'done' );
			}
		);
		if ( ! $printed ) {
			return;
		}
		?>
<script>
(function () {
	var events = ['scroll', 'pointerdown', 'pointermove', 'touchstart', 'keydown', 'focusin'];
	function load() {
		events.forEach(function (e) { window.removeEventListener(e, load, true); });
		document.querySelectorAll('script[data-ssc-delay-src]').forEach(function (old) {
			var s = document.createElement('script');
			s.src = old.getAttribute('data-ssc-delay-src');
			s.async = true;
			old.replaceWith(s);
		});
	}
	events.forEach(function (e) { window.addEventListener(e, load, { capture: true, passive: true, once: true }); });
})();
</script>
		<?php
	}
}
