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
	);

	/**
	 * @return array[] Each: name, jobs (keys of JOBS), and whether those jobs are known.
	 */
	public static function active() {
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
			$out[] = array( 'name' => 'LiteSpeed Cache', 'jobs' => $jobs, 'known' => true );
		}
		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'get_rocket_option' ) ) {
			$out[] = array( 'name' => 'WP Rocket', 'jobs' => get_rocket_option( 'delay_js', 0 ) ? array( 'delay_js' ) : array(), 'known' => false );
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
		$map = array( 'defer_style' => 'defer_css', 'delay_script' => 'delay_js', 'eager_first_image' => 'lazy_images' );
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
