<?php
/**
 * Known leftovers. Anything not listed here is reported at most: this plugin only offers to
 * remove data it can attribute to a specific plugin that is no longer installed.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Rules {

	/** Extensions an orphan file may have to be a candidate. PDFs, documents and archives never are. */
	const MEDIA_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'mp4', 'webm', 'mov' );

	/** Keep hands off anything touched this recently: it may be an upload in progress. */
	const RECENT_DAYS = 7;

	/** Never offered, whichever plugin owned them: these can be legal or business records. */
	const PROTECTED_TABLE = '/(consent|payment|order|invoice|subscri|customer|contact|entries|submission|gdpr|audit|licen[cs]e)/i';

	/** [ owner plugin folders, table regex (no prefix), option regex, cron hook regex, postmeta key regex ] */
	public static function owners() {
		return array(
			'EWWW Image Optimizer' => array( array( 'ewww-image-optimizer', 'ewww-image-optimizer-cloud' ), '/^ewwwio_/', '/^(ewww_|exactdn_)/', '/^easyio_/', '/^_?ewww/' ),
			'SWIS Performance'     => array( array( 'swis-performance' ), '/^swis_/', '/^swis_/', '/^swis_/', null ),
			'LiteSpeed Cache'      => array( array( 'litespeed-cache' ), '/^litespeed_/', '/^litespeed[._]/', '/^litespeed_/', '/^litespeed-/' ),
			'UpdraftPlus'          => array( array( 'updraftplus' ), null, '/^updraft(plus)?[-_]/', '/^updraft_/', null ),
			'TinyPNG'              => array( array( 'tiny-compress-images' ), null, '/^tinypng_/', null, '/^tiny_compress_images$/' ),
			'WP Compress'          => array( array( 'wp-compress-image-optimizer' ), null, '/^wps_ic/', '/^wps_ic_/', null ),
			'Imagify'              => array( array( 'imagify' ), null, '/^imagify_/', '/^imagify_/', '/^_imagify/' ),
			'Asset CleanUp'        => array( array( 'wp-asset-clean-up', 'wp-asset-clean-up-pro' ), null, '/^wpassetcleanup_/', null, null ),
			'File Manager Pro'     => array( array( 'wp-file-manager-pro', 'wp-file-manager' ), '/^wpfm_/', null, null, null ),
			'Hostinger Reach'      => array( array( 'hostinger-reach' ), '/^hostinger_reach_/', null, null, null ),
			'PG WPtimize'          => array( array( 'wptimize', 'pg-wptimize' ), '/^pg_wptimize_/', '/^pg_wptimize/', '/^pg_wptimize_/', '/^_pg_wptimize/' ),
			'Scalability Pro'      => array( array( 'scalability-pro' ), '/^(scalability_pro_|spro_)/', '/^spro_/', null, null ),
			'Super Speedy Analysis' => array( array( 'super-speedy-performance-analysis' ), '/^sspa_/', '/^sspa_/', '/^sspa_/', null ),
			'DevKit'               => array( array( 'dp-devkit', 'wp-devkit' ), null, '/^dpdevkit/', '/^devkit_/', null ),
			'Object Cache Pro'     => array( array( 'object-cache-pro', 'docket-cache' ), null, null, '/^objectcache_/', null ),
		);
	}

	/** wp-content items that are safe to quarantine when their owner plugin is gone. */
	public static function folders() {
		return array(
			'wp-content/docket-cache-data'    => array( 'Docket Cache leftovers', array( 'docket-cache' ), 'low' ),
			'wp-content/sn-backups'           => array( 'Security Ninja config backups (may contain database passwords)', array( 'security-ninja', 'security-ninja-premium' ), 'low' ),
			'wp-content/litespeed'            => array( 'LiteSpeed Cache leftovers', array( 'litespeed-cache' ), 'low' ),
			'wp-content/wp-cio-config'        => array( 'WP Compress leftovers', array( 'wp-compress-image-optimizer' ), 'low' ),
			'wp-content/upgrade-temp-backup'  => array( 'Empty WordPress updater folder', array(), 'low' ),
			'wp-content/uploads/wp-file-manager-pro' => array( 'File Manager Pro leftovers', array( 'wp-file-manager-pro', 'wp-file-manager' ), 'low' ),
			'wp-content/updraft'              => array( 'Old UpdraftPlus backups. Check you do not need them first', array( 'updraftplus' ), 'review' ),
			'wp-content/backups-dup-lite'     => array( 'Old Duplicator backups. Check you do not need them first', array( 'duplicator' ), 'review' ),
			'wp-content/backwpup'             => array( 'Old BackWPup backups. Check you do not need them first', array( 'backwpup' ), 'review' ),
		);
	}

	/** Inactive plugins that may hold tracking code, custom code or security settings. */
	public static function sensitive_plugin( $slug, $name ) {
		return (bool) preg_match( '/header|footer|snippet|analytic|tag.?manager|pixel|tracking|security|firewall|backup|migrat|redirect|code|seo/i', $slug . ' ' . $name );
	}
}
