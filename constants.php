<?php
/**
 * Constants and helpers for WordPress database backend support.
 *
 * @package wordpress-databases-support
 */

if ( ! function_exists( 'wp_databases_support_normalize_db_engine' ) ) {
	/**
	 * Normalize supported database engine names.
	 *
	 * @param string $engine Database engine name.
	 * @return string Canonical database engine name.
	 */
	function wp_databases_support_normalize_db_engine( $engine ) {
		$engine = strtolower( (string) $engine );

		if ( in_array( $engine, array( 'postgres', 'pgsql', 'postgresql' ), true ) ) {
			return 'postgresql';
		}
		if ( in_array( $engine, array( 'duck', 'duckdb' ), true ) ) {
			return 'duckdb';
		}
		if ( in_array( $engine, array( 'sqlite', 'sqlite3' ), true ) ) {
			return 'sqlite';
		}

		return $engine;
	}
}

if ( ! function_exists( 'wp_postgresql_database_integration_normalize_db_engine' ) ) {
	/**
	 * Backward-compatible PostgreSQL normalizer.
	 *
	 * @param string $engine Database engine name.
	 * @return string Canonical database engine name.
	 */
	function wp_postgresql_database_integration_normalize_db_engine( $engine ) {
		return wp_databases_support_normalize_db_engine( $engine );
	}
}

if ( ! function_exists( 'wp_sqlite_database_integration_normalize_db_engine' ) ) {
	/**
	 * Backward-compatible SQLite/DuckDB normalizer.
	 *
	 * @param string $engine Database engine name.
	 * @return string Canonical database engine name.
	 */
	function wp_sqlite_database_integration_normalize_db_engine( $engine ) {
		return wp_databases_support_normalize_db_engine( $engine );
	}
}

if ( ! defined( 'DB_ENGINE' ) && defined( 'DATABASE_ENGINE' ) ) {
	define( 'DB_ENGINE', wp_databases_support_normalize_db_engine( DATABASE_ENGINE ) );
}

if ( ! defined( 'FQDBDIR' ) ) {
	if ( defined( 'DB_DIR' ) ) {
		$db_dir = DB_DIR;
		if ( function_exists( 'trailingslashit' ) ) {
			$db_dir = trailingslashit( $db_dir );
		} else {
			$db_dir = rtrim( $db_dir, '/\\' ) . '/';
		}
		define( 'FQDBDIR', $db_dir );
	} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
		define( 'FQDBDIR', WP_CONTENT_DIR . '/database/' );
	} elseif ( defined( 'ABSPATH' ) ) {
		define( 'FQDBDIR', ABSPATH . 'wp-content/database/' );
	}
}

if ( defined( 'FQDBDIR' ) && ! defined( 'FQDUCKDB' ) ) {
	if ( defined( 'DUCKDB_FILE' ) ) {
		define( 'FQDUCKDB', FQDBDIR . DUCKDB_FILE );
	} else {
		define( 'FQDUCKDB', FQDBDIR . '.ht.duckdb' );
	}
}
