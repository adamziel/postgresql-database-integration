<?php
/**
 * Tests for the standalone PostgreSQL wpdb drop-in class.
 *
 * @package wp-postgresql-integration
 */

require_once WP_POSTGRESQL_TEST_ROOT . '/wp-includes/postgresql/class-wp-postgresql-db.php';

class WP_PostgreSQL_DB_Tests extends WP_PostgreSQL_Test_Case {
	public function test_determine_charset_upgrades_utf8_to_utf8mb4(): void {
		$db       = $this->create_db_with_driver();
		$charset  = $db->determine_charset( 'utf8', 'utf8_general_ci' );
		$expected = array(
			'charset' => 'utf8mb4',
			'collate' => 'utf8mb4_unicode_520_ci',
		);

		$this->assertSame( $expected, $charset );
	}

	public function test_set_charset_updates_underlying_driver(): void {
		$db = $this->create_db_with_driver();

		$db->set_charset( $db->get_test_driver(), 'latin1', 'latin1_swedish_ci' );

		$this->assertSame( 'latin1', $db->get_test_driver()->get_charset() );
	}

	public function test_get_col_length_uses_driver_metadata_from_mysql_schema(): void {
		$db = $this->create_db_with_driver();
		$db->query(
			"CREATE TABLE wp_col_length (
				name varchar(32) NOT NULL,
				description text NOT NULL
			) DEFAULT CHARACTER SET utf8mb4"
		);

		$this->assertSame(
			array(
				'type'   => 'char',
				'length' => 32,
			),
			$db->get_col_length( 'wp_col_length', 'name' )
		);
		$this->assertSame(
			array(
				'type'   => 'byte',
				'length' => 65535,
			),
			$db->get_col_length( 'wp_col_length', 'description' )
		);
	}

	public function test_query_delegates_to_postgresql_driver(): void {
		$db = $this->create_db_with_driver();
		$db->query( 'CREATE TABLE wp_query (id integer NOT NULL)' );
		$db->query( 'INSERT INTO wp_query (id) VALUES (1), (2)' );

		$result = $db->query( 'SELECT id FROM wp_query ORDER BY id' );

		$this->assertSame( 2, $result );
		$this->assertCount( 2, $db->last_result );
		$this->assertSame( 2, $db->num_rows );
		$this->assertSame( '1', (string) $db->last_result[0]->id );
	}

	/**
	 * Create a drop-in instance wired to an isolated PostgreSQL driver.
	 *
	 * @return WP_PostgreSQL_Test_DB
	 */
	private function create_db_with_driver() {
		$driver = $this->create_driver();
		$db     = new WP_PostgreSQL_Test_DB( 'wordpress', 'wordpress', $this->get_schema(), '127.0.0.1' );
		$db->set_test_driver( $driver );

		return $db;
	}
}

class WP_PostgreSQL_Test_DB extends WP_PostgreSQL_DB {
	/**
	 * Attach the isolated test driver.
	 *
	 * @param WP_PostgreSQL_Driver $driver Test driver.
	 */
	public function set_test_driver( WP_PostgreSQL_Driver $driver ): void {
		$this->dbh   = $driver;
		$this->ready = true;
	}

	/**
	 * Get the attached test driver.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	public function get_test_driver(): WP_PostgreSQL_Driver {
		return $this->dbh;
	}
}
