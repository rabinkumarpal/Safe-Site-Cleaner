<?php
/**
 * Uninstalling removes the settings and speed rules only. Quarantined files and backups are left on
 * disk on purpose, because deleting a plugin must not silently destroy someone's only way back.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ssc_data_dir' );
delete_option( 'ssc_speed_rules' );
delete_transient( 'ssc_speed_sureforms_unused' );
