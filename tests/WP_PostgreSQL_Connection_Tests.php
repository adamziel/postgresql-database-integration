<?php
/**
 * Tests for the PostgreSQL connection wrapper.
 *
 * @package wp-postgresql-integration
 */

class WP_PostgreSQL_Connection_Tests extends WP_PostgreSQL_Test_Case {
	public function test_build_dsn_requires_database_name(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Option "dbname" is required' );

		WP_PostgreSQL_Connection::build_dsn( array( 'host' => '127.0.0.1' ) );
	}

	public function test_build_dsn_rejects_unsafe_parts(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot contain NUL bytes or semicolons' );

		WP_PostgreSQL_Connection::build_dsn(
			array(
				'host'   => '127.0.0.1',
				'dbname' => 'wordpress;sslmode=disable',
			)
		);
	}

	public function test_quote_identifier_escapes_embedded_quotes(): void {
		$this->assertSame( '"site""options"', WP_PostgreSQL_Connection::quote_identifier_value( 'site"options' ) );
	}

	public function test_quote_identifier_rejects_nul_bytes(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot contain NUL bytes' );

		WP_PostgreSQL_Connection::quote_identifier_value( "bad\0name" );
	}

	public function test_failed_statement_does_not_poison_transaction(): void {
		$pdo        = $this->create_isolated_pdo();
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$pdo->beginTransaction();
		$connection->query( 'CREATE TABLE tx_probe (id integer PRIMARY KEY)' );

		try {
			$connection->query( 'INSERT INTO tx_probe (id) VALUES (1), (1)' );
			$this->fail( 'Expected duplicate-key failure.' );
		} catch ( PDOException $e ) {
			$this->assertStringContainsString( 'duplicate key', strtolower( $e->getMessage() ) );
		}

		$connection->query( 'INSERT INTO tx_probe (id) VALUES (2)' );
		$stmt = $connection->query( 'SELECT COUNT(*) FROM tx_probe' );

		$this->assertSame( '1', (string) $stmt->fetchColumn() );
		$pdo->commit();
	}

	public function test_quote_preserves_mysql_text_bytes_that_postgresql_text_rejects(): void {
		$pdo        = $this->create_isolated_pdo();
		$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );

		$pdo->exec( 'CREATE TABLE text_probe (value text NOT NULL)' );
		$connection->query( 'INSERT INTO text_probe (value) VALUES (' . $connection->quote( "a\0b" ) . ')' );

		$stmt = $connection->query( 'SELECT value FROM text_probe' );
		$this->assertStringContainsString( 'WP_MYSQL_TEXT_V1', (string) $stmt->fetchColumn() );
	}
}
