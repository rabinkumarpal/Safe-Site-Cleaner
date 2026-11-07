<?php
/** Which pages can be checked, and the latest result for each. */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Results {

	const OPTION    = 'ssc_speed_results';
	const MAX_PAGES = 20;

	/** Turns a full address or a /path into a page on this site, or refuses. */
	public static function page_url( $input ) {
		$input = trim( (string) $input );
		if ( 0 === strpos( $input, '/' ) && 0 !== strpos( $input, '//' ) ) {
			$input = home_url( $input );
		}
		$parts = wp_parse_url( $input );
		$home  = wp_parse_url( home_url() );
		if ( empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			|| strtolower( $parts['host'] ) !== strtolower( $home['host'] ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			throw new InvalidArgumentException( 'Enter a page on this site.' );
		}
		return esc_url_raw( strtok( $input, '#' ) );
	}

	/** A sample to choose from: the home page, plus the newest item of each kind of content. */
	public static function pages() {
		$pages = array( array( 'url' => home_url( '/' ), 'label' => 'Home page' ) );
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			$ids = get_posts( array( 'post_type' => $type->name, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'orderby' => 'modified' ) );
			if ( $ids ) {
				$pages[] = array( 'url' => get_permalink( $ids[0] ), 'label' => $type->labels->singular_name . ': ' . wp_strip_all_tags( get_the_title( $ids[0] ) ) );
			}
		}
		foreach ( self::all() as $url => $saved ) {
			if ( ! in_array( $url, array_column( $pages, 'url' ), true ) ) {
				$pages[] = array( 'url' => $url, 'label' => $url );
			}
		}
		return array_slice( $pages, 0, 40 );
	}

	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( $url ) {
		$all = self::all();
		return $all[ $url ] ?? null;
	}

	public static function save_assets( $url, array $assets ) {
		return self::change(
			$url,
			static function ( array $page ) use ( $assets ) {
				$page['assets'] = $assets;
				return $page;
			}
		);
	}

	public static function save_psi( $url, $strategy, array $psi ) {
		return self::change(
			$url,
			static function ( array $page ) use ( $strategy, $psi ) {
				$page['psi'][ $strategy ] = $psi;
				return $page;
			}
		);
	}

	private static function change( $url, callable $fn ) {
		$all         = self::all();
		$page        = $fn( $all[ $url ] ?? array() );
		$page['at']  = time();
		$all[ $url ] = $page;
		if ( count( $all ) > self::MAX_PAGES ) {
			uasort(
				$all,
				static function ( $a, $b ) {
					return $b['at'] <=> $a['at'];
				}
			);
			$all = array_slice( $all, 0, self::MAX_PAGES, true );
		}
		// Kept out of autoload: a result can be large, and visitors never need one.
		update_option( self::OPTION, $all, false );
		return $page;
	}
}
