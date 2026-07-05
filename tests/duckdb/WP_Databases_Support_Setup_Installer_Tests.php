<?php

require_once dirname( __DIR__, 2 ) . '/wp-includes/database/setup/class-wp-databases-support-setup-installer.php';

#[PHPUnit\Framework\Attributes\Group( 'setup' )]
class WP_Databases_Support_Setup_Installer_Tests extends PHPUnit\Framework\TestCase {
	/**
	 * Temporary directories created by the current test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	protected function tearDown(): void {
		foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
			$this->remove_temp_dir( $temp_dir );
		}
		$this->temp_dirs = array();

		parent::tearDown();
	}

	public function test_php_r_one_liner_configures_duckdb(): void {
		$wp_root    = $this->create_wordpress_root();
		$plugin_dir = dirname( __DIR__, 2 );
		$code       = 'require ' . var_export( $plugin_dir . '/bin/setup-database.php', true ) . ';';
		$command    = PHP_BINARY
			. ' -r ' . escapeshellarg( $code )
			. ' --'
			. ' --wp-path=' . escapeshellarg( $wp_root )
			. ' --plugin-dir=' . escapeshellarg( $plugin_dir )
			. ' --engine=duckdb'
			. ' --db-dir=wp-content/database'
			. ' --duckdb-file=.site.duckdb'
			. ' --yes';

		$output = array();
		$exit   = 0;
		exec( $command . ' 2>&1', $output, $exit );

		$this->assertSame( 0, $exit, implode( "\n", $output ) );
		$this->assertStringContainsString( 'Configured WordPress for duckdb.', implode( "\n", $output ) );

		$config = file_get_contents( $wp_root . '/wp-config.php' );
		$this->assertIsString( $config );
		$this->assertStringContainsString( "define( 'DB_ENGINE', 'duckdb' );", $config );
		$this->assertStringContainsString( "define( 'DUCKDB_FILE', '.site.duckdb' );", $config );
		$this->assertStringContainsString( "define( 'DB_DIR', '" . $wp_root . "/wp-content/database/' );", $config );

		$dropin = file_get_contents( $wp_root . '/wp-content/db.php' );
		$this->assertIsString( $dropin );
		$this->assertStringContainsString( "WORDPRESS_DATABASES_SUPPORT_DB_DROPIN_VERSION", $dropin );
		$this->assertStringContainsString( $plugin_dir, $dropin );
	}

	public function test_installer_configures_sqlite_and_can_update_managed_config(): void {
		$wp_root   = $this->create_wordpress_root();
		$installer = new WP_Databases_Support_Setup_Installer( dirname( __DIR__, 2 ) );

		$first = $installer->install(
			array(
				'wp_path'              => $wp_root,
				'engine'               => 'sqlite',
				'sqlite_database_file' => '.first.sqlite',
				'yes'                  => true,
			)
		);
		$second = $installer->install(
			array(
				'wp_path'              => $wp_root,
				'engine'               => 'sqlite',
				'sqlite_database_file' => '.second.sqlite',
				'yes'                  => true,
			)
		);

		$this->assertSame( 'sqlite', $first['engine'] );
		$this->assertSame( 'sqlite', $second['engine'] );

		$config = file_get_contents( $wp_root . '/wp-config.php' );
		$this->assertIsString( $config );
		$this->assertStringContainsString( "define( 'DB_ENGINE', 'sqlite' );", $config );
		$this->assertStringNotContainsString( '.first.sqlite', $config );
		$this->assertStringContainsString( "define( 'DB_FILE', '.second.sqlite' );", $config );
	}

	public function test_installer_configures_postgresql_credentials(): void {
		$wp_root   = $this->create_wordpress_root();
		$installer = new WP_Databases_Support_Setup_Installer( dirname( __DIR__, 2 ) );

		$result = $installer->install(
			array(
				'wp_path'           => $wp_root,
				'engine'            => 'postgresql',
				'database_name'     => 'wp_pg',
				'database_user'     => 'wp_user',
				'database_password' => 'secret',
				'database_host'     => 'db.internal:5432',
				'yes'               => true,
			)
		);

		$this->assertSame( 'postgresql', $result['engine'] );

		$config = file_get_contents( $wp_root . '/wp-config.php' );
		$this->assertIsString( $config );
		$this->assertStringContainsString( "define( 'DB_NAME', 'wp_pg' );", $config );
		$this->assertStringContainsString( "define( 'DB_USER', 'wp_user' );", $config );
		$this->assertStringContainsString( "define( 'DB_PASSWORD', 'secret' );", $config );
		$this->assertStringContainsString( "define( 'DB_HOST', 'db.internal:5432' );", $config );
		$this->assertStringContainsString( "define( 'DB_ENGINE', 'postgresql' );", $config );
	}

	public function test_unmanaged_existing_wp_config_requires_force(): void {
		$wp_root = $this->create_wordpress_root( false );
		file_put_contents( $wp_root . '/wp-config.php', "<?php\ndefine( 'DB_NAME', 'existing' );\n" );

		$installer = new WP_Databases_Support_Setup_Installer( dirname( __DIR__, 2 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'wp-config.php already exists and is not managed by this setup helper' );

		$installer->install(
			array(
				'wp_path' => $wp_root,
				'engine'  => 'sqlite',
				'yes'     => true,
			)
		);
	}

	public function test_dry_run_does_not_write_files(): void {
		$wp_root   = $this->create_wordpress_root();
		$installer = new WP_Databases_Support_Setup_Installer( dirname( __DIR__, 2 ) );

		$result = $installer->install(
			array(
				'wp_path' => $wp_root,
				'engine'  => 'duckdb',
				'yes'     => true,
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['dry_run'] );
		$this->assertFileDoesNotExist( $wp_root . '/wp-config.php' );
		$this->assertFileDoesNotExist( $wp_root . '/wp-content/db.php' );
	}

	public function test_web_setup_infers_wordpress_root_from_request_when_plugin_is_symlinked(): void {
		$wp_root   = $this->create_wordpress_root();
		$installer = new WP_Databases_Support_Setup_Installer( dirname( __DIR__, 2 ) );
		$infer     = Closure::bind(
			function ( array $server ) {
				return $this->infer_wp_path_for_web( $server );
			},
			$installer,
			get_class( $installer )
		);

		$inferred = $infer(
			array(
				'DOCUMENT_ROOT'   => $wp_root,
				'REQUEST_URI'     => '/wp-content/plugins/wordpress-databases-support/setup-database.php',
				'SCRIPT_FILENAME' => dirname( __DIR__, 2 ) . '/setup-database.php',
			)
		);

		$this->assertSame( $wp_root, $inferred );
	}

	private function create_wordpress_root( bool $with_sample = true ): string {
		$temp_dir = sys_get_temp_dir() . '/wpds-setup-test-' . getmypid() . '-' . uniqid( '', true );
		mkdir( $temp_dir . '/wp-content/plugins', 0777, true );
		$this->temp_dirs[] = $temp_dir;

		if ( $with_sample ) {
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
		}

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
