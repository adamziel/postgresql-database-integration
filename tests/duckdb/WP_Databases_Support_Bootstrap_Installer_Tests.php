<?php

define( 'WP_DATABASES_SUPPORT_BOOTSTRAP_INSTALLER_NO_RUN', true );
require_once dirname( __DIR__, 2 ) . '/bin/install-database-support.php';

#[PHPUnit\Framework\Attributes\Group( 'setup' )]
class WP_Databases_Support_Bootstrap_Installer_Tests extends PHPUnit\Framework\TestCase {
	/**
	 * Temporary directories created by the current test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is required to build installer fixture archives.' );
		}
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
			$this->remove_temp_dir( $temp_dir );
		}
		$this->temp_dirs = array();

		parent::tearDown();
	}

	public function test_browser_mode_installs_from_archives_and_prints_wizard_path(): void {
		$wp_root     = $this->create_wordpress_root();
		$source_zip  = $this->create_plugin_source_archive();
		$sqlite_zip  = $this->create_sqlite_archive();
		$install_php = dirname( __DIR__, 2 ) . '/bin/install-database-support.php';

		$command = PHP_BINARY
			. ' ' . escapeshellarg( $install_php )
			. ' --wp-path=' . escapeshellarg( $wp_root )
			. ' --source-zip=' . escapeshellarg( $source_zip )
			. ' --sqlite-zip=' . escapeshellarg( $sqlite_zip );

		$output = array();
		$exit   = 0;
		exec( $command . ' 2>&1', $output, $exit );

		$joined = implode( "\n", $output );
		$this->assertSame( 0, $exit, $joined );
		$this->assertStringContainsString( 'Installed plugin at ', $joined );
		$this->assertStringContainsString( '/wp-content/plugins/wordpress-databases-support/setup-database.php', $joined );

		$plugin_dir = $wp_root . '/wp-content/plugins/wordpress-databases-support';
		$this->assertFileExists( $plugin_dir . '/wordpress-databases-support.php' );
		$this->assertFileExists( $plugin_dir . '/bin/setup-database.php' );
		$this->assertFileExists( $plugin_dir . '/external/sqlite-database-integration/packages/plugin-sqlite-database-integration/wp-includes/sqlite/db.php' );
		$this->assertFileDoesNotExist( $wp_root . '/wp-config.php' );
	}

	public function test_cli_mode_forwards_setup_args_and_configures_sqlite(): void {
		$wp_root     = $this->create_wordpress_root();
		$source_zip  = $this->create_plugin_source_archive();
		$sqlite_zip  = $this->create_sqlite_archive();
		$install_php = dirname( __DIR__, 2 ) . '/bin/install-database-support.php';

		$command = PHP_BINARY
			. ' ' . escapeshellarg( $install_php )
			. ' --wp-path=' . escapeshellarg( $wp_root )
			. ' --source-zip=' . escapeshellarg( $source_zip )
			. ' --sqlite-zip=' . escapeshellarg( $sqlite_zip )
			. ' --engine=sqlite'
			. ' --sqlite-file=.install.sqlite'
			. ' --yes';

		$output = array();
		$exit   = 0;
		exec( $command . ' 2>&1', $output, $exit );

		$joined = implode( "\n", $output );
		$this->assertSame( 0, $exit, $joined );
		$this->assertStringContainsString( 'Running database setup...', $joined );
		$this->assertStringContainsString( 'Configured WordPress for sqlite.', $joined );

		$config = file_get_contents( $wp_root . '/wp-config.php' );
		$this->assertIsString( $config );
		$this->assertStringContainsString( "define( 'DB_ENGINE', 'sqlite' );", $config );
		$this->assertStringContainsString( "define( 'DB_FILE', '.install.sqlite' );", $config );

		$plugin_dir = $wp_root . '/wp-content/plugins/wordpress-databases-support';
		$dropin     = file_get_contents( $wp_root . '/wp-content/db.php' );
		$this->assertIsString( $dropin );
		$this->assertStringContainsString( $plugin_dir, $dropin );
	}

	public function test_incomplete_existing_plugin_requires_force_install(): void {
		$wp_root     = $this->create_wordpress_root();
		$source_zip  = $this->create_plugin_source_archive();
		$sqlite_zip  = $this->create_sqlite_archive();
		$install_php = dirname( __DIR__, 2 ) . '/bin/install-database-support.php';
		$plugin_dir  = $wp_root . '/wp-content/plugins/wordpress-databases-support';
		mkdir( $plugin_dir, 0777, true );
		file_put_contents( $plugin_dir . '/broken.txt', 'not the plugin' );

		$base = PHP_BINARY
			. ' ' . escapeshellarg( $install_php )
			. ' --wp-path=' . escapeshellarg( $wp_root )
			. ' --source-zip=' . escapeshellarg( $source_zip )
			. ' --sqlite-zip=' . escapeshellarg( $sqlite_zip )
			. ' --setup=none';

		$output = array();
		$exit   = 0;
		exec( $base . ' 2>&1', $output, $exit );
		$this->assertSame( 1, $exit, implode( "\n", $output ) );
		$this->assertStringContainsString( 'Re-run with --force-install to replace it.', implode( "\n", $output ) );

		$output = array();
		$exit   = 0;
		exec( $base . ' --force-install 2>&1', $output, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $output ) );
		$this->assertFileExists( $plugin_dir . '/wordpress-databases-support.php' );
	}

	private function create_wordpress_root(): string {
		$temp_dir = $this->create_temp_dir( 'wpds-bootstrap-wp-' );
		mkdir( $temp_dir . '/wp-content/plugins', 0777, true );
		file_put_contents(
			$temp_dir . '/wp-config-sample.php',
			<<<'PHP'
<?php
define( 'DB_NAME', 'database_name_here' );
define( 'DB_USER', 'username_here' );
define( 'DB_PASSWORD', 'password_here' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wp_';

/* That's all, stop editing! Happy publishing. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
		);

		return $temp_dir;
	}

	private function create_plugin_source_archive(): string {
		$temp_dir = $this->create_temp_dir( 'wpds-bootstrap-source-' );
		$root     = $temp_dir . '/wordpress-databases-support-trunk';
		$repo     = dirname( __DIR__, 2 );

		mkdir( $root . '/bin', 0777, true );
		mkdir( $root . '/wp-includes/database/setup', 0777, true );
		copy( $repo . '/wordpress-databases-support.php', $root . '/wordpress-databases-support.php' );
		copy( $repo . '/db.copy', $root . '/db.copy' );
		copy( $repo . '/constants.php', $root . '/constants.php' );
		copy( $repo . '/setup-database.php', $root . '/setup-database.php' );
		copy( $repo . '/bin/setup-database.php', $root . '/bin/setup-database.php' );
		copy( $repo . '/wp-includes/db.php', $root . '/wp-includes/db.php' );
		copy(
			$repo . '/wp-includes/database/setup/class-wp-databases-support-setup-installer.php',
			$root . '/wp-includes/database/setup/class-wp-databases-support-setup-installer.php'
		);

		$zip = $temp_dir . '/source.zip';
		$this->zip_directory( $root, $zip );

		return $zip;
	}

	private function create_sqlite_archive(): string {
		$temp_dir = $this->create_temp_dir( 'wpds-bootstrap-sqlite-' );
		$root     = $temp_dir . '/plugin-sqlite-database-integration';

		mkdir( $root . '/wp-includes/sqlite', 0777, true );
		mkdir( $root . '/wp-includes/database', 0777, true );
		file_put_contents( $root . '/wp-includes/sqlite/db.php', "<?php\n// SQLite fixture.\n" );
		file_put_contents( $root . '/wp-includes/database/load.php', "<?php\n// Driver fixture.\n" );

		$zip = $temp_dir . '/sqlite.zip';
		$this->zip_directory( $root, $zip );

		return $zip;
	}

	private function zip_directory( string $source_dir, string $zip_path ): void {
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );

		$base_parent = dirname( $source_dir );
		$iterator    = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$path     = str_replace( '\\', '/', $item->getPathname() );
			$relative = ltrim( substr( $path, strlen( $base_parent ) ), '/\\' );
			if ( $item->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $path, $relative );
			}
		}
		$zip->close();
	}

	private function create_temp_dir( string $prefix ): string {
		$temp_dir = sys_get_temp_dir() . '/' . $prefix . getmypid() . '-' . uniqid( '', true );
		mkdir( $temp_dir, 0777, true );
		$this->temp_dirs[] = $temp_dir;

		return $temp_dir;
	}

	private function remove_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_temp_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
