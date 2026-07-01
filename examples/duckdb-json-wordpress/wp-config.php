<?php
/**
 * WordPress configuration for the DuckDB JSON Docker example.
 *
 * @package wordpress-databases-support
 */

$duckdb_json_site_url = getenv( 'WORDPRESS_SITE_URL' ) ?: 'http://localhost:8080';

define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'duckdb' );
define( 'DB_PASSWORD', 'duckdb' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_BACKEND', 'json' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-json/' );
define( 'DUCKDB_WORKING_DATABASE_FILE', __DIR__ . '/wp-content/database/.ht.duckdb-working' );
define( 'DUCKDB_PHP_AUTOLOAD', __DIR__ . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php' );

define( 'WP_HOME', $duckdb_json_site_url );
define( 'WP_SITEURL', $duckdb_json_site_url );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );

define( 'AUTH_KEY', 'duckdb-json-example-auth-key-change-me' );
define( 'SECURE_AUTH_KEY', 'duckdb-json-example-secure-auth-key-change-me' );
define( 'LOGGED_IN_KEY', 'duckdb-json-example-logged-in-key-change-me' );
define( 'NONCE_KEY', 'duckdb-json-example-nonce-key-change-me' );
define( 'AUTH_SALT', 'duckdb-json-example-auth-salt-change-me' );
define( 'SECURE_AUTH_SALT', 'duckdb-json-example-secure-auth-salt-change-me' );
define( 'LOGGED_IN_SALT', 'duckdb-json-example-logged-in-salt-change-me' );
define( 'NONCE_SALT', 'duckdb-json-example-nonce-salt-change-me' );

$table_prefix = 'wp_';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
