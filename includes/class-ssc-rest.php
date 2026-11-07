<?php
/** REST API for the admin screen. Every route needs an administrator and a valid nonce. */

defined( 'ABSPATH' ) || exit;

final class SSC_Rest {

	const NS = 'safe-site-cleaner/v1';

	public static function register() {
		$routes = array(
			array( 'status', 'GET', 'status' ),
			array( 'scan', 'POST', 'scan' ),
			array( 'tick', 'POST', 'tick' ),
			array( 'report', 'GET', 'report' ),
			array( 'apply', 'POST', 'apply' ),
			array( 'restore', 'POST', 'restore' ),
			array( 'purge', 'POST', 'purge' ),
			array( 'speed', 'GET', 'speed' ),
			array( 'speed/toggle', 'POST', 'speed_toggle' ),
			array( 'speed/delete', 'POST', 'speed_delete' ),
			array( 'speed/import', 'POST', 'speed_import' ),
			array( 'speed/pages', 'GET', 'speed_pages' ),
			array( 'speed/result', 'GET', 'speed_result' ),
			array( 'speed/scan', 'POST', 'speed_scan' ),
			array( 'speed/psi', 'POST', 'speed_psi' ),
			array( 'speed/key', 'POST', 'speed_key' ),
			array( 'speed/key-test', 'POST', 'speed_key_test' ),
		);
		// These only ever run for this API, so visitors' page loads don't pay for them.
		require_once SSC_DIR . 'includes/speed/class-ssc-speed-assets.php';
		require_once SSC_DIR . 'includes/speed/class-ssc-speed-psi.php';
		require_once SSC_DIR . 'includes/speed/class-ssc-speed-results.php';
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				'/' . $r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( __CLASS__, 'guarded_' . $r[2] ),
					'permission_callback' => array( __CLASS__, 'allowed' ),
				)
			);
		}
	}

	public static function allowed() {
		return current_user_can( 'manage_options' );
	}

	public static function __callStatic( $name, $args ) {
		$fn = substr( $name, 8 );
		try {
			nocache_headers();
			return rest_ensure_response( self::$fn( $args[0] ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'ssc_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	private static function run_id( WP_REST_Request $r ) {
		$id = (string) $r->get_param( 'run' );
		if ( ! SSC_Store::valid_id( $id ) || ! in_array( $id, SSC_Store::runs(), true ) ) {
			throw new InvalidArgumentException( 'Unknown run.' );
		}
		return $id;
	}

	private static function status( WP_REST_Request $r ) {
		$runs = array();
		foreach ( SSC_Store::runs() as $id ) {
			$runs[] = SSC_Engine::view( $id );
		}
		return array(
			'active'   => SSC_Engine::active_run(),
			'runs'     => $runs,
			'web_root' => SSC_Store::in_web_root(),
		);
	}

	private static function scan( WP_REST_Request $r ) {
		$keep = (int) $r->get_param( 'keep' );
		return SSC_Engine::view( SSC_Engine::start_scan( $keep ?: 3 ) );
	}

	private static function tick( WP_REST_Request $r ) {
		return SSC_Engine::tick( self::run_id( $r ) );
	}

	private static function report( WP_REST_Request $r ) {
		return SSC_Engine::report( self::run_id( $r ) );
	}

	private static function apply( WP_REST_Request $r ) {
		$ids = $r->get_param( 'ids' );
		if ( ! is_array( $ids ) ) {
			throw new InvalidArgumentException( 'No selection.' );
		}
		return SSC_Engine::view( SSC_Engine::start_apply( self::run_id( $r ), array_map( 'strval', $ids ) ) );
	}

	private static function restore( WP_REST_Request $r ) {
		$with_db = (bool) $r->get_param( 'with_db' );
		if ( $with_db && 'RESTORE' !== $r->get_param( 'phrase' ) ) {
			throw new InvalidArgumentException( 'Type RESTORE to confirm. This replaces the database with the backup.' );
		}
		return SSC_Engine::view( SSC_Engine::start_restore( self::run_id( $r ), $with_db ) );
	}

	private static function purge( WP_REST_Request $r ) {
		$run   = self::run_id( $r );
		$freed = SSC_Engine::purge( $run, (string) $r->get_param( 'phrase' ), (bool) $r->get_param( 'include_backup' ) );
		return array( 'freed' => $freed, 'run' => SSC_Engine::view( $run ) );
	}

	private static function speed( WP_REST_Request $r ) {
		return array( 'rules' => SSC_Speed_Rules::view() );
	}

	/** A rule changing mid-cleanup would show up in the before-and-after check as damage. */
	private static function assert_no_cleanup() {
		if ( SSC_Engine::active_run() ) {
			throw new RuntimeException( 'A cleanup job is running. Wait for it to finish, then change speed rules.' );
		}
	}

	private static function speed_toggle( WP_REST_Request $r ) {
		self::assert_no_cleanup();
		SSC_Speed_Rules::set_enabled( (string) $r->get_param( 'id' ), (bool) $r->get_param( 'enabled' ) );
		return array( 'rules' => SSC_Speed_Rules::view() );
	}

	private static function speed_delete( WP_REST_Request $r ) {
		self::assert_no_cleanup();
		SSC_Speed_Rules::delete( (string) $r->get_param( 'id' ) );
		return array( 'rules' => SSC_Speed_Rules::view() );
	}

	private static function speed_import( WP_REST_Request $r ) {
		self::assert_no_cleanup();
		$rows = $r->get_param( 'rules' );
		if ( ! is_array( $rows ) ) {
			throw new InvalidArgumentException( 'No rules to import.' );
		}
		return array( 'imported' => SSC_Speed_Rules::import( $rows ), 'rules' => SSC_Speed_Rules::view() );
	}

	private static function speed_pages( WP_REST_Request $r ) {
		$home = home_url( '/' );
		return array(
			'pages'     => SSC_Speed_Results::pages(),
			'has_key'   => SSC_Speed_Psi::has_key(),
			'reachable' => SSC_Speed_Psi::reachable( $home ),
		);
	}

	private static function speed_result( WP_REST_Request $r ) {
		$url = SSC_Speed_Results::page_url( $r->get_param( 'url' ) );
		return array( 'url' => $url, 'page' => SSC_Speed_Results::get( $url ) );
	}

	private static function speed_scan( WP_REST_Request $r ) {
		$url = SSC_Speed_Results::page_url( $r->get_param( 'url' ) );
		return array( 'url' => $url, 'page' => SSC_Speed_Results::save_assets( $url, SSC_Speed_Assets::scan( $url ) ) );
	}

	private static function speed_psi( WP_REST_Request $r ) {
		$url      = SSC_Speed_Results::page_url( $r->get_param( 'url' ) );
		$strategy = 'desktop' === $r->get_param( 'strategy' ) ? 'desktop' : 'mobile';
		return array( 'url' => $url, 'page' => SSC_Speed_Results::save_psi( $url, $strategy, SSC_Speed_Psi::run( $url, $strategy ) ) );
	}

	private static function speed_key( WP_REST_Request $r ) {
		SSC_Speed_Psi::save_key( $r->get_param( 'key' ) );
		return array( 'has_key' => SSC_Speed_Psi::has_key() );
	}

	private static function speed_key_test( WP_REST_Request $r ) {
		SSC_Speed_Psi::test_key();
		return array( 'ok' => true );
	}
}
