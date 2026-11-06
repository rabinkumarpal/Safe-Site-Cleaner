<?php
/**
 * WP-CLI commands.
 *
 * ## EXAMPLES
 *
 *     wp ssc scan
 *     wp ssc report
 *     wp ssc apply --low-risk --yes
 *     wp ssc restore <run> --yes
 */

defined( 'ABSPATH' ) || exit;

final class SSC_CLI {

	private function drive( $run ) {
		$last = '';
		do {
			$v   = SSC_Engine::tick( $run );
			$msg = $v['label'] . ( $v['sub'] ? ' (' . $v['sub'] . ')' : '' );
			if ( $msg !== $last ) {
				WP_CLI::log( $msg );
				$last = $msg;
			}
		} while ( 'running' === $v['status'] );
		foreach ( $v['log'] as $line ) {
			WP_CLI::log( '  ' . $line );
		}
		return $v;
	}

	/** A refusal is a normal outcome, so show its reason rather than a crash screen. */
	private function attempt( callable $fn ) {
		try {
			return $fn();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function latest() {
		$runs = SSC_Store::runs();
		if ( ! $runs ) {
			WP_CLI::error( 'No runs yet. Use: wp ssc scan' );
		}
		return $runs[0];
	}

	/**
	 * Scans the site. Changes nothing.
	 *
	 * [--keep=<n>]
	 * : Revisions to keep per post.
	 * ---
	 * default: 3
	 * ---
	 */
	public function scan( $args, $assoc ) {
		$run = $this->attempt( static function () use ( $assoc ) { return SSC_Engine::start_scan( (int) $assoc['keep'] ); } );
		$v   = $this->drive( $run );
		if ( 'done' !== $v['status'] ) {
			WP_CLI::error( $v['error'] ?: 'Scan failed.' );
		}
		WP_CLI::success( "Scan complete: $run" );
		$this->report( array( $run ), array() );
	}

	/**
	 * Shows the latest scan.
	 *
	 * [<run>]
	 * : Run ID.
	 *
	 * [--format=<format>]
	 * : summary or json.
	 * ---
	 * default: summary
	 * ---
	 */
	public function report( $args, $assoc ) {
		$run = $args[0] ?? $this->latest();
		$r   = SSC_Engine::report( $run );
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			WP_CLI::line( wp_json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );
			return;
		}
		$groups = array();
		foreach ( $r['scan']['items'] as $it ) {
			$g = $it['group'] . ' / ' . $it['risk'];
			if ( ! isset( $groups[ $g ] ) ) {
				$groups[ $g ] = array( 'group' => $it['group'], 'risk' => $it['risk'], 'items' => 0, 'size' => 0 );
			}
			$groups[ $g ]['items']++;
			$groups[ $g ]['size'] += $it['bytes'];
		}
		foreach ( $groups as &$g ) {
			$g['size'] = size_format( $g['size'] );
		}
		WP_CLI\Utils\format_items( 'table', array_values( $groups ), array( 'group', 'risk', 'items', 'size' ) );
		$kept = array();
		foreach ( $r['scan']['kept'] as $k ) {
			$kept[ $k['group'] ] = ( $kept[ $k['group'] ] ?? 0 ) + 1;
		}
		WP_CLI::log( 'Kept on purpose: ' . wp_json_encode( $kept ) );
		if ( $r['run']['stats'] ) {
			WP_CLI::log( sprintf( 'Pages checked: %d (%d OK). Files checked: %d. Already broken: %d.', $r['run']['stats']['pages'], $r['run']['stats']['pages_ok'], $r['run']['stats']['assets'], count( $r['run']['stats']['broken'] ) ) );
		}
	}

	/**
	 * Cleans selected items, verifies the site, and rolls back automatically on any problem.
	 *
	 * [<run>]
	 * : Run ID. Defaults to the latest.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated item IDs from the report.
	 *
	 * [--low-risk]
	 * : Select every low-risk item.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function apply( $args, $assoc ) {
		$run  = $args[0] ?? $this->latest();
		$scan = SSC_Store::read( $run, 'scan', array( 'items' => array() ) );
		$ids  = array();
		if ( ! empty( $assoc['ids'] ) ) {
			$ids = array_filter( array_map( 'trim', explode( ',', $assoc['ids'] ) ) );
		} elseif ( ! empty( $assoc['low-risk'] ) ) {
			foreach ( $scan['items'] as $it ) {
				if ( 'low' === $it['risk'] ) {
					$ids[] = $it['id'];
				}
			}
		} else {
			WP_CLI::error( 'Choose --ids or --low-risk.' );
		}
		WP_CLI::confirm( sprintf( 'Clean %d item(s)?', count( $ids ) ), $assoc );
		$v = $this->drive( $this->attempt( static function () use ( $run, $ids ) { return SSC_Engine::start_apply( $run, $ids ); } ) );
		foreach ( $v['problems'] as $p ) {
			WP_CLI::warning( $p );
		}
		if ( 'applied' === $v['outcome'] ) {
			WP_CLI::success( 'Cleaned and verified. Logged-in admin screens were not checked from the command line.' );
		} elseif ( 'rolled_back' === $v['outcome'] ) {
			WP_CLI::warning( 'Problems were found, so everything was rolled back.' );
		} else {
			WP_CLI::error( $v['error'] ?: 'Stopped. Nothing was changed.' );
		}
	}

	/**
	 * Puts everything back from a run.
	 *
	 * <run>
	 * : Run ID.
	 *
	 * [--files-only]
	 * : Leave the database as it is.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function restore( $args, $assoc ) {
		$files_only = ! empty( $assoc['files-only'] );
		WP_CLI::confirm( $files_only ? 'Put files back?' : 'Put files back and REPLACE the database with the backup?', $assoc );
		$v = $this->drive( $this->attempt( static function () use ( $args, $files_only ) { return SSC_Engine::start_restore( $args[0], ! $files_only ); } ) );
		foreach ( $v['restore_problems'] as $p ) {
			WP_CLI::warning( $p );
		}
		'restored' === $v['outcome'] ? WP_CLI::success( 'Restored.' ) : WP_CLI::error( $v['error'] ?: 'Restore failed.' );
	}

	/**
	 * Permanently deletes a run's quarantine. Cannot be undone.
	 *
	 * <run>
	 * : Run ID.
	 *
	 * --phrase=<phrase>
	 * : DELETE, or DELETE NOW within 24 hours of the cleanup.
	 *
	 * [--include-backup]
	 * : Also delete the database backup.
	 */
	public function purge( $args, $assoc ) {
		$freed = $this->attempt( static function () use ( $args, $assoc ) { return SSC_Engine::purge( $args[0], $assoc['phrase'], ! empty( $assoc['include-backup'] ) ); } );
		WP_CLI::success( 'Deleted ' . size_format( $freed ) );
	}

	/** Continues a job that was interrupted. */
	public function resume() {
		$run = SSC_Engine::active_run();
		if ( ! $run ) {
			WP_CLI::success( 'No job is waiting.' );
			return;
		}
		$v = $this->drive( $run );
		foreach ( array_merge( $v['problems'], $v['restore_problems'] ) as $p ) {
			WP_CLI::warning( $p );
		}
		WP_CLI::success( 'Finished: ' . ( $v['outcome'] ?: $v['status'] ) );
	}

	/** Lists runs. */
	public function runs() {
		$rows = array();
		foreach ( SSC_Store::runs() as $id ) {
			$v      = SSC_Engine::view( $id );
			$rows[] = array(
				'run'        => $id,
				'job'        => $v['kind'] . ' ' . $v['status'],
				'outcome'    => $v['outcome'],
				'quarantine' => size_format( $v['quarantine_bytes'] ),
				'backup'     => size_format( $v['backup_bytes'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'run', 'job', 'outcome', 'quarantine', 'backup' ) );
	}
}
