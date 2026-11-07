<?php
/**
 * Where a rule applies: page types, addresses, device and visitor. A rule without a "where"
 * applies everywhere, as before.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Where {

	const DEVICES  = array( 'all', 'phone', 'desktop' );
	const VISITORS = array( 'all', 'logged_out', 'logged_in' );
	const MAX      = 20;

	/** Set by a preview link, so a phone-only rule can be tested from a desktop browser. */
	public static $device = null;

	/**
	 * @param mixed $where Raw input.
	 * @return array|null Clean "where", or null when it means everywhere.
	 */
	public static function clean( $where ) {
		if ( null === $where || array() === $where ) {
			return null;
		}
		if ( ! is_array( $where ) ) {
			throw new InvalidArgumentException( '"where" must be a set of choices.' );
		}
		$out = array(
			'types'   => self::list_of( $where['types'] ?? array(), '~^(front|blog|archive|search|404|post_type:[a-z0-9_-]{1,20})$~', 'page type' ),
			'include' => self::patterns( $where['include'] ?? array() ),
			'exclude' => self::patterns( $where['exclude'] ?? array() ),
			'device'  => (string) ( $where['device'] ?? 'all' ),
			'visitor' => (string) ( $where['visitor'] ?? 'all' ),
		);
		if ( ! in_array( $out['device'], self::DEVICES, true ) ) {
			throw new InvalidArgumentException( 'Device must be all, phone or desktop.' );
		}
		if ( ! in_array( $out['visitor'], self::VISITORS, true ) ) {
			throw new InvalidArgumentException( 'Visitor must be all, logged_out or logged_in.' );
		}
		$everywhere = ! $out['types'] && ! $out['include'] && ! $out['exclude'] && 'all' === $out['device'] && 'all' === $out['visitor'];
		return $everywhere ? null : $out;
	}

	/** Whether the current request is somewhere the rule applies. Needs the main query to have run. */
	public static function matches( $where ) {
		if ( ! $where ) {
			return true;
		}
		if ( 'logged_in' === $where['visitor'] && ! is_user_logged_in() ) {
			return false;
		}
		if ( 'logged_out' === $where['visitor'] && is_user_logged_in() ) {
			return false;
		}
		if ( 'all' !== $where['device'] && ( 'phone' === $where['device'] ) !== self::is_phone() ) {
			return false;
		}
		if ( $where['types'] && ! array_filter( $where['types'], array( __CLASS__, 'is_type' ) ) ) {
			return false;
		}
		return self::path_ok( $where, self::request_path() );
	}

	/**
	 * A best guess from the address alone, for picking pages to test a rule on.
	 * Page types that need a real request to tell, such as archives, count as possible.
	 */
	public static function may_match_url( $where, $url ) {
		if ( ! $where ) {
			return true;
		}
		if ( ! self::path_ok( $where, self::path_of( $url ) ) ) {
			return false;
		}
		if ( ! $where['types'] ) {
			return true;
		}
		$front = untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) );
		$id    = $front ? 0 : url_to_postid( $url );
		foreach ( $where['types'] as $type ) {
			if ( ( 'front' === $type && $front ) || ( 0 === strpos( $type, 'post_type:' ) && $id && get_post_type( $id ) === substr( $type, 10 ) ) ) {
				return true;
			}
			if ( in_array( $type, array( 'blog', 'archive', 'search', '404' ), true ) && ! $front && ! $id ) {
				return true;
			}
		}
		return false;
	}

	/** Plain words for the screen. */
	public static function describe( $where ) {
		if ( ! $where ) {
			return 'Everywhere';
		}
		$labels = array_column( self::type_options(), 'label', 'value' );
		$parts  = array();
		if ( $where['types'] ) {
			$parts[] = 'On ' . implode( ', ', array_map( static function ( $t ) use ( $labels ) { return $labels[ $t ] ?? $t; }, $where['types'] ) );
		}
		if ( $where['include'] ) {
			$parts[] = 'only at ' . implode( ', ', $where['include'] );
		}
		if ( $where['exclude'] ) {
			$parts[] = 'never at ' . implode( ', ', $where['exclude'] );
		}
		if ( 'all' !== $where['device'] ) {
			$parts[] = 'phone' === $where['device'] ? 'phones only' : 'desktop only';
		}
		if ( 'all' !== $where['visitor'] ) {
			$parts[] = 'logged_in' === $where['visitor'] ? 'logged-in users only' : 'logged-out visitors only';
		}
		return ucfirst( implode( '; ', $parts ) );
	}

	/** Page types this site has, for the editor. */
	public static function type_options() {
		$out = array(
			array( 'value' => 'front', 'label' => 'Home page' ),
			array( 'value' => 'blog', 'label' => 'Blog page' ),
			array( 'value' => 'archive', 'label' => 'Archives (categories, tags, dates)' ),
			array( 'value' => 'search', 'label' => 'Search results' ),
			array( 'value' => '404', 'label' => 'Not-found page' ),
		);
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			$out[] = array( 'value' => 'post_type:' . $type->name, 'label' => 'Single ' . $type->labels->singular_name );
		}
		return $out;
	}

	public static function is_phone() {
		return null !== self::$device ? 'phone' === self::$device : wp_is_mobile();
	}

	private static function is_type( $type ) {
		switch ( $type ) {
			case 'front':
				return is_front_page();
			case 'blog':
				return is_home();
			case 'archive':
				return is_archive();
			case 'search':
				return is_search();
			case '404':
				return is_404();
		}
		return is_singular( substr( $type, 10 ) );
	}

	private static function path_ok( array $where, $path ) {
		if ( $where['include'] && ! self::any( $where['include'], $path ) ) {
			return false;
		}
		return ! ( $where['exclude'] && self::any( $where['exclude'], $path ) );
	}

	/** The request's address relative to the home page, always with a trailing slash. */
	private static function request_path() {
		return self::path_of( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	private static function path_of( $url ) {
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		return trailingslashit( '/' . ltrim( $path, '/' ) );
	}

	/** `*` matches anything, including nothing. A pattern without `*` matches that one address. */
	private static function any( array $patterns, $path ) {
		foreach ( $patterns as $p ) {
			$p     = false === strpos( $p, '*' ) ? trailingslashit( $p ) : $p;
			$regex = '~^' . str_replace( '\*', '.*', preg_quote( $p, '~' ) ) . '$~i';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	private static function patterns( $list ) {
		return self::list_of(
			array_map(
				static function ( $p ) {
					return '/' . ltrim( trim( (string) $p ), '/' );
				},
				array_filter( array_map( 'strval', (array) $list ), 'strlen' )
			),
			'#^/[A-Za-z0-9._~\-/*%]{0,200}$#',
			'address'
		);
	}

	private static function list_of( $list, $regex, $what ) {
		$list = array_values( array_unique( array_map( 'strval', (array) $list ) ) );
		if ( count( $list ) > self::MAX ) {
			throw new InvalidArgumentException( 'At most ' . self::MAX . ' of each kind of choice.' );
		}
		foreach ( $list as $item ) {
			if ( ! preg_match( $regex, $item ) ) {
				throw new InvalidArgumentException( 'Invalid ' . $what . ' "' . sanitize_text_field( $item ) . '".' );
			}
		}
		return $list;
	}
}
