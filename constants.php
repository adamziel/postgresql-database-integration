<?php
/**
 * Constants and helpers for the PostgreSQL database integration.
 *
 * @package wp-postgresql-integration
 */

if ( ! function_exists( 'wp_postgresql_database_integration_normalize_db_engine' ) ) {
	/**
	 * Normalize supported PostgreSQL database engine names.
	 *
	 * @param string $engine Database engine name.
	 * @return string Canonical database engine name.
	 */
	function wp_postgresql_database_integration_normalize_db_engine( $engine ) {
		$engine = strtolower( (string) $engine );

		if ( in_array( $engine, array( 'postgres', 'pgsql', 'postgresql' ), true ) ) {
			return 'postgresql';
		}

		return $engine;
	}
}

if ( ! defined( 'DB_ENGINE' ) && defined( 'DATABASE_ENGINE' ) ) {
	define( 'DB_ENGINE', wp_postgresql_database_integration_normalize_db_engine( DATABASE_ENGINE ) );
}
