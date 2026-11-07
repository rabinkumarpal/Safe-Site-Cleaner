<?php
/**
 * Private storage, run state and the path guard. Run state lives in files, not the
 * database, so it survives a database restore.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Store {

	const OPTION = 'ssc_data_dir';

	/** Folder for backups and quarantine. Outside the web root whenever the host allows it. */
	/** Clears the object cache and any known page cache. */
	public static function flush_caches() {
		wp_cache_flush();
		do_action( 'litespeed_purge_all' );
		do_action( 'w3tc_flush_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	public static function data_dir() {
		$dir = get_option( self::OPTION );
		if ( is_string( $dir ) && '' !== $dir && is_dir( $dir ) && is_writable( $dir ) ) {
			return $dir;
		}
		$parent = dirname( rtrim( ABSPATH, '/\\' ) );
		$dir    = '';
		if ( @is_dir( $parent ) && @is_writable( $parent ) ) {
			$dir = $parent . '/ssc-data-' . substr( wp_hash( 'ssc-data', 'nonce' ), 0, 12 );
		} else {
			$dir = WP_CONTENT_DIR . '/ssc-data-' . bin2hex( random_bytes( 16 ) );
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Could not create the private storage folder.' );
		}
		@chmod( $dir, 0750 );
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
		file_put_contents( $dir . '/web.config', '<configuration><system.webServer><authorization><deny users="*"/></authorization></system.webServer></configuration>' );
		update_option( self::OPTION, $dir, false );
		return $dir;
	}

	public static function in_web_root() {
		return 0 === strpos( wp_normalize_path( self::data_dir() ) . '/', wp_normalize_path( ABSPATH ) );
	}

	public static function new_run() {
		$id = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
		wp_mkdir_p( self::run_dir( $id ) . '/files' );
		return $id;
	}

	public static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id );
	}

	public static function run_dir( $id ) {
		if ( ! self::valid_id( $id ) ) {
			throw new InvalidArgumentException( 'Invalid run ID.' );
		}
		return self::data_dir() . '/runs/' . $id;
	}

	public static function runs() {
		$out = glob( self::data_dir() . '/runs/*', GLOB_ONLYDIR ) ?: array();
		$out = array_map( 'basename', $out );
		rsort( $out );
		return array_values( array_filter( $out, array( __CLASS__, 'valid_id' ) ) );
	}

	public static function read( $run, $name, $default = null ) {
		$file = self::run_dir( $run ) . '/' . $name . '.json';
		if ( ! is_file( $file ) ) {
			return $default;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return null === $data ? $default : $data;
	}

	/** Atomic: a crash mid-write can never leave half a state file. */
	public static function write( $run, $name, $data ) {
		$file = self::run_dir( $run ) . '/' . $name . '.json';
		$json = wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES );
		if ( false === $json || false === file_put_contents( $file . '.tmp', $json ) || ! rename( $file . '.tmp', $file ) ) {
			throw new RuntimeException( 'Could not save run state.' );
		}
	}

	public static function append_manifest( $run, array $row ) {
		$line = wp_json_encode( $row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES ) . "\n";
		if ( false === file_put_contents( self::run_dir( $run ) . '/manifest.ndjson', $line, FILE_APPEND | LOCK_EX ) ) {
			throw new RuntimeException( 'Could not write the undo manifest.' );
		}
	}

	public static function manifest( $run ) {
		$file = self::run_dir( $run ) . '/manifest.ndjson';
		$rows = array();
		if ( is_file( $file ) ) {
			foreach ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	/** Only one tick of any job can run at a time. */
	public static function lock() {
		$fh = fopen( self::data_dir() . '/lock', 'c' );
		if ( ! $fh || ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			return false;
		}
		return $fh;
	}

	public static function unlock( $fh ) {
		if ( $fh ) {
			flock( $fh, LOCK_UN );
			fclose( $fh );
		}
	}

	/** True if the path sits somewhere this plugin must never touch. */
	public static function is_protected( $rel ) {
		$rel = ltrim( wp_normalize_path( $rel ), '/' );
		if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
			return true;
		}
		$base = basename( $rel );
		if ( in_array( $rel, array( 'wp-config.php', 'index.php', '.htaccess', 'wp-content', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads', 'wp-content/mu-plugins', 'wp-content/index.php' ), true ) ) {
			return true;
		}
		foreach ( array( 'wp-admin', 'wp-includes', 'wp-content/ai1wm-backups', 'wp-content/plugins/' . SSC_SLUG, 'wp-content/languages' ) as $root ) {
			if ( $rel === $root || 0 === strpos( $rel, $root . '/' ) ) {
				return true;
			}
		}
		if ( false !== stripos( $rel, 'ai1wm' ) || preg_match( '/\.wpress$/i', $base ) ) {
			return true;
		}
		if ( preg_match( '#^wp-content/plugins/[^/]*(migration|backup|duplicator)[^/]*\.zip$#i', $rel ) ) {
			return true;
		}
		$uploads = 'wp-content/uploads/';
		if ( 0 === strpos( $rel, $uploads ) ) {
			foreach ( array( 'sureforms', 'sureforms-private', 'gravity_forms', 'wpforms', 'woocommerce_uploads', 'wc-logs' ) as $private ) {
				if ( 0 === strpos( $rel, $uploads . $private . '/' ) || $rel === $uploads . $private ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Resolves a site-relative path and refuses anything outside the site or protected. */
	public static function guard( $rel ) {
		if ( self::is_protected( $rel ) ) {
			throw new RuntimeException( 'Refused protected path: ' . $rel );
		}
		$abs  = wp_normalize_path( ABSPATH . ltrim( $rel, '/' ) );
		$real = realpath( dirname( $abs ) );
		if ( false === $real || 0 !== strpos( wp_normalize_path( $real ) . '/', wp_normalize_path( realpath( ABSPATH ) ) . '/' ) ) {
			throw new RuntimeException( 'Refused path outside the site: ' . $rel );
		}
		return $abs;
	}

	public static function tree_size( $path ) {
		if ( is_link( $path ) ) {
			return 0;
		}
		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}
		$size = 0;
		if ( is_dir( $path ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && ! $file->isLink() ) {
					$size += $file->getSize();
				}
			}
		}
		return $size;
	}

	public static function remove_tree( $path ) {
		$data = wp_normalize_path( self::data_dir() ) . '/';
		if ( 0 !== strpos( wp_normalize_path( $path ) . '/', $data ) ) {
			throw new RuntimeException( 'Refused to delete outside private storage.' );
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		return rmdir( $path );
	}
}
