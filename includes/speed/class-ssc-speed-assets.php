<?php
/**
 * Lists the CSS and JavaScript one page loads, how each loads, how big it is and who it belongs to.
 * It reads the page a logged-out visitor gets, so it adds no code to the site's front end.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Assets {

	const MAX_ASSETS  = 150;
	const MAX_REMOTE  = 20;
	const REMOTE_TIME = 15;

	/**
	 * @param string $url A page on this site.
	 * @return array Asset list and totals.
	 */
	public static function scan( $url ) {
		$page = SSC_SiteCheck::fetch_html( $url );
		if ( ! empty( $page['error'] ) ) {
			throw new RuntimeException( 'The page could not be loaded: ' . $page['error'] );
		}
		if ( 200 !== $page['status'] ) {
			throw new RuntimeException( 'The page returned status ' . $page['status'] . ', so it was not checked.' );
		}
		$found = self::parse( $page['body'] );
		$rows  = self::describe( $found['assets'] );
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['bytes'] <=> (int) $a['bytes'];
			}
		);
		return array(
			'at'     => time(),
			'totals' => self::totals( $rows ) + array(
				'html_bytes'       => strlen( $page['body'] ),
				'inline_css_bytes' => $found['inline_css'],
				'inline_js_bytes'  => $found['inline_js'],
				'images'           => $found['images'],
				'lazy_images'      => $found['lazy_images'],
				'response_ms'      => $page['ms'],
			),
			'hints'  => array(
				'priority_lazy' => $found['priority_lazy'],
				'emoji'         => $found['emoji'],
				'fonts'         => self::upload_fonts( $found['fonts'] ),
			),
			'owners' => self::owners( $rows ),
			'assets' => array_slice( $rows, 0, self::MAX_ASSETS ),
		);
	}

	/**
	 * Reads load-related tags from a page's HTML.
	 *
	 * @param string $html Page HTML.
	 * @return array
	 */
	public static function parse( $html ) {
		$html       = (string) preg_replace( '~<!--.*?-->~s', '', (string) $html );
		$emoji      = false !== strpos( $html, '_wpemojiSettings' );
		$html       = (string) preg_replace( '~<noscript\b.*?</noscript>~is', '', $html );
		$inline_css = 0;
		$inline_js  = 0;
		$fonts      = array();
		// Inline code is measured and then removed, because it can contain text that looks like a tag.
		$html = (string) preg_replace_callback(
			'~(<script\b[^>]*>)(.*?)</script>~is',
			static function ( $m ) use ( &$inline_js ) {
				$inline_js += strlen( $m[2] );
				return $m[1];
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'~<style\b[^>]*>(.*?)</style>~is',
			static function ( $m ) use ( &$inline_css, &$fonts ) {
				$inline_css += strlen( $m[1] );
				if ( preg_match_all( '~@font-face\s*\{[^}]*?url\(\s*[\'"]?([^\'")\s]+\.woff2)~i', $m[1], $found ) ) {
					$fonts = array_merge( $fonts, $found[1] );
				}
				return '';
			},
			$html
		);
		$head_end = stripos( $html, '</head>' );
		$assets   = array();
		preg_match_all( '~<(link|script)\b[^>]*>~i', $html, $tags, PREG_OFFSET_CAPTURE );
		$preloaded = array();
		foreach ( $tags[0] as $i => $match ) {
			$attrs   = self::attrs( $match[0] );
			if ( 'preload' === strtolower( $attrs['rel'] ?? '' ) && 'font' === strtolower( $attrs['as'] ?? '' ) ) {
				$preloaded[] = basename( (string) strtok( $attrs['href'] ?? '', '?#' ) );
			}
			$in_head = false === $head_end || $match[1] < $head_end;
			$row     = 'link' === strtolower( $tags[1][ $i ][0] ) ? self::link_row( $attrs ) : self::script_row( $attrs, $in_head );
			if ( $row && ! isset( $assets[ $row['url'] ] ) ) {
				$assets[ $row['url'] ] = $row;
			}
		}
		$images = preg_match_all( '~<img\b[^>]*>~i', $html, $imgs );
		$lazy   = 0;
		$late   = 0;
		foreach ( $imgs[0] as $img ) {
			$a = self::attrs( $img );
			if ( 'lazy' === strtolower( $a['loading'] ?? '' ) ) {
				++$lazy;
				$late += 'high' === strtolower( $a['fetchpriority'] ?? '' ) ? 1 : 0;
			}
		}
		$fonts = array_filter(
			array_unique( $fonts ),
			static function ( $font ) use ( $preloaded ) {
				return ! in_array( basename( (string) strtok( $font, '?#' ) ), $preloaded, true );
			}
		);
		return array(
			'assets'      => array_values( $assets ),
			'inline_css'  => $inline_css,
			'inline_js'   => $inline_js,
			'images'      => (int) $images,
			'lazy_images' => $lazy,
			'priority_lazy' => $late,
			'emoji'       => $emoji,
			'fonts'       => array_values( $fonts ),
		);
	}

	/** Font paths relative to the uploads folder. Rules can only preload fonts from there. */
	private static function upload_fonts( array $fonts ) {
		$base = (string) preg_replace( '~^https?:~', '', wp_get_upload_dir()['baseurl'] ) . '/';
		$out  = array();
		foreach ( $fonts as $font ) {
			$bare = (string) preg_replace( '~^https?:~', '', (string) strtok( $font, '?#' ) );
			if ( 0 === strpos( $bare, $base ) ) {
				$out[] = substr( $bare, strlen( $base ) );
			}
		}
		$out = array_values( array_unique( $out ) );
		// Body text and headlines use these weights far more than any other, so they are the ones worth fetching early.
		usort(
			$out,
			static function ( $a, $b ) {
				$rank = static function ( $file ) {
					return preg_match( '~regular|bold~i', basename( $file ) ) && ! preg_match( '~italic|semi|extra~i', basename( $file ) ) ? 0 : 1;
				};
				return $rank( $a ) <=> $rank( $b );
			}
		);
		return $out;
	}

	private static function attrs( $tag ) {
		$out = array();
		$tag = (string) preg_replace( '~^<[a-z]+~i', '', $tag );
		preg_match_all( '~([a-zA-Z_:][-\w:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $tag, $m, PREG_SET_ORDER );
		foreach ( $m as $a ) {
			$out[ strtolower( $a[1] ) ] = html_entity_decode( $a[4] ?? ( $a[3] ?? ( $a[2] ?? '' ) ), ENT_QUOTES );
		}
		return $out;
	}

	private static function link_row( array $a ) {
		$rel = strtolower( $a['rel'] ?? '' );
		$as  = strtolower( $a['as'] ?? '' );
		if ( empty( $a['href'] ) || ( 'stylesheet' !== $rel && ! ( 'preload' === $rel && 'style' === $as ) ) ) {
			return null;
		}
		$media = strtolower( trim( $a['media'] ?? 'all' ) );
		if ( 'preload' === $rel || ( 'print' === $media && isset( $a['onload'] ) ) ) {
			$state = 'deferred';
		} elseif ( in_array( $media, array( '', 'all', 'screen' ), true ) ) {
			$state = 'blocking';
		} else {
			$state = 'conditional';
		}
		return array( 'kind' => 'css', 'url' => $a['href'], 'handle' => (string) preg_replace( '~-css$~', '', $a['id'] ?? '' ), 'state' => $state );
	}

	private static function script_row( array $a, $in_head ) {
		$type = strtolower( trim( $a['type'] ?? '' ) );
		if ( '' !== $type && 'module' !== $type && false === strpos( $type, 'javascript' ) && ! isset( $a['data-ssc-delay-src'] ) ) {
			return null;
		}
		$url = $a['data-ssc-delay-src'] ?? ( $a['src'] ?? '' );
		if ( '' === $url ) {
			return null;
		}
		if ( isset( $a['data-ssc-delay-src'] ) ) {
			$state = 'delayed';
		} elseif ( isset( $a['async'] ) || isset( $a['defer'] ) || 'module' === $type ) {
			$state = 'deferred';
		} else {
			$state = $in_head ? 'blocking' : 'during_load';
		}
		return array( 'kind' => 'js', 'url' => $url, 'handle' => (string) preg_replace( '~-js$~', '', $a['id'] ?? '' ), 'state' => $state );
	}

	private static function describe( array $assets ) {
		$home     = wp_parse_url( home_url(), PHP_URL_HOST );
		$deadline = time() + self::REMOTE_TIME;
		$remote   = 0;
		$rows     = array();
		foreach ( $assets as $row ) {
			$url = $row['url'];
			if ( 0 === strpos( $url, '//' ) ) {
				$url = wp_parse_url( home_url(), PHP_URL_SCHEME ) . ':' . $url;
			} elseif ( 0 === strpos( $url, '/' ) ) {
				$url = home_url( $url );
			}
			$host        = wp_parse_url( $url, PHP_URL_HOST );
			$third_party = $host && $host !== $home;
			$file        = $third_party ? null : self::local_file( $url );
			$bytes       = $file ? (int) filesize( $file ) : null;
			$exact       = null !== $bytes;
			if ( null === $bytes && $remote < self::MAX_REMOTE && time() < $deadline ) {
				++$remote;
				$bytes = self::remote_size( $url, $third_party );
			}
			$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
			$rows[] = array(
				'kind'        => $row['kind'],
				'handle'      => $row['handle'],
				'url'         => $url,
				'file'        => basename( $path ) ?: (string) $host,
				'owner'       => $third_party ? (string) $host : self::owner( $url ),
				'third_party' => (bool) $third_party,
				'state'       => $row['state'],
				'bytes'       => $bytes,
				'exact'       => $exact,
			);
		}
		return $rows;
	}

	/** Maps a URL on this site to a file inside it, or null. */
	private static function local_file( $url ) {
		$path = rawurldecode( (string) strtok( $url, '?#' ) );
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}
		foreach ( array( array( content_url(), WP_CONTENT_DIR ), array( includes_url(), ABSPATH . WPINC ), array( site_url(), ABSPATH ) ) as $map ) {
			$base = rawurldecode( untrailingslashit( preg_replace( '~^https?:~', '', $map[0] ) ) );
			$bare = preg_replace( '~^https?:~', '', $path );
			if ( 0 === strpos( $bare, $base . '/' ) ) {
				$file = untrailingslashit( $map[1] ) . substr( $bare, strlen( $base ) );
				return is_file( $file ) ? $file : null;
			}
		}
		return null;
	}

	/** Size the server reports, usually after compression. Null when it doesn't say. */
	private static function remote_size( $url, $third_party ) {
		$args = array(
			'timeout'     => 4,
			'redirection' => 3,
			'sslverify'   => false,
			'user-agent'  => 'SafeSiteCleaner/' . SSC_VERSION,
		);
		// Addresses in third-party markup could point inside the server's network, so those use the safe call.
		$res  = $third_party ? wp_safe_remote_head( $url, array( 'sslverify' => true ) + $args ) : wp_remote_head( $url, $args );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$len = wp_remote_retrieve_header( $res, 'content-length' );
		return is_numeric( $len ) ? (int) $len : null;
	}

	private static function owner( $url ) {
		$bare = preg_replace( '~^https?:~', '', (string) strtok( $url, '?#' ) );
		foreach ( array( 'plugin' => plugins_url(), 'mu' => content_url( 'mu-plugins' ), 'theme' => get_theme_root_uri(), 'uploads' => wp_get_upload_dir()['baseurl'] ) as $kind => $base ) {
			$base = preg_replace( '~^https?:~', '', untrailingslashit( $base ) ) . '/';
			if ( 0 !== strpos( $bare, $base ) ) {
				continue;
			}
			$slug = strtok( substr( $bare, strlen( $base ) ), '/' );
			if ( 'plugin' === $kind ) {
				return self::plugin_name( $slug );
			}
			if ( 'theme' === $kind ) {
				$theme = wp_get_theme( $slug );
				return $theme->exists() ? $theme->get( 'Name' ) . ' (theme)' : $slug . ' (theme)';
			}
			return 'mu' === $kind ? 'Must-use plugins' : 'Uploads folder';
		}
		return 'WordPress';
	}

	private static function plugin_name( $slug ) {
		static $names = null;
		if ( null === $names ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$names = array();
			foreach ( get_plugins() as $file => $data ) {
				$names[ dirname( $file ) ] = $data['Name'];
			}
		}
		return $names[ $slug ] ?? $slug;
	}

	private static function totals( array $rows ) {
		$t = array( 'files' => count( $rows ), 'css_bytes' => 0, 'js_bytes' => 0, 'blocking' => 0, 'blocking_bytes' => 0, 'third_party' => 0, 'unknown_sizes' => 0 );
		foreach ( $rows as $r ) {
			$t[ $r['kind'] . '_bytes' ] += (int) $r['bytes'];
			if ( 'blocking' === $r['state'] ) {
				++$t['blocking'];
				$t['blocking_bytes'] += (int) $r['bytes'];
			}
			$t['third_party']   += $r['third_party'] ? 1 : 0;
			$t['unknown_sizes'] += null === $r['bytes'] ? 1 : 0;
		}
		return $t;
	}

	private static function owners( array $rows ) {
		$by = array();
		foreach ( $rows as $r ) {
			$o = $r['owner'];
			if ( ! isset( $by[ $o ] ) ) {
				$by[ $o ] = array( 'owner' => $o, 'bytes' => 0, 'files' => 0, 'third_party' => $r['third_party'] );
			}
			$by[ $o ]['bytes'] += (int) $r['bytes'];
			++$by[ $o ]['files'];
		}
		usort(
			$by,
			static function ( $a, $b ) {
				return $b['bytes'] <=> $a['bytes'];
			}
		);
		return array_values( $by );
	}
}
