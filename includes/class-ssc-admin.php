<?php
/** The Tools > Safe Site Cleaner screen. */

defined( 'ABSPATH' ) || exit;

final class SSC_Admin {

	public static function menu() {
		$hook = add_management_page(
			__( 'Safe Site Cleaner', 'safe-site-cleaner' ),
			__( 'Safe Site Cleaner', 'safe-site-cleaner' ),
			'manage_options',
			SSC_SLUG,
			array( __CLASS__, 'render' )
		);
		add_action(
			'admin_enqueue_scripts',
			static function ( $current ) use ( $hook ) {
				if ( $current !== $hook ) {
					return;
				}
				wp_enqueue_style( SSC_SLUG, plugins_url( 'assets/admin.css', SSC_FILE ), array(), SSC_VERSION );
				wp_enqueue_script( SSC_SLUG, plugins_url( 'assets/admin.js', SSC_FILE ), array(), SSC_VERSION, true );
				wp_localize_script(
					SSC_SLUG,
					'SSC',
					array(
						'root'  => esc_url_raw( rest_url( SSC_Rest::NS . '/' ) ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'tab'   => self::tab(),
					)
				);
				if ( 'speed' === self::tab() ) {
					wp_enqueue_script( SSC_SLUG . '-speed', plugins_url( 'assets/speed.js', SSC_FILE ), array( SSC_SLUG ), SSC_VERSION, true );
				}
			}
		);
	}

	private static function tab() {
		return isset( $_GET['tab'] ) && 'speed' === $_GET['tab'] ? 'speed' : 'clean'; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = admin_url( 'tools.php?page=' . SSC_SLUG );
		echo '<div class="wrap ssc"><h1>' . esc_html__( 'Safe Site Cleaner', 'safe-site-cleaner' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf( '<a class="nav-tab%s" href="%s">%s</a>', 'clean' === self::tab() ? ' nav-tab-active' : '', esc_url( $url ), esc_html__( 'Clean', 'safe-site-cleaner' ) );
		printf( '<a class="nav-tab%s" href="%s">%s</a>', 'speed' === self::tab() ? ' nav-tab-active' : '', esc_url( $url . '&tab=speed' ), esc_html__( 'Speed', 'safe-site-cleaner' ) );
		echo '</nav>';
		echo '<div id="ssc-app"><p>' . esc_html__( 'Loading…', 'safe-site-cleaner' ) . '</p></div></div>';
	}
}
