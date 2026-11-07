<?php
/**
 * Profiles: a site's speed rules as a file for another site. Page IDs differ between sites, so
 * exceptions travel as page addresses and are matched again on arrival.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Profile {

	const FORMAT = 'safe-site-cleaner-speed-profile';

	public static function export() {
		$rows = array();
		foreach ( SSC_Speed_Rules::all() as $rule ) {
			unset( $rule['id'] );
			if ( isset( $rule['except_pages'] ) ) {
				$rule['except_paths'] = array_values( array_filter( array_map( array( __CLASS__, 'path_of' ), $rule['except_pages'] ) ) );
				unset( $rule['except_pages'] );
			}
			$rows[] = $rule;
		}
		return array(
			'format'      => self::FORMAT,
			'version'     => 1,
			'plugin'      => SSC_VERSION,
			'exported_at' => gmdate( 'c' ),
			'rules'       => $rows,
		);
	}

	/** The rows in a profile, or in a plain list of rules. */
	public static function rows( $data ) {
		if ( is_array( $data ) && isset( $data['format'] ) ) {
			if ( self::FORMAT !== $data['format'] ) {
				throw new InvalidArgumentException( 'This is not a Safe Site Cleaner speed profile.' );
			}
			if ( (int) ( $data['version'] ?? 0 ) > 1 ) {
				throw new InvalidArgumentException( 'This profile came from a newer version of the plugin. Update the plugin first.' );
			}
			$data = $data['rules'] ?? null;
		}
		if ( ! is_array( $data ) || array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
			throw new InvalidArgumentException( 'No list of rules found. A profile holds its rules inside [ ].' );
		}
		if ( count( $data ) > SSC_Speed_Rules::MAX ) {
			throw new InvalidArgumentException( 'Too many rules. The limit is ' . SSC_Speed_Rules::MAX . '.' );
		}
		return $data;
	}

	/**
	 * Says what importing would do, without saving anything.
	 *
	 * @return array One row per rule.
	 */
	public static function check( $data ) {
		$existing = array_column( SSC_Speed_Rules::all(), 'id' );
		$seen     = self::seen_handles();
		$out      = array();
		foreach ( self::rows( $data ) as $row ) {
			try {
				$rule = SSC_Speed_Rules::clean( is_array( $row ) ? $row : array() );
			} catch ( InvalidArgumentException $e ) {
				$out[] = array( 'status' => 'invalid', 'label' => is_array( $row ) ? sanitize_text_field( (string) ( $row['label'] ?? '' ) ) : '', 'error' => $e->getMessage(), 'warnings' => array() );
				continue;
			}
			$warnings = array();
			$problem  = SSC_Speed_Rules::problem( $rule );
			if ( $problem ) {
				$warnings[] = $problem;
			}
			foreach ( (array) ( $row['except_paths'] ?? array() ) as $path ) {
				if ( ! SSC_Speed_Rules::page_for_path( (string) $path ) ) {
					$warnings[] = 'No page here matches the exception ' . sanitize_text_field( (string) $path ) . ', so the rule will also apply to any page meant by it.';
				}
			}
			if ( $seen && ! empty( $rule['handles'] ) ) {
				$missing = array_diff( $rule['handles'], $seen );
				if ( $missing ) {
					$warnings[] = 'Not seen on the pages checked here, so it may do nothing: ' . implode( ', ', $missing );
				}
			}
			$out[] = array(
				'status'   => in_array( $rule['id'], $existing, true ) ? 'replace' : 'new',
				'label'    => $rule['label'],
				'what'     => SSC_Speed_Rules::what( $rule ),
				'enabled'  => $rule['enabled'],
				'error'    => '',
				'warnings' => $warnings,
			);
		}
		return $out;
	}

	private static function path_of( $page_id ) {
		$link = get_permalink( $page_id );
		if ( ! $link ) {
			return '';
		}
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = (string) wp_parse_url( $link, PHP_URL_PATH );
		return '/' . ltrim( 0 === strpos( $path, $home ) ? substr( $path, strlen( $home ) ) : $path, '/' );
	}

	/** Handles found by page checks on this site. */
	private static function seen_handles() {
		$seen = array();
		foreach ( SSC_Speed_Results::all() as $page ) {
			foreach ( (array) ( $page['assets']['assets'] ?? array() ) as $asset ) {
				$seen[ $asset['handle'] ] = 1;
			}
		}
		return array_keys( array_filter( $seen, static function ( $v, $k ) { return '' !== (string) $k; }, ARRAY_FILTER_USE_BOTH ) );
	}
}
