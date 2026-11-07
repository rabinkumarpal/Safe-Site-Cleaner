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
						'page'  => admin_url( 'tools.php?page=' . SSC_SLUG ),
					)
				);
				if ( 'speed' === self::tab() ) {
					wp_enqueue_script( SSC_SLUG . '-speed-check', plugins_url( 'assets/speed-check.js', SSC_FILE ), array( SSC_SLUG ), SSC_VERSION, true );
					wp_enqueue_script( SSC_SLUG . '-speed', plugins_url( 'assets/speed.js', SSC_FILE ), array( SSC_SLUG . '-speed-check' ), SSC_VERSION, true );
				}
				if ( 'settings' === self::tab() ) {
					wp_enqueue_script( SSC_SLUG . '-settings', plugins_url( 'assets/settings.js', SSC_FILE ), array( SSC_SLUG ), SSC_VERSION, true );
				}
			}
		);
	}

	private static function tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $tab, array( 'speed', 'settings' ), true ) ? $tab : 'clean';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = admin_url( 'tools.php?page=' . SSC_SLUG );
		echo '<div class="wrap ssc"><h1>' . esc_html__( 'Safe Site Cleaner', 'safe-site-cleaner' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		$tabs = array(
			'clean'    => array( $url, __( 'Clean', 'safe-site-cleaner' ) ),
			'speed'    => array( $url . '&tab=speed', __( 'Speed', 'safe-site-cleaner' ) ),
			'settings' => array( $url . '&tab=settings', __( 'Settings', 'safe-site-cleaner' ) ),
		);
		foreach ( $tabs as $key => $tab ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $key === self::tab() ? ' nav-tab-active' : '', esc_url( $tab[0] ), esc_html( $tab[1] ) );
		}
		echo '</nav>';
		echo '<div id="ssc-app"><p>' . esc_html__( 'Loading…', 'safe-site-cleaner' ) . '</p></div></div>';
	}
}
