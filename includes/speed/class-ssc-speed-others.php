<?php
/**
 * Other speed plugins on the site and what they already do. Only settings confirmed from each plugin's
 * own source are read. For anything else the plugin is named but its features are left unknown.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Others {

	/** Jobs another plugin can share with a rule type or recipe. */
	const JOBS = array(
		'defer_css'   => 'loads CSS without blocking',
		'defer_js'    => 'defers JavaScript',
		'delay_js'    => 'delays JavaScript until the visitor interacts',
		'lazy_images' => 'lazy-loads images',
		'emoji'       => 'removes the emoji script',
		'combine'     => 'combines CSS or JavaScript files',
		'lazy_iframes' => 'lazy-loads embedded frames',
		'youtube_facade' => 'replaces YouTube embeds with a preview image',
		'guest'       => 'uses Guest Mode, which applies its strongest settings for first-time visitors',
	);

	/**
	 * @return array[] Each: name, jobs (keys of JOBS), and whether those jobs are known.
	 */
	/**
	 * @param array|null $rows A page's files, when known. A plugin's settings can't always say what a
	 *                         visitor gets, so what the page actually shows counts too.
	 */
	public static function active( $rows = null ) {
		$out = array();
		if ( defined( 'LSCWP_V' ) ) {
			// The filter only exists while LiteSpeed is loaded, which the constant guarantees.
			$conf = static function ( $key ) {
				return apply_filters( 'litespeed_conf', $key );
			};
			$jobs = array();
			if ( $conf( 'optm-css_async' ) ) {
				$jobs[] = 'defer_css';
			}
			$js = (int) $conf( 'optm-js_defer' );
			if ( 2 === $js ) {
				$jobs[] = 'delay_js';
			} elseif ( $js ) {
				$jobs[] = 'defer_js';
			}
			if ( $conf( 'media-lazy' ) ) {
				$jobs[] = 'lazy_images';
			}
			if ( $conf( 'optm-emoji_rm' ) ) {
				$jobs[] = 'emoji';
			}
			if ( $conf( 'optm-css_comb' ) || $conf( 'optm-js_comb' ) ) {
				$jobs[] = 'combine';
			}
			if ( $conf( 'media-iframe_lazy' ) ) {
				$jobs[] = 'lazy_iframes';
			}
			// LiteSpeed's own code lazy-loads images and frames for guests whenever Guest Mode is on.
			if ( $conf( 'guest_optm' ) ) {
				$jobs = array_merge( $jobs, array( 'guest', 'lazy_images', 'lazy_iframes' ) );
			}
			if ( self::page_shows_delay( 'LiteSpeed Cache', $rows ) ) {
				$jobs[] = 'delay_js';
			}
			$jobs = array_values( array_unique( $jobs ) );
			$out[] = array( 'name' => 'LiteSpeed Cache', 'jobs' => $jobs, 'known' => true );
		}
		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'get_rocket_option' ) ) {
			$jobs = array();
			if ( get_rocket_option( 'delay_js', 0 ) || self::page_shows_delay( 'WP Rocket', $rows ) ) {
				$jobs[] = 'delay_js';
			}
			// WP Rocket switches its YouTube preview off whenever iframe lazy-load is off.
			if ( get_rocket_option( 'lazyload_iframes', 0 ) ) {
				$jobs[] = 'lazy_iframes';
				if ( get_rocket_option( 'lazyload_youtube', 0 ) ) {
					$jobs[] = 'youtube_facade';
				}
			}
			$out[] = array( 'name' => 'WP Rocket', 'jobs' => $jobs, 'known' => false );
		}
		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
			$jobs  = array();
			$extra = (array) get_option( 'autoptimize_extra_settings', array() );
			if ( get_option( 'autoptimize_css_defer' ) ) {
				$jobs[] = 'defer_css';
			}
			if ( get_option( 'autoptimize_js' ) && get_option( 'autoptimize_js_defer_not_aggregate' ) ) {
				$jobs[] = 'defer_js';
			}
			if ( ! empty( $extra['autoptimize_extra_checkbox_field_1'] ) ) {
				$jobs[] = 'emoji';
			}
			if ( get_option( 'autoptimize_css' ) || ( get_option( 'autoptimize_js' ) && get_option( 'autoptimize_js_aggregate' ) ) ) {
				$jobs[] = 'combine';
			}
			$out[] = array( 'name' => 'Autoptimize', 'jobs' => $jobs, 'known' => false );
		}
		if ( class_exists( 'Perfmatters\CSS' ) ) {
			$out[] = array( 'name' => 'Perfmatters', 'jobs' => array(), 'known' => false );
		}
		return $out;
	}

	/** Scripts on a checked page that this plugin delays. Falls back to saved page checks. */
	private static function page_shows_delay( $name, $rows ) {
		if ( null === $rows && class_exists( 'SSC_Speed_Results' ) ) {
			$rows = array();
			foreach ( SSC_Speed_Results::all() as $page ) {
				$rows = array_merge( $rows, (array) ( $page['assets']['assets'] ?? array() ) );
			}
		}
		foreach ( (array) $rows as $r ) {
			if ( 'delayed' === ( $r['state'] ?? '' ) && $name === ( $r['by'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** The other plugin already doing a job, or ''. */
	public static function doing( $job, ?array $active = null ) {
		foreach ( null === $active ? self::active() : $active as $plugin ) {
			if ( in_array( $job, $plugin['jobs'], true ) ) {
				return $plugin['name'];
			}
		}
		return '';
	}

	/** The job another plugin may already do for a rule, or ''. */
	public static function job_for( array $rule ) {
		$map = array( 'defer_style' => 'defer_css', 'delay_script' => 'delay_js', 'eager_first_image' => 'lazy_images', 'lazy_iframes' => 'lazy_iframes' );
		if ( 'video_facade' === $rule['type'] ) {
			return in_array( 'youtube', $rule['providers'] ?? array(), true ) ? 'youtube_facade' : '';
		}
		if ( 'recipe' === $rule['type'] ) {
			return 'wp_emoji' === $rule['recipe'] ? 'emoji' : '';
		}
		return $map[ $rule['type'] ] ?? '';
	}

	/** Warnings for enabled rules that share a job with another plugin. */
	public static function overlaps() {
		$active = self::active();
		$out    = array();
		foreach ( SSC_Speed_Rules::enabled() as $rule ) {
			$job   = self::job_for( $rule );
			$other = $job ? self::doing( $job, $active ) : '';
			if ( ! $other ) {
				continue;
			}
			$out[] = 'eager_first_image' === $rule['type']
				? '"' . $rule['label'] . '": ' . $other . ' also lazy-loads images. If it lazy-loads the main image again, exclude that image in ' . $other . '.'
				: '"' . $rule['label'] . '": ' . $other . ' already ' . self::JOBS[ $job ] . '. Two plugins doing the same job can conflict, so keep one.';
		}
		return $out;
	}

	/** For the admin screen. */
	public static function view() {
		return array_map(
			static function ( $p ) {
				$p['does'] = array_map(
					static function ( $j ) {
						return SSC_Speed_Others::JOBS[ $j ];
					},
					$p['jobs']
				);
				return $p;
			},
			self::active()
		);
	}
}
