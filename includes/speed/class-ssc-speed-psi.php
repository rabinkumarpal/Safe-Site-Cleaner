<?php
/**
 * Speed score from Google PageSpeed Insights. Failure messages are written here rather than passed
 * on, because the API key is part of the request address and must never reach the screen.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Psi {

	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const KEY      = 'ssc_speed_psi_key';

	const LAB = array(
		'largest-contentful-paint' => 'Largest Contentful Paint',
		'first-contentful-paint'   => 'First Contentful Paint',
		'total-blocking-time'      => 'Total Blocking Time',
		'cumulative-layout-shift'  => 'Cumulative Layout Shift',
		'speed-index'              => 'Speed Index',
	);

	const FIELD = array(
		'LARGEST_CONTENTFUL_PAINT_MS'  => array( 'Largest Contentful Paint', 'ms' ),
		'INTERACTION_TO_NEXT_PAINT'    => array( 'Interaction to Next Paint', 'ms' ),
		'CUMULATIVE_LAYOUT_SHIFT_SCORE' => array( 'Cumulative Layout Shift', 'cls' ),
	);

	public static function has_key() {
		return '' !== (string) get_option( self::KEY, '' );
	}

	public static function save_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			delete_option( self::KEY );
			return;
		}
		if ( ! preg_match( '~^[A-Za-z0-9_-]{20,100}$~', $key ) ) {
			throw new InvalidArgumentException( 'That does not look like a Google API key.' );
		}
		update_option( self::KEY, $key, false );
	}

	/** Checks the saved key with a real request. A public page is used, so this works on local sites. */
	public static function test_key() {
		if ( ! self::has_key() ) {
			throw new RuntimeException( 'No key is saved.' );
		}
		self::run( 'https://example.com/', 'mobile' );
	}

	/** Google's test can only load addresses the public internet can reach. */
	public static function reachable( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || false === strpos( $host, '.' ) || preg_match( '~\.(local|localhost|test|invalid|example|internal|lan)$~', $host ) ) {
			$public = false;
		} elseif ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$public = (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		} else {
			$public = true;
		}
		/** Filters whether Google can load this address, for setups the guess above gets wrong. */
		return (bool) apply_filters( 'ssc_speed_psi_reachable', $public, $url );
	}

	/**
	 * @param string $url      Page on this site.
	 * @param string $strategy mobile or desktop.
	 * @return array Score, metrics and fixes.
	 */
	public static function run( $url, $strategy ) {
		$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';
		if ( ! self::reachable( $url ) ) {
			throw new RuntimeException( 'Google cannot reach this address from the internet, so it has no score to give. Check the live site instead.' );
		}
		$key   = (string) get_option( self::KEY, '' );
		$query = 'url=' . rawurlencode( $url ) . '&strategy=' . $strategy . '&category=performance';
		if ( '' !== $key ) {
			$query .= '&key=' . rawurlencode( $key );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$res = wp_remote_get( self::ENDPOINT . '?' . $query, array( 'timeout' => 90, 'limit_response_size' => 16 * MB_IN_BYTES ) );
		if ( is_wp_error( $res ) ) {
			throw new RuntimeException( 'Google PageSpeed Insights did not respond in time. Please try again.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 429 === $code ) {
			throw new RuntimeException( '' === $key ? 'Google\'s shared PageSpeed quota is used up right now. Add a free API key in the Settings tab to get your own.' : 'The daily quota on your PageSpeed API key is used up.' );
		}
		if ( in_array( $code, array( 400, 401, 403 ), true ) && '' !== $key && false !== stripos( (string) wp_remote_retrieve_body( $res ), 'api key' ) ) {
			throw new RuntimeException( 'Google rejected the PageSpeed API key.' );
		}
		if ( 200 !== $code ) {
			throw new RuntimeException( 'Google could not load this page. It may be blocked by a firewall or need a login.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$out  = is_array( $data ) ? self::read( $data ) : null;
		if ( ! $out ) {
			throw new RuntimeException( 'Google returned no speed measurement for this page.' );
		}
		return $out;
	}

	/** Keeps only what the screen shows. Whole responses are several MB. */
	public static function read( array $data ) {
		$lh     = (array) ( $data['lighthouseResult'] ?? array() );
		$audits = (array) ( $lh['audits'] ?? array() );
		if ( ! isset( $lh['categories']['performance']['score'] ) ) {
			return null;
		}
		$lab = array();
		foreach ( self::LAB as $id => $name ) {
			if ( ! empty( $audits[ $id ]['displayValue'] ) ) {
				$lab[] = array(
					'id'    => $id,
					'name'  => $name,
					'value' => wp_strip_all_tags( (string) $audits[ $id ]['displayValue'] ),
					'band'  => self::band( $audits[ $id ]['score'] ?? null ),
				);
			}
		}
		return array(
			'at'    => time(),
			'score' => (int) round( 100 * (float) $lh['categories']['performance']['score'] ),
			'lab'   => $lab,
			'field' => self::field( (array) ( $data['loadingExperience'] ?? array() ) ),
			'fixes' => self::fixes( $audits ),
			'lcp'   => self::lcp_element( $audits ),
		);
	}

	/** The element Google measured as the largest, by its CSS selector. */
	private static function lcp_element( array $audits ) {
		foreach ( array( 'lcp-breakdown-insight', 'lcp-discovery-insight', 'largest-contentful-paint-element' ) as $key ) {
			foreach ( (array) ( $audits[ $key ]['details']['items'] ?? array() ) as $item ) {
				$node = ( $item['type'] ?? '' ) === 'node' ? $item : ( $item['node'] ?? null );
				if ( is_array( $node ) && ! empty( $node['selector'] ) ) {
					return array( 'selector' => substr( (string) $node['selector'], 0, 300 ), 'label' => wp_strip_all_tags( substr( (string) ( $node['nodeLabel'] ?? '' ), 0, 120 ) ) );
				}
			}
		}
		return null;
	}

	private static function band( $score ) {
		if ( ! is_numeric( $score ) ) {
			return '';
		}
		return $score >= 0.9 ? 'good' : ( $score >= 0.5 ? 'average' : 'poor' );
	}

	/** Real-visitor numbers. Google only has these for pages or sites with enough traffic. */
	private static function field( array $exp ) {
		$rows = array();
		foreach ( self::FIELD as $id => $meta ) {
			$m = $exp['metrics'][ $id ] ?? null;
			if ( ! isset( $m['percentile'] ) ) {
				continue;
			}
			$p      = (float) $m['percentile'];
			$value  = 'cls' === $meta[1] ? number_format( $p / 100, 2 ) : ( $p >= 1000 ? number_format( $p / 1000, 1 ) . ' s' : (int) $p . ' ms' );
			$rows[] = array(
				'name'  => $meta[0],
				'value' => $value,
				'band'  => array( 'FAST' => 'good', 'AVERAGE' => 'average', 'SLOW' => 'poor' )[ $m['category'] ?? '' ] ?? '',
			);
		}
		return $rows ? array( 'rows' => $rows, 'site_wide' => ! empty( $exp['origin_fallback'] ) ) : null;
	}

	/** Lighthouse 13 reports savings per measure. Older versions report one total. */
	private static function savings( array $audit ) {
		$per_metric = array_filter( (array) ( $audit['metricSavings'] ?? array() ), 'is_numeric' );
		return $per_metric ? (float) max( $per_metric ) : (float) ( $audit['details']['overallSavingsMs'] ?? 0 );
	}

	private static function fixes( array $audits ) {
		$list = array();
		foreach ( $audits as $id => $a ) {
			if ( ! is_array( $a ) || isset( self::LAB[ $id ] ) || empty( $a['title'] ) || ! is_numeric( $a['score'] ?? null ) || $a['score'] >= 0.9 ) {
				continue;
			}
			if ( in_array( $a['scoreDisplayMode'] ?? '', array( 'informative', 'manual', 'notApplicable', 'error' ), true ) ) {
				continue;
			}
			$list[] = array(
				'title'   => wp_strip_all_tags( (string) $a['title'] ),
				'value'   => wp_strip_all_tags( (string) ( $a['displayValue'] ?? '' ) ),
				'savings' => self::savings( $a ),
				'score'   => (float) $a['score'],
			);
		}
		usort(
			$list,
			static function ( $x, $y ) {
				return $y['savings'] <=> $x['savings'] ?: $x['score'] <=> $y['score'];
			}
		);
		return array_slice( $list, 0, 8 );
	}
}
