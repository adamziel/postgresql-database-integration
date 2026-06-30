<?php
/**
 * Database drop-in dispatcher.
 *
 * @package wp-postgresql-integration
 */

require_once __DIR__ . '/../constants.php';

$database_engine = defined( 'DB_ENGINE' )
	? wp_postgresql_database_integration_normalize_db_engine( DB_ENGINE )
	: 'postgresql';

if ( 'postgresql' !== $database_engine ) {
	return;
}

require_once __DIR__ . '/postgresql/db.php';
