<?php
/**
 * Builds the "is this used anywhere?" index from every database table and from the code of
 * active plugins and themes. It matches liberally: a false "in use" only keeps a file,
 * while a false "unused" could remove something the site needs.
 */

defined( 'ABSPATH' ) || exit;

final class SSC_Refs {

	public $tokens = array();
	public $ids = array();
	public $shortcodes = array();
	public $blocks = array();
	public $elements = array();
	public $plugin_paths = array();
	public $paths = array();

	/** "photo-300x200.bk.png" and "photo-scaled.png" both reduce to "photo". */
	public static function base( $name ) {
		$name = strtolower( rawurldecode( $name ) );
		$name = preg_replace( '/\.(jpe?g|png|gif|webp|avif|bmp|tiff?|svg|mp4|webm|mov|pdf|ico)(\.(webp|avif))?$/', '', $name );
		$name = preg_replace( '/\.bk$/', '', $name );
		$name = preg_replace( '/-\d+x\d+$/', '', $name );
		$name = preg_replace( '/-e\d{10,}$/', '', $name );
		$name = preg_replace( '/-scaled$/', '', $name );
		return $name;
	}

	/**
	 * @param string $text     Raw text.
	 * @param bool   $with_ids Collect numeric tokens. Off for code, where numbers are noise.
	 * @param bool   $content  True for post content and meta, which can reference plugins.
	 */
	public function add( $text, $with_ids = true, $content = false ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return;
		}
		if ( $content ) {
			if ( preg_match_all( '/\[([a-zA-Z][\w-]{1,60})[\s\]]/', $text, $m ) ) {
				foreach ( $m[1] as $x ) {
					$this->shortcodes[ strtolower( $x ) ] = 1;
				}
			}
			if ( preg_match_all( '~<!-- wp:([a-z0-9-]+)/~', $text, $m ) ) {
				foreach ( $m[1] as $x ) {
					$this->blocks[ $x ] = 1;
				}
			}
			if ( preg_match_all( '/s:4:"name";s:\d+:"([\w-]{1,60})"/', $text, $m ) ) {
				foreach ( $m[1] as $x ) {
					$this->elements[ strtolower( $x ) ] = 1;
				}
			}
			if ( preg_match_all( '/"widgetType":"([\w-]{1,60})"/', $text, $m ) ) {
				foreach ( $m[1] as $x ) {
					$this->elements[ strtolower( $x ) ] = 1;
				}
			}
		}
		$text = str_replace( '\\/', '/', $text );
		if ( false !== strpos( $text, '\\u' ) ) {
			$text = preg_replace_callback(
				'/\\\\u([0-9a-fA-F]{4})/',
				static function ( $m ) {
					return html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' );
				},
				$text
			);
		}
		if ( $content && preg_match_all( '~wp-content/plugins/([a-z0-9_-]+)/~i', $text, $m ) ) {
			foreach ( $m[1] as $x ) {
				$this->plugin_paths[ strtolower( $x ) ] = 1;
			}
		}
		if ( preg_match_all( '~/uploads/(\d{4}/\d{2}/[^"\'\s<>()\\\\,?#]+)~', $text, $m ) ) {
			foreach ( $m[1] as $x ) {
				$this->paths[ rawurldecode( rtrim( $x, ';&' ) ) ] = 1;
			}
		}
		if ( preg_match_all( '~(?:wp-image-|attachment[_-]|image[_-]id[_-]?)(\d{1,9})~i', $text, $m ) ) {
			foreach ( $m[1] as $x ) {
				$this->ids[ (int) $x ] = 1;
			}
		}
		if ( preg_match_all( '~[^\s"\'<>()\[\]{}=,;:/\\\\?&#|*`]+~', $text, $m ) ) {
			foreach ( $m[0] as $t ) {
				if ( ctype_digit( $t ) ) {
					if ( $with_ids && strlen( $t ) < 10 ) {
						$this->ids[ (int) $t ] = 1;
					}
					continue;
				}
				if ( strlen( $t ) < 3 || strlen( $t ) > 300 ) {
					continue;
				}
				$this->tokens[ self::base( $t ) ] = 1;
			}
		}
	}

	public static function start() {
		global $wpdb;
		$tables = array();
		foreach ( SSC_Backup::tables() as $t ) {
			$tables[] = $t;
		}
		return array( 'tables' => $tables, 'i' => 0, 'cursor' => 0, 'part' => 0, 'files' => null, 'f' => 0 );
	}

	private static function column_lists( $table ) {
		global $wpdb;
		$text = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `$table`", ARRAY_A ) as $c ) {
			$type = strtolower( $c['Type'] );
			if ( preg_match( '/char|text|json|blob/', $type ) ) {
				$text[] = $c['Field'];
			} elseif ( preg_match( '/attachment|image|media|thumb|file|photo|logo|avatar/i', $c['Field'] ) ) {
				$text[] = $c['Field'];
			}
		}
		return $text;
	}

	/** Scans the next batch of rows. Returns true once every table has been read. */
	public static function db_step( $run, array &$r, $deadline ) {
		global $wpdb;
		$self = new self();
		$done = false;
		while ( time() < $deadline ) {
			if ( $r['i'] >= count( $r['tables'] ) ) {
				$done = true;
				break;
			}
			$t     = $r['tables'][ $r['i'] ];
			$limit = 500;
			if ( $t === $wpdb->posts ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID k, post_content a, post_excerpt b, post_content_filtered c FROM {$wpdb->posts} WHERE ID>%d AND post_type NOT IN ('revision','attachment') ORDER BY ID LIMIT %d", $r['cursor'], $limit ), ARRAY_A );
				foreach ( $rows as $row ) {
					$self->add( $row['a'] . "\n" . $row['b'] . "\n" . $row['c'], true, true );
					$r['cursor'] = (int) $row['k'];
				}
			} elseif ( $t === $wpdb->postmeta ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id k, pm.meta_value a FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_id>%d AND (p.ID IS NULL OR p.post_type NOT IN ('revision','attachment')) ORDER BY pm.meta_id LIMIT %d", $r['cursor'], $limit ), ARRAY_A );
				foreach ( $rows as $row ) {
					$self->add( $row['a'], true, true );
					$r['cursor'] = (int) $row['k'];
				}
			} elseif ( $t === $wpdb->options ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id k, option_value a FROM {$wpdb->options} WHERE option_id>%d AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_id LIMIT %d", $r['cursor'], '\\_transient\\_%', '\\_site\\_transient\\_%', $limit ), ARRAY_A );
				foreach ( $rows as $row ) {
					$self->add( $row['a'], true, false );
					$r['cursor'] = (int) $row['k'];
				}
			} else {
				$cols = self::column_lists( $t );
				$rows = array();
				if ( $cols ) {
					$select = implode( ',', array_map( static function ( $c ) { return '`' . $c . '`'; }, $cols ) );
					$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT $select FROM `$t` LIMIT %d OFFSET %d", $limit, $r['cursor'] ), ARRAY_N );
					foreach ( $rows as $row ) {
						$self->add( implode( "\n", array_map( 'strval', $row ) ), true, false );
					}
					$r['cursor'] += count( $rows );
				}
			}
			if ( count( $rows ) < $limit ) {
				$r['i']++;
				$r['cursor'] = 0;
			}
		}
		$self->save( $run, $r['part']++ );
		return $done;
	}

	public static function code_files() {
		$roots = array( get_stylesheet_directory() );
		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$roots[] = get_template_directory();
		}
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$roots[] = WPMU_PLUGIN_DIR;
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			$d = dirname( $p );
			if ( '.' !== $d && SSC_SLUG !== $d ) {
				$roots[] = WP_PLUGIN_DIR . '/' . $d;
			}
		}
		$files = array();
		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
					static function ( $f ) {
						return ! ( $f->isDir() && in_array( $f->getFilename(), array( 'node_modules', 'vendor', '.git', 'tests', 'languages' ), true ) );
					}
				)
			);
			foreach ( $it as $f ) {
				if ( $f->isFile() && $f->getSize() < 524288 && in_array( strtolower( $f->getExtension() ), array( 'php', 'phtml', 'css', 'js', 'json', 'html', 'twig' ), true ) ) {
					$files[] = $f->getPathname();
				}
			}
		}
		return $files;
	}

	public static function code_step( $run, array &$r, $deadline ) {
		if ( null === $r['files'] ) {
			$r['files'] = self::code_files();
			$r['f']     = 0;
		}
		$self = new self();
		while ( time() < $deadline && $r['f'] < count( $r['files'] ) ) {
			$self->add( (string) @file_get_contents( $r['files'][ $r['f'] ] ), false, false );
			$r['f']++;
		}
		$self->save( $run, $r['part']++ );
		return $r['f'] >= count( $r['files'] );
	}

	private function save( $run, $n ) {
		$dir = SSC_Store::run_dir( $run ) . '/refs';
		wp_mkdir_p( $dir );
		$data = array(
			't' => array_keys( $this->tokens ),
			'i' => array_keys( $this->ids ),
			's' => array_keys( $this->shortcodes ),
			'b' => array_keys( $this->blocks ),
			'e' => array_keys( $this->elements ),
			'p' => array_keys( $this->plugin_paths ),
			'u' => array_keys( $this->paths ),
		);
		file_put_contents( $dir . '/part-' . $n . '.json', wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES ) );
	}

	public static function merged( $run ) {
		$self = new self();
		foreach ( glob( SSC_Store::run_dir( $run ) . '/refs/part-*.json' ) ?: array() as $f ) {
			$d = json_decode( (string) file_get_contents( $f ), true );
			if ( ! is_array( $d ) ) {
				throw new RuntimeException( 'The reference index could not be read, so usage cannot be judged safely.' );
			}
			$self->tokens       += array_fill_keys( $d['t'], 1 );
			$self->ids          += array_fill_keys( $d['i'], 1 );
			$self->shortcodes   += array_fill_keys( $d['s'], 1 );
			$self->blocks       += array_fill_keys( $d['b'], 1 );
			$self->elements     += array_fill_keys( $d['e'], 1 );
			$self->plugin_paths += array_fill_keys( $d['p'], 1 );
			$self->paths        += array_fill_keys( $d['u'], 1 );
		}
		return $self;
	}
}
