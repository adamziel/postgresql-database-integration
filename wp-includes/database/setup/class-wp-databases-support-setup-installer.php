<?php
/**
 * Standalone first-install database setup helper.
 *
 * @package wordpress-databases-support
 */

if ( ! class_exists( 'WP_Databases_Support_Setup_Installer' ) ) {
	/**
	 * Installs this repository's database drop-in and wp-config.php constants.
	 */
	class WP_Databases_Support_Setup_Installer {
		const MANAGED_BLOCK_START = '/* BEGIN WordPress Databases Support */';
		const MANAGED_BLOCK_END   = '/* END WordPress Databases Support */';

		/**
		 * Plugin root directory.
		 *
		 * @var string
		 */
		private $plugin_dir;

		/**
		 * Constructor.
		 *
		 * @param string|null $plugin_dir Plugin root directory.
		 */
		public function __construct( $plugin_dir = null ) {
			$this->plugin_dir = $this->normalize_path(
				null === $plugin_dir ? dirname( __DIR__, 3 ) : $plugin_dir
			);
		}

		/**
		 * Return setup metadata for supported engines.
		 *
		 * @return array<string,array<string,mixed>> Engine manifests.
		 */
		public function engine_manifests() {
			return array(
				'sqlite'     => array(
					'label'       => 'SQLite',
					'description' => 'Stores the site in one local SQLite database file.',
				),
				'postgresql' => array(
					'label'       => 'PostgreSQL',
					'description' => 'Connects WordPress to a running PostgreSQL database.',
				),
				'duckdb'     => array(
					'label'       => 'DuckDB',
					'description' => 'Stores the site in one local DuckDB database file, or connects through a DuckDB sidecar.',
				),
			);
		}

		/**
		 * Install the configured database backend.
		 *
		 * @param array<string,mixed> $options Setup options.
		 * @return array<string,mixed> Result details.
		 *
		 * @throws RuntimeException When setup cannot complete.
		 * @throws InvalidArgumentException When options are invalid.
		 */
		public function install( array $options ) {
			$options    = $this->normalize_options( $options );
			$wp_path    = $options['wp_path'];
			$engine     = $options['engine'];
			$constants  = $this->constants_for_engine( $engine, $options );
			$warnings   = $this->requirement_warnings( $engine, $options );
			$dry_run    = ! empty( $options['dry_run'] );
			$config     = $this->prepare_wp_config( $wp_path, $constants, ! empty( $options['force'] ) );
			$dropin     = $this->prepare_dropin( $wp_path, ! empty( $options['force'] ) );
			$created    = array();
			$would_write = array(
				$config['path'],
				$dropin['path'],
			);

			if ( ! empty( $options['strict'] ) && ! empty( $warnings ) ) {
				throw new RuntimeException( 'Requirement check failed: ' . implode( '; ', $warnings ) );
			}

			if ( ! $dry_run ) {
				$this->ensure_directory( $wp_path . '/wp-content' );

				if ( in_array( $engine, array( 'sqlite', 'duckdb' ), true ) ) {
					$this->ensure_directory( rtrim( (string) $constants['DB_DIR'], '/\\' ) );
					$created[] = rtrim( (string) $constants['DB_DIR'], '/\\' );
				}

				if ( isset( $constants['DUCKDB_EXTERNAL_STORAGE_DIR'] ) && $this->is_local_duckdb_path( $constants['DUCKDB_EXTERNAL_STORAGE_DIR'] ) ) {
					$this->ensure_directory( rtrim( (string) $constants['DUCKDB_EXTERNAL_STORAGE_DIR'], '/\\' ) );
					$created[] = rtrim( (string) $constants['DUCKDB_EXTERNAL_STORAGE_DIR'], '/\\' );
				}

				$this->write_file( $config['path'], $config['contents'] );
				$this->write_file( $dropin['path'], $dropin['contents'] );
			}

			return array(
				'ok'              => true,
				'engine'          => $engine,
				'wp_path'         => $wp_path,
				'plugin_dir'      => $this->plugin_dir,
				'wp_config_path'  => $config['path'],
				'dropin_path'     => $dropin['path'],
				'constants'       => $constants,
				'warnings'        => $warnings,
				'created_dirs'    => array_values( array_unique( $created ) ),
				'would_write'     => $would_write,
				'dry_run'         => $dry_run,
				'install_url_hint' => 'wp-admin/install.php',
			);
		}

		/**
		 * Parse CLI arguments.
		 *
		 * @param array<int,string> $argv Raw argv.
		 * @return array<string,mixed> Options.
		 *
		 * @throws InvalidArgumentException When arguments are invalid.
		 */
		public function parse_cli_args( array $argv ) {
			$options = array();
			$count   = count( $argv );
			for ( $i = 1; $i < $count; ++$i ) {
				$arg = $argv[ $i ];
				if ( '--' === $arg ) {
					continue;
				}
				if ( 0 !== strpos( $arg, '--' ) ) {
					throw new InvalidArgumentException( 'Unexpected argument: ' . $arg );
				}

				$name  = substr( $arg, 2 );
				$value = true;
				$pos   = strpos( $name, '=' );
				if ( false !== $pos ) {
					$value = substr( $name, $pos + 1 );
					$name  = substr( $name, 0, $pos );
				} elseif ( $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
					$value = $argv[ ++$i ];
				}

				$name = str_replace( '-', '_', $name );
				if ( isset( $options[ $name ] ) ) {
					if ( ! is_array( $options[ $name ] ) ) {
						$options[ $name ] = array( $options[ $name ] );
					}
					$options[ $name ][] = $value;
				} else {
					$options[ $name ] = $value;
				}
			}

			return $options;
		}

		/**
		 * Run the CLI command.
		 *
		 * @param array<int,string> $argv Raw argv.
		 * @return int Exit code.
		 */
		public function run_cli( array $argv ) {
			try {
				$options = $this->parse_cli_args( $argv );
				if ( ! empty( $options['help'] ) || ! empty( $options['h'] ) ) {
					$this->print_cli_help();
					return 0;
				}

				if ( isset( $options['plugin_dir'] ) ) {
					$this->plugin_dir = $this->normalize_path( $options['plugin_dir'] );
				}

				$result = $this->install( $options );
				$this->print_cli_result( $result );
				return 0;
			} catch ( Exception $e ) {
				fwrite( STDERR, 'Database setup failed: ' . $e->getMessage() . "\n" );
				fwrite( STDERR, "Run with --help for usage.\n" );
				return 1;
			}
		}

		/**
		 * Render and handle the standalone web setup form.
		 *
		 * @param array<string,mixed> $server Request server data.
		 * @param array<string,mixed> $post   Request post data.
		 * @return void
		 */
		public function run_web( array $server, array $post ) {
			$wp_path = $this->infer_wp_path_for_web( $server );
			$result  = null;
			$error   = null;
			$method  = isset( $server['REQUEST_METHOD'] ) ? strtoupper( (string) $server['REQUEST_METHOD'] ) : 'GET';

			if ( 'POST' === $method ) {
				try {
					$options = $this->web_options_from_post( $post, $wp_path );
					$result  = $this->install( $options );
				} catch ( Exception $e ) {
					$error = $e->getMessage();
				}
			}

			$this->render_web_page( $wp_path, $post, $result, $error );
		}

		/**
		 * Normalize top-level setup options.
		 *
		 * @param array<string,mixed> $options Raw options.
		 * @return array<string,mixed> Normalized options.
		 *
		 * @throws InvalidArgumentException When options are invalid.
		 */
		private function normalize_options( array $options ) {
			$options = $this->normalize_aliases( $options );

			$wp_path = isset( $options['wp_path'] ) ? $options['wp_path'] : getcwd();
			$wp_path = $this->normalize_path( $wp_path );
			if ( ! is_dir( $wp_path ) ) {
				throw new InvalidArgumentException( 'WordPress path does not exist: ' . $wp_path );
			}

			if ( ! is_file( $this->plugin_dir . '/db.copy' ) ) {
				throw new RuntimeException( 'Could not find db.copy in plugin directory: ' . $this->plugin_dir );
			}

			$engine = isset( $options['engine'] ) ? $options['engine'] : null;
			if ( null === $engine || false === $engine || '' === trim( (string) $engine ) ) {
				throw new InvalidArgumentException( 'Choose a database engine with --engine=sqlite, --engine=postgresql, or --engine=duckdb.' );
			}

			$engine = $this->normalize_engine( $engine );
			if ( ! isset( $this->engine_manifests()[ $engine ] ) ) {
				throw new InvalidArgumentException( 'Unsupported database engine: ' . $engine );
			}

			$options['wp_path'] = $wp_path;
			$options['engine']  = $engine;

			foreach ( array( 'force', 'yes', 'dry_run', 'strict' ) as $flag ) {
				$options[ $flag ] = $this->truthy( isset( $options[ $flag ] ) ? $options[ $flag ] : false );
			}

			if ( empty( $options['yes'] ) && 'cli' === PHP_SAPI && empty( $options['dry_run'] ) ) {
				throw new InvalidArgumentException( 'Pass --yes to confirm writing wp-config.php and wp-content/db.php.' );
			}

			return $options;
		}

		/**
		 * Normalize common option aliases.
		 *
		 * @param array<string,mixed> $options Raw options.
		 * @return array<string,mixed> Options.
		 */
		private function normalize_aliases( array $options ) {
			$aliases = array(
				'path'                    => 'wp_path',
				'wordpress_path'          => 'wp_path',
				'db_name'                 => 'database_name',
				'db_user'                 => 'database_user',
				'db_password'             => 'database_password',
				'db_host'                 => 'database_host',
				'sqlite_file'             => 'sqlite_database_file',
				'duckdb_file'             => 'duckdb_database_file',
				'duckdb_connection'       => 'duckdb_connection_mode',
				'duckdb_socket'           => 'duckdb_remote_socket',
				'duckdb_host'             => 'duckdb_remote_host',
				'duckdb_port'             => 'duckdb_remote_port',
				'duckdb_url'              => 'duckdb_remote_url',
				'duckdb_sidecar'          => 'duckdb_sidecar_command',
				'duckdb_storage_backend' => 'duckdb_backend',
				'duckdb_external_dir'    => 'duckdb_external_storage_dir',
				'duckdb_json_dir'        => 'duckdb_external_storage_dir',
				'duckdb_working_file'    => 'duckdb_working_database_file',
				'y'                       => 'yes',
				'f'                       => 'force',
			);

			foreach ( $aliases as $alias => $canonical ) {
				if ( array_key_exists( $alias, $options ) && ! array_key_exists( $canonical, $options ) ) {
					$options[ $canonical ] = $options[ $alias ];
				}
			}

			return $options;
		}

		/**
		 * Normalize an engine name.
		 *
		 * @param mixed $engine Raw engine.
		 * @return string Engine.
		 */
		private function normalize_engine( $engine ) {
			$engine = strtolower( trim( (string) $engine ) );
			if ( in_array( $engine, array( 'postgres', 'pgsql' ), true ) ) {
				return 'postgresql';
			}
			if ( 'sqlite3' === $engine ) {
				return 'sqlite';
			}
			if ( in_array( $engine, array( 'duck', 'duckdb' ), true ) ) {
				return 'duckdb';
			}

			return $engine;
		}

		/**
		 * Build wp-config.php constants for an engine.
		 *
		 * @param string              $engine  Engine.
		 * @param array<string,mixed> $options Options.
		 * @return array<string,mixed> Constants.
		 */
		private function constants_for_engine( $engine, array $options ) {
			$db_dir = isset( $options['db_dir'] )
				? $this->normalize_path_against( $options['db_dir'], $options['wp_path'] )
				: $this->normalize_path( $options['wp_path'] . '/wp-content/database' );

			$constants = array(
				'DB_NAME'     => $this->option_string( $options, 'database_name', 'wordpress' ),
				'DB_USER'     => $this->option_string( $options, 'database_user', $engine ),
				'DB_PASSWORD' => $this->option_string( $options, 'database_password', $engine ),
				'DB_HOST'     => $this->option_string( $options, 'database_host', 'localhost' ),
				'DB_CHARSET'  => $this->option_string( $options, 'db_charset', 'utf8mb4' ),
				'DB_COLLATE'  => $this->option_string( $options, 'db_collate', '' ),
				'DB_ENGINE'   => $engine,
				'DATABASE_ENGINE' => $engine,
			);

			if ( 'postgresql' === $engine ) {
				$constants['DB_USER']     = $this->option_string( $options, 'database_user', 'wordpress' );
				$constants['DB_PASSWORD'] = $this->option_string( $options, 'database_password', '' );
				$constants['DB_HOST']     = $this->option_string( $options, 'database_host', '127.0.0.1:5432' );
				return $constants;
			}

			$constants['DB_DIR'] = rtrim( $db_dir, '/\\' ) . '/';
			if ( 'sqlite' === $engine ) {
				$constants['DB_FILE'] = $this->option_string( $options, 'sqlite_database_file', '.ht.sqlite' );
				return $constants;
			}

			$constants['DUCKDB_FILE']         = $this->option_string( $options, 'duckdb_database_file', '.ht.duckdb' );
			$constants['DUCKDB_PHP_AUTOLOAD'] = $this->option_string(
				$options,
				'duckdb_php_autoload',
				$this->plugin_dir . '/vendor/autoload.php'
			);

			$backend = $this->option_string( $options, 'duckdb_backend', '' );
			if ( '' !== $backend ) {
				$backend = strtolower( trim( $backend ) );
				$constants['DUCKDB_BACKEND'] = $backend;
				$constants['DUCKDB_WORKING_DATABASE_FILE'] = isset( $options['duckdb_working_database_file'] )
					? $this->normalize_duckdb_path_against( $options['duckdb_working_database_file'], $options['wp_path'] )
					: rtrim( $db_dir, '/\\' ) . '/.ht.duckdb-working';

				$constants['DUCKDB_EXTERNAL_STORAGE_DIR'] = isset( $options['duckdb_external_storage_dir'] )
					? $this->normalize_duckdb_path_against( $options['duckdb_external_storage_dir'], $options['wp_path'] )
					: rtrim( $db_dir, '/\\' ) . '/duckdb-' . $backend . '/';
			} elseif ( isset( $options['duckdb_working_database_file'] ) ) {
				$constants['DUCKDB_WORKING_DATABASE_FILE'] = $this->normalize_duckdb_path_against( $options['duckdb_working_database_file'], $options['wp_path'] );
			}

			foreach (
				array(
					'duckdb_backend_file_extension' => 'DUCKDB_BACKEND_FILE_EXTENSION',
					'duckdb_backend_read_sql'       => 'DUCKDB_BACKEND_READ_SQL',
					'duckdb_backend_write_sql'      => 'DUCKDB_BACKEND_WRITE_SQL',
					'duckdb_backend_setup_sql'      => 'DUCKDB_BACKEND_SETUP_SQL',
					'duckdb_backend_tables'         => 'DUCKDB_BACKEND_TABLES',
					'duckdb_metadata_manifest_file' => 'DUCKDB_METADATA_MANIFEST_FILE',
				) as $option => $constant
			) {
				if ( array_key_exists( $option, $options ) && false !== $options[ $option ] && null !== $options[ $option ] && '' !== $options[ $option ] ) {
					$constants[ $constant ] = in_array( $option, array( 'duckdb_metadata_manifest_file' ), true )
						? $this->normalize_duckdb_path_against( $options[ $option ], $options['wp_path'] )
						: $options[ $option ];
				}
			}

			if ( array_key_exists( 'duckdb_backend_atomic_flush', $options ) ) {
				$constants['DUCKDB_BACKEND_ATOMIC_FLUSH'] = $this->truthy( $options['duckdb_backend_atomic_flush'] );
			}

			$connection = $this->option_string( $options, 'duckdb_connection_mode', '' );
			if ( '' !== $connection ) {
				$constants['DUCKDB_CONNECTION'] = $connection;
			}
			foreach (
				array(
					'duckdb_remote_socket'   => 'DUCKDB_REMOTE_SOCKET',
					'duckdb_remote_host'     => 'DUCKDB_REMOTE_HOST',
					'duckdb_remote_port'     => 'DUCKDB_REMOTE_PORT',
					'duckdb_remote_url'      => 'DUCKDB_REMOTE_URL',
					'duckdb_sidecar_command' => 'DUCKDB_SIDECAR_COMMAND',
				) as $option => $constant
			) {
				$value = $this->option_string( $options, $option, '' );
				if ( '' !== $value ) {
					$constants[ $constant ] = $value;
				}
			}

			return $constants;
		}

		/**
		 * Prepare wp-config.php contents.
		 *
		 * @param string              $wp_path   WordPress root.
		 * @param array<string,mixed> $constants Constants.
		 * @param bool                $force     Whether to update unmanaged configs.
		 * @return array{path:string,contents:string}
		 *
		 * @throws RuntimeException When config cannot be safely prepared.
		 */
		private function prepare_wp_config( $wp_path, array $constants, $force ) {
			$config_path = $wp_path . '/wp-config.php';
			$sample_path = $wp_path . '/wp-config-sample.php';
			$exists      = is_file( $config_path );
			$contents    = '';

			if ( $exists ) {
				$contents = file_get_contents( $config_path );
				if ( ! is_string( $contents ) ) {
					throw new RuntimeException( 'Could not read existing wp-config.php.' );
				}
				if ( false === strpos( $contents, self::MANAGED_BLOCK_START ) && ! $force ) {
					throw new RuntimeException( 'wp-config.php already exists and is not managed by this setup helper. Re-run with --force to update it.' );
				}
			} elseif ( is_file( $sample_path ) ) {
				$contents = file_get_contents( $sample_path );
				if ( ! is_string( $contents ) ) {
					throw new RuntimeException( 'Could not read wp-config-sample.php.' );
				}
			} else {
				$contents = $this->minimal_wp_config_template();
			}

			$contents = $this->remove_managed_block( $contents );
			$contents = $this->remove_constant_defines( $contents, array_keys( $constants ) );
			$contents = $this->insert_managed_block( $contents, $this->format_constants_block( $constants ) );

			if ( false === strpos( $contents, '$table_prefix' ) ) {
				$contents = $this->insert_before_bootstrap( $contents, "\n\$table_prefix = 'wp_';\n" );
			}
			if ( false === strpos( $contents, "require_once ABSPATH . 'wp-settings.php'" ) ) {
				$contents .= "\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
			}

			return array(
				'path'     => $config_path,
				'contents' => $contents,
			);
		}

		/**
		 * Prepare wp-content/db.php contents.
		 *
		 * @param string $wp_path WordPress root.
		 * @param bool   $force   Whether to overwrite unmanaged drop-ins.
		 * @return array{path:string,contents:string}
		 *
		 * @throws RuntimeException When drop-in cannot be safely prepared.
		 */
		private function prepare_dropin( $wp_path, $force ) {
			$source = file_get_contents( $this->plugin_dir . '/db.copy' );
			if ( ! is_string( $source ) ) {
				throw new RuntimeException( 'Could not read db.copy.' );
			}

			$contents = str_replace(
				'{WORDPRESS_DATABASES_SUPPORT_IMPLEMENTATION_FOLDER_PATH}',
				$this->plugin_dir,
				$source
			);
			$path     = $wp_path . '/wp-content/db.php';

			if ( is_file( $path ) ) {
				$current = file_get_contents( $path );
				if ( ! is_string( $current ) ) {
					throw new RuntimeException( 'Could not read existing wp-content/db.php.' );
				}
				if ( $current === $contents ) {
					return array(
						'path'     => $path,
						'contents' => $contents,
					);
				}
				if ( false === strpos( $current, 'WORDPRESS_DATABASES_SUPPORT_DB_DROPIN_VERSION' ) && ! $force ) {
					throw new RuntimeException( 'wp-content/db.php already exists and is not this plugin drop-in. Re-run with --force to overwrite it.' );
				}
			}

			return array(
				'path'     => $path,
				'contents' => $contents,
			);
		}

		/**
		 * Return requirement warnings for an engine.
		 *
		 * @param string              $engine  Engine.
		 * @param array<string,mixed> $options Options.
		 * @return string[] Warning messages.
		 */
		private function requirement_warnings( $engine, array $options ) {
			$warnings = array();

			if ( 'sqlite' === $engine ) {
				if ( ! extension_loaded( 'pdo_sqlite' ) ) {
					$warnings[] = 'PHP extension pdo_sqlite is not loaded.';
				}
				if ( ! is_file( $this->plugin_dir . '/external/sqlite-database-integration/packages/plugin-sqlite-database-integration/wp-includes/sqlite/db.php' ) ) {
					$warnings[] = 'SQLite support needs the external/sqlite-database-integration submodule or packaged SQLite files.';
				}
			}

			if ( 'postgresql' === $engine ) {
				if ( ! extension_loaded( 'pdo' ) ) {
					$warnings[] = 'PHP extension pdo is not loaded.';
				}
				if ( ! extension_loaded( 'pdo_pgsql' ) ) {
					$warnings[] = 'PHP extension pdo_pgsql is not loaded.';
				}
			}

			if ( 'duckdb' === $engine ) {
				$connection = strtolower( $this->option_string( $options, 'duckdb_connection_mode', 'ffi' ) );
				if ( in_array( $connection, array( 'ffi', 'embedded', 'embedded-ffi', 'direct', 'native' ), true ) ) {
					if ( ! extension_loaded( 'ffi' ) ) {
						$warnings[] = 'PHP extension ffi is not loaded; embedded DuckDB needs ffi.enable=1.';
					}
					$autoload = $this->option_string( $options, 'duckdb_php_autoload', $this->plugin_dir . '/vendor/autoload.php' );
					if ( ! is_file( $autoload ) ) {
						$warnings[] = 'DuckDB PHP autoload file was not found at ' . $autoload . '.';
					}
				}
			}

			return $warnings;
		}

		/**
		 * Remove this helper's managed block.
		 *
		 * @param string $contents Config contents.
		 * @return string Config contents.
		 */
		private function remove_managed_block( $contents ) {
			$pattern = '/' . preg_quote( self::MANAGED_BLOCK_START, '/' ) . '.*?' . preg_quote( self::MANAGED_BLOCK_END, '/' ) . "\\s*/s";
			return preg_replace( $pattern, '', $contents );
		}

		/**
		 * Remove one-line define statements for managed constants.
		 *
		 * @param string   $contents Config contents.
		 * @param string[] $constants Constant names.
		 * @return string Config contents.
		 */
		private function remove_constant_defines( $contents, array $constants ) {
			foreach ( $constants as $constant ) {
				$pattern  = '/^[ \t]*define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,.*\);\s*\R?/m';
				$contents = preg_replace( $pattern, '', $contents );
			}

			return $contents;
		}

		/**
		 * Insert the managed constants block before WordPress bootstraps.
		 *
		 * @param string $contents Config contents.
		 * @param string $block    Managed constants block.
		 * @return string Config contents.
		 */
		private function insert_managed_block( $contents, $block ) {
			return $this->insert_before_bootstrap( $contents, "\n" . $block . "\n" );
		}

		/**
		 * Insert text before ABSPATH/wp-settings bootstrap when possible.
		 *
		 * @param string $contents Config contents.
		 * @param string $insert   Text to insert.
		 * @return string Config contents.
		 */
		private function insert_before_bootstrap( $contents, $insert ) {
			$patterns = array(
				'/\/\*\s*That\'s all, stop editing!.*?\*\//i',
				'/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)/',
				'/require_once\s+ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*;/',
			);

			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
					return substr( $contents, 0, $matches[0][1] ) . $insert . substr( $contents, $matches[0][1] );
				}
			}

			return rtrim( $contents ) . "\n" . $insert;
		}

		/**
		 * Format constants as PHP code.
		 *
		 * @param array<string,mixed> $constants Constants.
		 * @return string PHP code.
		 */
		private function format_constants_block( array $constants ) {
			$lines   = array( self::MANAGED_BLOCK_START );
			$lines[] = '// Generated by wordpress-databases-support/bin/setup-database.php.';
			foreach ( $constants as $name => $value ) {
				$lines[] = 'define( ' . var_export( $name, true ) . ', ' . var_export( $value, true ) . ' );';
			}
			$lines[] = self::MANAGED_BLOCK_END;

			return implode( "\n", $lines ) . "\n";
		}

		/**
		 * Minimal wp-config.php when wp-config-sample.php is unavailable.
		 *
		 * @return string Config template.
		 */
		private function minimal_wp_config_template() {
			$salts = array(
				'AUTH_KEY',
				'SECURE_AUTH_KEY',
				'LOGGED_IN_KEY',
				'NONCE_KEY',
				'AUTH_SALT',
				'SECURE_AUTH_SALT',
				'LOGGED_IN_SALT',
				'NONCE_SALT',
			);

			$config = "<?php\n";
			foreach ( $salts as $salt ) {
				$config .= 'define( ' . var_export( $salt, true ) . ', ' . var_export( $this->random_secret(), true ) . " );\n";
			}
			$config .= "\n\$table_prefix = 'wp_';\n\n";
			$config .= "if ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\n";
			$config .= "require_once ABSPATH . 'wp-settings.php';\n";

			return $config;
		}

		/**
		 * Normalize a filesystem path without requiring it to exist.
		 *
		 * @param mixed $path Path.
		 * @return string Path.
		 */
		private function normalize_path( $path ) {
			$path = str_replace( '\\', '/', trim( (string) $path ) );
			if ( '' === $path ) {
				return '';
			}
			$real = realpath( $path );
			if ( is_string( $real ) ) {
				return rtrim( str_replace( '\\', '/', $real ), '/' );
			}
			if ( 0 !== strpos( $path, '/' ) ) {
				$path = getcwd() . '/' . $path;
			}

			return rtrim( $path, '/' );
		}

		/**
		 * Normalize a path, resolving relative paths against a base directory.
		 *
		 * @param mixed  $path Path.
		 * @param string $base Base directory.
		 * @return string Path.
		 */
		private function normalize_path_against( $path, $base ) {
			$path = str_replace( '\\', '/', trim( (string) $path ) );
			if ( '' !== $path && 0 !== strpos( $path, '/' ) ) {
				$path = rtrim( $base, '/\\' ) . '/' . $path;
			}

			return $this->normalize_path( $path );
		}

		/**
		 * Normalize a DuckDB path-like setting without rewriting URI storage.
		 *
		 * @param mixed  $path Path, URI, or :memory: value.
		 * @param string $base Base directory for relative local paths.
		 * @return string Path.
		 */
		private function normalize_duckdb_path_against( $path, $base ) {
			$path = str_replace( '\\', '/', trim( (string) $path ) );
			if ( '' === $path || ':memory:' === $path || 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) ) {
				return $path;
			}

			return $this->normalize_path_against( $path, $base );
		}

		/**
		 * Check whether a DuckDB path-like setting is a local filesystem path.
		 *
		 * @param mixed $path Path-like setting.
		 * @return bool Whether it points to the local filesystem.
		 */
		private function is_local_duckdb_path( $path ) {
			$path = trim( (string) $path );
			return '' !== $path && ':memory:' !== $path && 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path );
		}

		/**
		 * Ensure a directory exists.
		 *
		 * @param string $dir Directory.
		 * @return void
		 *
		 * @throws RuntimeException When the directory cannot be created.
		 */
		private function ensure_directory( $dir ) {
			if ( is_dir( $dir ) ) {
				return;
			}
			if ( ! mkdir( $dir, 0775, true ) && ! is_dir( $dir ) ) {
				throw new RuntimeException( 'Could not create directory: ' . $dir );
			}
		}

		/**
		 * Write a file atomically enough for local setup.
		 *
		 * @param string $path     Path.
		 * @param string $contents Contents.
		 * @return void
		 *
		 * @throws RuntimeException When write fails.
		 */
		private function write_file( $path, $contents ) {
			$this->ensure_directory( dirname( $path ) );
			$tmp = $path . '.tmp-' . getmypid() . '-' . mt_rand();
			if ( false === file_put_contents( $tmp, $contents ) ) {
				throw new RuntimeException( 'Could not write temporary file: ' . $tmp );
			}
			if ( ! rename( $tmp, $path ) ) {
				@unlink( $tmp );
				throw new RuntimeException( 'Could not write file: ' . $path );
			}
		}

		/**
		 * Get a string option.
		 *
		 * @param array<string,mixed> $options Options.
		 * @param string              $key     Option key.
		 * @param string              $default Default value.
		 * @return string Option value.
		 */
		private function option_string( array $options, $key, $default ) {
			if ( ! array_key_exists( $key, $options ) || false === $options[ $key ] || null === $options[ $key ] ) {
				return $default;
			}

			return (string) $options[ $key ];
		}

		/**
		 * Interpret a boolean option.
		 *
		 * @param mixed $value Value.
		 * @return bool Whether it is truthy.
		 */
		private function truthy( $value ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( is_int( $value ) ) {
				return 0 !== $value;
			}
			if ( ! is_string( $value ) ) {
				return ! empty( $value );
			}

			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		/**
		 * Generate a local random secret.
		 *
		 * @return string Secret.
		 */
		private function random_secret() {
			try {
				return bin2hex( random_bytes( 32 ) );
			} catch ( Exception $e ) {
				return sha1( uniqid( 'wordpress-databases-support-', true ) );
			}
		}

		/**
		 * Infer WordPress root from a standard plugin directory.
		 *
		 * @return string WordPress root.
		 */
		private function infer_wp_path_from_plugin_dir() {
			$wp_path = dirname( $this->plugin_dir, 3 );
			if ( basename( dirname( $this->plugin_dir ) ) === 'plugins' && basename( dirname( dirname( $this->plugin_dir ) ) ) === 'wp-content' ) {
				return $this->normalize_path( $wp_path );
			}

			return $this->normalize_path( getcwd() );
		}

		/**
		 * Infer WordPress root for the browser setup entrypoint.
		 *
		 * The plugin directory may be symlinked. In that case __DIR__ and realpath()
		 * point at the repository, while the web request still maps to the actual
		 * WordPress tree. Prefer web server paths before falling back to plugin_dir.
		 *
		 * @param array<string,mixed> $server Request server data.
		 * @return string WordPress root.
		 */
		private function infer_wp_path_for_web( array $server ) {
			$document_root = isset( $server['DOCUMENT_ROOT'] ) ? str_replace( '\\', '/', (string) $server['DOCUMENT_ROOT'] ) : '';
			$request_uri   = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';
			$request_path  = '' !== $request_uri ? (string) parse_url( $request_uri, PHP_URL_PATH ) : '';
			if ( '' !== $document_root && '' !== $request_path ) {
				$marker = '/wp-content/plugins/';
				$pos    = strpos( $request_path, $marker );
				if ( false !== $pos ) {
					$candidate = rtrim( $document_root, '/\\' ) . substr( $request_path, 0, $pos );
					if ( $this->looks_like_wordpress_root( $candidate ) ) {
						return $this->normalize_path( $candidate );
					}
				}
			}

			$script_filename = isset( $server['SCRIPT_FILENAME'] ) ? str_replace( '\\', '/', (string) $server['SCRIPT_FILENAME'] ) : '';
			if ( '' !== $script_filename ) {
				$marker = '/wp-content/plugins/';
				$pos    = strpos( $script_filename, $marker );
				if ( false !== $pos ) {
					$candidate = substr( $script_filename, 0, $pos );
					if ( $this->looks_like_wordpress_root( $candidate ) ) {
						return $this->normalize_path( $candidate );
					}
				}
			}

			if ( '' !== $document_root && $this->looks_like_wordpress_root( $document_root ) ) {
				return $this->normalize_path( $document_root );
			}

			return $this->infer_wp_path_from_plugin_dir();
		}

		/**
		 * Check whether a directory looks like a WordPress root.
		 *
		 * @param string $path Candidate root.
		 * @return bool Whether the path looks like a WordPress root.
		 */
		private function looks_like_wordpress_root( $path ) {
			$path = rtrim( str_replace( '\\', '/', (string) $path ), '/\\' );
			if ( '' === $path || ! is_dir( $path ) ) {
				return false;
			}

			return is_dir( $path . '/wp-content' )
				|| is_file( $path . '/wp-config-sample.php' )
				|| is_file( $path . '/wp-load.php' )
				|| is_dir( $path . '/wp-admin' );
		}

		/**
		 * Convert web POST data into install options.
		 *
		 * @param array<string,mixed> $post    POST data.
		 * @param string              $wp_path WordPress root.
		 * @return array<string,mixed> Options.
		 */
		private function web_options_from_post( array $post, $wp_path ) {
			$options = array(
				'wp_path' => $wp_path,
				'engine'  => isset( $post['engine'] ) ? (string) $post['engine'] : '',
				'yes'     => true,
			);

			$fields = array(
				'database_name',
				'database_user',
				'database_password',
				'database_host',
				'db_dir',
				'sqlite_database_file',
				'duckdb_database_file',
				'duckdb_connection_mode',
				'duckdb_remote_socket',
				'duckdb_remote_host',
				'duckdb_remote_port',
				'duckdb_remote_url',
				'duckdb_sidecar_command',
			);
			foreach ( $fields as $field ) {
				if ( isset( $post[ $field ] ) && '' !== trim( (string) $post[ $field ] ) ) {
					$options[ $field ] = trim( (string) $post[ $field ] );
				}
			}

			return $options;
		}

		/**
		 * Render the standalone web page.
		 *
		 * @param string                   $wp_path WordPress root.
		 * @param array<string,mixed>      $post    POST data.
		 * @param array<string,mixed>|null $result  Setup result.
		 * @param string|null              $error   Error message.
		 * @return void
		 */
		private function render_web_page( $wp_path, array $post, $result, $error ) {
			$existing_config = is_file( $wp_path . '/wp-config.php' );
			$can_submit      = ! $existing_config || ( is_array( $result ) && ! empty( $result['ok'] ) );
			$engine          = isset( $post['engine'] ) ? (string) $post['engine'] : 'duckdb';

			header( 'Content-Type: text/html; charset=utf-8' );
			echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
			echo '<title>WordPress Database Setup</title>';
			echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:880px;margin:40px auto;padding:0 20px;line-height:1.45;color:#1d2327}label{display:block;font-weight:600;margin-top:16px}input,select{box-sizing:border-box;width:100%;max-width:560px;padding:8px;border:1px solid #8c8f94;border-radius:4px}.button{display:inline-block;margin-top:20px;padding:9px 14px;background:#2271b1;color:#fff;border:0;border-radius:4px;text-decoration:none;cursor:pointer}.notice{padding:12px 14px;border-left:4px solid #72aee6;background:#f0f6fc;margin:20px 0}.error{border-color:#d63638;background:#fcf0f1}.success{border-color:#00a32a;background:#edfaef}code{background:#f6f7f7;padding:2px 4px}</style>';
			echo '</head><body>';
			echo '<h1>WordPress Database Setup</h1>';
			echo '<p>Choose the database driver before running the normal WordPress installer.</p>';
			echo '<p><strong>WordPress path:</strong> <code>' . $this->esc_html( $wp_path ) . '</code></p>';

			if ( is_array( $result ) && ! empty( $result['ok'] ) ) {
				echo '<div class="notice success"><p><strong>Database driver configured.</strong></p>';
				echo '<p>Drop-in: <code>' . $this->esc_html( $result['dropin_path'] ) . '</code></p>';
				echo '<p>Config: <code>' . $this->esc_html( $result['wp_config_path'] ) . '</code></p>';
				if ( ! empty( $result['warnings'] ) ) {
					echo '<p><strong>Warnings:</strong></p><ul>';
					foreach ( $result['warnings'] as $warning ) {
						echo '<li>' . $this->esc_html( $warning ) . '</li>';
					}
					echo '</ul>';
				}
				echo '<p><a class="button" href="../../../wp-admin/install.php">Continue to WordPress install</a></p></div>';
				echo '</body></html>';
				return;
			} elseif ( null !== $error ) {
				echo '<div class="notice error"><strong>Setup failed:</strong> ' . $this->esc_html( $error ) . '</div>';
			}

			if ( $existing_config && null === $result ) {
				echo '<div class="notice error"><p><strong>wp-config.php already exists.</strong></p><p>This web setup is intended for a new WordPress site. Use the CLI with <code>--force</code> if you intentionally want to update an existing config.</p></div>';
				echo '</body></html>';
				return;
			}

			if ( ! $can_submit ) {
				echo '</body></html>';
				return;
			}

			echo '<form method="post">';
			echo '<label for="engine">Database driver</label>';
			echo '<select id="engine" name="engine">';
			foreach ( $this->engine_manifests() as $key => $manifest ) {
				$selected = $engine === $key ? ' selected' : '';
				echo '<option value="' . $this->esc_attr( $key ) . '"' . $selected . '>' . $this->esc_html( $manifest['label'] ) . '</option>';
			}
			echo '</select>';

			$this->render_input( 'database_name', 'Database name', $post, 'wordpress' );
			$this->render_input( 'database_user', 'Database user', $post, 'wordpress' );
			$this->render_input( 'database_password', 'Database password', $post, '' );
			$this->render_input( 'database_host', 'Database host', $post, '127.0.0.1:5432' );
			$this->render_input( 'db_dir', 'Local database directory for SQLite/DuckDB', $post, $wp_path . '/wp-content/database' );
			$this->render_input( 'sqlite_database_file', 'SQLite file name', $post, '.ht.sqlite' );
			$this->render_input( 'duckdb_database_file', 'DuckDB file name', $post, '.ht.duckdb' );
			$this->render_input( 'duckdb_connection_mode', 'DuckDB connection mode', $post, 'ffi' );
			$this->render_input( 'duckdb_remote_socket', 'DuckDB Unix socket path', $post, '' );
			$this->render_input( 'duckdb_remote_host', 'DuckDB TCP host', $post, '' );
			$this->render_input( 'duckdb_remote_port', 'DuckDB TCP port', $post, '' );
			$this->render_input( 'duckdb_remote_url', 'DuckDB HTTP URL', $post, '' );
			$this->render_input( 'duckdb_sidecar_command', 'DuckDB managed sidecar command', $post, '' );

			echo '<button class="button" type="submit">Configure database driver</button>';
			echo '</form>';
			echo '</body></html>';
		}

		/**
		 * Render a form input.
		 *
		 * @param string              $name    Field name.
		 * @param string              $label   Label.
		 * @param array<string,mixed> $post    POST data.
		 * @param string              $default Default value.
		 * @return void
		 */
		private function render_input( $name, $label, array $post, $default ) {
			$value = isset( $post[ $name ] ) ? (string) $post[ $name ] : $default;
			echo '<label for="' . $this->esc_attr( $name ) . '">' . $this->esc_html( $label ) . '</label>';
			echo '<input id="' . $this->esc_attr( $name ) . '" name="' . $this->esc_attr( $name ) . '" value="' . $this->esc_attr( $value ) . '">';
		}

		/**
		 * Escape HTML text.
		 *
		 * @param mixed $value Value.
		 * @return string Escaped value.
		 */
		private function esc_html( $value ) {
			return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
		}

		/**
		 * Escape HTML attribute.
		 *
		 * @param mixed $value Value.
		 * @return string Escaped value.
		 */
		private function esc_attr( $value ) {
			return $this->esc_html( $value );
		}

		/**
		 * Print CLI result.
		 *
		 * @param array<string,mixed> $result Setup result.
		 * @return void
		 */
		private function print_cli_result( array $result ) {
			if ( ! empty( $result['dry_run'] ) ) {
				echo "Dry run complete. Would write:\n";
				foreach ( $result['would_write'] as $path ) {
					echo ' - ' . $path . "\n";
				}
			} else {
				echo 'Configured WordPress for ' . $result['engine'] . ".\n";
				echo 'wp-config.php: ' . $result['wp_config_path'] . "\n";
				echo 'drop-in: ' . $result['dropin_path'] . "\n";
			}

			if ( ! empty( $result['warnings'] ) ) {
				echo "Warnings:\n";
				foreach ( $result['warnings'] as $warning ) {
					echo ' - ' . $warning . "\n";
				}
			}
			echo 'Next: open ' . rtrim( $result['wp_path'], '/\\' ) . '/' . $result['install_url_hint'] . "\n";
		}

		/**
		 * Print CLI help.
		 *
		 * @return void
		 */
		private function print_cli_help() {
			echo <<<'TEXT'
Usage:
  php bin/setup-database.php --wp-path=/path/to/wordpress --engine=duckdb --yes
  php -r "require 'wp-content/plugins/wordpress-databases-support/bin/setup-database.php';" -- --engine=sqlite --yes

Required:
  --engine=sqlite|postgresql|duckdb
  --yes                         Confirm writes when running from CLI.

Common options:
  --wp-path=.                   WordPress root. Defaults to the current directory.
  --plugin-dir=PATH             Plugin root if the script cannot infer it.
  --force                       Update an existing unmanaged wp-config.php or db.php.
  --dry-run                     Show files that would be written.
  --strict                      Fail if PHP extensions or packaged drivers are missing.

PostgreSQL:
  --db-name=wordpress --db-user=wordpress --db-password=secret --db-host=127.0.0.1:5432

SQLite:
  --db-dir=wp-content/database --sqlite-file=.ht.sqlite

DuckDB:
  --db-dir=wp-content/database --duckdb-file=.ht.duckdb
  --duckdb-backend=json
  --duckdb-external-storage-dir=wp-content/database/duckdb-json
  --duckdb-working-database-file=wp-content/database/.ht.duckdb-working
  --duckdb-connection=ffi|unix|tcp|http|sidecar
  --duckdb-socket=/run/wp-duckdb/wordpress.sock
  --duckdb-host=127.0.0.1 --duckdb-port=9901
  --duckdb-url=http://127.0.0.1:9902/query
  --duckdb-sidecar="php -d ffi.enable=1 .../bin/duckdb-sidecar.php --stdio --path=..."
  --duckdb-backend-file-extension=psv
  --duckdb-backend-read-sql="SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')"
  --duckdb-backend-write-sql="COPY {table} TO {path} (HEADER, DELIMITER '|')"

TEXT;
		}
	}
}
