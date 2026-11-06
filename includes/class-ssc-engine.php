<?php
/**
 * Runs scan, clean, rollback and restore as small resumable steps. Each step is safe to re-run,
 * so a timed-out request or crash can continue from saved state.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Engine {

	const SCAN_MAX_AGE = HOUR_IN_SECONDS;
	const COOLING_OFF  = DAY_IN_SECONDS;

	private static $steps = array(
		'scan'    => array( 'refs_db', 'refs_code', 'crawl_base', 'assets_base', 'analyze' ),
		'apply'   => array( 'preflight', 'backup', 'verify_backup', 'files', 'database', 'flush', 'crawl_after', 'assets_after', 'decide' ),
		'restore' => array( 'rb_files', 'rb_load', 'rb_swap', 'rb_crawl', 'rb_assets', 'rb_check' ),
	);

	private static $labels = array(
		'refs_db'       => 'Reading the database',
		'refs_code'     => 'Reading active plugin and theme code',
		'crawl_base'    => 'Loading your real pages',
		'assets_base'   => 'Checking files the pages use',
		'analyze'       => 'Working out what is safe',
		'preflight'     => 'Running safety checks',
		'backup'        => 'Backing up the database',
		'verify_backup' => 'Test-restoring the backup',
		'files'         => 'Moving files to quarantine',
		'database'      => 'Cleaning the database',
		'flush'         => 'Clearing caches',
		'crawl_after'   => 'Re-checking your pages',
		'assets_after'  => 'Re-checking files',
		'decide'        => 'Comparing before and after',
		'rb_files'      => 'Putting files back',
		'rb_load'       => 'Loading the database backup',
		'rb_swap'       => 'Swapping the database back',
		'rb_crawl'      => 'Re-checking your pages',
		'rb_assets'     => 'Re-checking files',
		'rb_check'      => 'Confirming the result',
	);

	public static function active_run() {
		foreach ( SSC_Store::runs() as $id ) {
			$s = SSC_Store::read( $id, 'state' );
			if ( is_array( $s ) && isset( $s['job'] ) && 'running' === $s['job']['status'] ) {
				return $id;
			}
		}
		return null;
	}

	private static function assert_idle() {
		$active = self::active_run();
		if ( $active ) {
			throw new RuntimeException( 'Another job is still running. Let it finish first.' );
		}
	}

	private static function new_job( $kind, $extra = array() ) {
		return array_merge(
			array(
				'kind'    => $kind,
				'status'  => 'running',
				'step'    => 0,
				'steps'   => self::$steps[ $kind ],
				'started' => time(),
				'log'     => array(),
				'error'   => '',
			),
			$extra
		);
	}

	public static function start_scan( $keep_revisions = 3 ) {
		self::assert_idle();
		foreach ( SSC_Store::runs() as $id ) {
			$old = SSC_Store::read( $id, 'state' );
			if ( is_array( $old ) && empty( $old['touched'] ) ) {
				SSC_Store::remove_tree( SSC_Store::run_dir( $id ) );
			}
		}
		$id = SSC_Store::new_run();
		SSC_Store::write(
			$id,
			'state',
			array(
				'id'      => $id,
				'created' => time(),
				'keep'    => max( 1, (int) $keep_revisions ),
				'touched' => false,
				'outcome' => '',
				'refs'    => SSC_Refs::start(),
				'job'     => self::new_job( 'scan' ),
			)
		);
		return $id;
	}

	/** The client names items by ID only. Paths and tables always come from the stored scan. */
	public static function start_apply( $run, array $ids ) {
		self::assert_idle();
		$s = SSC_Store::read( $run, 'state' );
		if ( ! is_array( $s ) || 'scan' !== $s['job']['kind'] || 'done' !== $s['job']['status'] ) {
			throw new RuntimeException( 'This scan is not ready. Run a new scan.' );
		}
		if ( ! empty( $s['touched'] ) ) {
			throw new RuntimeException( 'This scan has already been used. Run a new scan.' );
		}
		if ( time() - (int) $s['scanned'] > self::SCAN_MAX_AGE ) {
			throw new RuntimeException( 'This scan is over an hour old and the site may have changed. Run a new scan.' );
		}
		$scan = SSC_Store::read( $run, 'scan' );
		$map  = array();
		foreach ( $scan['items'] as $it ) {
			$map[ $it['id'] ] = $it;
		}
		$selected = array();
		foreach ( array_unique( $ids ) as $i ) {
			if ( ! isset( $map[ $i ] ) ) {
				throw new RuntimeException( 'Selection contains an item that is not in the scan.' );
			}
			$selected[] = $map[ $i ];
		}
		if ( ! $selected ) {
			throw new RuntimeException( 'Nothing selected.' );
		}
		SSC_Store::write( $run, 'selected', $selected );
		$s['job']    = self::new_job( 'apply', array( 'cursor' => 0, 'db_cursor' => 0 ) );
		$s['backup'] = SSC_Backup::start();
		$s['moved']  = array();
		SSC_Store::write( $run, 'state', $s );
		return $run;
	}

	public static function start_restore( $run, $with_db = true ) {
		self::assert_idle();
		$s = SSC_Store::read( $run, 'state' );
		if ( ! is_array( $s ) || empty( $s['touched'] ) ) {
			throw new RuntimeException( 'This run has nothing to restore.' );
		}
		if ( ! empty( $s['purged'] ) ) {
			throw new RuntimeException( 'This run was permanently deleted and cannot be restored.' );
		}
		if ( $with_db && ! is_file( SSC_Backup::file( $run ) ) ) {
			throw new RuntimeException( 'The database backup for this run is gone.' );
		}
		$job = self::new_job( 'restore', array( 'cursor' => 0, 'with_db' => (bool) $with_db, 'reason' => 'manual' ) );
		if ( ! $with_db ) {
			$job['steps'] = array_values( array_diff( $job['steps'], array( 'rb_load', 'rb_swap' ) ) );
		}
		$s['job'] = $job;
		SSC_Store::write( $run, 'state', $s );
		return $run;
	}

	/**
	 * Advances a job by one time slice.
	 *
	 * @return array Public view of the job.
	 */
	public static function tick( $run ) {
		$lock = SSC_Store::lock();
		if ( ! $lock ) {
			return self::view( $run ) + array( 'busy' => true );
		}
		try {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}
			@set_time_limit( 120 );
			$max      = (int) ini_get( 'max_execution_time' );
			$budget   = $max > 0 ? max( 5, min( 20, (int) ( $max * 0.5 ) ) ) : 20;
			$deadline = time() + $budget;
			$s        = SSC_Store::read( $run, 'state' );
			if ( ! is_array( $s ) ) {
				throw new RuntimeException( 'Run not found.' );
			}
			while ( 'running' === $s['job']['status'] && time() < $deadline ) {
				$step = $s['job']['steps'][ $s['job']['step'] ];
				try {
					$finished = self::{'step_' . $step}( $s, $run, $deadline );
				} catch ( Throwable $e ) {
					self::on_error( $s, $e );
					break;
				}
				if ( $finished && 'running' === $s['job']['status'] ) {
					$s['job']['step']++;
					if ( $s['job']['step'] >= count( $s['job']['steps'] ) ) {
						$s['job']['status'] = 'done';
					}
				}
				SSC_Store::write( $run, 'state', $s );
			}
			SSC_Store::write( $run, 'state', $s );
		} finally {
			SSC_Store::unlock( $lock );
		}
		return self::view( $run );
	}

	/** Failing before anything changed just stops. Failing after changes starts a rollback. */
	private static function on_error( array &$s, Throwable $e ) {
		$s['job']['log'][] = 'Error: ' . $e->getMessage();
		$s['job']['error'] = $e->getMessage();
		if ( 'apply' === $s['job']['kind'] && ! empty( $s['touched'] ) ) {
			$s['problems'] = array( 'The cleanup hit an error: ' . $e->getMessage() );
			self::begin_rollback( $s );
			return;
		}
		$s['job']['status'] = 'failed';
		if ( 'apply' === $s['job']['kind'] ) {
			$s['outcome'] = 'stopped';
		}
		try {
			SSC_Backup::drop_temp();
		} catch ( Throwable $ignored ) {
			unset( $ignored );
		}
	}

	private static function begin_rollback( array &$s ) {
		$s['job'] = self::new_job( 'restore', array( 'cursor' => 0, 'with_db' => ! empty( $s['backup']['done'] ), 'reason' => 'auto' ) );
		if ( empty( $s['backup']['done'] ) ) {
			$s['job']['steps'] = array_values( array_diff( $s['job']['steps'], array( 'rb_load', 'rb_swap' ) ) );
		}
	}

	private static function log( array &$s, $msg ) {
		$s['job']['log'][] = $msg;
	}

	// ---- Scan ----

	private static function step_refs_db( array &$s, $run, $deadline ) {
		return SSC_Refs::db_step( $run, $s['refs'], $deadline );
	}

	private static function step_refs_code( array &$s, $run, $deadline ) {
		return SSC_Refs::code_step( $run, $s['refs'], $deadline );
	}

	private static function crawl( array &$s, $run, $deadline, $name, $urls = null ) {
		if ( empty( $s['c'] ) || $s['c']['name'] !== $name ) {
			$s['c'] = array( 'name' => $name, 'urls' => null === $urls ? SSC_SiteCheck::urls() : $urls, 'i' => 0, 'pages' => array() );
		}
		$c =& $s['c'];
		while ( time() < $deadline && $c['i'] < count( $c['urls'] ) ) {
			$u               = $c['urls'][ $c['i'] ];
			$c['pages'][ $u ] = SSC_SiteCheck::fetch_page( $u );
			$c['i']++;
		}
		if ( $c['i'] >= count( $c['urls'] ) ) {
			SSC_Store::write( $run, $name, $c['pages'] );
			unset( $c );
			unset( $s['c'] );
			return true;
		}
		return false;
	}

	private static function check_assets( array &$s, $run, $deadline, $pages_name, $name ) {
		if ( empty( $s['a'] ) || $s['a']['name'] !== $name ) {
			$set = array();
			foreach ( (array) SSC_Store::read( $run, $pages_name, array() ) as $p ) {
				foreach ( $p['assets'] as $a ) {
					$set[ $a ] = 1;
				}
			}
			$s['a'] = array( 'name' => $name, 'list' => array_keys( $set ), 'i' => 0, 'res' => array() );
		}
		$a =& $s['a'];
		while ( time() < $deadline && $a['i'] < count( $a['list'] ) ) {
			$p              = $a['list'][ $a['i'] ];
			$a['res'][ $p ] = SSC_SiteCheck::head( $p );
			$a['i']++;
		}
		if ( $a['i'] >= count( $a['list'] ) ) {
			SSC_Store::write( $run, $name, $a['res'] );
			unset( $a );
			unset( $s['a'] );
			return true;
		}
		return false;
	}

	private static function step_crawl_base( array &$s, $run, $deadline ) {
		$done = self::crawl( $s, $run, $deadline, 'pages_before' );
		if ( $done ) {
			$s['admin_before'] = SSC_SiteCheck::admin_screen_ok();
		}
		return $done;
	}

	private static function step_assets_base( array &$s, $run, $deadline ) {
		return self::check_assets( $s, $run, $deadline, 'pages_before', 'assets_before' );
	}

	private static function step_analyze( array &$s, $run, $deadline ) {
		$pages  = (array) SSC_Store::read( $run, 'pages_before', array() );
		$assets = (array) SSC_Store::read( $run, 'assets_before', array() );
		$home   = home_url( '/' );
		$ok     = isset( $pages[ $home ] ) && 200 === (int) $pages[ $home ]['status'];
		$result = SSC_Analyzer::run( $run, $pages, $assets, $s['keep'] );
		SSC_Store::write( $run, 'scan', $result );
		$broken = array();
		foreach ( $assets as $p => $c ) {
			if ( 200 !== (int) $c ) {
				$broken[] = array( 'path' => $p, 'code' => (int) $c );
			}
		}
		$s['can_verify'] = $ok;
		$s['scanned']    = time();
		$s['stats']      = array(
			'pages'       => count( $pages ),
			'pages_ok'    => count( array_filter( $pages, static function ( $p ) { return 200 === (int) $p['status']; } ) ),
			'assets'      => count( $assets ),
			'broken'      => $broken,
			'web_root'    => SSC_Store::in_web_root(),
		);
		SSC_Store::remove_tree( SSC_Store::run_dir( $run ) . '/refs' );
		return true;
	}

	// ---- Apply ----

	private static function step_preflight( array &$s, $run, $deadline ) {
		if ( empty( $s['can_verify'] ) ) {
			throw new RuntimeException( 'This server cannot load its own pages, so results could not be verified. Nothing was changed.' );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new RuntimeException( 'File changes are disabled on this site (DISALLOW_FILE_MODS). Nothing was changed.' );
		}
		if ( ! SSC_Backup::can_create_tables() ) {
			throw new RuntimeException( 'The database account cannot create tables, so a backup could not be test-restored. Nothing was changed.' );
		}
		$free = @disk_free_space( SSC_Store::data_dir() );
		$need = SSC_Backup::estimated_bytes() * 2;
		if ( false !== $free && $free < $need ) {
			throw new RuntimeException( 'Not enough disk space for a safe backup. Nothing was changed.' );
		}
		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			throw new RuntimeException( 'wp-content is not writable. Nothing was changed.' );
		}
		SSC_Backup::drop_temp();
		self::log( $s, 'Safety checks passed.' );
		return true;
	}

	private static function step_backup( array &$s, $run, $deadline ) {
		$done = SSC_Backup::dump_step( $run, $s['backup'], $deadline );
		if ( $done ) {
			self::log( $s, 'Database backed up: ' . size_format( $s['backup']['bytes'] ) . ', ' . count( $s['backup']['tables'] ) . ' tables.' );
			$s['load'] = SSC_Backup::start_load();
		}
		return $done;
	}

	private static function step_verify_backup( array &$s, $run, $deadline ) {
		$skip = SSC_Backup::unswappable();
		$done = SSC_Backup::load_step( $run, $s['load'], $deadline, $skip );
		if ( $done ) {
			foreach ( $s['backup']['counts'] as $t => $n ) {
				if ( ! in_array( $t, $skip, true ) && ( ! isset( $s['load']['seen'][ $t ] ) || (int) $s['load']['seen'][ $t ] !== (int) $n ) ) {
					throw new RuntimeException( "Backup check failed for $t. Nothing was changed." );
				}
			}
			SSC_Backup::drop_temp();
			self::log( $s, 'Backup test-restored: ' . count( $s['load']['seen'] ) . ' tables match.' );
			unset( $s['load'] );
		}
		return $done;
	}

	private static function move_one( array &$s, $run, $rel ) {
		$src = SSC_Store::guard( $rel );
		$dst = SSC_Store::run_dir( $run ) . '/files/' . $rel;
		if ( ! file_exists( $src ) && ! is_link( $src ) ) {
			return 0;
		}
		if ( file_exists( $dst ) ) {
			throw new RuntimeException( 'Quarantine already has a copy of ' . $rel );
		}
		wp_mkdir_p( dirname( $dst ) );
		$bytes = SSC_Store::tree_size( $src );
		// Written before the move, so a crash mid-move still leaves a record to undo.
		SSC_Store::append_manifest( $run, array( 'a' => 'move', 'rel' => $rel, 'bytes' => $bytes ) );
		$s['touched'] = true;
		if ( ! @rename( $src, $dst ) ) {
			if ( is_dir( $src ) || ! copy( $src, $dst ) || filesize( $src ) !== filesize( $dst ) || ! unlink( $src ) ) {
				throw new RuntimeException( 'Could not move ' . $rel );
			}
		}
		return $bytes;
	}

	private static function step_files( array &$s, $run, $deadline ) {
		global $wpdb;
		$selected = (array) SSC_Store::read( $run, 'selected', array() );
		$up_rel   = 'wp-content/uploads/';
		$base     = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		$active   = (array) get_option( 'active_plugins', array() );
		while ( time() < $deadline && $s['job']['cursor'] < count( $selected ) ) {
			$it = $selected[ $s['job']['cursor'] ];
			if ( 'move' === $it['type'] ) {
				if ( ! empty( $it['plugin'] ) && in_array( $it['plugin'], $active, true ) ) {
					self::log( $s, 'Skipped ' . $it['label'] . ': it became active after the scan.' );
				} else {
					$s['job']['moved_bytes'] = ( $s['job']['moved_bytes'] ?? 0 ) + self::move_one( $s, $run, $it['key'] );
					if ( 0 === strpos( $it['key'], $up_rel ) ) {
						$s['moved'][] = $it['key'];
					}
				}
			} elseif ( 'repair' === $it['type'] ) {
				$from = $base . $it['src'];
				$to   = $base . $it['key'];
				SSC_Store::guard( $up_rel . $it['key'] );
				if ( ! file_exists( $to ) && is_file( $from ) ) {
					$sha = sha1_file( $from );
					SSC_Store::append_manifest( $run, array( 'a' => 'copy', 'rel' => $up_rel . $it['key'], 'sha' => $sha ) );
					$s['touched'] = true;
					if ( ! copy( $from, $to ) || sha1_file( $to ) !== $sha ) {
						throw new RuntimeException( 'Could not repair ' . $it['key'] );
					}
					$s['job']['repaired'] = ( $s['job']['repaired'] ?? 0 ) + 1;
				}
			} elseif ( 'attachment' === $it['type'] ) {
				$id = (int) $it['key'];
				if ( 'attachment' === get_post_type( $id ) ) {
					foreach ( (array) ( $it['files'] ?? array() ) as $f ) {
						$s['job']['moved_bytes'] = ( $s['job']['moved_bytes'] ?? 0 ) + self::move_one( $s, $run, $up_rel . $f );
						$s['moved'][]            = $up_rel . $f;
					}
					$s['touched'] = true;
					wp_delete_attachment( $id, true );
				}
			}
			$s['job']['cursor']++;
			if ( 0 === $s['job']['cursor'] % 100 ) {
				SSC_Store::write( $run, 'state', $s );
			}
		}
		$done = $s['job']['cursor'] >= count( $selected );
		if ( $done ) {
			$s['job']['cursor'] = 0;
			self::log( $s, 'Moved ' . size_format( $s['job']['moved_bytes'] ?? 0 ) . ' to quarantine.' );
		}
		return $done;
	}

	private static function step_database( array &$s, $run, $deadline ) {
		global $wpdb;
		$selected = (array) SSC_Store::read( $run, 'selected', array() );
		$unswap   = SSC_Backup::unswappable();
		while ( time() < $deadline && $s['job']['cursor'] < count( $selected ) ) {
			$it = $selected[ $s['job']['cursor'] ];
			switch ( $it['type'] ) {
				case 'revisions':
					$keep    = max( 1, (int) $it['keep'] );
					$parents = $wpdb->get_col( $wpdb->prepare( "SELECT post_parent FROM {$wpdb->posts} WHERE post_type='revision' GROUP BY post_parent HAVING COUNT(*)>%d LIMIT 50", $keep ) );
					if ( $parents ) {
						$s['touched'] = true;
						foreach ( $parents as $p ) {
							$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_parent=%d ORDER BY post_date DESC, ID DESC LIMIT 100000 OFFSET %d", $p, $keep ) ) );
							foreach ( array_chunk( $ids, 200 ) as $chunk ) {
								$in = implode( ',', $chunk );
								$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
								$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type='revision' AND ID IN ($in)" );
							}
						}
						continue 2;
					}
					break;
				case 'orphan_meta':
					$s['touched'] = true;
					$wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.ID IS NULL" );
					break;
				case 'expired_transients':
					$s['touched'] = true;
					delete_expired_transients( true );
					break;
				case 'table':
					if ( ! in_array( $it['key'], $unswap, true ) && in_array( $it['key'], SSC_Backup::tables(), true ) ) {
						$s['touched'] = true;
						$wpdb->query( 'DROP TABLE `' . str_replace( '`', '', $it['key'] ) . '`' );
					}
					break;
				case 'options':
					$s['touched'] = true;
					foreach ( (array) $it['names'] as $n ) {
						delete_option( $n );
					}
					break;
				case 'meta':
					$s['touched'] = true;
					foreach ( (array) $it['names'] as $n ) {
						delete_post_meta_by_key( $n );
					}
					break;
				case 'cron':
					$s['touched'] = true;
					foreach ( (array) $it['names'] as $n ) {
						wp_unschedule_hook( $n );
					}
					break;
			}
			$s['job']['cursor']++;
		}
		$done = $s['job']['cursor'] >= count( $selected );
		if ( $done ) {
			$s['job']['cursor'] = 0;
		}
		return $done;
	}

	private static function step_flush( array &$s, $run, $deadline ) {
		wp_cache_flush();
		do_action( 'litespeed_purge_all' );
		do_action( 'w3tc_flush_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		return true;
	}

	private static function step_crawl_after( array &$s, $run, $deadline ) {
		$urls = array_keys( (array) SSC_Store::read( $run, 'pages_before', array() ) );
		return self::crawl( $s, $run, $deadline, 'pages_after', $urls );
	}

	private static function step_assets_after( array &$s, $run, $deadline ) {
		return self::check_assets( $s, $run, $deadline, 'pages_after', 'assets_after' );
	}

	private static function comparison( array $s, $run, $pages, $assets ) {
		$root  = wp_parse_url( site_url( '/' ), PHP_URL_PATH );
		$moved = array();
		foreach ( (array) ( $s['moved'] ?? array() ) as $rel ) {
			$moved[] = rtrim( (string) $root, '/' ) . '/' . $rel;
		}
		return SSC_SiteCheck::compare(
			(array) SSC_Store::read( $run, 'pages_before', array() ),
			(array) SSC_Store::read( $run, $pages, array() ),
			(array) SSC_Store::read( $run, 'assets_before', array() ),
			(array) SSC_Store::read( $run, $assets, array() ),
			$moved
		);
	}

	private static function step_decide( array &$s, $run, $deadline ) {
		$problems = self::comparison( $s, $run, 'pages_after', 'assets_after' );
		$admin    = SSC_SiteCheck::admin_screen_ok();
		if ( false === $admin ) {
			$problems[] = 'The admin Plugins screen now shows an error.';
		}
		$s['admin_checked'] = null !== $admin;
		if ( $problems ) {
			$s['problems'] = array_slice( $problems, 0, 200 );
			self::log( $s, count( $problems ) . ' problem(s) found. Rolling back automatically.' );
			self::begin_rollback( $s );
			return false;
		}
		$s['outcome'] = 'applied';
		$s['applied'] = time();
		$s['stats_after'] = array(
			'moved_bytes' => $s['job']['moved_bytes'] ?? 0,
			'repaired'    => $s['job']['repaired'] ?? 0,
			'pages'       => count( (array) SSC_Store::read( $run, 'pages_after', array() ) ),
			'assets'      => count( (array) SSC_Store::read( $run, 'assets_after', array() ) ),
		);
		self::log( $s, 'All pages and files match or improve on the earlier check.' );
		return true;
	}

	// ---- Rollback and restore ----

	private static function step_rb_files( array &$s, $run, $deadline ) {
		$rows = array_reverse( SSC_Store::manifest( $run ) );
		$dir  = SSC_Store::run_dir( $run ) . '/files/';
		while ( time() < $deadline && $s['job']['cursor'] < count( $rows ) ) {
			$r = $rows[ $s['job']['cursor'] ];
			$to = wp_normalize_path( ABSPATH . $r['rel'] );
			if ( 'move' === $r['a'] ) {
				$from = $dir . $r['rel'];
				if ( file_exists( $from ) ) {
					if ( file_exists( $to ) ) {
						self::log( $s, 'Kept the newer file instead of restoring: ' . $r['rel'] );
					} else {
						wp_mkdir_p( dirname( $to ) );
						if ( ! @rename( $from, $to ) ) {
							throw new RuntimeException( 'Could not put back ' . $r['rel'] . '. It is still safe in quarantine.' );
						}
					}
				}
			} elseif ( 'copy' === $r['a'] && is_file( $to ) && sha1_file( $to ) === $r['sha'] ) {
				unlink( $to );
			}
			$s['job']['cursor']++;
		}
		$done = $s['job']['cursor'] >= count( $rows );
		if ( $done ) {
			self::log( $s, 'Files put back: ' . count( $rows ) . ' item(s).' );
			SSC_Backup::drop_temp();
			$s['load'] = SSC_Backup::start_load();
		}
		return $done;
	}

	private static function step_rb_load( array &$s, $run, $deadline ) {
		return SSC_Backup::load_step( $run, $s['load'], $deadline, SSC_Backup::unswappable() );
	}

	private static function step_rb_swap( array &$s, $run, $deadline ) {
		SSC_Backup::swap_in( array_keys( $s['load']['seen'] ) );
		$skipped = (array) $s['load']['skipped'];
		self::log( $s, 'Database restored: ' . count( $s['load']['seen'] ) . ' tables.' . ( $skipped ? ' Not restored (linked tables, never changed by the cleanup): ' . implode( ', ', $skipped ) : '' ) );
		unset( $s['load'] );
		SSC_Backup::drop_temp();
		wp_cache_flush();
		return true;
	}

	private static function step_rb_crawl( array &$s, $run, $deadline ) {
		$urls = array_keys( (array) SSC_Store::read( $run, 'pages_before', array() ) );
		return self::crawl( $s, $run, $deadline, 'pages_restored', $urls );
	}

	private static function step_rb_assets( array &$s, $run, $deadline ) {
		return self::check_assets( $s, $run, $deadline, 'pages_restored', 'assets_restored' );
	}

	private static function step_rb_check( array &$s, $run, $deadline ) {
		$s['moved']           = array();
		$remaining            = self::comparison( $s, $run, 'pages_restored', 'assets_restored' );
		$s['restore_problems'] = array_slice( $remaining, 0, 200 );
		$auto                 = 'auto' === $s['job']['reason'];
		$s['outcome']         = $auto ? 'rolled_back' : 'restored';
		$s['restored']        = time();
		self::log( $s, $remaining ? count( $remaining ) . ' difference(s) remain after restore. Please check them.' : 'Pages and files match the earlier check.' );
		return true;
	}

	// ---- Permanent deletion ----

	/**
	 * The only irreversible action. Needs a typed phrase, and within the cooling-off period a
	 * stronger one. The database backup is kept unless explicitly included.
	 */
	public static function purge( $run, $phrase, $include_backup = false ) {
		self::assert_idle();
		$s = SSC_Store::read( $run, 'state' );
		if ( ! is_array( $s ) || empty( $s['touched'] ) ) {
			throw new RuntimeException( 'This run has nothing to delete.' );
		}
		if ( 'applied' !== ( $s['outcome'] ?? '' ) ) {
			throw new RuntimeException( 'Only a successful cleanup can be permanently deleted.' );
		}
		$early = time() - (int) $s['applied'] < self::COOLING_OFF;
		if ( $early && 'DELETE NOW' !== $phrase ) {
			throw new RuntimeException( 'This cleanup is under 24 hours old. Type DELETE NOW to confirm you have checked the site.' );
		}
		if ( ! $early && ! in_array( $phrase, array( 'DELETE', 'DELETE NOW' ), true ) ) {
			throw new RuntimeException( 'Type DELETE to confirm.' );
		}
		$dir   = SSC_Store::run_dir( $run );
		$freed = SSC_Store::tree_size( $dir . '/files' );
		SSC_Store::remove_tree( $dir . '/files' );
		wp_mkdir_p( $dir . '/files' );
		if ( $include_backup && is_file( SSC_Backup::file( $run ) ) ) {
			$freed += (int) filesize( SSC_Backup::file( $run ) );
			SSC_Store::remove_tree( SSC_Backup::file( $run ) );
		}
		$s['purged']       = time();
		$s['purged_bytes'] = $freed;
		SSC_Store::write( $run, 'state', $s );
		return $freed;
	}

	// ---- Views ----

	public static function view( $run ) {
		$s = SSC_Store::read( $run, 'state' );
		if ( ! is_array( $s ) ) {
			throw new RuntimeException( 'Run not found.' );
		}
		$job  = $s['job'];
		$step = $job['steps'][ min( $job['step'], count( $job['steps'] ) - 1 ) ];
		$sub  = '';
		if ( isset( $s['c'] ) ) {
			$sub = $s['c']['i'] . ' of ' . count( $s['c']['urls'] ) . ' pages';
		} elseif ( isset( $s['a'] ) ) {
			$sub = $s['a']['i'] . ' of ' . count( $s['a']['list'] ) . ' files';
		}
		return array(
			'id'       => $s['id'],
			'kind'     => $job['kind'],
			'status'   => $job['status'],
			'step'     => $job['step'],
			'total'    => count( $job['steps'] ),
			'label'    => self::$labels[ $step ] ?? $step,
			'sub'      => $sub,
			'log'      => $job['log'],
			'error'    => $job['error'],
			'reason'   => $job['reason'] ?? '',
			'outcome'  => $s['outcome'] ?? '',
			'problems' => $s['problems'] ?? array(),
			'restore_problems' => $s['restore_problems'] ?? array(),
			'touched'  => ! empty( $s['touched'] ),
			'can_verify' => ! empty( $s['can_verify'] ),
			'stats'    => $s['stats'] ?? null,
			'stats_after' => $s['stats_after'] ?? null,
			'admin_checked' => $s['admin_checked'] ?? null,
			'scanned'  => $s['scanned'] ?? 0,
			'applied'  => $s['applied'] ?? 0,
			'purged'   => $s['purged'] ?? 0,
			'purged_bytes' => $s['purged_bytes'] ?? 0,
			'created'  => $s['created'],
			'quarantine_bytes' => ! empty( $s['touched'] ) ? SSC_Store::tree_size( SSC_Store::run_dir( $run ) . '/files' ) : 0,
			'backup_bytes' => is_file( SSC_Backup::file( $run ) ) ? (int) filesize( SSC_Backup::file( $run ) ) : 0,
			'cooling_off_left' => ! empty( $s['applied'] ) ? max( 0, self::COOLING_OFF - ( time() - (int) $s['applied'] ) ) : 0,
		);
	}

	public static function report( $run ) {
		return array(
			'run'  => self::view( $run ),
			'scan' => SSC_Store::read( $run, 'scan', array( 'items' => array(), 'kept' => array() ) ),
		);
	}
}
