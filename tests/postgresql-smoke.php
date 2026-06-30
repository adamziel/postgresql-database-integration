<?php
/**
 * Standalone PostgreSQL driver smoke test.
 *
 * @package wp-postgresql-integration
 */

require_once __DIR__ . '/../wp-includes/database/load.php';

class WP_PostgreSQL_Smoke_Failure extends RuntimeException {}

function wp_postgresql_smoke_fail( string $message ): void {
	throw new WP_PostgreSQL_Smoke_Failure( $message );
}

function wp_postgresql_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		wp_postgresql_smoke_fail( $message );
	}
}

function wp_postgresql_smoke_env( string $name, ?string $default = null ): ?string {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		return $default;
	}

	return $value;
}

$dsn      = wp_postgresql_smoke_env( 'PGSQL_TEST_DSN' );
$user     = wp_postgresql_smoke_env( 'PGSQL_TEST_USER' );
$password = wp_postgresql_smoke_env( 'PGSQL_TEST_PASSWORD' );

if ( null === $dsn ) {
	wp_postgresql_smoke_fail( 'Set PGSQL_TEST_DSN to a pgsql PDO DSN.' );
}

$pdo = new PDO( $dsn, $user, $password );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );

wp_postgresql_smoke_assert(
	'pgsql' === $pdo->getAttribute( PDO::ATTR_DRIVER_NAME ),
	'PGSQL_TEST_DSN must use the pgsql PDO driver.'
);

$schema     = 'wp_pg_smoke_' . strtolower( bin2hex( random_bytes( 8 ) ) );
$schema_sql = WP_PostgreSQL_Connection::quote_identifier_value( $schema );
$failure    = null;
$throwable  = null;

try {
	$pdo->exec( 'CREATE SCHEMA ' . $schema_sql );
	$pdo->exec( 'SET search_path TO ' . $schema_sql . ', public' );

	$connection = new WP_PostgreSQL_Connection( array( 'pdo' => $pdo ) );
	$driver     = new WP_PostgreSQL_Driver( $connection, 'wptests' );

	$db_name_property = new ReflectionProperty( WP_PostgreSQL_Driver::class, 'db_name' );
	if ( PHP_VERSION_ID < 80100 ) {
		$db_name_property->setAccessible( true );
	}
	$db_name_property->setValue( $driver, $schema );

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
			('blogname', 'PostgreSQL Smoke', 'yes')"
	);
	wp_postgresql_smoke_assert( 2 === $affected_rows, 'Expected two inserted option rows.' );

	$rows = $driver->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl'" );
	wp_postgresql_smoke_assert( 1 === count( $rows ), 'Expected one siteurl row.' );
	wp_postgresql_smoke_assert( 'https://example.test' === $rows[0]->option_value, 'Unexpected siteurl value.' );

	$rows = $driver->query( "SELECT CONCAT(option_name, ':', option_value) AS value FROM wp_options WHERE option_name = 'blogname'" );
	wp_postgresql_smoke_assert( 1 === count( $rows ), 'Expected one CONCAT result row.' );
	wp_postgresql_smoke_assert( 'blogname:PostgreSQL Smoke' === $rows[0]->value, 'Unexpected CONCAT translation result.' );

	$rows = $driver->query( "SHOW TABLES LIKE 'wp_options'" );
	wp_postgresql_smoke_assert( 1 === count( $rows ), 'Expected SHOW TABLES to find wp_options.' );

	$rows = $driver->query( 'SELECT COUNT(*) AS count FROM wp_options' );
	wp_postgresql_smoke_assert( '2' === (string) $rows[0]->count, 'Expected two rows in wp_options.' );

	$stmt = $pdo->prepare(
		"SELECT COUNT(*) FROM information_schema.tables
		WHERE table_schema = ? AND table_name = 'wp_options'"
	);
	$stmt->execute( array( $schema ) );
	wp_postgresql_smoke_assert( '1' === (string) $stmt->fetchColumn(), 'Expected wp_options in the isolated schema.' );

	echo "PostgreSQL smoke test passed for schema {$schema}.\n";
} catch ( WP_PostgreSQL_Smoke_Failure $e ) {
	$failure = $e;
} catch ( Throwable $e ) {
	$throwable = $e;
} finally {
	try {
		if ( $pdo->inTransaction() ) {
			$pdo->rollBack();
		}

		$pdo->exec( 'DROP SCHEMA IF EXISTS ' . $schema_sql . ' CASCADE' );
	} catch ( Throwable $e ) {
		fwrite( STDERR, "WARN: Failed to clean up schema {$schema}: {$e->getMessage()}\n" );
	}
}

if ( null !== $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}

if ( null !== $throwable ) {
	throw $throwable;
}
