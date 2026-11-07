<?php
/**
 * Uninstalling removes the settings, speed rules and speed results only. Quarantined files and backups are left on
 * disk on purpose, because deleting a plugin must not silently destroy someone's only way back.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ssc_data_dir' );
delete_option( 'ssc_speed_rules' );
delete_option( 'ssc_speed_results' );
delete_option( 'ssc_speed_psi_key' );
delete_option( 'ssc_speed_trial' );
wp_clear_scheduled_hook( 'ssc_speed_trial_end' );
delete_transient( 'ssc_speed_sureforms_unused' );
