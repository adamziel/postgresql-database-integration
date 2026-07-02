#!/usr/bin/env bash
set -euo pipefail

repo_dir=$(cd "$(dirname "$0")/.." && pwd)
work_root=${WP_DUCKDB_PLUGIN_SMOKE_DIR:-"/tmp/wp-duckdb-plugin-smoke-$(id -u)-$$"}
cache_dir=${WP_DUCKDB_PLUGIN_SMOKE_CACHE:-"/tmp/wp-duckdb-plugin-smoke-cache"}
wordpress_version=${WORDPRESS_VERSION:-latest}
woocommerce_version=${WOOCOMMERCE_VERSION:-10.9.1}
query_monitor_version=${QUERY_MONITOR_VERSION:-4.0.7}

if [ "$#" -gt 0 ]; then
	backends=("$@")
else
	# Keep the default short for local iteration. Pass json/csv/parquet explicitly
	# when running the full external-backend matrix.
	backends=(duckdb)
fi

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Missing required command: $1" >&2
		exit 2
	fi
}

download() {
	local url=$1
	local dest=$2

	if [ -f "$dest" ]; then
		return
	fi

	mkdir -p "$(dirname "$dest")"
	curl -fsSL "$url" -o "$dest"
}

copy_dir() {
	local src=$1
	local dest=$2

	mkdir -p "$dest"
	rsync -a --delete "$src"/ "$dest"/
}

prepare_wordpress() {
	local dest=$1
	local archive="$cache_dir/wordpress-$wordpress_version.tar.gz"
	local url="https://wordpress.org/wordpress-$wordpress_version.tar.gz"

	rm -rf "$dest"
	mkdir -p "$dest"

	if [ -n "${WORDPRESS_SOURCE_DIR:-}" ]; then
		copy_dir "$WORDPRESS_SOURCE_DIR" "$dest"
		return
	fi

	if [ "$wordpress_version" = "latest" ]; then
		url="https://wordpress.org/latest.tar.gz"
	fi

	download "$url" "$archive"
	tar -xzf "$archive" -C "$dest" --strip-components=1
}

prepare_plugin_from_zip() {
	local slug=$1
	local version=$2
	local dest_plugins=$3
	local archive="$cache_dir/$slug-$version.zip"

	if [ -d "$dest_plugins/$slug" ]; then
		return
	fi

	download "https://downloads.wordpress.org/plugin/$slug.$version.zip" "$archive"
	unzip -q "$archive" -d "$dest_plugins"
}

prepare_databases_support_plugin() {
	local wp_root=$1
	local plugin_dest="$wp_root/wp-content/plugins/wordpress-databases-support"

	copy_dir "$repo_dir" "$plugin_dest"
	rm -rf "$plugin_dest/.git" "$plugin_dest/vendor" "$plugin_dest/.phpunit.result.cache"
	cp "$plugin_dest/db.copy" "$wp_root/wp-content/db.php"

	(
		cd "$plugin_dest"
		COMPOSER_NO_DEV=1 composer require --no-interaction --no-progress --with-all-dependencies satur.io/duckdb
		php -d ffi.enable=1 -r 'require "vendor/autoload.php"; Saturio\DuckDB\CLib\Installer::install();'
		composer dump-autoload --no-dev --no-interaction --optimize
	)
}

write_wp_config() {
	local wp_root=$1
	local backend=$2
	local site_url=$3

	BACKEND="$backend" SITE_URL="$site_url" WORDPRESS_ROOT="$wp_root" php <<'PHP'
<?php
$root     = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
$backend  = strtolower( getenv( 'BACKEND' ) ?: 'duckdb' );
$site_url = getenv( 'SITE_URL' ) ?: 'http://127.0.0.1:8080';

function duckdb_smoke_env_first( array $names ): ?string {
	foreach ( $names as $name ) {
		$value = getenv( $name );
		if ( false !== $value && '' !== $value ) {
			return $value;
		}
	}

	return null;
}

function duckdb_smoke_env_json_array( string $name ): ?array {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		return null;
	}

	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, "Expected $name to be a JSON array.\n" );
		exit( 1 );
	}

	foreach ( $decoded as $item ) {
		if ( ! is_string( $item ) || '' === trim( $item ) ) {
			fwrite( STDERR, "Expected $name to contain only non-empty strings.\n" );
			exit( 1 );
		}
	}

	return array_values( $decoded );
}

function duckdb_smoke_env_table_list(): ?array {
	$tables = duckdb_smoke_env_json_array( 'WP_DUCKDB_BACKEND_TABLES_JSON' );
	if ( null !== $tables ) {
		return $tables;
	}

	$tables_file = duckdb_smoke_env_first( array( 'WP_DUCKDB_BACKEND_TABLES_FILE', 'DUCKDB_BACKEND_TABLES_FILE' ) );
	if ( null !== $tables_file && is_file( $tables_file ) ) {
		$tables = file( $tables_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return is_array( $tables ) ? array_values( array_unique( array_map( 'trim', $tables ) ) ) : array();
	}

	$tables = duckdb_smoke_env_first( array( 'WP_DUCKDB_BACKEND_TABLES', 'DUCKDB_BACKEND_TABLES' ) );
	if ( null === $tables ) {
		return null;
	}

	return array_values(
		array_unique(
			array_filter(
				array_map( 'trim', preg_split( '/[\r\n,]+/', $tables ) ?: array() ),
				static function ( string $table ): bool {
					return '' !== $table;
				}
			)
		)
	);
}

function duckdb_smoke_bool_env( array $names ): ?bool {
	$value = duckdb_smoke_env_first( $names );
	if ( null === $value ) {
		return null;
	}

	return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
}

function duckdb_smoke_expand_placeholders( ?string $value, array $placeholders ): ?string {
	if ( null === $value ) {
		return null;
	}

	return strtr( $value, $placeholders );
}

$database_dir = $root . '/wp-content/database/';
$config       = <<<'CONFIG'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'DB_ENGINE', 'duckdb' );
define( 'DATABASE_ENGINE', 'duckdb' );
define( 'FS_METHOD', 'direct' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', false );

define( 'AUTH_KEY', 'duckdb-plugin-smoke-auth-key' );
define( 'SECURE_AUTH_KEY', 'duckdb-plugin-smoke-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'duckdb-plugin-smoke-logged-in-key' );
define( 'NONCE_KEY', 'duckdb-plugin-smoke-nonce-key' );
define( 'AUTH_SALT', 'duckdb-plugin-smoke-auth-salt' );
define( 'SECURE_AUTH_SALT', 'duckdb-plugin-smoke-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'duckdb-plugin-smoke-logged-in-salt' );
define( 'NONCE_SALT', 'duckdb-plugin-smoke-nonce-salt' );

$table_prefix = 'wp_';

CONFIG;

$config .= 'define( \'WP_HOME\', ' . var_export( $site_url, true ) . " );\n";
$config .= 'define( \'WP_SITEURL\', ' . var_export( $site_url, true ) . " );\n";
$config .= 'define( \'DB_DIR\', ' . var_export( $database_dir, true ) . " );\n";
$config .= "define( 'DUCKDB_FILE', '.ht.duckdb-plugin-smoke' );\n";
$config .= 'define( \'DUCKDB_PHP_AUTOLOAD\', ' . var_export( $root . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php', true ) . " );\n";

if ( ! in_array( $backend, array( 'duckdb', 'duck', 'native', 'file' ), true ) ) {
	$backend_slug         = preg_replace( '/[^a-z0-9_-]+/', '-', $backend );
	$placeholders         = array(
		'{root}'         => $root,
		'{database_dir}' => $database_dir,
		'{backend}'      => $backend,
		'{backend_slug}' => $backend_slug,
	);
	$external_storage_dir = duckdb_smoke_expand_placeholders( duckdb_smoke_env_first( array( 'WP_DUCKDB_EXTERNAL_STORAGE_DIR', 'DUCKDB_EXTERNAL_STORAGE_DIR' ) ), $placeholders );
	$working_database     = duckdb_smoke_expand_placeholders( duckdb_smoke_env_first( array( 'WP_DUCKDB_WORKING_DATABASE_FILE', 'DUCKDB_WORKING_DATABASE_FILE' ) ), $placeholders );
	$file_extension       = duckdb_smoke_env_first( array( 'WP_DUCKDB_BACKEND_FILE_EXTENSION', 'DUCKDB_BACKEND_FILE_EXTENSION' ) );

	if ( null === $external_storage_dir && ( in_array( $backend, array( 'csv', 'json', 'parquet' ), true ) || null !== $file_extension ) ) {
		$external_storage_dir = $database_dir . 'duckdb-' . $backend_slug . '/';
	}
	if ( null === $working_database ) {
		$working_database = $database_dir . '.ht.duckdb-plugin-smoke-' . $backend_slug . '-working';
	}

	$config .= 'define( \'DUCKDB_BACKEND\', ' . var_export( $backend, true ) . " );\n";
	if ( null !== $external_storage_dir ) {
		$config .= 'define( \'DUCKDB_EXTERNAL_STORAGE_DIR\', ' . var_export( $external_storage_dir, true ) . " );\n";
	}
	$config .= 'define( \'DUCKDB_WORKING_DATABASE_FILE\', ' . var_export( $working_database, true ) . " );\n";

	if ( null !== $file_extension ) {
		$config .= 'define( \'DUCKDB_BACKEND_FILE_EXTENSION\', ' . var_export( $file_extension, true ) . " );\n";
	}

	$read_sql = duckdb_smoke_env_first( array( 'WP_DUCKDB_BACKEND_READ_SQL', 'DUCKDB_BACKEND_READ_SQL' ) );
	if ( null !== $read_sql ) {
		$config .= 'define( \'DUCKDB_BACKEND_READ_SQL\', ' . var_export( $read_sql, true ) . " );\n";
	}

	$write_sql = duckdb_smoke_env_first( array( 'WP_DUCKDB_BACKEND_WRITE_SQL', 'DUCKDB_BACKEND_WRITE_SQL' ) );
	if ( null !== $write_sql ) {
		$config .= 'define( \'DUCKDB_BACKEND_WRITE_SQL\', ' . var_export( $write_sql, true ) . " );\n";
	}

	$setup_sql = duckdb_smoke_env_json_array( 'WP_DUCKDB_BACKEND_SETUP_SQL_JSON' );
	if ( null !== $setup_sql ) {
		$setup_sql = array_map(
			static function ( string $statement ) use ( $placeholders ): string {
				return duckdb_smoke_expand_placeholders( $statement, $placeholders );
			},
			$setup_sql
		);
		$config .= 'define( \'DUCKDB_BACKEND_SETUP_SQL\', ' . var_export( $setup_sql, true ) . " );\n";
	} else {
		$setup_sql = duckdb_smoke_env_first( array( 'WP_DUCKDB_BACKEND_SETUP_SQL', 'DUCKDB_BACKEND_SETUP_SQL' ) );
		if ( null !== $setup_sql ) {
			$setup_sql = duckdb_smoke_expand_placeholders( $setup_sql, $placeholders );
			$config .= 'define( \'DUCKDB_BACKEND_SETUP_SQL\', ' . var_export( $setup_sql, true ) . " );\n";
		}
	}

	$tables = duckdb_smoke_env_table_list();
	if ( null !== $tables ) {
		$config .= 'define( \'DUCKDB_BACKEND_TABLES\', ' . var_export( $tables, true ) . " );\n";
	}

	$atomic_flush = duckdb_smoke_bool_env( array( 'WP_DUCKDB_BACKEND_ATOMIC_FLUSH', 'DUCKDB_BACKEND_ATOMIC_FLUSH' ) );
	if ( null !== $atomic_flush ) {
		$config .= 'define( \'DUCKDB_BACKEND_ATOMIC_FLUSH\', ' . ( $atomic_flush ? 'true' : 'false' ) . " );\n";
	}
}

$config .= "\nif ( ! defined( 'ABSPATH' ) ) {\n";
$config .= "	define( 'ABSPATH', __DIR__ . '/' );\n";
$config .= "}\n";
$config .= "require_once ABSPATH . 'wp-settings.php';\n";

file_put_contents( $root . '/wp-config.php', $config );
PHP
}

php_install_and_activate() {
	local wp_root=$1

	WORDPRESS_ROOT="$wp_root" php -d ffi.enable=1 <<'PHP'
<?php
define( 'WP_INSTALLING', true );

$root = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

function duckdb_smoke_has_siteurl(): bool {
	global $wpdb;

	$suppress = $wpdb->suppress_errors( true );
	$siteurl  = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
			'siteurl'
		)
	);
	$wpdb->suppress_errors( $suppress );

	return is_string( $siteurl ) && '' !== $siteurl;
}

if ( ! duckdb_smoke_has_siteurl() ) {
	wp_install( 'DuckDB Plugin Smoke', 'admin', 'admin@example.test', true, '', 'password' );
}

update_option( 'siteurl', WP_SITEURL );
update_option( 'home', WP_HOME );
update_option( 'blogdescription', 'WooCommerce and Query Monitor running on DuckDB.' );

foreach ( array( 'query-monitor/query-monitor.php', 'woocommerce/woocommerce.php' ) as $plugin ) {
	if ( ! is_plugin_active( $plugin ) ) {
		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			fwrite( STDERR, 'Failed to activate ' . $plugin . ': ' . $result->get_error_message() . "\n" );
			exit( 1 );
		}
	}
}

if ( class_exists( 'WC_Install' ) ) {
	WC_Install::install();
}

if ( function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Simple' ) ) {
	$product_id = (int) get_option( 'duckdb_smoke_product_id', 0 );
	$product    = $product_id ? wc_get_product( $product_id ) : false;
	if ( ! $product ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'DuckDB Smoke Product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( '9.99' );
		$product->set_manage_stock( false );
		$product_id = $product->save();
		update_option( 'duckdb_smoke_product_id', $product_id );
	}
}

$tables_file = getenv( 'WP_DUCKDB_SMOKE_TABLES_FILE' );
if ( false !== $tables_file && '' !== $tables_file ) {
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	if ( is_array( $tables ) ) {
		$tables = array_values(
			array_filter(
				array_unique( $tables ),
				static function ( $table ): bool {
					return is_string( $table ) && 0 !== stripos( $table, '__wp_duckdb_' );
				}
			)
		);
		sort( $tables, SORT_STRING );
		$tables_dir = dirname( $tables_file );
		if ( ! is_dir( $tables_dir ) ) {
			mkdir( $tables_dir, 0777, true );
		}
		file_put_contents( $tables_file, implode( "\n", $tables ) . "\n" );
	}
}

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
}

echo "Installed WordPress, WooCommerce, and Query Monitor.\n";
PHP
}

php_verify_site() {
	local wp_root=$1

	WORDPRESS_ROOT="$wp_root" php -d ffi.enable=1 <<'PHP'
<?php
$root = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$checks = array(
	'query-monitor/query-monitor.php' => is_plugin_active( 'query-monitor/query-monitor.php' ),
	'woocommerce/woocommerce.php'    => is_plugin_active( 'woocommerce/woocommerce.php' ),
);

foreach ( $checks as $plugin => $active ) {
	if ( ! $active ) {
		fwrite( STDERR, "Plugin is not active: $plugin\n" );
		exit( 1 );
	}
}

$product_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'product' AND post_status = 'publish'" );
$order_tables  = $wpdb->get_results( "SHOW TABLES LIKE 'wp_wc_%'" );

if ( $product_count < 1 ) {
	fwrite( STDERR, "Expected at least one published WooCommerce product.\n" );
	exit( 1 );
}
if ( count( $order_tables ) < 1 ) {
	fwrite( STDERR, "Expected WooCommerce custom tables to exist.\n" );
	exit( 1 );
}

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
}

echo 'products=' . $product_count . "\n";
echo 'woocommerce_tables=' . count( $order_tables ) . "\n";
PHP
}

start_server() {
	local wp_root=$1
	local port=$2
	local log=$3

	(
		cd "$wp_root"
		php -d ffi.enable=1 -S "127.0.0.1:$port" >"$log" 2>&1
	) &
	START_SERVER_PID=$!
}

wait_for_http() {
	local url=$1

	for _ in $(seq 1 40); do
		if curl --max-time 3 -fsS "$url/wp-login.php" >/dev/null 2>&1; then
			return
		fi
		sleep 0.25
	done

	echo "Timed out waiting for $url" >&2
	return 1
}

assert_http_ok() {
	local url=$1
	local path=$2
	local output=$3

	local status
	status=$(curl --max-time 20 -fsS -o "$output" -w '%{http_code}' "$url$path")
	if [ "$status" != "200" ]; then
		echo "Expected HTTP 200 for $path, got $status" >&2
		return 1
	fi
	if grep -Eiq 'Error establishing a database connection|One or more database tables are unavailable|Database Error|WordPress &rsaquo; Error|Fatal error' "$output"; then
		echo "HTTP response for $path contains a WordPress/PHP error." >&2
		return 1
	fi
}

check_logs() {
	local wp_root=$1
	local server_log=$2
	local debug_log="$wp_root/wp-content/debug.log"

	if [ -f "$server_log" ] && grep -Eiq 'WordPress database error|DuckDB query failed|Failed to execute DuckDB|Fatal error|Parse error' "$server_log"; then
		echo "Server log contains an error:" >&2
		grep -Ei 'WordPress database error|DuckDB query failed|Failed to execute DuckDB|Fatal error|Parse error' "$server_log" >&2
		return 1
	fi

	if [ -f "$debug_log" ] && grep -Eiq 'WordPress database error|DuckDB query failed|Failed to execute DuckDB|Fatal error|Parse error' "$debug_log"; then
		echo "WordPress debug log contains an error:" >&2
		grep -Ei 'WordPress database error|DuckDB query failed|Failed to execute DuckDB|Fatal error|Parse error' "$debug_log" >&2
		return 1
	fi
}

run_backend() {
	local backend=$1
	local backend_slug
	backend_slug=$(printf '%s' "$backend" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')
	local wp_root="$work_root/$backend_slug/wordpress"
	local port=$(( 8100 + ( RANDOM % 800 ) ))
	local site_url="http://127.0.0.1:$port"
	local server_log="$work_root/$backend_slug/server.log"
	local tables_file="$work_root/$backend_slug/tables.txt"
	local server_pid=''

	echo "==> DuckDB plugin smoke: backend=$backend"
	prepare_wordpress "$wp_root"
	mkdir -p "$wp_root/wp-content/plugins"
	prepare_databases_support_plugin "$wp_root"
	prepare_plugin_from_zip query-monitor "$query_monitor_version" "$wp_root/wp-content/plugins"
	prepare_plugin_from_zip woocommerce "$woocommerce_version" "$wp_root/wp-content/plugins"
	write_wp_config "$wp_root" "$backend" "$site_url"
	WP_DUCKDB_SMOKE_TABLES_FILE="$tables_file" php_install_and_activate "$wp_root"
	if [ "${WP_DUCKDB_SMOKE_REWRITE_CONFIG_WITH_TABLES:-0}" = "1" ]; then
		WP_DUCKDB_BACKEND_TABLES_FILE="$tables_file" write_wp_config "$wp_root" "$backend" "$site_url"
	fi
	php_verify_site "$wp_root"

	start_server "$wp_root" "$port" "$server_log"
	server_pid=$START_SERVER_PID
	trap 'if [ -n "${server_pid:-}" ]; then kill "$server_pid" >/dev/null 2>&1 || true; fi' EXIT
	wait_for_http "$site_url"
	assert_http_ok "$site_url" '/' "$work_root/$backend_slug/front-page.html"
	assert_http_ok "$site_url" '/wp-login.php' "$work_root/$backend_slug/login.html"
	assert_http_ok "$site_url" '/?post_type=product' "$work_root/$backend_slug/products.html"
	check_logs "$wp_root" "$server_log"
	kill "$server_pid" >/dev/null 2>&1 || true
	wait "$server_pid" 2>/dev/null || true
	server_pid=''
	trap - EXIT

	echo "PASS backend=$backend root=$wp_root"
}

require_command curl
require_command tar
require_command unzip
require_command rsync
require_command composer
require_command php

mkdir -p "$work_root" "$cache_dir"

for backend in "${backends[@]}"; do
	run_backend "$backend"
done
