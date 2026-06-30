<?php
/**
 * Tests for the PostgreSQL MySQL-emulation driver.
 *
 * @package wp-postgresql-integration
 */

class WP_PostgreSQL_Driver_Tests extends WP_PostgreSQL_Test_Case {
	public function test_create_insert_select_and_show_tables(): void {
		$driver = $this->create_driver();

		$driver->query(
			"CREATE TABLE wp_options (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL default 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) DEFAULT CHARACTER SET utf8mb4"
		);

		$affected_rows = $driver->query(
			"INSERT INTO wp_options (option_name, option_value, autoload) VALUES
				('siteurl', 'https://example.test', 'yes'),
				('blogname', 'PostgreSQL Tests', 'yes')"
		);
		$this->assertSame( 2, $affected_rows );

		$rows = $driver->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" );
		$this->assertSame( 'https://example.test', $rows[0]->option_value );

		$tables = $driver->query( "SHOW TABLES LIKE 'wp_options'" );
		$this->assertCount( 1, $tables );
	}

	public function test_common_mysql_functions_are_rewritten(): void {
		$driver = $this->create_driver();

		$rows = $driver->query(
			"SELECT
				CONCAT('post', 'gre', 'sql') AS joined,
				IFNULL(NULL, 'fallback') AS fallback_value,
				CHAR_LENGTH('database') AS length_value,
				LOCATE('gre', 'postgresql') AS position_value"
		);

		$this->assertSame( 'postgresql', $rows[0]->joined );
		$this->assertSame( 'fallback', $rows[0]->fallback_value );
		$this->assertSame( '8', (string) $rows[0]->length_value );
		$this->assertSame( '5', (string) $rows[0]->position_value );
	}

	public function test_mysql_limit_offset_count_is_rewritten(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE wp_numbers (id integer NOT NULL)' );
		$driver->query( 'INSERT INTO wp_numbers (id) VALUES (1), (2), (3), (4)' );

		$rows = $driver->query( 'SELECT id FROM wp_numbers ORDER BY id LIMIT 1, 2' );

		$this->assertSame( array( '2', '3' ), array_map( array( $this, 'get_id_property_as_string' ), $rows ) );
	}

	public function test_replace_updates_conflicting_row_and_reports_insert_id(): void {
		$driver = $this->create_driver();
		$driver->query(
			"CREATE TABLE wp_replace (
				id bigint(20) unsigned NOT NULL auto_increment,
				slug varchar(50) NOT NULL,
				value varchar(50) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			) DEFAULT CHARACTER SET utf8mb4"
		);

		$this->assertSame( 1, $driver->query( "INSERT INTO wp_replace (slug, value) VALUES ('a', 'first')" ) );
		$this->assertSame( 2, $driver->query( "REPLACE INTO wp_replace (slug, value) VALUES ('a', 'second')" ) );

		$rows = $driver->query( "SELECT value FROM wp_replace WHERE slug = 'a'" );
		$this->assertSame( 'second', $rows[0]->value );
		$this->assertGreaterThan( 0, (int) $driver->get_insert_id() );
	}

	public function test_information_schema_select_exposes_created_table(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE wp_info (id integer NOT NULL)' );

		$rows = $driver->query(
			"SELECT table_name FROM information_schema.tables
			WHERE table_schema = DATABASE() AND table_name = 'wp_info'"
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'wp_info', $rows[0]->TABLE_NAME );
	}

	/**
	 * Read the id property from a result row.
	 *
	 * @param object $row Result row.
	 * @return string
	 */
	public function get_id_property_as_string( $row ) {
		return (string) $row->id;
	}
}
