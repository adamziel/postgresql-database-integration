<?php
/**
 * Tests for MySQL REGEXP behavior through the PostgreSQL driver.
 *
 * @package wp-postgresql-integration
 */

class WP_PostgreSQL_Driver_RegExp_Tests extends WP_PostgreSQL_Test_Case {
	public function test_regexp_operator_matches_mysql_style_pattern(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SELECT 'PostgreSQL database integration' REGEXP 'database[[:space:]]+integration' AS matched" );

		$this->assertSame( '1', (string) $rows[0]->matched );
	}

	public function test_not_regexp_operator_negates_match(): void {
		$driver = $this->create_driver();

		$rows = $driver->query( "SELECT 'postgresql' NOT REGEXP '^mysql$' AS matched" );

		$this->assertSame( '1', (string) $rows[0]->matched );
	}

	public function test_regexp_works_in_where_clause(): void {
		$driver = $this->create_driver();
		$driver->query( 'CREATE TABLE wp_regexp (value varchar(50) NOT NULL)' );
		$driver->query( "INSERT INTO wp_regexp (value) VALUES ('alpha'), ('beta'), ('alphabet')" );

		$rows = $driver->query( "SELECT value FROM wp_regexp WHERE value REGEXP '^alpha' ORDER BY value" );

		$this->assertSame( array( 'alpha', 'alphabet' ), array_map( array( $this, 'get_value_property' ), $rows ) );
	}

	/**
	 * Read the value property from a result row.
	 *
	 * @param object $row Result row.
	 * @return string
	 */
	public function get_value_property( $row ) {
		return $row->value;
	}
}
