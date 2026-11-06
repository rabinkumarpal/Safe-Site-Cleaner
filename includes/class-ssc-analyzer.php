<?php
/**
 * Turns the collected evidence into two lists: what can be cleaned, and what is kept and why.
 * Read-only. Each rule below exists because the simpler version would remove something in use.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Analyzer {

	private $items = array();
	private $kept = array();
	private $refs;
	private $rendered = array();
	private $installed = array();
	private $keep_revisions;

	public static function run( $run, array $pages, array $assets, $keep_revisions = 3 ) {
		$self                 = new self();
		$self->refs           = SSC_Refs::merged( $run );
		$self->keep_revisions = max( 1, (int) $keep_revisions );
		foreach ( $pages as $p ) {
			foreach ( $p['assets'] as $a ) {
				$self->rendered[ $a ] = 1;
			}
		}
		foreach ( glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) ?: array() as $d ) {
			$self->installed[ basename( $d ) ] = 1;
		}
		$self->plugins();
		$self->themes();
		$self->folders();
		$self->media( $assets );
		$self->database();
		usort(
			$self->items,
			static function ( $a, $b ) {
				return array( $a['group'], $b['bytes'] ?? 0 ) <=> array( $b['group'], $a['bytes'] ?? 0 );
			}
		);
		return array( 'items' => $self->items, 'kept' => $self->kept );
	}

	private function item( $type, $group, $key, $label, $risk, $why, array $extra = array() ) {
		$this->items[] = array_merge(
			array(
				'id'    => substr( sha1( $type . '|' . $key ), 0, 16 ),
				'type'  => $type,
				'group' => $group,
				'key'   => $key,
				'label' => $label,
				'risk'  => $risk,
				'why'   => $why,
				'bytes' => 0,
			),
			$extra
		);
	}

	private function keep( $group, $label, $why ) {
		$this->kept[] = array( 'group' => $group, 'label' => $label, 'why' => $why );
	}

	private function owner_installed( array $slugs ) {
		foreach ( $slugs as $s ) {
			if ( isset( $this->installed[ $s ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active   = (array) get_option( 'active_plugins', array() );
		$all      = get_plugins();
		$required = array();
		foreach ( $all as $file => $h ) {
			if ( in_array( $file, $active, true ) ) {
				$data = get_file_data( WP_PLUGIN_DIR . '/' . $file, array( 'r' => 'Requires Plugins' ) );
				foreach ( array_filter( array_map( 'trim', explode( ',', (string) $data['r'] ) ) ) as $slug ) {
					$required[ $slug ] = 1;
				}
			}
		}
		$active_dirs = array();
		foreach ( $active as $a ) {
			$active_dirs[ dirname( $a ) ] = 1;
		}
		foreach ( $all as $file => $h ) {
			$dir = dirname( $file );
			if ( '.' === $dir || SSC_SLUG === $dir ) {
				continue;
			}
			$name = $h['Name'];
			if ( isset( $active_dirs[ $dir ] ) ) {
				$this->keep( 'Plugins', $name, 'Active. This plugin never offers active plugins for removal: deactivate one yourself and re-scan.' );
				continue;
			}
			if ( isset( $required[ $dir ] ) ) {
				$this->keep( 'Plugins', $name, 'Another active plugin requires it.' );
				continue;
			}
			$rel = $this->plugin_used( WP_PLUGIN_DIR . '/' . $dir, $dir );
			if ( $rel ) {
				$this->keep( 'Plugins', $name, 'Inactive, but your content still uses it: ' . $rel );
				continue;
			}
			$sensitive = SSC_Rules::sensitive_plugin( $dir, $name );
			$this->item(
				'move',
				'Inactive plugins',
				'wp-content/plugins/' . $dir,
				$name,
				$sensitive ? 'review' : 'low',
				$sensitive ? 'Inactive with no references in content. It may hold tracking, custom code or security settings, so check the live site does not rely on it.' : 'Inactive with no references in content. Its data stays in the database.',
				array( 'bytes' => SSC_Store::tree_size( WP_PLUGIN_DIR . '/' . $dir ), 'plugin' => $file )
			);
		}
		foreach ( glob( WP_PLUGIN_DIR . '/*.zip' ) ?: array() as $zip ) {
			$b = basename( $zip );
			if ( SSC_Store::is_protected( 'wp-content/plugins/' . $b ) ) {
				$this->keep( 'Plugins', $b, 'Migration or backup tool: left alone.' );
				continue;
			}
			$this->item( 'move', 'Stray files', 'wp-content/plugins/' . $b, $b, 'low', 'Installer zip in the plugins folder. WordPress never uses it.', array( 'bytes' => (int) filesize( $zip ) ) );
		}
	}

	/** Returns evidence that content still needs this inactive plugin, or an empty string. */
	private function plugin_used( $dir, $slug ) {
		if ( isset( $this->refs->plugin_paths[ strtolower( $slug ) ] ) ) {
			return 'content links to its files';
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $f ) {
					return ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', 'vendor', 'tests' ), true ) );
				}
			)
		);
		$n = 0;
		foreach ( $it as $f ) {
			if ( ! $f->isFile() || $f->getSize() > 400000 || ++$n > 3000 ) {
				continue;
			}
			$e = strtolower( $f->getExtension() );
			if ( 'json' === $e && 'block.json' === $f->getFilename() ) {
				$j = json_decode( (string) file_get_contents( $f->getPathname() ), true );
				if ( isset( $j['name'] ) && isset( $this->refs->blocks[ explode( '/', $j['name'] )[0] ] ) ) {
					return 'block ' . $j['name'];
				}
			}
			if ( 'php' !== $e ) {
				continue;
			}
			$c = (string) file_get_contents( $f->getPathname() );
			if ( preg_match_all( '/add_shortcode\s*\(\s*[\'"]([\w-]+)[\'"]/', $c, $m ) ) {
				foreach ( $m[1] as $s ) {
					if ( isset( $this->refs->shortcodes[ strtolower( $s ) ] ) ) {
						return "shortcode [$s]";
					}
				}
			}
			if ( preg_match_all( '~register_block_type\s*\(\s*[\'"]([a-z0-9-]+)/~', $c, $m ) ) {
				foreach ( $m[1] as $s ) {
					if ( isset( $this->refs->blocks[ $s ] ) ) {
						return "block $s";
					}
				}
			}
			if ( preg_match_all( '/(?:public\s+\$name\s*=\s*|function\s+get_name\s*\(\s*\)[^{]*\{\s*return\s+)[\'"]([\w-]+)[\'"]/', $c, $m ) ) {
				foreach ( $m[1] as $s ) {
					if ( isset( $this->refs->elements[ strtolower( $s ) ] ) ) {
						return "page builder element $s";
					}
				}
			}
		}
		return '';
	}

	private function themes() {
		$active  = array( get_stylesheet(), get_template() );
		$inactive = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( ! in_array( $slug, $active, true ) ) {
				$inactive[ $slug ] = $theme;
			}
		}
		$fallback = '';
		foreach ( array_keys( $inactive ) as $slug ) {
			if ( preg_match( '/^twenty/', $slug ) ) {
				$fallback = $slug;
			}
		}
		foreach ( $inactive as $slug => $theme ) {
			if ( $slug === $fallback ) {
				$this->keep( 'Themes', $theme->get( 'Name' ), 'Newest default theme: WordPress falls back to it if your theme ever fails.' );
				continue;
			}
			$this->item( 'move', 'Inactive themes', 'wp-content/themes/' . $slug, $theme->get( 'Name' ), 'low', 'Not active and not a parent of the active theme.', array( 'bytes' => SSC_Store::tree_size( $theme->get_stylesheet_directory() ) ) );
		}
	}

	private function folders() {
		foreach ( SSC_Rules::folders() as $rel => $rule ) {
			$abs = ABSPATH . $rel;
			if ( ! file_exists( $abs ) ) {
				continue;
			}
			if ( $this->owner_installed( $rule[1] ) ) {
				$this->keep( 'Leftovers', basename( $rel ), 'The plugin that owns it is still installed.' );
				continue;
			}
			$this->item( 'move', 'Leftover folders', $rel, basename( $rel ), $rule[2], $rule[0], array( 'bytes' => SSC_Store::tree_size( $abs ) ) );
		}
		foreach ( array_merge( glob( WP_CONTENT_DIR . '/temp-write-test-*' ) ?: array(), glob( ABSPATH . '.htaccess.bk' ) ?: array() ) as $abs ) {
			$rel = ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $abs ) ), '/' );
			$this->item( 'move', 'Stray files', $rel, basename( $rel ), 'low', 'Temporary or backup file nothing loads.', array( 'bytes' => is_file( $abs ) ? (int) filesize( $abs ) : 0 ) );
		}
		$ai = WP_CONTENT_DIR . '/ai1wm-backups';
		if ( is_dir( $ai ) ) {
			$this->keep( 'Leftovers', 'All-in-One WP Migration backups', 'Backups from migration tools are never touched.' );
		}
	}

	/** Attachment files, generated sizes and format variants. */
	private static function attachment_files( $main, $meta, $backup ) {
		$dir   = dirname( $main );
		$names = array( basename( $main ) );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = $meta['original_image'];
			}
			foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$names[] = $s['file'];
				}
				foreach ( (array) ( $s['sources'] ?? array() ) as $src ) {
					if ( ! empty( $src['file'] ) ) {
						$names[] = $src['file'];
					}
				}
			}
		}
		if ( is_array( $backup ) ) {
			foreach ( $backup as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$names[] = $s['file'];
				}
			}
		}
		$out = array();
		foreach ( array_unique( $names ) as $n ) {
			$out[] = ( '.' === $dir ? '' : $dir . '/' ) . $n;
		}
		return $out;
	}

	private static function safe_unserialize( $v ) {
		if ( ! is_serialized( $v ) ) {
			return null;
		}
		return @unserialize( trim( $v ), array( 'allowed_classes' => false ) );
	}

	private function media( array $assets ) {
		global $wpdb;
		$up     = wp_get_upload_dir();
		$base   = wp_normalize_path( $up['basedir'] );
		$prefix = wp_parse_url( $up['baseurl'], PHP_URL_PATH ) . '/';
		$rows   = $wpdb->get_results(
			"SELECT p.ID, p.post_mime_type mime, p.post_modified_gmt mod_gmt, f.meta_value file, m.meta_value meta, b.meta_value backup
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} f ON f.post_id=p.ID AND f.meta_key='_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_wp_attachment_metadata'
			 LEFT JOIN {$wpdb->postmeta} b ON b.post_id=p.ID AND b.meta_key='_wp_attachment_backup_sizes'
			 WHERE p.post_type='attachment'",
			ARRAY_A
		);

		$rendered_up = array();
		foreach ( array_keys( $this->rendered ) as $a ) {
			if ( 0 === strpos( $a, $prefix ) ) {
				$rendered_up[ substr( $a, strlen( $prefix ) ) ] = 1;
			}
		}

		$expected = array();
		$atts     = array();
		foreach ( $rows as $r ) {
			$files = self::attachment_files( $r['file'], self::safe_unserialize( $r['meta'] ), self::safe_unserialize( $r['backup'] ) );
			foreach ( $files as $f ) {
				$expected[ $f ] = (int) $r['ID'];
			}
			$atts[] = array( 'row' => $r, 'files' => $files );
		}

		// Everything the site expects on disk but which is missing. A missing file next to a
		// similarly named "orphan" usually means a rename, not a deletion candidate.
		$missing = array();
		foreach ( array_keys( $expected ) as $f ) {
			if ( ! file_exists( $base . '/' . $f ) ) {
				$missing[ $f ] = 1;
			}
		}
		foreach ( array_keys( $this->refs->paths ) as $f ) {
			if ( ! file_exists( $base . '/' . $f ) ) {
				$missing[ $f ] = 1;
			}
		}
		foreach ( $assets as $path => $code ) {
			if ( 404 === (int) $code && 0 === strpos( $path, $prefix ) ) {
				$missing[ substr( $path, strlen( $prefix ) ) ] = 1;
			}
		}

		$orphans = array();
		if ( is_dir( $base ) ) {
			foreach ( glob( $base . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ) ?: array() as $year ) {
				$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $year, FilesystemIterator::SKIP_DOTS ) );
				foreach ( $it as $f ) {
					if ( ! $f->isFile() || $f->isLink() ) {
						continue;
					}
					$rel = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $base ) ), '/' );
					if ( '.DS_Store' === $f->getFilename() || isset( $expected[ $rel ] ) ) {
						continue;
					}
					// A format variant of a known file, like photo.jpg.webp or photo.bk.jpg of a live attachment, is held below by its base name.
					$orphans[ $rel ] = array( 'bytes' => $f->getSize(), 'mtime' => $f->getMTime() );
				}
			}
		}

		$renames   = $this->find_renames( array_keys( $missing ), $orphans );
		$recent    = time() - SSC_Rules::RECENT_DAYS * DAY_IN_SECONDS;
		$by_base   = array();
		foreach ( $atts as $a ) {
			$by_base[ dirname( $a['row']['file'] ) . '/' . SSC_Refs::base( basename( $a['row']['file'] ) ) ] = 1;
		}

		foreach ( $renames['repairs'] as $target => $src ) {
			$this->item(
				'repair',
				'Repairs',
				$target,
				basename( $target ),
				'low',
				'Pages expect this image, but it exists as ' . basename( $src ) . '. It looks renamed in the database only (e.g. after a rebrand). Copying it to the expected name fixes the broken image.',
				array( 'src' => $src, 'bytes' => 0 )
			);
		}

		foreach ( $orphans as $rel => $o ) {
			$name = basename( $rel );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, SSC_Rules::MEDIA_EXT, true ) ) {
				$this->keep( 'Media', $rel, 'Not an image or video: documents are never treated as unused.' );
			} elseif ( preg_match( '/^(.+\.(?:jpe?g|png|gif))\.(?:webp|avif)$/i', $name, $v ) && file_exists( dirname( $base . '/' . $rel ) . '/' . $v[1] ) ) {
				$this->keep( 'Media', $rel, 'Next-gen copy of an existing image: a server rule may serve it to browsers.' );
			} elseif ( isset( $renames['suspects'][ $rel ] ) ) {
				$this->keep( 'Media', $rel, 'Looks like the renamed original of a missing file: ' . basename( $renames['suspects'][ $rel ] ) );
			} elseif ( isset( $rendered_up[ $rel ] ) ) {
				$this->keep( 'Media', $rel, 'Shown on a page.' );
			} elseif ( isset( $this->refs->paths[ $rel ] ) ) {
				$this->keep( 'Media', $rel, 'Referenced by path in the database or code.' );
			} elseif ( $o['mtime'] > $recent ) {
				$this->keep( 'Media', $rel, 'Changed in the last ' . SSC_Rules::RECENT_DAYS . ' days.' );
			} else {
				$is_bk = (bool) preg_match( '/\.bk\.[a-z0-9]+$/i', $name );
				$b     = SSC_Refs::base( $name );
				// A reference to "photo" explains away only a generated size of a library item in this
				// same folder. A full-size file with that name could be what the reference means.
				$is_size = (bool) preg_match( '/-(\d+x\d+|scaled)\.[a-z0-9]+$/i', $name );
				$is_gen  = $is_size && isset( $by_base[ dirname( $rel ) . '/' . $b ] );
				if ( ! $is_bk && isset( $this->refs->tokens[ $b ] ) && ! $is_gen ) {
					$this->keep( 'Media', $rel, 'Its filename appears in the database or code.' );
					continue;
				}
				$this->item(
					'move',
					$is_bk ? 'Image backup copies' : 'Orphan media files',
					'wp-content/uploads/' . $rel,
					$rel,
					'low',
					$is_bk ? 'Pre-optimisation copy that no page or database row uses.' : 'File with no media library entry, not used by any page or database row.',
					array( 'bytes' => $o['bytes'] )
				);
			}
		}

		foreach ( $atts as $a ) {
			$r   = $a['row'];
			$id  = (int) $r['ID'];
			$b   = SSC_Refs::base( basename( $r['file'] ) );
			$why = '';
			if ( array_intersect_key( array_flip( $a['files'] ), $rendered_up ) ) {
				$why = 'Shown on a page.';
			} elseif ( isset( $this->refs->ids[ $id ] ) ) {
				$why = 'Its ID appears in live data (featured image, gallery, builder or custom field).';
			} elseif ( isset( $this->refs->tokens[ $b ] ) ) {
				$why = 'Its filename appears in the database or code.';
			} elseif ( isset( $this->refs->paths[ $r['file'] ] ) ) {
				$why = 'Referenced by path.';
			} elseif ( strtotime( $r['mod_gmt'] . ' UTC' ) > $recent ) {
				$why = 'Uploaded or changed in the last ' . SSC_Rules::RECENT_DAYS . ' days.';
			} elseif ( isset( $renames['targets_by_base'][ $b ] ) ) {
				$why = 'Part of a suspected rename.';
			}
			if ( $why ) {
				$this->keep( 'Media library', $r['file'], $why );
				continue;
			}
			$bytes = 0;
			$files = array();
			foreach ( $a['files'] as $f ) {
				if ( file_exists( $base . '/' . $f ) ) {
					$bytes  += (int) filesize( $base . '/' . $f );
					$files[] = $f;
				}
			}
			$is_media = 0 === strpos( (string) $r['mime'], 'image/' ) || 0 === strpos( (string) $r['mime'], 'video/' );
			$this->item(
				'attachment',
				'Unused media library items',
				(string) $id,
				$r['file'],
				$is_media ? 'low' : 'review',
				$is_media ? 'No page shows it, and neither its ID nor filename appears in any live data.' : 'Document with no references. People may have direct links to it from outside this site.',
				array( 'bytes' => $bytes, 'files' => $files )
			);
		}
	}

	/**
	 * Detects a rename: same folder and extension, one differing stretch in the middle of the name.
	 * A pair like oldbrand -> newbrand that recurs is strong evidence and earns a repair offer.
	 */
	private function find_renames( array $missing, array $orphans ) {
		$by_dir = array();
		foreach ( array_keys( $orphans ) as $rel ) {
			$by_dir[ dirname( $rel ) ][] = $rel;
		}
		$pairs = array();
		$found = array();
		foreach ( $missing as $m ) {
			$d = dirname( $m );
			if ( empty( $by_dir[ $d ] ) ) {
				continue;
			}
			$mb = strtolower( basename( $m ) );
			foreach ( $by_dir[ $d ] as $o ) {
				$ob = strtolower( basename( $o ) );
				if ( pathinfo( $ob, PATHINFO_EXTENSION ) !== pathinfo( $mb, PATHINFO_EXTENSION ) || preg_match( '/\.bk\./', $ob ) ) {
					continue;
				}
				$diff = self::middle_diff( $ob, $mb );
				if ( $diff ) {
					$key             = $diff[0] . '>' . $diff[1];
					$pairs[ $key ]   = ( $pairs[ $key ] ?? 0 ) + 1;
					$found[ $m ][ $o ] = $key;
				}
			}
		}
		$out = array( 'repairs' => array(), 'suspects' => array(), 'targets_by_base' => array() );
		foreach ( $found as $m => $cands ) {
			foreach ( $cands as $o => $key ) {
				$out['suspects'][ $o ]                            = $m;
				$out['targets_by_base'][ SSC_Refs::base( basename( $m ) ) ] = 1;
			}
			if ( 1 === count( $cands ) && $pairs[ reset( $cands ) ] >= 3 ) {
				$out['repairs'][ $m ] = key( $cands );
			}
		}
		return $out;
	}

	/** Returns [from, to] when two names differ only by one stretch and share most characters. */
	private static function middle_diff( $a, $b ) {
		if ( $a === $b ) {
			return null;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		$p  = 0;
		while ( $p < $la && $p < $lb && $a[ $p ] === $b[ $p ] ) {
			$p++;
		}
		$s = 0;
		while ( $s < $la - $p && $s < $lb - $p && $a[ $la - 1 - $s ] === $b[ $lb - 1 - $s ] ) {
			$s++;
		}
		$ma = substr( $a, $p, $la - $p - $s );
		$mb = substr( $b, $p, $lb - $p - $s );
		$shared = $p + $s;
		if ( '' === $ma || '' === $mb || strlen( $ma ) > 40 || strlen( $mb ) > 40 || preg_match( '/^\d+$/', $ma . $mb ) ) {
			return null;
		}
		if ( $shared < 8 || $shared / max( $la, $lb ) < 0.5 ) {
			return null;
		}
		return array( $ma, $mb );
	}

	private function database() {
		global $wpdb;
		$keep  = $this->keep_revisions;
		$extra = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(c-%d),0) FROM (SELECT COUNT(*) c FROM {$wpdb->posts} WHERE post_type='revision' GROUP BY post_parent HAVING c>%d) t",
				$keep,
				$keep
			)
		);
		if ( $extra ) {
			$this->item( 'revisions', 'Database', 'revisions', "$extra old post revisions", 'low', "Keeps the newest $keep revisions of every post. Removes older ones and their custom fields.", array( 'keep' => $keep ) );
		}
		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.ID IS NULL" );
		if ( $orphans ) {
			$this->item( 'orphan_meta', 'Database', 'orphan_meta', "$orphans custom fields of deleted posts", 'low', 'The post they belonged to no longer exists.' );
		}
		$expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED)<%d", '\\_transient\\_timeout\\_%', time() ) );
		if ( $expired ) {
			$this->item( 'expired_transients', 'Database', 'expired_transients', "$expired expired temporary values", 'low', 'WordPress would discard these anyway.' );
		}

		$unswappable = SSC_Backup::unswappable();
		$claimed     = array();
		foreach ( SSC_Rules::owners() as $owner => $rule ) {
			list( $slugs, $table_re, $option_re, $cron_re, $meta_re ) = $rule;
			$gone = ! $this->owner_installed( $slugs );
			if ( $table_re ) {
				foreach ( SSC_Backup::tables() as $t ) {
					$short = substr( $t, strlen( $wpdb->prefix ) );
					if ( ! preg_match( $table_re, $short ) ) {
						continue;
					}
					$claimed[ $t ] = 1;
					$rows          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" );
					if ( ! $gone ) {
						$this->keep( 'Database', $t, "$owner is still installed." );
					} elseif ( preg_match( SSC_Rules::PROTECTED_TABLE, $short ) ) {
						$this->keep( 'Database', $t, 'Name suggests business or legal records, so it is never offered.' );
					} elseif ( in_array( $t, $unswappable, true ) ) {
						$this->keep( 'Database', $t, 'Linked to other tables, so removing it could not be undone cleanly.' );
					} else {
						$this->item( 'table', 'Leftover tables', $t, "$t ($rows rows)", $rows ? 'review' : 'low', "Left by $owner, which is no longer installed.", array( 'owner' => $owner ) );
					}
				}
			}
			if ( ! $gone ) {
				continue;
			}
			if ( $option_re ) {
				$names = array_values( array_filter( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" ), static function ( $n ) use ( $option_re ) { return preg_match( $option_re, $n ); } ) );
				if ( $names ) {
					$this->item( 'options', 'Leftover settings', 'options:' . $owner, count( $names ) . " settings from $owner", 'low', "Left by $owner, which is no longer installed.", array( 'names' => $names ) );
				}
			}
			if ( $meta_re ) {
				$keys = array_values( array_filter( $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}" ), static function ( $n ) use ( $meta_re ) { return preg_match( $meta_re, (string) $n ); } ) );
				if ( $keys ) {
					$this->item( 'meta', 'Leftover settings', 'meta:' . $owner, count( $keys ) . " kinds of post data from $owner", 'low', "Left by $owner, which is no longer installed.", array( 'names' => $keys ) );
				}
			}
			if ( $cron_re ) {
				$hooks = array();
				foreach ( (array) _get_cron_array() as $events ) {
					foreach ( array_keys( (array) $events ) as $hook ) {
						if ( preg_match( $cron_re, $hook ) ) {
							$hooks[ $hook ] = 1;
						}
					}
				}
				if ( $hooks ) {
					$this->item( 'cron', 'Leftover settings', 'cron:' . $owner, count( $hooks ) . " scheduled jobs from $owner", 'low', 'They keep firing with nothing to run them.', array( 'names' => array_keys( $hooks ) ) );
				}
			}
		}
		foreach ( SSC_Backup::tables() as $t ) {
			if ( isset( $claimed[ $t ] ) ) {
				continue;
			}
			$short = substr( $t, strlen( $wpdb->prefix ) );
			if ( ! in_array( $short, array( 'posts', 'postmeta', 'options', 'users', 'usermeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links' ), true ) ) {
				$this->keep( 'Database', $t, 'Owner unknown or still installed. Unknown tables are never offered.' );
			}
		}
	}
}
