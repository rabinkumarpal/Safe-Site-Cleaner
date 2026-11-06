<?php
/**
 * Uninstalling removes the setting only. Quarantined files and backups are left on disk on
 * purpose, because deleting a plugin must not silently destroy someone's only way back.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ssc_data_dir' );
