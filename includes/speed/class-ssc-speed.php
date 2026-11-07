<?php
/**
 * Applies enabled speed rules to front-end pages. This is the only part of the plugin that runs for
 * visitors, so it reads the saved rules and nothing else: no scanning, and no hooks if no rule is on.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed {

	const PREVIEW      = 'ssc_speed_preview';
	const PREVIEW_TIME = 900;

	private static $skip = null;
	private static $deferred = null;
	private static $delayed = null;
	private static $here = array();

	public static function boot() {
		SSC_Speed_Recipes::watch();
		add_action( 'ssc_speed_trial_end', array( 'SSC_Speed_Rules', 'trial' ) );
		add_action( 'ssc_speed_vimeo_thumb', array( 'SSC_Speed_Html', 'fetch_vimeo_thumb' ) );
		if ( is_admin() || 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		if ( isset( $_GET[ self::PREVIEW ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::start_preview( (string) $_GET[ self::PREVIEW ] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
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
		if ( in_array( 'delay_script', $types, true ) ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'delay_script' ), 10, 2 );
			add_action( 'wp_footer', array( __CLASS__, 'delay_loader' ), 100 );
		}
		foreach ( SSC_Speed_Rules::enabled( 'recipe' ) as $rule ) {
			SSC_Speed_Recipes::start( $rule['recipe'] );
		}
		if ( array_intersect( SSC_Speed_Html::TYPES, $types ) ) {
			// Late, so the page's own redirects and other plugins' buffers start first.
			add_action( 'template_redirect', array( 'SSC_Speed_Html', 'start' ), PHP_INT_MAX );
		}
	}

	/**
	 * A link that shows a page as a logged-out visitor would see it, with these rules forced on or off.
	 * It is signed and short-lived, and it can't do more than choose between the site's own rules.
	 */
	public static function preview_url( $url, array $on, array $off, $device = null ) {
		$payload = rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'on' => $on, 'off' => $off, 'device' => $device, 'exp' => time() + self::PREVIEW_TIME ) ) ), '+/', '-_' ), '=' );
		return add_query_arg(
			array(
				self::PREVIEW => $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) ),
				'ssc_r'       => wp_rand(),
			),
			$url
		);
	}

	/** @return array|null The link's rule choices, or null if it isn't genuine or has expired. */
	public static function read_preview( $token ) {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
		return is_array( $data ) && ( $data['exp'] ?? 0 ) >= time() ? $data : null;
	}

	private static function start_preview( $token ) {
		$data = self::read_preview( $token );
		if ( ! $data ) {
			return;
		}
		SSC_Speed_Rules::force( array_map( 'strval', (array) $data['on'] ), array_map( 'strval', (array) $data['off'] ) );
		if ( in_array( $data['device'] ?? null, array( 'phone', 'desktop' ), true ) ) {
			SSC_Speed_Where::$device = $data['device'];
		}
		add_filter( 'determine_current_user', '__return_zero', PHP_INT_MAX );
		add_action( 'wp_head', array( __CLASS__, 'print_probe' ), -1000 );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Safe Site Cleaner preview' );
		add_action(
			'send_headers',
			static function () {
				nocache_headers();
				header( 'X-Robots-Tag: noindex, nofollow' );
			}
		);
	}

	public static function print_probe() {
		echo '<script>' . file_get_contents( SSC_DIR . 'assets/speed-probe.js' ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.WP.AlternativeFunctions
	}

	/**
	 * Enabled rules that apply to this request. Where a rule applies depends on the page, which isn't
	 * known until WordPress has run the main query, so nothing applies before that.
	 */
	public static function here( $type ) {
		if ( ! did_action( 'wp' ) ) {
			return array();
		}
		if ( ! isset( self::$here[ $type ] ) ) {
			self::$here[ $type ] = array_values(
				array_filter(
					SSC_Speed_Rules::enabled( $type ),
					static function ( $rule ) {
						return SSC_Speed_Where::matches( $rule['where'] ?? null );
					}
				)
			);
		}
		return self::$here[ $type ];
	}

	public static function recipe_here( $name ) {
		return in_array( $name, array_column( self::here( 'recipe' ), 'recipe' ), true );
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
		foreach ( self::here( 'preload_font' ) as $rule ) {
			foreach ( $rule['files'] as $file ) {
				$type = '.woff' === substr( $file, -5 ) ? 'font/woff' : 'font/woff2';
				printf( '<link rel="preload" href="%s" as="font" type="%s" crossorigin>' . "\n", esc_url( $base . $file ), esc_attr( $type ) );
			}
		}
	}

	public static function eager_first_image( $attr ) {
		if ( ! self::skip() && 'high' === ( $attr['fetchpriority'] ?? '' ) && self::here( 'eager_first_image' ) ) {
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
			foreach ( self::here( 'defer_style' ) as $rule ) {
				if ( ! $rule['except_pages'] || ! is_page( $rule['except_pages'] ) ) {
					self::$deferred = array_merge( self::$deferred, $rule['handles'] );
				}
			}
		}
		return self::$deferred;
	}

	private static function delayed_handles() {
		if ( ! did_action( 'wp' ) ) {
			return array();
		}
		if ( null === self::$delayed ) {
			self::$delayed = array();
			foreach ( self::here( 'delay_script' ) as $rule ) {
				self::$delayed = array_merge( self::$delayed, $rule['handles'] );
			}
		}
		return self::$delayed;
	}

	public static function delay_script( $tag, $handle ) {
		if ( self::skip() || ! in_array( $handle, self::delayed_handles(), true ) ) {
			return $tag;
		}
		return str_replace( ' src=', ' data-ssc-delay-src=', $tag );
	}

	public static function delay_loader() {
		if ( self::skip() ) {
			return;
		}
		$printed = array_filter(
			self::delayed_handles(),
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
