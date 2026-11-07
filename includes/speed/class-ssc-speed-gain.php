<?php
/**
 * What a cleanup did for speed, measured on the home page before and after. Measuring must never
 * get in the way of a cleanup, so any failure here just means no number is shown.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Speed_Gain {

	public static function measure() {
		require_once SSC_DIR . 'includes/speed/class-ssc-speed-assets.php';
		require_once SSC_DIR . 'includes/speed/class-ssc-speed-psi.php';
		$out = array( 'autoload_bytes' => self::autoload_bytes() );
		$url = home_url( '/' );
		try {
			$totals = SSC_Speed_Assets::scan( $url )['totals'];
			$out   += array(
				'files'    => $totals['files'],
				'bytes'    => $totals['css_bytes'] + $totals['js_bytes'] + $totals['html_bytes'],
				'blocking' => $totals['blocking'],
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
		// Only with a key: a cleanup shouldn't spend the shared quota or depend on Google answering.
		if ( SSC_Speed_Psi::has_key() && SSC_Speed_Psi::reachable( $url ) ) {
			try {
				$out['score'] = SSC_Speed_Psi::run( $url, 'mobile' )['score'];
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
		return $out;
	}

	/** Settings WordPress loads on every request, so bloat here slows every page. */
	private static function autoload_bytes() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" );
	}
}
