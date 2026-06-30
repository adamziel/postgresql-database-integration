<?php
/**
 * PHPUnit bootstrap for PostgreSQL-backed tests.
 *
 * @package wp-postgresql-integration
 */

require_once __DIR__ . '/bootstrap-common.php';

abstract class WP_PostgreSQL_Test_Case extends PHPUnit\Framework\TestCase {
	/**
	 * PDO connection for this test.
	 *
	 * @var PDO|null
	 */
	protected $pdo;

	/**
	 * PostgreSQL connection wrapper for this test.
	 *
	 * @var WP_PostgreSQL_Connection|null
	 */
	protected $connection;

	/**
	 * Driver for this test.
	 *
	 * @var WP_PostgreSQL_Driver|null
	 */
	protected $driver;

	/**
	 * Isolated PostgreSQL schema name.
	 *
	 * @var string|null
	 */
	private $schema;

	/**
	 * Create an isolated driver using the PGSQL_TEST_* environment variables.
	 *
	 * @return WP_PostgreSQL_Driver
	 */
	protected function create_driver() {
		$this->pdo        = $this->create_isolated_pdo();
		$this->connection = new WP_PostgreSQL_Connection( array( 'pdo' => $this->pdo ) );
		$this->driver     = new WP_PostgreSQL_Driver( $this->connection, 'wordpress_test' );

		$db_name_property = new ReflectionProperty( WP_PostgreSQL_Driver::class, 'db_name' );
		if ( PHP_VERSION_ID < 80100 ) {
			$db_name_property->setAccessible( true );
		}
		$db_name_property->setValue( $this->driver, $this->schema );

		return $this->driver;
	}

	/**
	 * Create an isolated PDO connection and schema.
	 *
	 * @return PDO
	 */
	protected function create_isolated_pdo() {
		$dsn = getenv( 'PGSQL_TEST_DSN' );
		if ( false === $dsn || '' === $dsn ) {
			$this->markTestSkipped( 'Set PGSQL_TEST_DSN to run PostgreSQL integration tests.' );
		}

		$user     = getenv( 'PGSQL_TEST_USER' );
		$password = getenv( 'PGSQL_TEST_PASSWORD' );
		$pdo      = new PDO(
			$dsn,
			false === $user ? null : $user,
			false === $password ? null : $password
		);
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

		$this->schema = 'wp_pg_tests_' . strtolower( bin2hex( random_bytes( 8 ) ) );
		$schema_sql   = WP_PostgreSQL_Connection::quote_identifier_value( $this->schema );
		$pdo->exec( 'CREATE SCHEMA ' . $schema_sql );
		$pdo->exec( 'SET search_path TO ' . $schema_sql . ', public' );

		return $pdo;
	}

	/**
	 * Get the active isolated schema name.
	 *
	 * @return string
	 */
	protected function get_schema() {
		if ( null === $this->schema ) {
			$this->fail( 'No isolated PostgreSQL schema has been created.' );
		}

		return $this->schema;
	}

	/**
	 * Drop the isolated schema.
	 */
	protected function tearDown(): void {
		if ( $this->pdo instanceof PDO && null !== $this->schema ) {
			try {
				if ( $this->pdo->inTransaction() ) {
					$this->pdo->rollBack();
				}

				$this->pdo->exec(
					'DROP SCHEMA IF EXISTS ' . WP_PostgreSQL_Connection::quote_identifier_value( $this->schema ) . ' CASCADE'
				);
			} catch ( Throwable $e ) {
				fwrite( STDERR, "WARN: Failed to drop test schema {$this->schema}: {$e->getMessage()}\n" );
			}
		}

		$this->pdo        = null;
		$this->connection = null;
		$this->driver     = null;
		$this->schema     = null;

		parent::tearDown();
	}
}
