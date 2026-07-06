#!/usr/bin/env php
<?php
/**
 * Bootstrap installer for WordPress Databases Support.
 *
 * This script is intentionally standalone so it can be run with:
 *
 * curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php
 *
 * @package wordpress-databases-support
 */

if ( ! class_exists( 'WP_Databases_Support_Bootstrap_Installer' ) ) {
	/**
	 * Downloads the plugin, installs it into wp-content/plugins, and optionally
	 * hands off to bin/setup-database.php.
	 */
	class WP_Databases_Support_Bootstrap_Installer {
		const PLUGIN_SLUG = 'wordpress-databases-support';
		const PLUGIN_ZIP_NAME = 'wordpress-databases-support.zip';
		const DEFAULT_REPO_OWNER = 'adamziel';
		const DEFAULT_REPO_NAME = 'wordpress-databases-support';
		const DEFAULT_REF = 'trunk';
		const DEFAULT_PLUGIN_RELEASE = 'latest';
		const SQLITE_REPO_OWNER = 'WordPress';
		const SQLITE_REPO_NAME = 'sqlite-database-integration';
		const SQLITE_PLUGIN_RELEASE = 'v3.0.0-rc.7';
		const SQLITE_PLUGIN_ZIP_NAME = 'plugin-sqlite-database-integration.zip';

		/**
		 * Temporary directories to remove before exit.
		 *
		 * @var string[]
		 */
		private $temp_dirs = array();

		/**
		 * Run the CLI installer.
		 *
		 * @param array<int,string> $argv Raw argv.
		 * @return int Exit code.
		 */
		public function run_cli( array $argv ) {
			try {
				$parsed = $this->parse_cli_args( $argv );
				if ( ! empty( $parsed['options']['help'] ) || ! empty( $parsed['options']['h'] ) ) {
					$this->print_help();
					return 0;
				}

				$options    = $this->normalize_options( $parsed['options'], $parsed['setup_args'] );
				$plugin_dir = $this->install_plugin( $options );

				if ( ! empty( $options['install_duckdb_client'] ) ) {
					$this->install_duckdb_client( $plugin_dir );
				}

				if ( 'cli' === $options['setup_mode'] ) {
					$setup_exit = $this->run_setup_cli( $plugin_dir, $options, $parsed['setup_args'] );
					$this->cleanup();
					return $setup_exit;
				}

				$this->print_install_result( $plugin_dir, $options );
				$this->cleanup();
				return 0;
			} catch ( Exception $e ) {
				$this->cleanup();
				fwrite( STDERR, 'Install failed: ' . $e->getMessage() . "\n" );
				fwrite( STDERR, "Run with --help for usage.\n" );
				return 1;
			}
		}

		/**
		 * Parse CLI flags into installer options and setup flags.
		 *
		 * Unknown flags are preserved for bin/setup-database.php.
		 *
		 * @param array<int,string> $argv Raw argv.
		 * @return array{options:array<string,mixed>,setup_args:string[]}
		 */
		public function parse_cli_args( array $argv ) {
			$options          = array();
			$setup_args       = array();
			$installer_only   = array(
				'help'                  => true,
				'h'                     => true,
				'setup'                 => true,
				'source_ref'            => true,
				'ref'                   => true,
				'source_zip'            => true,
				'source_zip_url'        => true,
				'repo_zip'              => true,
				'repo_zip_url'          => true,
				'plugin_zip'            => true,
				'plugin_zip_url'        => true,
				'release'               => true,
				'version'               => true,
				'sqlite_ref'            => true,
				'sqlite_zip'            => true,
				'sqlite_zip_url'        => true,
				'force_install'         => true,
				'install_duckdb_client' => true,
				'with_duckdb_client'    => true,
			);
			$shared          = array(
				'wp_path'         => true,
				'path'            => true,
				'wordpress_path'  => true,
				'plugin_dir'      => true,
				'plugin_path'     => true,
				'force'           => true,
				'f'               => true,
			);
			$count           = count( $argv );

			for ( $i = 1; $i < $count; ++$i ) {
				$arg = $argv[ $i ];
				if ( '--' === $arg ) {
					continue;
				}
				if ( 0 !== strpos( $arg, '--' ) ) {
					$setup_args[] = $arg;
					continue;
				}

				$name      = substr( $arg, 2 );
				$value     = true;
				$tokens    = array( $arg );
				$separator = strpos( $name, '=' );
				if ( false !== $separator ) {
					$value = substr( $name, $separator + 1 );
					$name  = substr( $name, 0, $separator );
				} elseif ( $i + 1 < $count && 0 !== strpos( $argv[ $i + 1 ], '--' ) ) {
					$value    = $argv[ ++$i ];
					$tokens[] = $value;
				}

				$name = str_replace( '-', '_', $name );
				if ( isset( $installer_only[ $name ] ) || isset( $shared[ $name ] ) ) {
					$options[ $name ] = $value;
				}
				if ( ! isset( $installer_only[ $name ] ) && ! in_array( $name, array( 'wp_path', 'path', 'wordpress_path', 'plugin_dir', 'plugin_path' ), true ) ) {
					foreach ( $tokens as $token ) {
						$setup_args[] = $token;
					}
				}
			}

			return array(
				'options'    => $options,
				'setup_args' => $setup_args,
			);
		}

		/**
		 * Normalize installer options.
		 *
		 * @param array<string,mixed> $options    Raw options.
		 * @param string[]            $setup_args Setup args.
		 * @return array<string,mixed> Options.
		 */
		private function normalize_options( array $options, array $setup_args ) {
			if ( isset( $options['path'] ) && ! isset( $options['wp_path'] ) ) {
				$options['wp_path'] = $options['path'];
			}
			if ( isset( $options['wordpress_path'] ) && ! isset( $options['wp_path'] ) ) {
				$options['wp_path'] = $options['wordpress_path'];
			}
			if ( isset( $options['plugin_path'] ) && ! isset( $options['plugin_dir'] ) ) {
				$options['plugin_dir'] = $options['plugin_path'];
			}
			if ( isset( $options['ref'] ) && ! isset( $options['source_ref'] ) ) {
				$options['source_ref'] = $options['ref'];
			}
			if ( isset( $options['repo_zip'] ) && ! isset( $options['source_zip'] ) ) {
				$options['source_zip'] = $options['repo_zip'];
			}
			if ( isset( $options['repo_zip_url'] ) && ! isset( $options['source_zip_url'] ) ) {
				$options['source_zip_url'] = $options['repo_zip_url'];
			}
			if ( isset( $options['with_duckdb_client'] ) && ! isset( $options['install_duckdb_client'] ) ) {
				$options['install_duckdb_client'] = $options['with_duckdb_client'];
			}
			if ( isset( $options['version'] ) && ! isset( $options['release'] ) ) {
				$options['release'] = $options['version'];
			}

			$use_source_archive = isset( $options['source_ref'] ) || isset( $options['source_zip'] ) || isset( $options['source_zip_url'] );

			$wp_path = isset( $options['wp_path'] ) ? $options['wp_path'] : getcwd();
			$wp_path = $this->normalize_path( $wp_path );
			if ( ! is_dir( $wp_path ) ) {
				throw new InvalidArgumentException( 'WordPress path does not exist: ' . $wp_path );
			}

			$plugin_dir = isset( $options['plugin_dir'] )
				? $this->normalize_path( $options['plugin_dir'] )
				: $this->normalize_path( $wp_path . '/wp-content/plugins/' . self::PLUGIN_SLUG );

			$setup_mode = isset( $options['setup'] ) ? strtolower( (string) $options['setup'] ) : '';
			if ( '' === $setup_mode ) {
				$setup_mode = $this->setup_args_include_engine( $setup_args ) ? 'cli' : 'browser';
			}
			if ( in_array( $setup_mode, array( 'web', 'wizard' ), true ) ) {
				$setup_mode = 'browser';
			}
			if ( in_array( $setup_mode, array( 'command', 'setup-cli' ), true ) ) {
				$setup_mode = 'cli';
			}
			if ( in_array( $setup_mode, array( 'none', 'install-only' ), true ) ) {
				$setup_mode = 'none';
			}
			if ( ! in_array( $setup_mode, array( 'browser', 'cli', 'none' ), true ) ) {
				throw new InvalidArgumentException( 'Unsupported --setup value. Use browser, cli, or none.' );
			}

			return array(
				'wp_path'               => $wp_path,
				'plugin_dir'            => $plugin_dir,
				'setup_mode'            => $setup_mode,
				'source_ref'            => isset( $options['source_ref'] ) ? (string) $options['source_ref'] : self::DEFAULT_REF,
				'source_zip'            => isset( $options['source_zip'] ) ? (string) $options['source_zip'] : '',
				'source_zip_url'        => isset( $options['source_zip_url'] ) ? (string) $options['source_zip_url'] : '',
				'plugin_zip'            => isset( $options['plugin_zip'] ) ? (string) $options['plugin_zip'] : '',
				'plugin_zip_url'        => isset( $options['plugin_zip_url'] ) ? (string) $options['plugin_zip_url'] : '',
				'release'               => isset( $options['release'] ) ? (string) $options['release'] : self::DEFAULT_PLUGIN_RELEASE,
				'use_source_archive'    => $use_source_archive,
				'sqlite_zip'            => isset( $options['sqlite_zip'] ) ? (string) $options['sqlite_zip'] : '',
				'sqlite_zip_url'        => isset( $options['sqlite_zip_url'] ) ? (string) $options['sqlite_zip_url'] : '',
				'sqlite_ref'            => isset( $options['sqlite_ref'] ) ? (string) $options['sqlite_ref'] : self::SQLITE_PLUGIN_RELEASE,
				'force_install'         => $this->truthy( isset( $options['force_install'] ) ? $options['force_install'] : false ),
				'install_duckdb_client' => $this->truthy( isset( $options['install_duckdb_client'] ) ? $options['install_duckdb_client'] : false ),
			);
		}

		/**
		 * Install or reuse the plugin directory.
		 *
		 * @param array<string,mixed> $options Options.
		 * @return string Plugin directory.
		 */
		private function install_plugin( array $options ) {
			$plugin_dir = $options['plugin_dir'];
			if ( is_dir( $plugin_dir ) ) {
				if ( empty( $options['force_install'] ) ) {
					$this->assert_existing_plugin_is_usable( $plugin_dir );
					echo 'Using existing plugin at ' . $plugin_dir . ".\n";
					return $plugin_dir;
				}
				$this->remove_recursive( $plugin_dir );
			}

			$this->ensure_directory( dirname( $plugin_dir ) );
			if ( '' !== $options['plugin_zip'] || '' !== $options['plugin_zip_url'] || empty( $options['use_source_archive'] ) ) {
				$source_dir = $this->prepare_packaged_plugin( $options );
			} else {
				$source_dir = $this->prepare_source_plugin( $options );
			}

			$this->copy_recursive( $source_dir, $plugin_dir );
			$this->assert_existing_plugin_is_usable( $plugin_dir );
			echo 'Installed plugin at ' . $plugin_dir . ".\n";

			return $plugin_dir;
		}

		/**
		 * Prepare a packaged plugin zip.
		 *
		 * @param array<string,mixed> $options Options.
		 * @return string Source directory.
		 */
		private function prepare_packaged_plugin( array $options ) {
			$temp_dir = $this->create_temp_dir( 'wpds-plugin-zip-' );
			$zip      = $temp_dir . '/plugin.zip';
			$source   = '' !== $options['plugin_zip']
				? $options['plugin_zip']
				: ( '' !== $options['plugin_zip_url'] ? $options['plugin_zip_url'] : $this->plugin_release_zip_url( $options['release'] ) );
			$this->materialize_archive( $source, $zip );
			$extract_dir = $temp_dir . '/extract';
			$this->ensure_directory( $extract_dir );
			$this->extract_zip( $zip, $extract_dir );

			return $this->find_directory_containing( $extract_dir, 'wordpress-databases-support.php' );
		}

		/**
		 * Prepare a source archive plus the pinned SQLite integration archive.
		 *
		 * @param array<string,mixed> $options Options.
		 * @return string Source directory.
		 */
		private function prepare_source_plugin( array $options ) {
			$temp_dir = $this->create_temp_dir( 'wpds-source-' );

			$source_zip = $temp_dir . '/source.zip';
			$source     = '' !== $options['source_zip']
				? $options['source_zip']
				: ( '' !== $options['source_zip_url'] ? $options['source_zip_url'] : $this->github_archive_url( self::DEFAULT_REPO_OWNER, self::DEFAULT_REPO_NAME, $options['source_ref'] ) );
			$this->materialize_archive( $source, $source_zip );

			$source_extract_dir = $temp_dir . '/source';
			$this->ensure_directory( $source_extract_dir );
			$this->extract_zip( $source_zip, $source_extract_dir );
			$plugin_source = $this->find_directory_containing( $source_extract_dir, 'wordpress-databases-support.php' );

			$sqlite_zip = $temp_dir . '/sqlite.zip';
			$sqlite     = '' !== $options['sqlite_zip']
				? $options['sqlite_zip']
				: ( '' !== $options['sqlite_zip_url'] ? $options['sqlite_zip_url'] : $this->sqlite_plugin_zip_url( $options['sqlite_ref'] ) );
			$this->materialize_archive( $sqlite, $sqlite_zip );

			$sqlite_extract_dir = $temp_dir . '/sqlite';
			$this->ensure_directory( $sqlite_extract_dir );
			$this->extract_zip( $sqlite_zip, $sqlite_extract_dir );
			$sqlite_source = $this->find_sqlite_package_dir( $sqlite_extract_dir );

			$sqlite_dest = $plugin_source . '/external/sqlite-database-integration/packages/plugin-sqlite-database-integration';
			if ( file_exists( $sqlite_dest ) ) {
				$this->remove_recursive( $sqlite_dest );
			}
			$this->ensure_directory( dirname( $sqlite_dest ) );
			$this->copy_recursive( $sqlite_source, $sqlite_dest );

			return $plugin_source;
		}

		/**
		 * Run the plugin's setup CLI.
		 *
		 * @param string              $plugin_dir Plugin directory.
		 * @param array<string,mixed> $options    Installer options.
		 * @param string[]            $setup_args Setup args.
		 * @return int Exit code.
		 */
		private function run_setup_cli( $plugin_dir, array $options, array $setup_args ) {
			$setup_script = $plugin_dir . '/bin/setup-database.php';
			if ( ! is_file( $setup_script ) ) {
				throw new RuntimeException( 'Could not locate setup CLI at ' . $setup_script );
			}

			$args = array(
				$setup_script,
				'--wp-path=' . $options['wp_path'],
				'--plugin-dir=' . $plugin_dir,
			);
			foreach ( $setup_args as $setup_arg ) {
				$args[] = $setup_arg;
			}

			$command = escapeshellarg( PHP_BINARY );
			foreach ( $args as $arg ) {
				$command .= ' ' . escapeshellarg( $arg );
			}

			echo 'Running database setup...' . "\n";
			$output = array();
			$exit   = 0;
			exec( $command . ' 2>&1', $output, $exit );
			foreach ( $output as $line ) {
				echo $line . "\n";
			}

			return $exit;
		}

		/**
		 * Install the DuckDB PHP client into the installed plugin.
		 *
		 * @param string $plugin_dir Plugin directory.
		 * @return void
		 */
		private function install_duckdb_client( $plugin_dir ) {
			$composer = $this->find_command( 'composer' );
			if ( null === $composer ) {
				throw new RuntimeException( 'Composer is required for --install-duckdb-client, but composer was not found on PATH.' );
			}

			$commands = array(
				escapeshellarg( $composer ) . ' require --no-interaction --no-progress --with-all-dependencies satur.io/duckdb',
				escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( 'require "vendor/autoload.php"; Saturio\\DuckDB\\CLib\\Installer::install();' ),
				escapeshellarg( $composer ) . ' dump-autoload --no-dev --no-interaction --optimize',
			);
			foreach ( $commands as $command ) {
				$output = array();
				$exit   = 0;
				exec( 'cd ' . escapeshellarg( $plugin_dir ) . ' && ' . $command . ' 2>&1', $output, $exit );
				foreach ( $output as $line ) {
					echo $line . "\n";
				}
				if ( 0 !== $exit ) {
					throw new RuntimeException( 'DuckDB PHP client installation failed.' );
				}
			}
		}

		/**
		 * Print browser-mode install result.
		 *
		 * @param string              $plugin_dir Plugin directory.
		 * @param array<string,mixed> $options    Options.
		 * @return void
		 */
		private function print_install_result( $plugin_dir, array $options ) {
			$wizard_path = $this->wizard_path_hint( $plugin_dir, $options['wp_path'] );
			if ( 'none' === $options['setup_mode'] ) {
				echo "Plugin install complete.\n";
				return;
			}

			echo "Open the database setup wizard:\n";
			echo $wizard_path . "\n";
			echo "Or run CLI setup now by adding flags after php --, for example:\n";
			echo "-- --engine=sqlite --yes\n";
		}

		/**
		 * Ensure an existing plugin directory has the expected files.
		 *
		 * @param string $plugin_dir Plugin directory.
		 * @return void
		 */
		private function assert_existing_plugin_is_usable( $plugin_dir ) {
			$required = array(
				'wordpress-databases-support.php',
				'db.copy',
				'setup-database.php',
				'bin/setup-database.php',
				'wp-includes/db.php',
				'external/sqlite-database-integration/packages/plugin-sqlite-database-integration/wp-includes/sqlite/db.php',
			);
			foreach ( $required as $relative ) {
				if ( ! is_file( $plugin_dir . '/' . $relative ) ) {
					throw new RuntimeException( 'Existing plugin directory is incomplete at ' . $plugin_dir . '. Re-run with --force-install to replace it.' );
				}
			}
		}

		/**
		 * Materialize a local path or URL into a zip file.
		 *
		 * @param string $source Source path or URL.
		 * @param string $dest   Destination path.
		 * @return void
		 */
		private function materialize_archive( $source, $dest ) {
			if ( '' === trim( $source ) ) {
				throw new InvalidArgumentException( 'Archive source is empty.' );
			}
			if ( ! $this->is_url( $source ) ) {
				$path = $this->normalize_path( $source );
				if ( ! is_file( $path ) ) {
					throw new InvalidArgumentException( 'Archive file does not exist: ' . $path );
				}
				if ( ! copy( $path, $dest ) ) {
					throw new RuntimeException( 'Could not copy archive: ' . $path );
				}
				return;
			}

			$curl = $this->find_command( 'curl' );
			if ( null !== $curl ) {
				$command = escapeshellarg( $curl ) . ' -fL --retry 3 --connect-timeout 15 -o ' . escapeshellarg( $dest ) . ' ' . escapeshellarg( $source );
				$output  = array();
				$exit    = 0;
				exec( $command . ' 2>&1', $output, $exit );
				if ( 0 === $exit && is_file( $dest ) && filesize( $dest ) > 0 ) {
					return;
				}
			}

			$contents = @file_get_contents( $source );
			if ( ! is_string( $contents ) || '' === $contents ) {
				throw new RuntimeException( 'Could not download archive: ' . $source );
			}
			if ( false === file_put_contents( $dest, $contents ) ) {
				throw new RuntimeException( 'Could not write archive to: ' . $dest );
			}
		}

		/**
		 * Extract a zip archive.
		 *
		 * @param string $zip  Zip path.
		 * @param string $dest Destination directory.
		 * @return void
		 */
		private function extract_zip( $zip, $dest ) {
			if ( class_exists( 'ZipArchive' ) ) {
				$archive = new ZipArchive();
				$opened  = $archive->open( $zip );
				if ( true !== $opened ) {
					throw new RuntimeException( 'Could not open zip archive: ' . $zip );
				}
				if ( ! $archive->extractTo( $dest ) ) {
					$archive->close();
					throw new RuntimeException( 'Could not extract zip archive: ' . $zip );
				}
				$archive->close();
				return;
			}

			$unzip = $this->find_command( 'unzip' );
			if ( null === $unzip ) {
				throw new RuntimeException( 'Extracting archives requires the PHP zip extension or the unzip command.' );
			}

			$output = array();
			$exit   = 0;
			exec( escapeshellarg( $unzip ) . ' -q ' . escapeshellarg( $zip ) . ' -d ' . escapeshellarg( $dest ) . ' 2>&1', $output, $exit );
			if ( 0 !== $exit ) {
				throw new RuntimeException( 'Could not extract zip archive: ' . implode( "\n", $output ) );
			}
		}

		/**
		 * Find the first directory below root containing a relative file path.
		 *
		 * @param string $root          Root directory.
		 * @param string $relative_file Relative file path.
		 * @return string Directory path.
		 */
		private function find_directory_containing( $root, $relative_file ) {
			$root = rtrim( $root, '/\\' );
			if ( is_file( $root . '/' . $relative_file ) ) {
				return $root;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( ! $item->isDir() ) {
					continue;
				}
				$path = str_replace( '\\', '/', $item->getPathname() );
				if ( is_file( $path . '/' . $relative_file ) ) {
					return $path;
				}
			}

			throw new RuntimeException( 'Could not find ' . $relative_file . ' inside archive.' );
		}

		/**
		 * Find a packaged SQLite plugin directory.
		 *
		 * @param string $root Extracted archive root.
		 * @return string SQLite package directory.
		 */
		private function find_sqlite_package_dir( $root ) {
			try {
				$package_dir = $this->find_directory_containing( $root, 'wp-includes/sqlite/db.php' );
				if ( ! is_file( $package_dir . '/wp-includes/database/load.php' ) ) {
					throw new RuntimeException( 'SQLite plugin package is missing wp-includes/database/load.php.' );
				}
				return $package_dir;
			} catch ( Exception $e ) {
				$repo_dir = $this->find_directory_containing(
					$root,
					'packages/plugin-sqlite-database-integration/wp-includes/sqlite/db.php'
				);
				$package_dir = $repo_dir . '/packages/plugin-sqlite-database-integration';
				$driver_dir  = $repo_dir . '/packages/mysql-on-sqlite/src';
				if ( ! is_dir( $driver_dir ) ) {
					throw new RuntimeException( 'SQLite archive is missing packages/mysql-on-sqlite/src.' );
				}
				if ( ! is_dir( $package_dir . '/wp-includes/database' ) ) {
					$this->copy_recursive( $driver_dir, $package_dir . '/wp-includes/database' );
				}

				return $package_dir;
			}
		}

		/**
		 * Copy a directory recursively.
		 *
		 * @param string $source Source directory.
		 * @param string $dest   Destination directory.
		 * @return void
		 */
		private function copy_recursive( $source, $dest ) {
			if ( is_dir( $dest ) ) {
				$this->remove_recursive( $dest );
			}
			$this->ensure_directory( $dest );

			$items = scandir( $source );
			if ( ! is_array( $items ) ) {
				throw new RuntimeException( 'Could not read directory: ' . $source );
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$source_path = $source . '/' . $item;
				$dest_path   = $dest . '/' . $item;
				if ( is_dir( $source_path ) ) {
					$this->copy_recursive( $source_path, $dest_path );
				} elseif ( ! copy( $source_path, $dest_path ) ) {
					throw new RuntimeException( 'Could not copy file: ' . $source_path );
				}
			}
		}

		/**
		 * Remove a file or directory recursively.
		 *
		 * @param string $path Path.
		 * @return void
		 */
		private function remove_recursive( $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				@unlink( $path );
				return;
			}
			if ( ! is_dir( $path ) ) {
				return;
			}
			$items = scandir( $path );
			if ( ! is_array( $items ) ) {
				throw new RuntimeException( 'Could not read directory: ' . $path );
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$this->remove_recursive( $path . '/' . $item );
			}
			@rmdir( $path );
		}

		/**
		 * Create a temp directory.
		 *
		 * @param string $prefix Prefix.
		 * @return string Path.
		 */
		private function create_temp_dir( $prefix ) {
			$base = rtrim( sys_get_temp_dir(), '/\\' ) . '/' . $prefix . getmypid() . '-' . str_replace( '.', '-', uniqid( '', true ) );
			if ( ! mkdir( $base, 0775, true ) && ! is_dir( $base ) ) {
				throw new RuntimeException( 'Could not create temporary directory: ' . $base );
			}
			$this->temp_dirs[] = $base;

			return $base;
		}

		/**
		 * Clean temporary directories.
		 *
		 * @return void
		 */
		private function cleanup() {
			foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
				$this->remove_recursive( $temp_dir );
			}
			$this->temp_dirs = array();
		}

		/**
		 * Ensure a directory exists.
		 *
		 * @param string $dir Directory.
		 * @return void
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
		 * Locate an executable.
		 *
		 * @param string $command Command name.
		 * @return string|null Command path.
		 */
		private function find_command( $command ) {
			$output = array();
			$exit   = 0;
			exec( 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null', $output, $exit );
			if ( 0 !== $exit || empty( $output[0] ) ) {
				return null;
			}

			return $output[0];
		}

		/**
		 * Build a GitHub archive URL.
		 *
		 * @param string $owner Repository owner.
		 * @param string $repo  Repository name.
		 * @param string $ref   Branch, tag, or commit ref.
		 * @return string URL.
		 */
		private function github_archive_url( $owner, $repo, $ref ) {
			return 'https://github.com/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/archive/' . rawurlencode( $ref ) . '.zip';
		}

		/**
		 * Build this plugin's release asset URL.
		 *
		 * @param string $release Release tag or "latest".
		 * @return string URL.
		 */
		private function plugin_release_zip_url( $release ) {
			$release = trim( $release );
			if ( '' === $release || 'latest' === strtolower( $release ) ) {
				return 'https://github.com/' . rawurlencode( self::DEFAULT_REPO_OWNER ) . '/' . rawurlencode( self::DEFAULT_REPO_NAME ) . '/releases/latest/download/' . self::PLUGIN_ZIP_NAME;
			}

			return 'https://github.com/' . rawurlencode( self::DEFAULT_REPO_OWNER ) . '/' . rawurlencode( self::DEFAULT_REPO_NAME ) . '/releases/download/' . rawurlencode( $release ) . '/' . self::PLUGIN_ZIP_NAME;
		}

		/**
		 * Build the SQLite integration release asset URL.
		 *
		 * @param string $release Release tag.
		 * @return string URL.
		 */
		private function sqlite_plugin_zip_url( $release ) {
			return 'https://github.com/' . rawurlencode( self::SQLITE_REPO_OWNER ) . '/' . rawurlencode( self::SQLITE_REPO_NAME ) . '/releases/download/' . rawurlencode( $release ) . '/' . self::SQLITE_PLUGIN_ZIP_NAME;
		}

		/**
		 * Check for URL strings.
		 *
		 * @param string $value Value.
		 * @return bool Whether value is URL.
		 */
		private function is_url( $value ) {
			return 1 === preg_match( '#^https?://#i', $value );
		}

		/**
		 * Check whether setup args choose an engine.
		 *
		 * @param string[] $args Args.
		 * @return bool Whether an engine arg exists.
		 */
		private function setup_args_include_engine( array $args ) {
			$count = count( $args );
			for ( $i = 0; $i < $count; ++$i ) {
				$arg = $args[ $i ];
				if ( 0 === strpos( $arg, '--engine=' ) || '--engine' === $arg ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Return a setup wizard path hint.
		 *
		 * @param string $plugin_dir Plugin directory.
		 * @param string $wp_path    WordPress path.
		 * @return string Path or URL fragment.
		 */
		private function wizard_path_hint( $plugin_dir, $wp_path ) {
			$wp_path    = rtrim( str_replace( '\\', '/', $wp_path ), '/' );
			$plugin_dir = rtrim( str_replace( '\\', '/', $plugin_dir ), '/' );
			$prefix     = $wp_path . '/';
			if ( 0 === strpos( $plugin_dir . '/', $prefix ) ) {
				return '/' . substr( $plugin_dir, strlen( $prefix ) ) . '/setup-database.php';
			}

			return $plugin_dir . '/setup-database.php';
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
		 * Print help text.
		 *
		 * @return void
		 */
		private function print_help() {
			echo <<<'TEXT'
Install WordPress Databases Support without git.

Run from a WordPress root:
  curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php

Install and configure in one command:
  curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- --engine=duckdb --install-duckdb-client --duckdb-backend=json --yes --force
  curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- --engine=sqlite --yes --force

Installer options:
  --wp-path=/path/to/wordpress       WordPress root. Defaults to the current directory.
  --plugin-dir=PATH                  Plugin install directory. Defaults to wp-content/plugins/wordpress-databases-support.
  --setup=browser|cli|none           Open wizard path, run CLI setup, or install only.
  --release=latest                   Plugin release tag to install. Defaults to the latest GitHub release.
  --plugin-zip=/path/plugin.zip      Install from a local packaged plugin zip.
  --plugin-zip-url=URL               Install from a packaged plugin zip URL.
  --ref=trunk                        Development: install from a GitHub source archive ref instead of a release.
  --source-zip=/path/source.zip      Development: install from a local repository archive.
  --sqlite-ref=v3.0.0-rc.7           SQLite integration release tag to package with the install.
  --sqlite-zip=/path/sqlite.zip      Use a local SQLite integration package zip.
  --force-install                    Replace an existing plugin directory.
  --install-duckdb-client            Run Composer in the plugin directory and install satur.io/duckdb.

All database setup flags after php -- are forwarded to bin/setup-database.php:
  --engine=sqlite|postgresql|duckdb
  --db-name=wordpress --db-user=wordpress --db-password=secret --db-host=127.0.0.1:5432
  --duckdb-backend=json|csv|parquet|custom_name
  --duckdb-external-storage-dir=wp-content/database/duckdb-json
  --duckdb-working-database-file=wp-content/database/.ht.duckdb-working
  --duckdb-backend-read-sql="SELECT * FROM read_csv_auto({path})"
  --duckdb-backend-write-sql="COPY {table} TO {path}"
  --duckdb-backend-setup-sql="INSTALL httpfs"
  --duckdb-backend-tables=wp_options,wp_posts
  --duckdb-backend-atomic-flush=0
  --duckdb-metadata-manifest-file=wp-content/database/.wp-duckdb-json-metadata
  --duckdb-connection=ffi|unix|tcp|http|sidecar
  --duckdb-socket=/run/wp-duckdb/wordpress.sock
  --duckdb-host=127.0.0.1 --duckdb-port=9901
  --duckdb-url=http://127.0.0.1:9902/query
  --duckdb-sidecar="php -d ffi.enable=1 .../bin/duckdb-sidecar.php --stdio --path=..."
  --force --dry-run --strict --yes

TEXT;
		}
	}
}

if ( PHP_SAPI === 'cli' && ! defined( 'WP_DATABASES_SUPPORT_BOOTSTRAP_INSTALLER_NO_RUN' ) ) {
	$wp_databases_support_bootstrap_installer = new WP_Databases_Support_Bootstrap_Installer();
	exit( $wp_databases_support_bootstrap_installer->run_cli( isset( $argv ) && is_array( $argv ) ? $argv : array( __FILE__ ) ) );
}
