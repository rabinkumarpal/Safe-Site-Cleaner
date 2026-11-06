<?php
/**
 * Loads the site's real pages. The same check runs before and after a cleanup, and a difference
 * is what triggers rollback. Rendered output catches what static scanning misses.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_SiteCheck {

	public static function urls( $max = 3000 ) {
		$urls  = array( home_url( '/' ) );
		$posts = (int) get_option( 'page_for_posts' );
		if ( $posts ) {
			$urls[] = get_permalink( $posts );
		}
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		$ids = get_posts(
			array(
				'post_type'        => array_values( $types ),
				'post_status'      => 'publish',
				'numberposts'      => $max,
				'fields'           => 'ids',
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'number' => 50, 'hide_empty' => true ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$l = get_term_link( $term );
					if ( is_string( $l ) ) {
						$urls[] = $l;
					}
				}
			}
		}
		$urls = array_filter( $urls, 'is_string' );
		$home = home_url( '/' );
		foreach ( $urls as $k => $u ) {
			if ( untrailingslashit( $u ) === untrailingslashit( $home ) ) {
				$urls[ $k ] = $home;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/** Non-ASCII filenames must be percent-encoded or the check silently tests nothing. */
	public static function encode( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return $url;
		}
		$path = implode( '/', array_map( 'rawurlencode', explode( '/', rawurldecode( isset( $p['path'] ) ? $p['path'] : '/' ) ) ) );
		return ( isset( $p['scheme'] ) ? $p['scheme'] : 'http' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	private static function args( $extra = array() ) {
		return array_merge(
			array(
				'timeout'    => 30,
				'redirection' => 5,
				'sslverify'  => false,
				'user-agent' => 'SafeSiteCleaner/' . SSC_VERSION,
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
			),
			$extra
		);
	}

	public static function fetch_page( $url ) {
		$bust = add_query_arg( 'ssc_check', wp_rand(), self::encode( $url ) );
		$res  = wp_remote_get( $bust, self::args() );
		if ( is_wp_error( $res ) ) {
			return array( 'status' => 0, 'title' => '', 'bytes' => 0, 'fatal' => false, 'shortcodes' => array(), 'assets' => array(), 'error' => $res->get_error_message() );
		}
		$body = wp_remote_retrieve_body( $res );
		return array(
			'status'     => (int) wp_remote_retrieve_response_code( $res ),
			'title'      => preg_match( '~<title[^>]*>(.*?)</title>~is', $body, $m ) ? trim( $m[1] ) : '',
			'bytes'      => strlen( $body ),
			'fatal'      => false !== stripos( $body, 'critical error' ) || false !== strpos( $body, 'Fatal error' ),
			'shortcodes' => self::raw_shortcodes( $body ),
			'assets'     => self::assets( $body ),
		);
	}

	/** Shortcode-looking text with attributes: `[gallery id=1]`, which should never reach a visitor. */
	public static function raw_shortcodes( $body ) {
		$text = preg_replace( '~<(script|style)\b.*?</\1>~is', '', $body );
		preg_match_all( '/\[([a-z][a-z0-9_-]{2,40})\s+[a-z0-9_-]+=[^\]\n]{0,120}\]/i', (string) $text, $m );
		return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
	}

	/** Every local wp-content file a page refers to, as a decoded site path. */
	public static function assets( $body ) {
		$body = str_replace( array( '\\/', '&amp;' ), array( '/', '&' ), $body );
		$out  = array();
		$rel  = wp_parse_url( content_url(), PHP_URL_PATH );
		$host = preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '~' );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		$pre  = '(?:(?:https?:)?//' . $host . ( $port ? ':' . (int) $port : '' ) . ')?';
		if ( preg_match_all( '~(?<![\w/.-])' . $pre . '(' . preg_quote( (string) $rel, '~' ) . '/[^"\'\s<>()\\\\,]+)~i', $body, $m ) ) {
			foreach ( $m[1] as $p ) {
				$p = rawurldecode( (string) strtok( $p, '?#' ) );
				// Files only: this skips PHP, folder URLs, and wildcard patterns from prefetch rules.
				if ( false === strpos( $p, '*' ) && ! preg_match( '/\.php$/i', $p ) && preg_match( '/\.[a-z0-9]{1,8}$/i', $p ) ) {
					$out[ $p ] = 1;
				}
			}
		}
		return array_keys( $out );
	}

	public static function head( $path ) {
		// WordPress's HTTP library undoes the encoding of non-ASCII URLs, so servers can wrongly
		// answer 404. For these names, check the file itself.
		if ( preg_match( '/[^\x20-\x7e]/', $path ) ) {
			$root = (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH );
			$rel  = 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : ltrim( $path, '/' );
			return is_file( ABSPATH . $rel ) ? 200 : 404;
		}
		$scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$port   = wp_parse_url( home_url(), PHP_URL_PORT );
		$url    = self::encode( $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path );
		$res    = wp_remote_head( $url, self::args( array( 'timeout' => 15 ) ) );
		$code   = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 405 === $code || 501 === $code ) {
			$res  = wp_remote_get( $url, self::args( array( 'timeout' => 15, 'limit_response_size' => 1024 ) ) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		}
		return $code;
	}

	/** Uses the current admin's cookies in this request only. They are never saved to disk. */
	public static function admin_screen_ok() {
		if ( ! is_user_logged_in() || ! wp_get_session_token() ) {
			return null;
		}
		// Browsers send the admin login cookie only to /wp-admin, so a REST request never has it.
		// Build a short-lived one for this one request. It is never saved.
		$uid     = get_current_user_id();
		$expire  = time() + 300;
		$token   = wp_get_session_token();
		$cookies = array();
		foreach ( array( AUTH_COOKIE => 'auth', SECURE_AUTH_COOKIE => 'secure_auth', LOGGED_IN_COOKIE => 'logged_in' ) as $name => $scheme ) {
			$cookies[] = new WP_Http_Cookie( array( 'name' => $name, 'value' => wp_generate_auth_cookie( $uid, $expire, $scheme, $token ) ) );
		}
		$res = wp_remote_get( admin_url( 'plugins.php' ), self::args( array( 'cookies' => $cookies, 'redirection' => 0 ) ) );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			return in_array( $code, array( 301, 302 ), true ) ? null : false;
		}
		$body = wp_remote_retrieve_body( $res );
		return false === stripos( $body, 'critical error' ) && false === strpos( $body, 'Fatal error' );
	}

	/**
	 * Lists only what got worse. Pre-existing faults are not blamed on the cleanup.
	 *
	 * @return string[] Plain-language problems.
	 */
	public static function compare( array $before, array $after, array $assets_before, array $assets_after, array $moved ) {
		$problems = array();
		$moved    = array_fill_keys( $moved, 1 );
		foreach ( $before as $url => $b ) {
			if ( ! isset( $after[ $url ] ) ) {
				continue;
			}
			$a = $after[ $url ];
			if ( 200 === (int) $b['status'] && 200 !== (int) $a['status'] ) {
				$problems[] = "Page now returns {$a['status']}: $url";
				continue;
			}
			if ( empty( $b['fatal'] ) && ! empty( $a['fatal'] ) ) {
				$problems[] = "Page now shows a PHP error: $url";
			}
			if ( '' !== $b['title'] && $a['title'] !== $b['title'] ) {
				$problems[] = "Page title changed: $url";
			}
			if ( $b['bytes'] > 2000 && $a['bytes'] < $b['bytes'] * 0.7 ) {
				$problems[] = "Page shrank by more than 30%: $url";
			}
			foreach ( array_diff( $a['shortcodes'], $b['shortcodes'] ) as $s ) {
				$problems[] = "Raw shortcode [$s] now shows on: $url";
			}
			foreach ( $a['assets'] as $path ) {
				if ( isset( $moved[ $path ] ) ) {
					$problems[] = "Page uses a file that was moved to quarantine: $path on $url";
				}
			}
		}
		foreach ( $assets_after as $path => $code ) {
			if ( 200 !== (int) $code && ( ! isset( $assets_before[ $path ] ) || 200 === (int) $assets_before[ $path ] ) ) {
				$problems[] = "File no longer loads ($code): $path";
			}
		}
		return $problems;
	}
}
