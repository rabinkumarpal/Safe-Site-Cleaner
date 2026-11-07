<?php
/**
 * Rules that change the finished page: embeds and videos. Theme, builder and plugin markup only exist
 * as one page, so this is the only place all of it can be reached. It runs only where such a rule
 * applies, and never inside scripts, templates or noscript, where markup is only text.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Html {

	const TYPES = array( 'lazy_iframes', 'video_facade', 'video_preload', 'video_poster_only' );

	const VIMEO_CACHE = 'ssc_vimeo_thumb_';

	private static $facade_used = false;

	/** Hooked late on template_redirect, when where a rule applies is known. */
	public static function start() {
		if ( SSC_Speed::skip() || is_feed() || is_robots() || is_embed() || is_trackback() || wp_doing_ajax() ) {
			return;
		}
		foreach ( self::TYPES as $type ) {
			if ( SSC_Speed::here( $type ) ) {
				ob_start( array( __CLASS__, 'rewrite' ) );
				return;
			}
		}
	}

	/** Output buffer callback. Anything that isn't an HTML page passes through untouched. */
	public static function rewrite( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '</body>' ) || ! self::is_html_response() ) {
			return $html;
		}
		try {
			return self::apply( $html );
		} catch ( Throwable $e ) {
			return $html; // A broken rewrite must never cost a visitor the page.
		}
	}

	public static function apply( $html ) {
		$kept = array();
		// Markup inside these is text, not page content, so it is set aside and put back unchanged.
		$html = (string) preg_replace_callback(
			'~<(script|noscript|textarea|template|style)\b[^>]*>.*?</\1>|<!--.*?-->~is',
			static function ( $m ) use ( &$kept ) {
				$kept[] = $m[0];
				return "\x1aSSC" . ( count( $kept ) - 1 ) . "\x1a";
			},
			$html
		);
		$facade = self::rule( 'video_facade' );
		$lazy   = (bool) self::rule( 'lazy_iframes' );
		if ( $facade || $lazy ) {
			$html = (string) preg_replace_callback(
				'~<iframe\b[^>]*>.*?</iframe>~is',
				static function ( $m ) use ( $facade, $lazy ) {
					return self::iframe( $m[0], $facade, $lazy );
				},
				$html
			);
		}
		if ( self::rule( 'video_poster_only' ) ) {
			$html = (string) preg_replace_callback( '~<video\b[^>]*>.*?</video>~is', array( __CLASS__, 'poster_only' ), $html );
		}
		if ( self::rule( 'video_preload' ) ) {
			$html = (string) preg_replace_callback( '~<video\b[^>]*>~i', array( __CLASS__, 'preload' ), $html );
		}
		if ( self::$facade_used ) {
			$html = (string) preg_replace( '~</body>~i', self::facade_assets() . '</body>', $html, 1 );
		}
		return (string) preg_replace_callback(
			"~\x1aSSC(\d+)\x1a~",
			static function ( $m ) use ( $kept ) {
				return $kept[ (int) $m[1] ];
			},
			$html
		);
	}

	/** Attributes of one tag, lower-case names, decoded values. */
	public static function attrs( $tag ) {
		$out = array();
		$tag = (string) preg_replace( '~^<[a-z0-9]+~i', '', (string) strtok( $tag, '>' ) );
		preg_match_all( '~([a-zA-Z_:][-\w:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?~', $tag, $m, PREG_SET_ORDER );
		foreach ( $m as $a ) {
			$out[ strtolower( $a[1] ) ] = html_entity_decode( $a[4] ?? ( $a[3] ?? ( $a[2] ?? '' ) ), ENT_QUOTES );
		}
		return $out;
	}

	/**
	 * What an iframe shows, from its address. Other lazy-loaders move the address into data-src or
	 * data-lazy-src, so those count too.
	 *
	 * @return array|null provider, id, embed URL.
	 */
	public static function provider( array $a ) {
		$src = '';
		foreach ( array( 'data-lazy-src', 'data-src', 'src' ) as $key ) {
			if ( ! empty( $a[ $key ] ) && 'about:blank' !== $a[ $key ] ) {
				$src = $a[ $key ];
				break;
			}
		}
		if ( preg_match( '~^(?:https?:)?//(?:www\.)?youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})~i', $src, $m ) ) {
			return array( 'provider' => 'youtube', 'id' => $m[1], 'src' => $src );
		}
		if ( preg_match( '~^(?:https?:)?//player\.vimeo\.com/video/(\d{1,15})~i', $src, $m ) ) {
			return array( 'provider' => 'vimeo', 'id' => $m[1], 'src' => $src );
		}
		if ( preg_match( '~^(?:https?:)?//(?:www\.|maps\.)?google\.[a-z.]{2,6}/maps(?:/embed|\?)~i', $src ) ) {
			return array( 'provider' => 'maps', 'id' => '', 'src' => $src );
		}
		return null;
	}

	private static function rule( $type ) {
		$rules = SSC_Speed::here( $type );
		return $rules ? $rules[0] : null;
	}

	private static function iframe( $tag, $facade, $lazy ) {
		$a = self::attrs( $tag );
		if ( isset( $a['data-ssc-keep'] ) || false !== strpos( ' ' . ( $a['class'] ?? '' ) . ' ', ' ssc-keep ' ) ) {
			return $tag;
		}
		$p = $facade ? self::provider( $a ) : null;
		if ( $p && in_array( $p['provider'], $facade['providers'], true ) ) {
			return self::facade( $a, $p, ! empty( $facade['nocookie'] ) );
		}
		if ( $lazy && ! isset( $a['loading'] ) ) {
			return (string) preg_replace( '~^<iframe\b~i', '<iframe loading="lazy"', $tag );
		}
		return $tag;
	}

	/** A box the size of the embed, with a play button. The real embed loads on click. */
	private static function facade( array $a, array $p, $nocookie ) {
		self::$facade_used = true;
		$src = $p['src'];
		if ( 0 === strpos( $src, '//' ) ) {
			$src = 'https:' . $src;
		}
		if ( 'youtube' === $p['provider'] && $nocookie ) {
			$src = (string) preg_replace( '~//(?:www\.)?youtube\.com/~i', '//www.youtube-nocookie.com/', $src );
		}
		if ( 'maps' !== $p['provider'] ) {
			$src = add_query_arg( 'autoplay', '1', $src );
		}
		$w      = (int) ( $a['width'] ?? 0 );
		$h      = (int) ( $a['height'] ?? 0 );
		$ratio  = $w > 0 && $h > 0 ? $w . '/' . $h : '16/9';
		$names  = array( 'youtube' => 'YouTube video', 'vimeo' => 'Vimeo video', 'maps' => 'map' );
		$title  = trim( (string) ( $a['title'] ?? '' ) );
		$label  = ( 'maps' === $p['provider'] ? 'Show map' : 'Play video' ) . ( '' !== $title ? ': ' . $title : '' );
		$thumb  = '';
		if ( 'youtube' === $p['provider'] ) {
			$thumb = 'https://i.ytimg.com/vi/' . $p['id'] . '/hqdefault.jpg';
		} elseif ( 'vimeo' === $p['provider'] ) {
			$thumb = self::vimeo_thumb( $p['id'] );
		}
		$style  = '--ssc-ratio:' . $ratio . ';' . ( $w > 0 && false === strpos( (string) ( $a['width'] ?? '' ), '%' ) ? 'max-width:' . $w . 'px;' : '' ) . ( $a['style'] ?? '' );
		$button = 'maps' === $p['provider']
			? '<button type="button" class="ssc-facade-map">' . esc_html( $label ) . '</button>'
			: '<button type="button" class="ssc-facade-play" aria-label="' . esc_attr( $label ) . '"><svg viewBox="0 0 68 48" width="68" height="48" aria-hidden="true"><path d="M66.5 7.7a8.5 8.5 0 0 0-6-6C55.3.3 34 .3 34 .3s-21.3 0-26.5 1.4a8.5 8.5 0 0 0-6 6C.1 12.9.1 24 .1 24s0 11.1 1.4 16.3a8.5 8.5 0 0 0 6 6C12.7 47.7 34 47.7 34 47.7s21.3 0 26.5-1.4a8.5 8.5 0 0 0 6-6C67.9 35.1 67.9 24 67.9 24s0-11.1-1.4-16.3z" fill="#212121" fill-opacity=".8"/><path d="M45 24 27 14v20" fill="#fff"/></svg></button>';
		return sprintf(
			'<div class="ssc-facade ssc-facade-%1$s %2$s" style="%3$s" data-ssc-src="%4$s" data-ssc-title="%5$s" data-ssc-allow="%6$s">%7$s%8$s</div>',
			esc_attr( $p['provider'] ),
			esc_attr( $a['class'] ?? '' ),
			esc_attr( $style ),
			esc_url( $src ),
			esc_attr( '' !== $title ? $title : $names[ $p['provider'] ] ),
			esc_attr( $a['allow'] ?? 'autoplay; encrypted-media; picture-in-picture; fullscreen' ),
			$thumb ? '<img src="' . esc_url( $thumb ) . '" alt="" loading="lazy" decoding="async">' : '',
			$button
		);
	}

	/** Small enough to print inline, so the placeholder costs no extra request. */
	private static function facade_assets() {
		return '<style>.ssc-facade{position:relative;display:block;width:100%;aspect-ratio:var(--ssc-ratio);background:#111;overflow:hidden}'
			// WordPress's embed block sizes frames with padding on the wrapper, but only where its block
			// styles load. The preview sizes itself instead, so that padding is hidden only when present.
			. '.wp-block-embed__wrapper:has(>.ssc-facade)::before{display:none}'
			. '.ssc-facade img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
			. '.ssc-facade button{position:absolute;inset:0;margin:auto;cursor:pointer;border:0;background:none;padding:0}'
			. '.ssc-facade-play{width:68px;height:48px}.ssc-facade-play:focus-visible,.ssc-facade-map:focus-visible{outline:3px solid #fff;outline-offset:2px}'
			. '.ssc-facade-map{width:max-content;height:max-content;padding:10px 18px;background:#fff;color:#111;border-radius:4px;font:600 15px/1.2 sans-serif}'
			. '.ssc-facade iframe{position:absolute;inset:0;width:100%;height:100%;border:0}</style>'
			. '<script>document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".ssc-facade button");if(!b){return;}'
			. 'var f=b.parentNode,i=document.createElement("iframe");i.src=f.getAttribute("data-ssc-src");i.title=f.getAttribute("data-ssc-title");'
			. 'i.setAttribute("allow",f.getAttribute("data-ssc-allow"));i.setAttribute("allowfullscreen","");f.textContent="";f.appendChild(i);i.focus();});</script>';
	}

	/** Thumbnails come from Vimeo's own API, fetched in the background so no visitor waits for it. */
	private static function vimeo_thumb( $id ) {
		$cached = get_transient( self::VIMEO_CACHE . $id );
		if ( false === $cached && ! wp_next_scheduled( 'ssc_speed_vimeo_thumb', array( $id ) ) ) {
			wp_schedule_single_event( time(), 'ssc_speed_vimeo_thumb', array( $id ) );
		}
		return is_string( $cached ) ? $cached : '';
	}

	public static function fetch_vimeo_thumb( $id ) {
		$id  = preg_replace( '~\D~', '', (string) $id );
		$res = wp_safe_remote_get( 'https://vimeo.com/api/oembed.json?width=640&url=' . rawurlencode( 'https://vimeo.com/' . $id ), array( 'timeout' => 5 ) );
		$url = '';
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			$url  = (string) ( $data['thumbnail_url'] ?? '' );
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
			// Only Vimeo's own image server, so a changed API answer can't put another site's image here.
			if ( 0 !== strpos( $url, 'https://' ) || ! preg_match( '~(^|\.)vimeocdn\.com$~', $host ) ) {
				$url = '';
			}
		}
		set_transient( self::VIMEO_CACHE . $id, $url, $url ? WEEK_IN_SECONDS : DAY_IN_SECONDS );
	}

	/** Videos that only play when asked don't need to download first. */
	public static function preload( $m ) {
		$a = self::attrs( $m[0] );
		if ( isset( $a['autoplay'] ) || isset( $a['data-ssc-keep'] ) ) {
			return $m[0];
		}
		// Without a poster, "none" would leave a blank box, so the first frame's details still load.
		$want = isset( $a['poster'] ) && '' !== $a['poster'] ? 'none' : 'metadata';
		$tag  = (string) preg_replace( '~\s+preload(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?~i', '', $m[0] );
		return (string) preg_replace( '~^<video\b~i', '<video preload="' . $want . '"', $tag );
	}

	/** Muted background videos become their poster image. Only those, since they carry no sound or controls. */
	public static function poster_only( $m ) {
		$a = self::attrs( $m[0] );
		if ( ! isset( $a['autoplay'], $a['muted'] ) || isset( $a['controls'] ) || empty( $a['poster'] ) || isset( $a['data-ssc-keep'] ) ) {
			return $m[0];
		}
		return sprintf(
			'<img src="%s" alt="" class="%s" style="%s" decoding="async"%s>',
			esc_url( $a['poster'] ),
			esc_attr( $a['class'] ?? '' ),
			esc_attr( 'object-fit:cover;' . ( $a['style'] ?? '' ) ),
			isset( $a['width'], $a['height'] ) ? ' width="' . (int) $a['width'] . '" height="' . (int) $a['height'] . '"' : ''
		);
	}

	private static function is_html_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}
		return true;
	}
}
