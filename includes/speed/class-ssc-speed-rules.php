<?php
/**
 * Speed rules: what to do on this site, saved as data so the same code works on any site.
 * Everything is validated here, because rules can arrive as pasted JSON and end up in page markup.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Rules {

	const OPTION = 'ssc_speed_rules';
	const MAX    = 200;

	const WHAT = array(
		'preload_font'      => 'Fetches these fonts early, so headlines do not wait behind the page\'s other files.',
		'eager_first_image' => 'Stops the page builder lazy-loading the image WordPress marks as highest priority.',
		'defer_style'       => 'Loads these stylesheets without holding up the first paint.',
		'delay_script'      => 'Loads these scripts on the visitor\'s first scroll, pointer, touch, key or focus.',
		'recipe'            => 'Drops assets a plugin loads for features this site does not use.',
	);

	public static function all() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	/** Enabled rules, optionally of one type. */
	public static function enabled( $type = null ) {
		return array_values(
			array_filter(
				self::all(),
				static function ( $r ) use ( $type ) {
					return ! empty( $r['enabled'] ) && ( null === $type || $type === $r['type'] );
				}
			)
		);
	}

	/**
	 * Adds rules, replacing any with the same target. Nothing is saved if any row is invalid.
	 *
	 * @param array $rows Raw rules.
	 * @return int Rules imported.
	 */
	public static function import( array $rows ) {
		$by_id = array();
		foreach ( self::all() as $r ) {
			$by_id[ $r['id'] ] = $r;
		}
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				throw new InvalidArgumentException( 'Rule ' . ( $i + 1 ) . ' is not a rule.' );
			}
			try {
				$rule = self::clean( $row );
			} catch ( InvalidArgumentException $e ) {
				throw new InvalidArgumentException( 'Rule ' . ( $i + 1 ) . ': ' . $e->getMessage() );
			}
			$by_id[ $rule['id'] ] = $rule;
		}
		if ( count( $by_id ) > self::MAX ) {
			throw new InvalidArgumentException( 'Too many rules. The limit is ' . self::MAX . '.' );
		}
		self::save( array_values( $by_id ) );
		return count( $rows );
	}

	public static function set_enabled( $id, $on ) {
		$rules = self::all();
		$found = false;
		foreach ( $rules as &$r ) {
			if ( $id === $r['id'] ) {
				$r['enabled'] = (bool) $on;
				$found        = true;
			}
		}
		unset( $r );
		if ( ! $found ) {
			throw new InvalidArgumentException( 'Unknown rule.' );
		}
		self::save( $rules );
	}

	public static function delete( $id ) {
		$rules = self::all();
		$kept  = array_values(
			array_filter(
				$rules,
				static function ( $r ) use ( $id ) {
					return $id !== $r['id'];
				}
			)
		);
		if ( count( $kept ) === count( $rules ) ) {
			throw new InvalidArgumentException( 'Unknown rule.' );
		}
		self::save( $kept );
	}

	/** Rules for the admin screen, with plain-language notes that are never saved. */
	public static function view() {
		return array_map(
			static function ( $r ) {
				$r['what']    = self::WHAT[ $r['type'] ];
				$r['problem'] = self::problem( $r );
				return $r;
			},
			self::all()
		);
	}

	/** Only what can be known from the admin side. Handles exist only while a page renders. */
	private static function problem( array $r ) {
		if ( 'preload_font' === $r['type'] ) {
			$dir = wp_get_upload_dir()['basedir'];
			foreach ( $r['files'] as $file ) {
				if ( ! is_file( $dir . '/' . $file ) ) {
					return "Font file not found in uploads: $file";
				}
			}
		}
		if ( 'recipe' === $r['type'] && ! SSC_Speed_Recipes::available( $r['recipe'] ) ) {
			return 'The plugin this recipe is for is not active, so it does nothing.';
		}
		return '';
	}

	private static function save( array $rules ) {
		update_option( self::OPTION, $rules, true );
		// Cached pages would keep the old markup, so a switch would seem to do nothing.
		SSC_Store::flush_caches();
	}

	private static function clean( array $row ) {
		$type = (string) ( $row['type'] ?? '' );
		if ( ! isset( self::WHAT[ $type ] ) ) {
			throw new InvalidArgumentException( 'Unknown type "' . sanitize_text_field( $type ) . '".' );
		}
		$rule = array(
			'type'    => $type,
			'label'   => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			// Only a real true turns a rule on, so an imported rule never goes live by accident.
			'enabled' => true === ( $row['enabled'] ?? false ),
		);
		$key  = '';
		switch ( $type ) {
			case 'preload_font':
				$rule['files'] = self::files( $row['files'] ?? null );
				$key           = implode( ',', $rule['files'] );
				break;
			case 'defer_style':
				$rule['handles']      = self::handles( $row['handles'] ?? null );
				$rule['except_pages'] = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $row['except_pages'] ?? array() ) ) ) ) );
				$key                  = implode( ',', $rule['handles'] );
				break;
			case 'delay_script':
				$rule['handles'] = self::handles( $row['handles'] ?? null );
				$key             = implode( ',', $rule['handles'] );
				break;
			case 'recipe':
				$rule['recipe'] = (string) ( $row['recipe'] ?? '' );
				if ( ! SSC_Speed_Recipes::exists( $rule['recipe'] ) ) {
					throw new InvalidArgumentException( 'Unknown recipe "' . sanitize_text_field( $rule['recipe'] ) . '".' );
				}
				$key = $rule['recipe'];
				break;
		}
		$rule['id'] = 'r' . substr( md5( $type . '|' . $key ), 0, 10 );
		if ( '' === $rule['label'] ) {
			$rule['label'] = ucfirst( str_replace( '_', ' ', $type ) );
		}
		return $rule;
	}

	private static function handles( $list ) {
		$list = is_array( $list ) ? array_values( array_unique( array_map( 'strval', $list ) ) ) : array();
		if ( ! $list ) {
			throw new InvalidArgumentException( 'It needs at least one handle.' );
		}
		foreach ( $list as $handle ) {
			if ( ! preg_match( '~^[A-Za-z0-9_.-]{1,100}$~', $handle ) ) {
				throw new InvalidArgumentException( 'Invalid handle "' . sanitize_text_field( $handle ) . '".' );
			}
		}
		return $list;
	}

	/** Font paths relative to the uploads folder. */
	private static function files( $list ) {
		$list = is_array( $list ) ? array_values( array_unique( array_map( 'strval', $list ) ) ) : array();
		if ( ! $list ) {
			throw new InvalidArgumentException( 'It needs at least one font file.' );
		}
		foreach ( $list as $file ) {
			if ( false !== strpos( $file, '..' ) || ! preg_match( '~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*\.woff2?$~', $file ) ) {
				throw new InvalidArgumentException( 'Invalid font file "' . sanitize_text_field( $file ) . '". Use a .woff or .woff2 path inside uploads, such as 2025/06/font.woff2.' );
			}
		}
		return $list;
	}
}
