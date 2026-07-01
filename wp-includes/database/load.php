<?php
/**
 * PostgreSQL driver loader.
 *
 * @package wp-postgresql-integration
 */

define( 'WP_POSTGRESQL_DRIVER_LOADER_PATH', __FILE__ );

require_once __DIR__ . '/php-polyfills.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/parser/class-wp-parser-grammar.php';
require_once __DIR__ . '/parser/class-wp-parser.php';
require_once __DIR__ . '/parser/class-wp-parser-node.php';
require_once __DIR__ . '/parser/class-wp-parser-token.php';
require_once __DIR__ . '/mysql/class-wp-mysql-token.php';
require_once __DIR__ . '/mysql/class-wp-mysql-lexer.php';
require_once __DIR__ . '/mysql/class-wp-mysql-parser.php';
require_once __DIR__ . '/postgresql/class-wp-postgresql-connection.php';
require_once __DIR__ . '/postgresql/class-wp-postgresql-create-table-translator.php';
require_once __DIR__ . '/postgresql/trait-wp-postgresql-driver-rewrite-rules.php';
require_once __DIR__ . '/postgresql/class-wp-postgresql-driver.php';
require_once __DIR__ . '/duckdb/class-wp-duckdb-driver-exception.php';
require_once __DIR__ . '/duckdb/class-wp-duckdb-runtime.php';
require_once __DIR__ . '/duckdb/class-wp-duckdb-result-statement.php';
require_once __DIR__ . '/duckdb/class-wp-duckdb-prepared-statement.php';
require_once __DIR__ . '/duckdb/class-wp-duckdb-connection.php';
require_once __DIR__ . '/duckdb/class-wp-duckdb-driver.php';
