<?php
/**
 * Speed rules: what to do on this site, saved as data so the same code works on any site.
 * Everything is validated here, because rules can arrive as pasted JSON and end up in page markup.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Rules {

	const OPTION = 'ssc_speed_rules';
	const MAX    = 200;
	const TRIAL  = 'ssc_speed_trial';
	const TRIAL_TIME = 600;

	/** Rule IDs a signed preview link switches on or off, for that one request. */
	private static $force = array( 'on' => array(), 'off' => array() );

	const WHAT = array(
		'preload_font'      => 'Fetches these fonts early, so headlines do not wait behind the page\'s other files.',
		'eager_first_image' => 'Stops the page builder lazy-loading the image WordPress marks as highest priority.',
		'defer_style'       => 'Loads these stylesheets without holding up the first paint.',
		'delay_script'      => 'Loads these scripts on the visitor\'s first scroll, pointer, touch, key or focus.',
		'recipe'            => 'Drops assets a plugin loads for features this site does not use.',
		'lazy_iframes'      => 'Lets the browser wait to load embedded frames, such as maps and widgets, until a visitor scrolls near them.',
		'video_facade'      => 'Shows a preview with a play button instead of the embedded player. The real player loads when a visitor clicks.',
		'video_preload'     => 'Stops videos that only play when clicked from downloading before anyone presses play.',
		'video_poster_only' => 'Shows the poster image instead of a muted background video that plays by itself.',
	);

	public static function all() {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	public static function force( array $on, array $off ) {
		self::$force = array( 'on' => $on, 'off' => $off );
	}

	/** Enabled rules, optionally of one type. */
	public static function enabled( $type = null ) {
		$force = self::$force;
		return array_values(
			array_filter(
				self::all(),
				static function ( $r ) use ( $type, $force ) {
					$on = in_array( $r['id'], $force['on'], true ) || ( ! empty( $r['enabled'] ) && ! in_array( $r['id'], $force['off'], true ) );
					return $on && ( null === $type || $type === $r['type'] );
				}
			)
		);
	}

	public static function has( array $ids ) {
		return ! array_diff( $ids, array_column( self::all(), 'id' ) );
	}

	/** The running trial. One past its time undoes itself here, so a closed tab can't leave it on. */
	public static function trial() {
		$trial = get_option( self::TRIAL );
		if ( ! is_array( $trial ) ) {
			return null;
		}
		if ( $trial['expires'] < time() ) {
			self::undo_trial();
			return null;
		}
		return $trial;
	}

	/**
	 * Turns rules on for a limited time, unless the server's own check of the page gets worse.
	 *
	 * @return string[] Problems. When there are any, the rules were put back.
	 */
	public static function start_trial( array $ids, $url ) {
		if ( self::trial() ) {
			throw new RuntimeException( 'Another test is still running. Finish it first.' );
		}
		if ( ! $ids || ! self::has( $ids ) ) {
			throw new InvalidArgumentException( 'Unknown rule.' );
		}
		$rules = self::all();
		$was   = array();
		foreach ( $rules as &$r ) {
			if ( in_array( $r['id'], $ids, true ) ) {
				$was[ $r['id'] ] = ! empty( $r['enabled'] );
				$r['enabled']    = true;
			}
		}
		unset( $r );
		$before = array( $url => SSC_SiteCheck::fetch_page( $url ) );
		self::save( $rules );
		$after    = array( $url => SSC_SiteCheck::fetch_page( $url ) );
		$problems = SSC_SiteCheck::compare( $before, $after, array(), array(), array() );
		update_option( self::TRIAL, array( 'was' => $was, 'expires' => time() + self::TRIAL_TIME ), false );
		if ( $problems ) {
			self::undo_trial();
			return $problems;
		}
		wp_schedule_single_event( time() + self::TRIAL_TIME + 5, 'ssc_speed_trial_end' );
		return array();
	}

	public static function keep_trial() {
		delete_option( self::TRIAL );
		wp_clear_scheduled_hook( 'ssc_speed_trial_end' );
	}

	public static function undo_trial() {
		$trial = get_option( self::TRIAL );
		if ( ! is_array( $trial ) ) {
			return;
		}
		$rules = self::all();
		foreach ( $rules as &$r ) {
			if ( isset( $trial['was'][ $r['id'] ] ) ) {
				$r['enabled'] = $trial['was'][ $r['id'] ];
			}
		}
		unset( $r );
		delete_option( self::TRIAL );
		wp_clear_scheduled_hook( 'ssc_speed_trial_end' );
		self::save( $rules );
	}

	/**
	 * Adds rules, replacing any with the same target. Nothing is saved if any row is invalid.
	 *
	 * @param array $rows Raw rules.
	 * @return array IDs imported, and which of them are new.
	 */
	public static function import( array $rows ) {
		$by_id = array();
		foreach ( self::all() as $r ) {
			$by_id[ $r['id'] ] = $r;
		}
		$ids     = array();
		$created = array();
		foreach ( $rows as $i => $row ) {
			if ( ! is_array( $row ) ) {
				throw new InvalidArgumentException( 'Rule ' . ( $i + 1 ) . ' is not a rule.' );
			}
			try {
				$rule = self::clean( $row );
			} catch ( InvalidArgumentException $e ) {
				throw new InvalidArgumentException( 'Rule ' . ( $i + 1 ) . ': ' . $e->getMessage() );
			}
			$ids[] = $rule['id'];
			if ( ! isset( $by_id[ $rule['id'] ] ) ) {
				$created[] = $rule['id'];
			}
			$by_id[ $rule['id'] ] = $rule;
		}
		if ( count( $by_id ) > self::MAX ) {
			throw new InvalidArgumentException( 'Too many rules. The limit is ' . self::MAX . '.' );
		}
		self::save( array_values( $by_id ) );
		return array( 'ids' => $ids, 'created' => $created );
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

	public static function set_where( $id, $where ) {
		$where = SSC_Speed_Where::clean( $where );
		$rules = self::all();
		$found = false;
		foreach ( $rules as &$r ) {
			if ( $id === $r['id'] ) {
				unset( $r['where'] );
				if ( $where ) {
					$r['where'] = $where;
				}
				$found = true;
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
				$r['what']       = self::what( $r );
				$r['problem']    = self::problem( $r );
				$r['where_text'] = SSC_Speed_Where::describe( $r['where'] ?? null );
				$r['where_note'] = 'all' !== ( $r['where']['device'] ?? 'all' ) ? 'Phone or desktop rules only work if your page cache keeps separate copies for phones. Otherwise one cached copy is shown on every device. Check your cache plugin\'s mobile setting.' : '';
				return $r;
			},
			self::all()
		);
	}

	public static function what( array $rule ) {
		return 'recipe' === $rule['type'] ? SSC_Speed_Recipes::what( $rule['recipe'] ) : self::WHAT[ $rule['type'] ];
	}

	/** A page by its address relative to the home page, such as /contact/. */
	public static function page_for_path( $path ) {
		return (int) url_to_postid( home_url( '/' . ltrim( $path, '/' ) ) );
	}

	/** Only what can be known from the admin side. Handles exist only while a page renders. */
	public static function problem( array $r ) {
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

	public static function clean( array $row ) {
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
				// Profiles carry addresses, because page IDs differ between sites.
				$paths                = array_map( array( __CLASS__, 'page_for_path' ), array_map( 'strval', (array) ( $row['except_paths'] ?? array() ) ) );
				$rule['except_pages'] = array_values( array_unique( array_filter( array_merge( array_map( 'absint', (array) ( $row['except_pages'] ?? array() ) ), $paths ) ) ) );
				$key                  = implode( ',', $rule['handles'] );
				break;
			case 'delay_script':
				$rule['handles'] = self::handles( $row['handles'] ?? null );
				$key             = implode( ',', $rule['handles'] );
				break;
			case 'video_facade':
				$providers = array_values( array_intersect( array( 'youtube', 'vimeo', 'maps' ), array_map( 'strval', (array) ( $row['providers'] ?? array( 'youtube', 'vimeo' ) ) ) ) );
				if ( ! $providers ) {
					throw new InvalidArgumentException( 'Choose at least one of youtube, vimeo or maps.' );
				}
				$rule['providers'] = $providers;
				// Privacy-enhanced mode unless the rule says otherwise: YouTube sets no cookies before play.
				$rule['nocookie'] = false !== ( $row['nocookie'] ?? true );
				$key              = 'video';
				break;
			case 'recipe':
				$rule['recipe'] = (string) ( $row['recipe'] ?? '' );
				if ( ! SSC_Speed_Recipes::exists( $rule['recipe'] ) ) {
					throw new InvalidArgumentException( 'Unknown recipe "' . sanitize_text_field( $rule['recipe'] ) . '".' );
				}
				$key = $rule['recipe'];
				break;
		}
		// Where a rule applies isn't part of its ID, so changing it edits the same rule.
		$where = SSC_Speed_Where::clean( $row['where'] ?? null );
		if ( $where ) {
			$rule['where'] = $where;
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
