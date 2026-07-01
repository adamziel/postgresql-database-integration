<?php
/**
 * Database drop-in dispatcher.
 *
 * @package wordpress-databases-support
 */

require_once __DIR__ . '/../constants.php';

$database_engine = defined( 'DB_ENGINE' )
	? wp_databases_support_normalize_db_engine( DB_ENGINE )
	: 'postgresql';

if ( 'postgresql' === $database_engine ) {
	require_once __DIR__ . '/postgresql/db.php';
	return;
}

if ( 'duckdb' === $database_engine ) {
	require_once __DIR__ . '/duckdb/db.php';
	return;
}

if ( 'sqlite' === $database_engine ) {
	$sqlite_db = __DIR__ . '/../external/sqlite-database-integration/packages/plugin-sqlite-database-integration/wp-includes/sqlite/db.php';
	if ( ! file_exists( $sqlite_db ) ) {
		wp_die(
			new WP_Error(
				'sqlite_integration_missing',
				'SQLite support requires the external/sqlite-database-integration submodule to be initialized or included in the plugin package.'
			),
			'SQLite integration is missing.'
		);
	}

	require_once $sqlite_db;
	return;
}
