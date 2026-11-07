<?php
/**
 * Turns a page scan into fixes to try. Only well-known cases become rules. Everything else stays
 * advice, because guessing which CSS or JavaScript a site can spare is how sites get broken.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Suggest {

	const ICON_CSS = '~font-?awesome|ionicons|themify-icons|bootstrap-icons|material-?icons|fonts\.googleapis\.com~i';

	const WAIT_JS = '~google\.com/recaptcha|gstatic\.com/recaptcha|hcaptcha\.com|embed\.tawk\.to|client\.crisp\.chat|widget\.intercom\.io|static\.zdassets\.com~i';

	/**
	 * @param array $scan A result from SSC_Speed_Assets::scan().
	 * @return array Fixes and advice.
	 */
	public static function build( array $scan ) {
		$covered = self::covered();
		$rows    = $scan['assets'] ?? array();
		$hints   = $scan['hints'] ?? array();
		$out     = array();
		$others  = SSC_Speed_Others::active();
		$taken   = array();
		// A fix another plugin already does is left out and named instead, so two plugins don't fight.
		$offer = static function ( array $fix ) use ( &$out, &$taken, $others ) {
			$job   = SSC_Speed_Others::job_for( $fix['rule'] );
			$other = $job ? SSC_Speed_Others::doing( $job, $others ) : '';
			if ( $other && 'lazy_images' !== $job ) {
				$taken[ $other ][] = SSC_Speed_Others::JOBS[ $job ];
				return;
			}
			$out[] = $fix;
		};

		if ( ! empty( $hints['priority_lazy'] ) && ! $covered['eager_first_image'] ) {
			$offer( self::fix( 'low', 'Stop lazy-loading the main image', 'The image this page marks as most important waits for a script before it loads, which delays what visitors see first.', array( 'type' => 'eager_first_image', 'label' => 'Do not lazy-load the main image' ) ) );
		}
		$pro = array_filter(
			$rows,
			static function ( $r ) {
				return (bool) preg_match( '~^(sureforms-pro|srfm-pro)~', $r['handle'] );
			}
		);
		if ( $pro && defined( 'SRFM_PRO_VER' ) && ! in_array( 'sureforms_unused', $covered['recipes'], true ) ) {
			$offer( self::recipe( 'low', 'Drop unused SureForms Pro files', 'sureforms_unused' ) );
		}
		if ( ! empty( $hints['emoji'] ) && ! in_array( 'wp_emoji', $covered['recipes'], true ) ) {
			$offer( self::recipe( 'low', 'Remove the emoji script', 'wp_emoji' ) );
		}
		$handles = array_column( $rows, 'handle' );
		if ( in_array( 'jquery-migrate', $handles, true ) && ! in_array( 'jquery_migrate', $covered['recipes'], true ) ) {
			$offer( self::recipe( 'test', 'Stop loading jQuery Migrate', 'jquery_migrate' ) );
		}
		if ( in_array( 'dashicons', $handles, true ) && ! in_array( 'dashicons_visitors', $covered['recipes'], true ) ) {
			$offer( self::recipe( 'test', 'Stop loading Dashicons for visitors', 'dashicons_visitors' ) );
		}

		$icons = self::handles( $rows, 'css', self::ICON_CSS, array( 'blocking' ), $covered['handles'] );
		if ( $icons ) {
			$offer( self::fix( 'test', 'Load icon and web fonts without holding up the page', 'These stylesheets block the first paint. Text can show before they arrive, though it may flash or shift, so compare before applying.', array( 'type' => 'defer_style', 'label' => 'Defer icon and web font CSS', 'handles' => $icons ) ) );
		}
		$wait = self::handles( $rows, 'js', self::WAIT_JS, array( 'blocking', 'during_load', 'deferred' ), $covered['handles'] );
		if ( $wait ) {
			$offer( self::fix( 'test', 'Load captcha and chat scripts on first interaction', 'These third-party scripts are heavy and nobody needs them until they start using the page.', array( 'type' => 'delay_script', 'label' => 'Delay captcha and chat scripts', 'handles' => $wait ) ) );
		}
		if ( ! empty( $hints['fonts'] ) && ! $covered['preload_font'] ) {
			$offer( self::fix( 'test', 'Fetch the main fonts early', 'Headlines often wait for their font. Fetching the first fonts early can help, but preloading the wrong one wastes data, so compare before applying.', array( 'type' => 'preload_font', 'label' => 'Preload main fonts', 'files' => array_slice( $hints['fonts'], 0, 2 ) ) ) );
		}
		$notes = array();
		foreach ( $taken as $name => $jobs ) {
			$notes[] = array( 'level' => 'advice', 'title' => $name . ' already does some of this', 'why' => 'It ' . implode( ', ', array_unique( $jobs ) ) . ', so the matching fixes are not suggested here.' );
		}
		foreach ( $others as $p ) {
			if ( in_array( 'combine', $p['jobs'], true ) ) {
				$notes[] = array( 'level' => 'advice', 'title' => $p['name'] . ' combines files', 'why' => 'Combined files hide which plugin each script or stylesheet came from, so fewer fixes can be suggested. The weights above count the combined files under ' . $p['name'] . '.' );
			}
		}
		return array_merge( $out, $notes, self::advice( $scan ) );
	}

	private static function recipe( $level, $title, $name ) {
		return self::fix( $level, $title, SSC_Speed_Recipes::what( $name ), array( 'type' => 'recipe', 'label' => $title, 'recipe' => $name ) );
	}

	private static function fix( $level, $title, $why, array $rule ) {
		return array( 'level' => $level, 'title' => $title, 'why' => $why, 'rule' => $rule );
	}

	private static function advice( array $scan ) {
		$t   = $scan['totals'] ?? array();
		$out = array();
		$top = $scan['owners'][0] ?? null;
		if ( ( $t['response_ms'] ?? 0 ) > 1000 ) {
			$out[] = array( 'level' => 'advice', 'title' => 'The server is slow to respond', 'why' => 'It took ' . round( $t['response_ms'] / 1000, 1 ) . ' seconds without a page cache. A cache plugin or better hosting helps here more than any file change.' );
		}
		if ( $top && ! $top['third_party'] && $top['bytes'] > 500 * KB_IN_BYTES ) {
			$out[] = 'WordPress' === $top['owner']
				? array( 'level' => 'advice', 'title' => 'WordPress\'s own files are the heaviest part of this page', 'why' => 'They add ' . size_format( $top['bytes'] ) . '. Visitors rarely need this much from WordPress itself. Usually a plugin asks for it, for example the block editor scripts, so check the file list for which ones and which plugin might load them.' )
				: array( 'level' => 'advice', 'title' => $top['owner'] . ' is the heaviest part of this page', 'why' => 'It adds ' . size_format( $top['bytes'] ) . '. Check whether it has its own settings to load less, or whether this page really needs it.' );
		}
		if ( ( $t['third_party'] ?? 0 ) > 5 ) {
			$out[] = array( 'level' => 'advice', 'title' => $t['third_party'] . ' files come from other sites', 'why' => 'Each one depends on another company\'s server. Remove any tools you no longer use.' );
		}
		$out[] = array( 'level' => 'advice', 'title' => 'Look for plugins you no longer use', 'why' => 'The Clean tab finds them. Removing one is safer and helps more than tuning its files.' );
		return $out;
	}

	/** What enabled rules already handle. */
	private static function covered() {
		$c = array( 'eager_first_image' => false, 'recipes' => array(), 'preload_font' => false, 'handles' => array() );
		foreach ( SSC_Speed_Rules::enabled() as $r ) {
			if ( isset( $c[ $r['type'] ] ) ) {
				$c[ $r['type'] ] = true;
			}
			if ( 'recipe' === $r['type'] ) {
				$c['recipes'][] = $r['recipe'];
			}
			if ( ! empty( $r['handles'] ) ) {
				$c['handles'] = array_merge( $c['handles'], $r['handles'] );
			}
		}
		return $c;
	}

	private static function handles( array $rows, $kind, $pattern, array $states, array $covered ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( $kind === $r['kind'] && $r['handle'] && in_array( $r['state'], $states, true ) && ! in_array( $r['handle'], $covered, true )
				&& ( preg_match( $pattern, $r['url'] ) || preg_match( $pattern, $r['handle'] ) )
				&& preg_match( '~^[A-Za-z0-9_.-]{1,100}$~', $r['handle'] ) ) {
				$out[] = $r['handle'];
			}
		}
		return array_values( array_unique( $out ) );
	}
}
