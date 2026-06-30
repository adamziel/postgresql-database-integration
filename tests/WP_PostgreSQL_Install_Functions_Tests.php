<?php
/**
 * Tests for standalone PostgreSQL install functions.
 *
 * @package wp-postgresql-integration
 */

class WP_PostgreSQL_Install_Functions_Tests extends WP_PostgreSQL_Test_Case {
	public function test_install_functions_use_standalone_paths(): void {
		$contents = file_get_contents( WP_POSTGRESQL_TEST_ROOT . '/wp-includes/postgresql/install-functions.php' );

		$this->assertStringContainsString( 'WP_PostgreSQL_Create_Table_Translator', $contents );
		$this->assertStringContainsString( "include_once ABSPATH . 'wp-admin/includes/schema.php'", $contents );
	}

	public function test_postgresql_make_db_current_silent_creates_schema_with_driver(): void {
		$this->load_install_functions_with_schema_stub();

		global $wpdb;
		$driver = $this->create_driver();
		$wpdb   = new WP_PostgreSQL_Test_Install_DB();
		$wpdb->dbh = $driver;

		$this->assertTrue( postgresql_make_db_current_silent() );

		$rows = $driver->query( "SHOW TABLES LIKE 'wp_install_probe'" );
		$this->assertCount( 1, $rows );
	}

	public function test_install_network_defines_network_installing_and_creates_global_tables(): void {
		$this->load_install_functions_with_schema_stub();

		global $wpdb;
		$driver = $this->create_driver();
		$wpdb   = new WP_PostgreSQL_Test_Install_DB();
		$wpdb->dbh = $driver;

		install_network();

		$this->assertTrue( defined( 'WP_INSTALLING_NETWORK' ) );
		$rows = $driver->query( "SHOW TABLES LIKE 'wp_site'" );
		$this->assertCount( 1, $rows );
	}

	/**
	 * Load install-functions.php after creating the schema stub it includes.
	 */
	private function load_install_functions_with_schema_stub(): void {
		require_once WP_POSTGRESQL_TEST_ROOT . '/wp-includes/postgresql/install-functions.php';
	}
}

class WP_PostgreSQL_Test_Install_DB extends wpdb {
	/**
	 * Public database handle for install-function compatibility checks.
	 *
	 * @var WP_PostgreSQL_Driver
	 */
	public $dbh;
}
