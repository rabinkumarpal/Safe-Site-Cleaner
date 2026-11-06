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
					)
				);
			}
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap ssc"><h1>' . esc_html__( 'Safe Site Cleaner', 'safe-site-cleaner' ) . '</h1><div id="ssc-app"><p>' . esc_html__( 'Loading…', 'safe-site-cleaner' ) . '</p></div></div>';
	}
}
