<?php
/**
 * Database backup and restore in PHP, so it works on hosts without mysqldump. Runs in small
 * steps to survive request time limits.
 *
 * Restore never overwrites in place: tables are loaded under temporary names, row counts are
 * checked, and only then are they swapped in with one atomic RENAME. A backup is not trusted
 * until that same loader has read it back successfully.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Backup {

	const CHUNK = 500;
	const TMP   = 'sscx_';
	const OLD   = 'sscold_';

	private static function db() {
		global $wpdb;
		if ( ! ( $wpdb->dbh instanceof mysqli ) ) {
			throw new RuntimeException( 'This database connection is not supported, so a safe backup cannot be made.' );
		}
		return $wpdb->dbh;
	}

	private static function q( $sql ) {
		$r = mysqli_query( self::db(), $sql );
		if ( false === $r ) {
			throw new RuntimeException( 'Database error: ' . mysqli_error( self::db() ) );
		}
		return $r;
	}

	private static function session() {
		self::q( "SET SESSION sql_mode=''" );
		self::q( 'SET SESSION foreign_key_checks=0' );
	}

	public static function tables() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		return $wpdb->get_col( $wpdb->prepare( "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name LIKE %s ORDER BY table_name", $like ) );
	}

	public static function tmp_name( $t ) {
		return self::TMP . substr( md5( $t ), 0, 24 );
	}

	private static function old_name( $t ) {
		return self::OLD . substr( md5( $t ), 0, 24 );
	}

	public static function estimated_bytes() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema=DATABASE()" );
	}

	/** Tables with foreign keys or triggers cannot be swapped safely, so they are never changed or dropped. */
	public static function unswappable() {
		global $wpdb;
		$out = $wpdb->get_col( "SELECT DISTINCT table_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL" );
		$out = array_merge( $out, $wpdb->get_col( "SELECT DISTINCT referenced_table_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL" ) );
		$out = array_merge( $out, $wpdb->get_col( "SELECT DISTINCT event_object_table FROM information_schema.triggers WHERE trigger_schema=DATABASE()" ) );
		return array_values( array_unique( $out ) );
	}

	public static function file( $run ) {
		return SSC_Store::run_dir( $run ) . '/database.sql.gz';
	}

	public static function start() {
		return array(
			'tables' => self::tables(),
			'i'      => 0,
			'offset' => 0,
			'bytes'  => 0,
			'counts' => array(),
			'done'   => false,
		);
	}

	/** Writes one chunk at a time. Returns true when the dump is complete. */
	public static function dump_step( $run, array &$b, $deadline ) {
		$db   = self::db();
		$file = self::file( $run );
		if ( is_file( $file ) && filesize( $file ) > $b['bytes'] ) {
			$fh = fopen( $file, 'r+' );
			ftruncate( $fh, $b['bytes'] );
			fclose( $fh );
		}
		clearstatcache( true, $file );
		while ( time() < $deadline ) {
			if ( $b['i'] >= count( $b['tables'] ) ) {
				$gz = gzopen( $file, 'ab6' );
				gzwrite( $gz, '-- SSC-COMPLETE ' . count( $b['tables'] ) . "\n" );
				gzclose( $gz );
				clearstatcache( true, $file );
				$b['bytes'] = filesize( $file );
				$b['done']  = true;
				return true;
			}
			$t   = $b['tables'][ $b['i'] ];
			$qt  = '`' . str_replace( '`', '``', $t ) . '`';
			$buf = '';
			if ( 0 === $b['offset'] ) {
				$create = mysqli_fetch_row( self::q( "SHOW CREATE TABLE $qt" ) )[1];
				$buf   .= '-- SSC-TABLE ' . $t . "\n" . str_replace( array( "\r", "\n" ), ' ', $create ) . ";\n";
			}
			$order = array();
			$keys  = self::q( "SHOW KEYS FROM $qt WHERE Key_name='PRIMARY'" );
			while ( $row = mysqli_fetch_assoc( $keys ) ) {
				$order[] = '`' . $row['Column_name'] . '`';
			}
			$sql = "SELECT * FROM $qt" . ( $order ? ' ORDER BY ' . implode( ',', $order ) : '' ) . ' LIMIT ' . self::CHUNK . ' OFFSET ' . (int) $b['offset'];
			$res = self::q( $sql );
			$n   = 0;
			while ( $row = mysqli_fetch_row( $res ) ) {
				$vals = array();
				foreach ( $row as $v ) {
					$vals[] = null === $v ? 'NULL' : "'" . mysqli_real_escape_string( $db, $v ) . "'";
				}
				$buf .= "INSERT INTO $qt VALUES (" . implode( ',', $vals ) . ");\n";
				$n++;
			}
			$b['offset'] += $n;
			if ( $n < self::CHUNK ) {
				$b['counts'][ $t ] = $b['offset'];
				$buf              .= '-- SSC-END ' . $t . ' ' . $b['offset'] . "\n";
				$b['i']++;
				$b['offset'] = 0;
			}
			$gz = gzopen( $file, 'ab6' );
			if ( ! $gz || false === gzwrite( $gz, $buf ) ) {
				throw new RuntimeException( 'Could not write the database backup. Is the disk full?' );
			}
			gzclose( $gz );
			clearstatcache( true, $file );
			$b['bytes'] = filesize( $file );
		}
		return false;
	}

	public static function start_load() {
		return array(
			'pos'     => 0,
			'seen'    => array(),
			'skipped' => array(),
			'table'   => '',
			'complete' => false,
		);
	}

	public static function drop_temp() {
		self::session();
		$r = self::q( "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND (table_name LIKE 'sscx\\_%' OR table_name LIKE 'sscold\\_%')" );
		while ( $row = mysqli_fetch_row( $r ) ) {
			self::q( 'DROP TABLE IF EXISTS `' . $row[0] . '`' );
		}
	}

	/**
	 * Reads the backup into temporary tables. Returns true when finished and every count matched.
	 * Tables with foreign keys or triggers are counted but not loaded.
	 */
	public static function load_step( $run, array &$l, $deadline, array $skip ) {
		self::session();
		$gz = gzopen( self::file( $run ), 'rb' );
		if ( ! $gz ) {
			throw new RuntimeException( 'Could not open the database backup.' );
		}
		if ( $l['pos'] ) {
			gzseek( $gz, $l['pos'] );
		}
		self::q( 'START TRANSACTION' );
		$lines = 0;
		while ( ! gzeof( $gz ) ) {
			if ( 0 === ( ++$lines % 200 ) ) {
				self::q( 'COMMIT' );
				self::q( 'START TRANSACTION' );
				$l['pos'] = gztell( $gz );
				if ( time() >= $deadline ) {
					gzclose( $gz );
					return false;
				}
			}
			$line = gzgets( $gz );
			if ( false === $line ) {
				break;
			}
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, '-- SSC-TABLE ' ) ) {
				$l['table'] = substr( $line, 13 );
				continue;
			}
			if ( 0 === strpos( $line, '-- SSC-END ' ) ) {
				$parts = explode( ' ', $line );
				$rows  = (int) array_pop( $parts );
				$t     = $l['table'];
				if ( in_array( $t, $skip, true ) ) {
					$l['skipped'][] = $t;
				} else {
					self::q( 'COMMIT' );
					$got = (int) mysqli_fetch_row( self::q( 'SELECT COUNT(*) FROM `' . self::tmp_name( $t ) . '`' ) )[0];
					if ( $got !== $rows ) {
						throw new RuntimeException( "Backup check failed for $t: expected $rows rows, loaded $got." );
					}
					$l['seen'][ $t ] = $rows;
					self::q( 'START TRANSACTION' );
				}
				continue;
			}
			if ( 0 === strpos( $line, '-- SSC-COMPLETE ' ) ) {
				self::q( 'COMMIT' );
				$l['complete'] = true;
				gzclose( $gz );
				return true;
			}
			$t = $l['table'];
			if ( in_array( $t, $skip, true ) ) {
				continue;
			}
			$from = '`' . str_replace( '`', '``', $t ) . '`';
			$to   = '`' . self::tmp_name( $t ) . '`';
			if ( 0 === strpos( $line, 'CREATE TABLE ' . $from ) ) {
				self::q( 'COMMIT' );
				self::q( "DROP TABLE IF EXISTS $to" );
				$line = 'CREATE TABLE ' . $to . substr( $line, strlen( 'CREATE TABLE ' . $from ) );
				// Check constraint names are unique per database, so the temporary copy must not reuse them.
				$line = preg_replace( '/CONSTRAINT `[^`]+` CHECK/', 'CHECK', $line );
				self::q( rtrim( $line, ';' ) );
				self::q( 'START TRANSACTION' );
			} elseif ( 0 === strpos( $line, 'INSERT INTO ' . $from ) ) {
				self::q( 'INSERT INTO ' . $to . substr( rtrim( $line, ';' ), strlen( 'INSERT INTO ' . $from ) ) );
			} else {
				throw new RuntimeException( 'The database backup contains an unexpected line and cannot be trusted.' );
			}
		}
		self::q( 'COMMIT' );
		gzclose( $gz );
		throw new RuntimeException( 'The database backup is incomplete: its end marker is missing.' );
	}

	/** One atomic RENAME moves every loaded table into place. Either all swap or none do. */
	public static function swap_in( array $tables ) {
		self::session();
		$pairs = array();
		$olds  = array();
		foreach ( $tables as $t ) {
			$tmp = self::tmp_name( $t );
			$old = self::old_name( $t );
			$has = mysqli_num_rows( self::q( "SHOW TABLES LIKE '" . mysqli_real_escape_string( self::db(), $t ) . "'" ) );
			if ( $has ) {
				$pairs[] = "`$t` TO `$old`";
				$olds[]  = $old;
			}
			$pairs[] = "`$tmp` TO `$t`";
		}
		if ( $pairs ) {
			self::q( 'RENAME TABLE ' . implode( ', ', $pairs ) );
		}
		foreach ( $olds as $old ) {
			self::q( "DROP TABLE IF EXISTS `$old`" );
		}
		wp_cache_flush();
	}

	/** Confirms the account can create and drop tables before anything depends on it. */
	public static function can_create_tables() {
		try {
			self::q( 'DROP TABLE IF EXISTS `sscx_probe`' );
			self::q( 'CREATE TABLE `sscx_probe` (id INT)' );
			self::q( 'DROP TABLE `sscx_probe`' );
			return true;
		} catch ( RuntimeException $e ) {
			return false;
		}
	}
}
