#!/usr/bin/env bash
set -euo pipefail

repo_dir=$(cd "$(dirname "$0")/.." && pwd)
run_id=${WP_DUCKDB_BENCHMARK_RUN_ID:-"$(date -u +%Y%m%dT%H%M%SZ)"}
output_dir=${WP_DUCKDB_BENCHMARK_OUTPUT_DIR:-"$repo_dir/artifacts/duckdb-benchmarks/runs/$run_id"}
work_root=${WP_DUCKDB_BENCHMARK_WORK_DIR:-"/tmp/wp-duckdb-benchmark-$(id -u)-$run_id"}
cache_dir=${WP_DUCKDB_BENCHMARK_CACHE:-"/tmp/wp-duckdb-benchmark-cache"}
wordpress_version=${WORDPRESS_VERSION:-latest}
server_workers=${WP_DUCKDB_BENCHMARK_SERVER_WORKERS:-4}
read_requests=${WP_DUCKDB_BENCHMARK_READ_REQUESTS:-40}
write_requests=${WP_DUCKDB_BENCHMARK_WRITE_REQUESTS:-20}
benchmark_timeout=${WP_DUCKDB_BENCHMARK_TIMEOUT:-60}
lock_timeout=${WP_DUCKDB_LOCK_TIMEOUT_SECONDS:-${DUCKDB_LOCK_TIMEOUT_SECONDS:-30}}
events_file="$output_dir/events.jsonl"
meta_file="$output_dir/meta.json"
benchmark_failed=0
actual_wordpress_version=""

if [ "$#" -gt 0 ]; then
	backends=("$@")
elif [ -n "${WP_DUCKDB_BENCHMARK_BACKENDS:-}" ]; then
	read -r -a backends <<<"$WP_DUCKDB_BENCHMARK_BACKENDS"
else
	backends=(mysql sqlite duckdb sqlite_attach mysql_attach json csv parquet s3_parquet)
fi

read -r -a concurrency_levels <<<"${WP_DUCKDB_BENCHMARK_CONCURRENCY:-1 2 4 8}"

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Missing required command: $1" >&2
		exit 2
	fi
}

download() {
	local url=$1
	local dest=$2
	local attempt

	if [ -f "$dest" ]; then
		return
	fi

	mkdir -p "$(dirname "$dest")"
	for attempt in 1 2 3 4 5; do
		if curl -fsSL "$url" -o "$dest"; then
			return
		fi
		rm -f "$dest"
		sleep "$attempt"
	done

	return 1
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

prepare_duckdb_vendor_cache() {
	local vendor_cache="$cache_dir/duckdb-php-vendor"

	if [ -f "$vendor_cache/vendor/autoload.php" ] && [ -f "$vendor_cache/vendor/satur.io/duckdb/lib/libduckdb.so" ]; then
		DUCKDB_VENDOR_CACHE="$vendor_cache/vendor"
		return
	fi

	rm -rf "$vendor_cache"
	mkdir -p "$vendor_cache"
	(
		cd "$vendor_cache"
		composer require --no-interaction --no-progress --with-all-dependencies satur.io/duckdb
		php -d ffi.enable=1 -r 'require "vendor/autoload.php"; Saturio\DuckDB\CLib\Installer::install();'
		composer dump-autoload --no-dev --no-interaction --optimize
	)
	DUCKDB_VENDOR_CACHE="$vendor_cache/vendor"
}

prepare_databases_support_plugin() {
	local wp_root=$1
	local plugin_dest="$wp_root/wp-content/plugins/wordpress-databases-support"

	prepare_duckdb_vendor_cache
	rm -rf "$plugin_dest"
	mkdir -p "$plugin_dest"
	rsync -a --delete \
		--exclude='.git' \
		--exclude='.github' \
		--exclude='.phpunit.result.cache' \
		--exclude='artifacts' \
		--exclude='build' \
		--exclude='docs' \
		--exclude='examples' \
		--exclude='external' \
		--exclude='tests' \
		--exclude='vendor' \
		"$repo_dir"/ "$plugin_dest"/
	ln -s "$DUCKDB_VENDOR_CACHE" "$plugin_dest/vendor"
	cp "$plugin_dest/db.copy" "$wp_root/wp-content/db.php"
}

prepare_sqlite_integration_plugin() {
	local wp_root=$1
	local plugin_dest="$wp_root/wp-content/plugins/sqlite-database-integration"

	rm -rf "$plugin_dest"
	mkdir -p "$plugin_dest"
	rsync -a --delete \
		--exclude='.git' \
		"$repo_dir/external/sqlite-database-integration/packages/plugin-sqlite-database-integration"/ "$plugin_dest"/
	rm -rf "$plugin_dest/wp-includes/database"
	cp -R "$repo_dir/external/sqlite-database-integration/packages/mysql-on-sqlite/src" "$plugin_dest/wp-includes/database"
	cp "$plugin_dest/db.copy" "$wp_root/wp-content/db.php"
}

start_mysql_server() {
	local backend_slug=$1
	local data_dir="$work_root/$backend_slug/mysql-data"
	local socket="$work_root/$backend_slug/mysql.sock"
	local log="$output_dir/$backend_slug-mysql.log"
	local pid_file="$work_root/$backend_slug/mysql.pid"
	local port=$(( 9300 + ( RANDOM % 500 ) ))

	require_command mariadb
	require_command mariadb-install-db
	require_command mariadbd

	rm -rf "$data_dir"
	mkdir -p "$data_dir" "$(dirname "$socket")"
	mariadb-install-db \
		--datadir="$data_dir" \
		--auth-root-authentication-method=normal \
		--skip-test-db \
		>"$log" 2>&1

	mariadbd \
		--datadir="$data_dir" \
		--socket="$socket" \
		--port="$port" \
		--bind-address=127.0.0.1 \
		--pid-file="$pid_file" \
		--skip-networking=0 \
		--log-error="$log" \
		--user="$(id -un)" &
	MYSQL_SERVER_PID=$!

	for _ in $(seq 1 80); do
		if mariadb --protocol=socket --socket="$socket" -uroot -e 'SELECT 1' >/dev/null 2>&1; then
			MYSQL_BENCHMARK_HOST="127.0.0.1:$port"
			mariadb --protocol=socket --socket="$socket" -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wordpress'@'127.0.0.1' IDENTIFIED BY 'wordpress';
CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'127.0.0.1';
GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'localhost';
FLUSH PRIVILEGES;
SQL
			return
		fi
		sleep 0.25
	done

	echo "Timed out waiting for MariaDB. Log: $log" >&2
	tail -100 "$log" >&2 || true
	return 1
}

stop_mysql_server() {
	local mysql_pid=${1:-}

	if [ -n "$mysql_pid" ]; then
		kill "$mysql_pid" >/dev/null 2>&1 || true
		wait "$mysql_pid" 2>/dev/null || true
	fi
}

download_minio_tools() {
	local bin_dir="$cache_dir/minio-bin"

	mkdir -p "$bin_dir"
	download https://dl.min.io/server/minio/release/linux-amd64/minio "$bin_dir/minio"
	download https://dl.min.io/client/mc/release/linux-amd64/mc "$bin_dir/mc"
	chmod +x "$bin_dir/minio" "$bin_dir/mc"
	MINIO_BIN="$bin_dir/minio"
	MC_BIN="$bin_dir/mc"
}

start_minio_server() {
	local backend_slug=$1
	local data_dir="$work_root/$backend_slug/minio-data"
	local log="$output_dir/$backend_slug-minio.log"
	local port=$(( 9900 + ( RANDOM % 500 ) ))
	local bucket

	download_minio_tools
	rm -rf "$data_dir"
	mkdir -p "$data_dir"

	MINIO_ROOT_USER=minioadmin MINIO_ROOT_PASSWORD=minioadmin \
		"$MINIO_BIN" server "$data_dir" --address "127.0.0.1:$port" >"$log" 2>&1 &
	MINIO_SERVER_PID=$!

	for _ in $(seq 1 80); do
		if curl --max-time 3 -fsS "http://127.0.0.1:$port/minio/health/ready" >/dev/null 2>&1; then
			bucket=$(printf 'duckdb-wordpress-benchmark-%s-%s' "$run_id" "$backend_slug" | tr '[:upper:]_' '[:lower:]-' | tr -c 'a-z0-9.-' '-')
			"$MC_BIN" alias set "benchmark-$backend_slug" "http://127.0.0.1:$port" minioadmin minioadmin >/dev/null
			"$MC_BIN" mb -p "benchmark-$backend_slug/$bucket" >/dev/null
			MINIO_BENCHMARK_ENDPOINT="127.0.0.1:$port"
			MINIO_BENCHMARK_BUCKET="$bucket"
			return
		fi
		sleep 0.25
	done

	echo "Timed out waiting for MinIO. Log: $log" >&2
	tail -100 "$log" >&2 || true
	return 1
}

stop_minio_server() {
	local minio_pid=${1:-}

	if [ -n "$minio_pid" ]; then
		kill "$minio_pid" >/dev/null 2>&1 || true
		wait "$minio_pid" 2>/dev/null || true
	fi
}

free_tcp_port() {
	php <<'PHP'
<?php
$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
if ( false === $server ) {
	fwrite( STDERR, $errstr . "\n" );
	exit( 1 );
}
$name = stream_socket_get_name( $server, false );
fclose( $server );
if ( ! is_string( $name ) || ! preg_match( '/:(\d+)$/', $name, $matches ) ) {
	exit( 1 );
}
echo $matches[1];
PHP
}

start_duckdb_sidecar_server() {
	local backend_slug=$1
	local wp_root=$2
	local backend=$3
	local transport=$4
	local endpoint=$5
	local database_dir="$wp_root/wp-content/database/"
	local database="$database_dir.ht.duckdb-benchmark"
	local plugin_dir="$wp_root/wp-content/plugins/wordpress-databases-support"
	local log="$output_dir/$backend_slug-duckdb-sidecar.log"
	local command

	if ! is_native_duckdb_backend "$backend"; then
		database="${WP_DUCKDB_WORKING_DATABASE_FILE:-${DUCKDB_WORKING_DATABASE_FILE:-}}"
		if [ -n "$database" ]; then
			database=${database//\{root\}/$wp_root}
			database=${database//\{database_dir\}/$database_dir}
			database=${database//\{backend\}/$backend}
			database=${database//\{backend_slug\}/$backend_slug}
		else
			database="$database_dir.ht.duckdb-benchmark-$backend_slug-working"
		fi
	fi

	mkdir -p "$(dirname "$database")"
	command=(php -d ffi.enable=1 "$plugin_dir/bin/duckdb-sidecar.php" --path="$database")
	if [ "$transport" = "unix" ]; then
		mkdir -p "$(dirname "$endpoint")"
		rm -f "$endpoint"
		command+=(--socket="$endpoint")
	elif [ "$transport" = "tcp" ]; then
		command+=(--tcp="$endpoint")
	elif [ "$transport" = "http" ]; then
		command+=(--http="$endpoint")
	else
		echo "Unsupported DuckDB sidecar transport for benchmark: $transport" >&2
		return 1
	fi

	"${command[@]}" >"$log" 2>&1 &
	DUCKDB_SIDECAR_PID=$!

	for _ in $(seq 1 80); do
		if DUCKDB_SIDECAR_PLUGIN_DIR="$plugin_dir" WP_DUCKDB_REMOTE_TRANSPORT="$transport" WP_DUCKDB_REMOTE_ENDPOINT="$endpoint" php -d ffi.enable=1 <<'PHP' >/dev/null 2>&1
<?php
$plugin_dir = getenv( 'DUCKDB_SIDECAR_PLUGIN_DIR' );
if ( false === $plugin_dir || '' === $plugin_dir ) {
	exit( 1 );
}
if ( is_file( $plugin_dir . '/vendor/autoload.php' ) ) {
	require_once $plugin_dir . '/vendor/autoload.php';
}
require_once $plugin_dir . '/wp-includes/database/load.php';
$transport = getenv( 'WP_DUCKDB_REMOTE_TRANSPORT' );
$endpoint  = getenv( 'WP_DUCKDB_REMOTE_ENDPOINT' );
if ( 'unix' === $transport ) {
	if ( ! is_string( $endpoint ) || ! is_socket( $endpoint ) ) {
		exit( 1 );
	}
	$options = array(
		'transport' => 'unix',
		'socket'    => $endpoint,
	);
} elseif ( 'tcp' === $transport || 'http' === $transport ) {
	if ( ! is_string( $endpoint ) || ! preg_match( '/^([^:]+):(\d+)$/', $endpoint, $matches ) ) {
		exit( 1 );
	}
	$options = array(
		'transport' => $transport,
		'host'      => $matches[1],
		'port'      => (int) $matches[2],
	);
	if ( 'http' === $transport ) {
		$options['url'] = 'http://' . $endpoint . '/query';
	}
} else {
	exit( 1 );
}
$connection = new WP_DuckDB_Remote_Connection( $options );
$connection->query( 'SELECT 1' )->fetchAll();
$connection->close();
PHP
		then
			return
		fi
		sleep 0.25
	done

	echo "Timed out waiting for DuckDB sidecar. Log: $log" >&2
	tail -100 "$log" >&2 || true
	return 1
}

stop_duckdb_sidecar_server() {
	local sidecar_pid=${1:-}

	if [ -n "$sidecar_pid" ]; then
		kill "$sidecar_pid" >/dev/null 2>&1 || true
		wait "$sidecar_pid" 2>/dev/null || true
	fi
}

clear_duckdb_backend_env() {
	unset WP_DUCKDB_EXTERNAL_STORAGE_DIR
	unset WP_DUCKDB_BACKEND_FILE_EXTENSION
	unset WP_DUCKDB_BACKEND_SETUP_SQL_JSON
	unset WP_DUCKDB_BACKEND_READ_SQL
	unset WP_DUCKDB_BACKEND_WRITE_SQL
	unset WP_DUCKDB_BACKEND_ATOMIC_FLUSH
	unset WP_DUCKDB_CONNECTION
	unset DUCKDB_CONNECTION
	unset WP_DUCKDB_REMOTE_HOST
	unset DUCKDB_REMOTE_HOST
	unset WP_DUCKDB_REMOTE_PORT
	unset DUCKDB_REMOTE_PORT
	unset WP_DUCKDB_REMOTE_URL
	unset DUCKDB_REMOTE_URL
	unset WP_DUCKDB_REMOTE_SOCKET
	unset DUCKDB_REMOTE_SOCKET
}

cleanup_backend() {
	local server_pid=${1:-}
	local mysql_pid=${2:-}
	local minio_pid=${3:-}
	local sidecar_pid=${4:-}
	local backend=${5:-}

	stop_server "$server_pid"
	stop_mysql_server "$mysql_pid"
	stop_minio_server "$minio_pid"
	stop_duckdb_sidecar_server "$sidecar_pid"

	if [ -n "$backend" ] && { uses_duckdb_external_config "$backend" || uses_duckdb_sidecar_config "$backend" || uses_duckdb_benchmark_remote_config "$backend"; }; then
		clear_duckdb_backend_env
	fi
}

write_benchmark_mu_plugin() {
	local wp_root=$1
	local mu_dir="$wp_root/wp-content/mu-plugins"

	mkdir -p "$mu_dir"
	cat >"$mu_dir/duckdb-benchmark.php" <<'PHP'
<?php
/**
 * Real WordPress read/write probes for the DuckDB backend benchmark.
 */

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'duckdb-benchmark/v1',
			'/read',
			array(
				'methods'             => 'GET',
				'callback'            => 'duckdb_benchmark_rest_read',
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'duckdb-benchmark/v1',
			'/write',
			array(
				'methods'             => 'POST',
				'callback'            => 'duckdb_benchmark_rest_write',
				'permission_callback' => 'duckdb_benchmark_can_write',
			)
		);
	}
);

function duckdb_benchmark_can_write( WP_REST_Request $request ): bool {
	return defined( 'DUCKDB_BENCHMARK_TOKEN' )
		&& hash_equals( (string) DUCKDB_BENCHMARK_TOKEN, (string) $request->get_param( 'token' ) );
}

function duckdb_benchmark_rest_read( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	$posts = get_posts(
		array(
			'numberposts' => 6,
			'post_status' => 'publish',
			'post_type'   => 'post',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);

	$post_meta = array();
	foreach ( $posts as $post ) {
		$post_meta[ $post->ID ] = get_post_meta( $post->ID, 'duckdb_benchmark_seed', true );
	}

	return rest_ensure_response(
		array(
			'ok'            => true,
			'site_name'     => get_option( 'blogname' ),
			'option_count'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->options" ),
			'post_count'    => count( $posts ),
			'post_meta'     => $post_meta,
			'latest_events' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'duckdb_benchmark_events' ),
		)
	);
}

function duckdb_benchmark_rest_write( WP_REST_Request $request ) {
	global $wpdb;

	$table    = $wpdb->prefix . 'duckdb_benchmark_events';
	$sequence = (string) $request->get_param( 'sequence' );
	$payload  = wp_json_encode(
		array(
			'sequence' => $sequence,
			'time'     => microtime( true ),
			'random'   => wp_generate_password( 24, false, false ),
		)
	);

	$inserted = $wpdb->insert(
		$table,
		array(
			'scenario'   => 'rest_write',
			'payload'    => $payload,
			'created_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error(
			'duckdb_benchmark_insert_failed',
			$wpdb->last_error ?: 'The benchmark insert returned false.',
			array( 'status' => 500 )
		);
	}

	$option_name = 'duckdb_benchmark_write_' . md5( $payload . '|' . $sequence );
	add_option( $option_name, $payload, '', false );
	update_option( 'duckdb_benchmark_last_payload', $payload, false );

	return rest_ensure_response(
		array(
			'ok'        => true,
			'insert_id' => (int) $wpdb->insert_id,
			'sequence'  => $sequence,
		)
	);
}
PHP
}

write_wp_config() {
	local wp_root=$1
	local backend=$2
	local site_url=$3
	local benchmark_token=$4
	local tables_file=${5:-}

	BACKEND="$backend" \
	SITE_URL="$site_url" \
	WORDPRESS_ROOT="$wp_root" \
	BENCHMARK_TOKEN="$benchmark_token" \
	BENCHMARK_TABLES_FILE="$tables_file" \
	BENCHMARK_LOCK_TIMEOUT="$lock_timeout" \
	MYSQL_BENCHMARK_HOST="${MYSQL_BENCHMARK_HOST:-127.0.0.1}" \
	php <<'PHP'
<?php
$root        = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
$backend     = strtolower( getenv( 'BACKEND' ) ?: 'duckdb' );
$site_url    = getenv( 'SITE_URL' ) ?: 'http://127.0.0.1:8080';
$token       = getenv( 'BENCHMARK_TOKEN' ) ?: 'duckdb-benchmark';
$tables_file = getenv( 'BENCHMARK_TABLES_FILE' ) ?: '';
$lock_timeout = max( 1, (int) ( getenv( 'BENCHMARK_LOCK_TIMEOUT' ) ?: 30 ) );
$mysql_host  = getenv( 'MYSQL_BENCHMARK_HOST' ) ?: '127.0.0.1';

function duckdb_benchmark_env_first( array $names ): ?string {
	foreach ( $names as $name ) {
		$value = getenv( $name );
		if ( false !== $value && '' !== $value ) {
			return $value;
		}
	}
	return null;
}

function duckdb_benchmark_env_json_array( string $name ): ?array {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		return null;
	}
	$decoded = json_decode( $value, true );
	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, "Expected $name to be a JSON array.\n" );
		exit( 1 );
	}
	return array_values( $decoded );
}

function duckdb_benchmark_bool_env( array $names ): ?bool {
	$value = duckdb_benchmark_env_first( $names );
	if ( null === $value ) {
		return null;
	}
	return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
}

function duckdb_benchmark_expand( ?string $value, array $placeholders ): ?string {
	return null === $value ? null : strtr( $value, $placeholders );
}

function duckdb_benchmark_tables_from_file( string $tables_file ): ?array {
	if ( '' === $tables_file || ! is_file( $tables_file ) ) {
		return null;
	}
	$tables = file( $tables_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! is_array( $tables ) ) {
		return null;
	}
	return array_values(
		array_unique(
			array_filter(
				array_map( 'trim', $tables ),
				static function ( string $table ): bool {
					return '' !== $table;
				}
			)
		)
	);
}

$database_dir = $root . '/wp-content/database/';

if ( 'mysql' === $backend || 'mariadb' === $backend ) {
	$config = <<<'CONFIG'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
CONFIG;
	$config .= 'define( \'DB_HOST\', ' . var_export( $mysql_host, true ) . " );\n";
	$config .= <<<'CONFIG'
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'FS_METHOD', 'direct' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', false );

define( 'AUTH_KEY', 'mysql-benchmark-auth-key' );
define( 'SECURE_AUTH_KEY', 'mysql-benchmark-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'mysql-benchmark-logged-in-key' );
define( 'NONCE_KEY', 'mysql-benchmark-nonce-key' );
define( 'AUTH_SALT', 'mysql-benchmark-auth-salt' );
define( 'SECURE_AUTH_SALT', 'mysql-benchmark-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'mysql-benchmark-logged-in-salt' );
define( 'NONCE_SALT', 'mysql-benchmark-nonce-salt' );

$table_prefix = 'wp_';

CONFIG;
	$config .= 'define( \'WP_HOME\', ' . var_export( $site_url, true ) . " );\n";
	$config .= 'define( \'WP_SITEURL\', ' . var_export( $site_url, true ) . " );\n";
	$config .= 'define( \'DUCKDB_BENCHMARK_TOKEN\', ' . var_export( $token, true ) . " );\n";
	$config .= "\nif ( ! defined( 'ABSPATH' ) ) {\n";
	$config .= "	define( 'ABSPATH', __DIR__ . '/' );\n";
	$config .= "}\n";
	$config .= "require_once ABSPATH . 'wp-settings.php';\n";
	file_put_contents( $root . '/wp-config.php', $config );
	return;
}

if ( 'sqlite' === $backend ) {
	$config = <<<'CONFIG'
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'DB_ENGINE', 'sqlite' );
define( 'DATABASE_ENGINE', 'sqlite' );
define( 'FS_METHOD', 'direct' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', false );

define( 'AUTH_KEY', 'sqlite-benchmark-auth-key' );
define( 'SECURE_AUTH_KEY', 'sqlite-benchmark-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'sqlite-benchmark-logged-in-key' );
define( 'NONCE_KEY', 'sqlite-benchmark-nonce-key' );
define( 'AUTH_SALT', 'sqlite-benchmark-auth-salt' );
define( 'SECURE_AUTH_SALT', 'sqlite-benchmark-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'sqlite-benchmark-logged-in-salt' );
define( 'NONCE_SALT', 'sqlite-benchmark-nonce-salt' );

$table_prefix = 'wp_';

CONFIG;
	$config .= 'define( \'WP_HOME\', ' . var_export( $site_url, true ) . " );\n";
	$config .= 'define( \'WP_SITEURL\', ' . var_export( $site_url, true ) . " );\n";
	$config .= 'define( \'DB_DIR\', ' . var_export( $database_dir, true ) . " );\n";
	$config .= "define( 'DB_FILE', '.ht.sqlite-benchmark' );\n";
	$config .= 'define( \'DUCKDB_BENCHMARK_TOKEN\', ' . var_export( $token, true ) . " );\n";
	$config .= "\nif ( ! defined( 'ABSPATH' ) ) {\n";
	$config .= "	define( 'ABSPATH', __DIR__ . '/' );\n";
	$config .= "}\n";
	$config .= "require_once ABSPATH . 'wp-settings.php';\n";
	file_put_contents( $root . '/wp-config.php', $config );
	return;
}

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
define( 'SCRIPT_DEBUG', false );
define( 'DISABLE_WP_CRON', true );
define( 'WP_AUTO_UPDATE_CORE', false );

define( 'AUTH_KEY', 'duckdb-benchmark-auth-key' );
define( 'SECURE_AUTH_KEY', 'duckdb-benchmark-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'duckdb-benchmark-logged-in-key' );
define( 'NONCE_KEY', 'duckdb-benchmark-nonce-key' );
define( 'AUTH_SALT', 'duckdb-benchmark-auth-salt' );
define( 'SECURE_AUTH_SALT', 'duckdb-benchmark-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'duckdb-benchmark-logged-in-salt' );
define( 'NONCE_SALT', 'duckdb-benchmark-nonce-salt' );

$table_prefix = 'wp_';

CONFIG;

$config .= 'define( \'WP_HOME\', ' . var_export( $site_url, true ) . " );\n";
$config .= 'define( \'WP_SITEURL\', ' . var_export( $site_url, true ) . " );\n";
$config .= 'define( \'DB_DIR\', ' . var_export( $database_dir, true ) . " );\n";
$config .= "define( 'DUCKDB_FILE', '.ht.duckdb-benchmark' );\n";
$config .= 'define( \'DUCKDB_PHP_AUTOLOAD\', ' . var_export( $root . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php', true ) . " );\n";
$config .= 'define( \'DUCKDB_BENCHMARK_TOKEN\', ' . var_export( $token, true ) . " );\n";
$config .= 'define( \'DUCKDB_LOCK_TIMEOUT_SECONDS\', ' . $lock_timeout . " );\n";
$config .= 'define( \'WP_DUCKDB_LOCK_TIMEOUT_SECONDS\', ' . $lock_timeout . " );\n";
$remote_socket = duckdb_benchmark_env_first( array( 'WP_DUCKDB_REMOTE_SOCKET', 'DUCKDB_REMOTE_SOCKET' ) );
if ( null !== $remote_socket ) {
	$config .= 'define( \'DUCKDB_REMOTE_SOCKET\', ' . var_export( $remote_socket, true ) . " );\n";
}
$remote_transport = duckdb_benchmark_env_first( array( 'WP_DUCKDB_CONNECTION', 'DUCKDB_CONNECTION', 'WP_DUCKDB_REMOTE_TRANSPORT', 'DUCKDB_REMOTE_TRANSPORT' ) );
if ( null !== $remote_transport ) {
	$config .= 'define( \'DUCKDB_CONNECTION\', ' . var_export( $remote_transport, true ) . " );\n";
}
$remote_host = duckdb_benchmark_env_first( array( 'WP_DUCKDB_REMOTE_HOST', 'DUCKDB_REMOTE_HOST' ) );
if ( null !== $remote_host ) {
	$config .= 'define( \'DUCKDB_REMOTE_HOST\', ' . var_export( $remote_host, true ) . " );\n";
}
$remote_port = duckdb_benchmark_env_first( array( 'WP_DUCKDB_REMOTE_PORT', 'DUCKDB_REMOTE_PORT' ) );
if ( null !== $remote_port ) {
	$config .= 'define( \'DUCKDB_REMOTE_PORT\', ' . var_export( $remote_port, true ) . " );\n";
}
$remote_url = duckdb_benchmark_env_first( array( 'WP_DUCKDB_REMOTE_URL', 'DUCKDB_REMOTE_URL' ) );
if ( null !== $remote_url ) {
	$config .= 'define( \'DUCKDB_REMOTE_URL\', ' . var_export( $remote_url, true ) . " );\n";
}

if ( ! in_array( $backend, array( 'duckdb', 'duck', 'native', 'file', 'duckdb_sidecar' ), true ) ) {
	$backend_slug    = preg_replace( '/[^a-z0-9_-]+/', '-', $backend );
	$placeholders    = array(
		'{root}'         => $root,
		'{database_dir}' => $database_dir,
		'{backend}'      => $backend,
		'{backend_slug}' => $backend_slug,
	);
	$external_dir    = duckdb_benchmark_expand( duckdb_benchmark_env_first( array( 'WP_DUCKDB_EXTERNAL_STORAGE_DIR', 'DUCKDB_EXTERNAL_STORAGE_DIR' ) ), $placeholders );
	$working_db      = duckdb_benchmark_expand( duckdb_benchmark_env_first( array( 'WP_DUCKDB_WORKING_DATABASE_FILE', 'DUCKDB_WORKING_DATABASE_FILE' ) ), $placeholders );
	$file_extension  = duckdb_benchmark_env_first( array( 'WP_DUCKDB_BACKEND_FILE_EXTENSION', 'DUCKDB_BACKEND_FILE_EXTENSION' ) );
	$preset_read_sql = null;
	$preset_write_sql = null;

	if ( null === $external_dir && in_array( $backend, array( 'csv', 'json', 'parquet' ), true ) ) {
		$external_dir = $database_dir . 'duckdb-' . $backend_slug . '/';
	}
	if ( null === $working_db ) {
		$working_db = $database_dir . '.ht.duckdb-benchmark-' . $backend_slug . '-working';
	}
	if ( 'pipe_text' === $backend ) {
		$file_extension  = null === $file_extension ? 'psv' : $file_extension;
		$preset_read_sql = "SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')";
		$preset_write_sql = "COPY {table} TO {path} (HEADER, DELIMITER '|')";
	}

	$config .= 'define( \'DUCKDB_BACKEND\', ' . var_export( $backend, true ) . " );\n";
	if ( null !== $external_dir ) {
		$config .= 'define( \'DUCKDB_EXTERNAL_STORAGE_DIR\', ' . var_export( $external_dir, true ) . " );\n";
	}
	$config .= 'define( \'DUCKDB_WORKING_DATABASE_FILE\', ' . var_export( $working_db, true ) . " );\n";
	if ( null !== $file_extension ) {
		$config .= 'define( \'DUCKDB_BACKEND_FILE_EXTENSION\', ' . var_export( $file_extension, true ) . " );\n";
	}

	$read_sql = duckdb_benchmark_env_first( array( 'WP_DUCKDB_BACKEND_READ_SQL', 'DUCKDB_BACKEND_READ_SQL' ) );
	if ( null === $read_sql ) {
		$read_sql = $preset_read_sql;
	}
	if ( null !== $read_sql ) {
		$config .= 'define( \'DUCKDB_BACKEND_READ_SQL\', ' . var_export( $read_sql, true ) . " );\n";
	}

	$write_sql = duckdb_benchmark_env_first( array( 'WP_DUCKDB_BACKEND_WRITE_SQL', 'DUCKDB_BACKEND_WRITE_SQL' ) );
	if ( null === $write_sql ) {
		$write_sql = $preset_write_sql;
	}
	if ( null !== $write_sql ) {
		$config .= 'define( \'DUCKDB_BACKEND_WRITE_SQL\', ' . var_export( $write_sql, true ) . " );\n";
	}

	$setup_sql = duckdb_benchmark_env_json_array( 'WP_DUCKDB_BACKEND_SETUP_SQL_JSON' );
	if ( null !== $setup_sql ) {
		$setup_sql = array_map(
			static function ( string $statement ) use ( $placeholders ): string {
				return duckdb_benchmark_expand( $statement, $placeholders );
			},
			$setup_sql
		);
		$config .= 'define( \'DUCKDB_BACKEND_SETUP_SQL\', ' . var_export( $setup_sql, true ) . " );\n";
	} else {
		$setup_sql = duckdb_benchmark_env_first( array( 'WP_DUCKDB_BACKEND_SETUP_SQL', 'DUCKDB_BACKEND_SETUP_SQL' ) );
		if ( null !== $setup_sql ) {
			$config .= 'define( \'DUCKDB_BACKEND_SETUP_SQL\', ' . var_export( duckdb_benchmark_expand( $setup_sql, $placeholders ), true ) . " );\n";
		}
	}

	$tables = duckdb_benchmark_env_json_array( 'WP_DUCKDB_BACKEND_TABLES_JSON' );
	if ( null === $tables ) {
		$tables = duckdb_benchmark_tables_from_file( $tables_file );
	}
	if ( null !== $tables ) {
		$config .= 'define( \'DUCKDB_BACKEND_TABLES\', ' . var_export( $tables, true ) . " );\n";
	}

	$manifest_file = duckdb_benchmark_expand( duckdb_benchmark_env_first( array( 'WP_DUCKDB_METADATA_MANIFEST_FILE', 'DUCKDB_METADATA_MANIFEST_FILE' ) ), $placeholders );
	if ( null !== $manifest_file ) {
		$config .= 'define( \'DUCKDB_METADATA_MANIFEST_FILE\', ' . var_export( $manifest_file, true ) . " );\n";
	}

	$atomic_flush = duckdb_benchmark_bool_env( array( 'WP_DUCKDB_BACKEND_ATOMIC_FLUSH', 'DUCKDB_BACKEND_ATOMIC_FLUSH' ) );
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

php_install_site() {
	local wp_root=$1
	local tables_file=$2

	WORDPRESS_ROOT="$wp_root" BENCHMARK_TABLES_FILE="$tables_file" php -d ffi.enable=1 <<'PHP'
<?php
define( 'WP_INSTALLING', true );

$root = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

global $wpdb;

function duckdb_benchmark_has_siteurl(): bool {
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

function duckdb_benchmark_write_table_list( string $tables_file ): void {
	if ( '' === $tables_file ) {
		return;
	}

	global $wpdb;
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	if ( ! is_array( $tables ) ) {
		return;
	}

	$tables = array_values(
		array_filter(
			array_unique( $tables ),
			static function ( $table ): bool {
				return is_string( $table ) && 0 !== stripos( $table, '__wp_duckdb_' );
			}
		)
	);
	sort( $tables, SORT_STRING );

	$dir = dirname( $tables_file );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $tables_file, implode( "\n", $tables ) . "\n" );
}

if ( ! duckdb_benchmark_has_siteurl() ) {
	wp_install( 'DuckDB Benchmark', 'admin', 'admin@example.test', true, '', 'password' );
}

update_option( 'siteurl', WP_SITEURL );
update_option( 'home', WP_HOME );
update_option( 'blogdescription', 'Real WordPress benchmark for DuckDB database storage.' );

for ( $i = 1; $i <= 24; $i++ ) {
	$title = sprintf( 'DuckDB Benchmark Post %02d', $i );
	$found = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_type = 'post' LIMIT 1",
			$title
		)
	);
	if ( ! $found ) {
		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => str_repeat( 'DuckDB benchmark content paragraph. ', 20 ),
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			fwrite( STDERR, 'Failed to create benchmark post: ' . $post_id->get_error_message() . "\n" );
			exit( 1 );
		}
		add_post_meta( (int) $post_id, 'duckdb_benchmark_seed', 'seed-' . $i, true );
	}
}

$table = $wpdb->prefix . 'duckdb_benchmark_events';
$created = $wpdb->query(
	"CREATE TABLE IF NOT EXISTS `$table` (
		`event_id` BIGINT NOT NULL AUTO_INCREMENT,
		`scenario` VARCHAR(191) NOT NULL,
		`payload` LONGTEXT NOT NULL,
		`created_at` DATETIME NOT NULL,
		PRIMARY KEY (`event_id`)
	)"
);

if ( false === $created ) {
	fwrite( STDERR, 'Failed to create benchmark events table: ' . $wpdb->last_error . "\n" );
	exit( 1 );
}

duckdb_benchmark_write_table_list( (string) getenv( 'BENCHMARK_TABLES_FILE' ) );

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
}

echo "Installed benchmark WordPress fixture.\n";
PHP
}

php_verify_site() {
	local wp_root=$1
	local backend=$2
	local tables_file=$3

	WORDPRESS_ROOT="$wp_root" BACKEND="$backend" BENCHMARK_TABLES_FILE="$tables_file" php -d ffi.enable=1 <<'PHP'
<?php
$root = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
require_once $root . '/wp-load.php';

global $wpdb;

$post_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish'" );
$event_table = $wpdb->prefix . 'duckdb_benchmark_events';
$event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $event_table" );

if ( $post_count < 24 ) {
	fwrite( STDERR, "Expected at least 24 published benchmark posts, got $post_count.\n" );
	exit( 1 );
}

$tables_file = getenv( 'BENCHMARK_TABLES_FILE' );
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
		file_put_contents( $tables_file, implode( "\n", $tables ) . "\n" );
	}
}

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
}

echo 'backend=' . getenv( 'BACKEND' ) . ' posts=' . $post_count . ' events=' . $event_count . "\n";
PHP
}

php_verify_writes() {
	local wp_root=$1
	local backend=$2
	local expected=$3

	WORDPRESS_ROOT="$wp_root" BACKEND="$backend" EXPECTED_WRITE_EVENTS="$expected" php -d ffi.enable=1 <<'PHP'
<?php
$root = rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' );
require_once $root . '/wp-load.php';

global $wpdb;

$backend  = (string) getenv( 'BACKEND' );
$expected = max( 0, (int) getenv( 'EXPECTED_WRITE_EVENTS' ) );
$table    = $wpdb->prefix . 'duckdb_benchmark_events';
$stored   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
$status   = $stored >= $expected ? 'pass' : 'fail';

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
}

echo json_encode(
	array(
		'type'                => 'verification',
		'backend'             => $backend,
		'expected_min_events' => $expected,
		'stored_events'       => $stored,
		'status'              => $status,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";

exit( 'pass' === $status ? 0 : 1 );
PHP
}

detect_wordpress_version() {
	local wp_root=$1

	WORDPRESS_ROOT="$wp_root" php <<'PHP'
<?php
require rtrim( getenv( 'WORDPRESS_ROOT' ), '/\\' ) . '/wp-includes/version.php';
echo $wp_version;
PHP
}

is_native_duckdb_backend() {
	case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
		duckdb|duck|native|file|duckdb_sidecar)
			return 0
			;;
	esac
	return 1
}

is_duckdb_sidecar_backend() {
	[ "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" = "duckdb_sidecar" ]
}

is_mysql_backend() {
	case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
		mysql|mariadb)
			return 0
			;;
	esac
	return 1
}

is_sqlite_backend() {
	[ "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" = "sqlite" ]
}

is_sqlite_attach_backend() {
	[ "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" = "sqlite_attach" ]
}

is_mysql_attach_backend() {
	[ "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" = "mysql_attach" ]
}

is_s3_parquet_backend() {
	[ "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" = "s3_parquet" ]
}

is_duckdb_powered_backend() {
	! is_mysql_backend "$1" && ! is_sqlite_backend "$1"
}

uses_duckdb_external_config() {
	is_s3_parquet_backend "$1" || is_sqlite_attach_backend "$1" || is_mysql_attach_backend "$1"
}

uses_duckdb_sidecar_config() {
	is_duckdb_sidecar_backend "$1"
}

configured_duckdb_benchmark_transport() {
	local transport=${WP_DUCKDB_BENCHMARK_DUCKDB_TRANSPORT:-}

	if [ -z "$transport" ]; then
		return
	fi

	printf '%s' "$transport" | tr '[:upper:]_' '[:lower:]-'
}

uses_duckdb_benchmark_remote_config() {
	is_duckdb_powered_backend "$1" && [ -n "$(configured_duckdb_benchmark_transport)" ]
}

remove_working_database() {
	local wp_root=$1
	local backend=$2
	local backend_slug=$3
	local database_dir="$wp_root/wp-content/database/"
	local working_database="${WP_DUCKDB_WORKING_DATABASE_FILE:-${DUCKDB_WORKING_DATABASE_FILE:-}}"

	if is_native_duckdb_backend "$backend"; then
		return
	fi

	if [ -n "$working_database" ]; then
		working_database=${working_database//\{root\}/$wp_root}
		working_database=${working_database//\{database_dir\}/$database_dir}
		working_database=${working_database//\{backend\}/$backend}
		working_database=${working_database//\{backend_slug\}/$backend_slug}
	else
		working_database="$database_dir.ht.duckdb-benchmark-$backend_slug-working"
	fi

	rm -f -- "$working_database" "$working_database.wal" "$working_database.lock" "$working_database.tmp"
}

start_server() {
	local wp_root=$1
	local port=$2
	local log=$3

	(
		cd "$wp_root"
		PHP_CLI_SERVER_WORKERS="$server_workers" php -d ffi.enable=1 -d max_execution_time=120 -S "127.0.0.1:$port" >"$log" 2>&1
	) &
	START_SERVER_PID=$!
}

wait_for_http() {
	local url=$1

	for _ in $(seq 1 80); do
		if curl --max-time 5 -fsS "$url/?rest_route=/duckdb-benchmark/v1/read" >/dev/null 2>&1; then
			return
		fi
		sleep 0.25
	done

	echo "Timed out waiting for $url" >&2
	return 1
}

stop_server() {
	local server_pid=${1:-}

	if [ -n "$server_pid" ]; then
		kill "$server_pid" >/dev/null 2>&1 || true
		wait "$server_pid" 2>/dev/null || true
	fi
}

emit_failure() {
	local type=$1
	local backend=$2
	local message=$3

	EVENT_TYPE="$type" BACKEND="$backend" MESSAGE="$message" php <<'PHP' >>"$events_file"
<?php
echo json_encode(
	array(
		'type'    => getenv( 'EVENT_TYPE' ),
		'backend' => getenv( 'BACKEND' ),
		'message' => getenv( 'MESSAGE' ),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
}

run_http_benchmark() {
	local backend=$1
	local label=$2
	local method=$3
	local url=$4
	local requests=$5
	local concurrency=$6
	local stderr_file=$7
	local expect_json=${8:-0}
	local extra_args=()
	local output
	local status

	if [ "$expect_json" = "1" ]; then
		extra_args+=(--expect-json)
	fi

	set +e
	output=$(php -d ffi.enable=1 "$repo_dir/bin/duckdb-http-benchmark.php" \
		--backend="$backend" \
		--label="$label" \
		--method="$method" \
		--url="$url" \
		--requests="$requests" \
		--concurrency="$concurrency" \
		--timeout="$benchmark_timeout" \
		"${extra_args[@]}" 2>>"$stderr_file")
	status=$?
	set -e

	if [ -n "$output" ]; then
		printf '%s\n' "$output" >>"$events_file"
	fi

	if [ "$status" -ne 0 ]; then
		benchmark_failed=1
		if [ -z "$output" ]; then
			emit_failure benchmark_failure "$backend" "$label concurrency=$concurrency exited with status $status"
		fi
		echo "Benchmark reported errors: backend=$backend label=$label concurrency=$concurrency" >&2
	fi
}

check_logs() {
	local backend=$1
	local wp_root=$2
	local server_log=$3
	local debug_log="$wp_root/wp-content/debug.log"
	local pattern='WordPress database error|DuckDB query failed|Failed to execute DuckDB|Fatal error|Parse error'
	local diagnostics_pattern='WP_DUCKDB_(QUERY_PROFILE|RUNTIME_)'

	if [ -f "$server_log" ] && grep -Ei "$pattern" "$server_log" | grep -Eiv "$diagnostics_pattern" >/dev/null; then
		emit_failure backend_failure "$backend" "Server log contains WordPress/DuckDB/PHP errors. See $server_log"
		grep -Ei "$pattern" "$server_log" | grep -Eiv "$diagnostics_pattern" >&2 || true
		benchmark_failed=1
	fi

	if [ -f "$debug_log" ] && grep -Ei "$pattern" "$debug_log" | grep -Eiv "$diagnostics_pattern" >/dev/null; then
		emit_failure backend_failure "$backend" "WordPress debug log contains WordPress/DuckDB/PHP errors. See $debug_log"
		grep -Ei "$pattern" "$debug_log" | grep -Eiv "$diagnostics_pattern" >&2 || true
		benchmark_failed=1
	fi
}

write_meta() {
	local wordpress_actual=$1
	local commit
	local host

	commit=$(git -C "$repo_dir" rev-parse --short=12 HEAD 2>/dev/null || printf 'unknown')
	host=$(uname -a)

	RUN_ID="$run_id" \
	OUTPUT_DIR="$output_dir" \
	WORK_ROOT="$work_root" \
	BACKENDS="${backends[*]}" \
	CONCURRENCY="${concurrency_levels[*]}" \
	READ_REQUESTS="$read_requests" \
	WRITE_REQUESTS="$write_requests" \
	SERVER_WORKERS="$server_workers" \
	WORDPRESS_REQUESTED="$wordpress_version" \
	WORDPRESS_ACTUAL="$wordpress_actual" \
	PHP_VERSION="$(php -r 'echo PHP_VERSION;')" \
	COMMIT="$commit" \
	HOST="$host" \
	LOCK_TIMEOUT="$lock_timeout" \
	DUCKDB_BENCHMARK_TRANSPORT="${WP_DUCKDB_BENCHMARK_DUCKDB_TRANSPORT:-}" \
	php <<'PHP' >"$meta_file"
<?php
echo json_encode(
	array(
		'run_id'                      => getenv( 'RUN_ID' ),
		'created_at'                  => gmdate( 'c' ),
		'output_dir'                  => getenv( 'OUTPUT_DIR' ),
		'work_root'                   => getenv( 'WORK_ROOT' ),
		'backends'                    => preg_split( '/\s+/', trim( (string) getenv( 'BACKENDS' ) ) ),
		'concurrency'                 => array_map( 'intval', preg_split( '/\s+/', trim( (string) getenv( 'CONCURRENCY' ) ) ) ),
		'read_requests_per_scenario'  => (int) getenv( 'READ_REQUESTS' ),
		'write_requests_per_scenario' => (int) getenv( 'WRITE_REQUESTS' ),
		'server_workers'              => (int) getenv( 'SERVER_WORKERS' ),
		'wordpress_requested'         => getenv( 'WORDPRESS_REQUESTED' ),
		'wordpress_version'           => getenv( 'WORDPRESS_ACTUAL' ),
		'php_version'                 => getenv( 'PHP_VERSION' ),
		'commit'                      => getenv( 'COMMIT' ),
		'host'                        => getenv( 'HOST' ),
		'duckdb_lock_timeout_seconds' => (int) getenv( 'LOCK_TIMEOUT' ),
		'duckdb_benchmark_transport'  => getenv( 'DUCKDB_BENCHMARK_TRANSPORT' ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
PHP
}

run_backend() {
	local backend=$1
	local backend_slug
	local wp_root
	local port
	local site_url
	local server_log
	local stderr_log
	local tables_file
	local benchmark_token
	local server_pid=''
	local mysql_pid=''
	local minio_pid=''
	local sidecar_pid=''
	local mysql_port=''
	local duckdb_transport=''
	local duckdb_endpoint=''
	local duckdb_port=''
	local expected_write_events=0
	local verification_output
	local verification_status

	backend_slug=$(printf '%s' "$backend" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')
	wp_root="$work_root/$backend_slug/wordpress"
	port=$(( 8300 + ( RANDOM % 700 ) ))
	site_url="http://127.0.0.1:$port"
	server_log="$output_dir/$backend_slug-server.log"
	stderr_log="$output_dir/$backend_slug-benchmark.stderr"
	tables_file="$output_dir/$backend_slug-tables.txt"
	benchmark_token="duckdb-benchmark-$run_id-$backend_slug"

	echo "==> WordPress database benchmark: backend=$backend"
	mkdir -p "$output_dir"
	trap 'cleanup_backend "${server_pid:-}" "${mysql_pid:-}" "${minio_pid:-}" "${sidecar_pid:-}" "$backend"' EXIT

	if is_mysql_backend "$backend" || is_mysql_attach_backend "$backend"; then
		start_mysql_server "$backend_slug"
		mysql_pid=$MYSQL_SERVER_PID
	fi

	if is_s3_parquet_backend "$backend"; then
		start_minio_server "$backend_slug"
		minio_pid=$MINIO_SERVER_PID
		export WP_DUCKDB_EXTERNAL_STORAGE_DIR="s3://$MINIO_BENCHMARK_BUCKET/wordpress/"
		export WP_DUCKDB_BACKEND_FILE_EXTENSION=parquet
		export WP_DUCKDB_BACKEND_SETUP_SQL_JSON="[\"INSTALL httpfs\",\"LOAD httpfs\",\"CREATE OR REPLACE SECRET wp_s3_benchmark (TYPE s3, PROVIDER config, KEY_ID 'minioadmin', SECRET 'minioadmin', REGION 'us-east-1', ENDPOINT '$MINIO_BENCHMARK_ENDPOINT', URL_STYLE 'path', USE_SSL false, SCOPE 's3://$MINIO_BENCHMARK_BUCKET/wordpress/')\"]"
		export WP_DUCKDB_BACKEND_READ_SQL='SELECT * FROM read_parquet({path})'
		export WP_DUCKDB_BACKEND_WRITE_SQL='COPY {table} TO {path} (FORMAT PARQUET, OVERWRITE_OR_IGNORE true)'
		export WP_DUCKDB_BACKEND_ATOMIC_FLUSH=0
	elif is_sqlite_attach_backend "$backend"; then
		export WP_DUCKDB_BACKEND_SETUP_SQL_JSON='["INSTALL sqlite","LOAD sqlite","ATTACH '\''{database_dir}/wordpress.sqlite'\'' AS wp_store (TYPE sqlite)"]'
		export WP_DUCKDB_BACKEND_READ_SQL='SELECT * FROM wp_store.{table}'
		export WP_DUCKDB_BACKEND_WRITE_SQL='CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}'
		export WP_DUCKDB_BACKEND_ATOMIC_FLUSH=0
	elif is_mysql_attach_backend "$backend"; then
		mysql_port=${MYSQL_BENCHMARK_HOST##*:}
		export WP_DUCKDB_BACKEND_SETUP_SQL_JSON="[\"INSTALL mysql\",\"LOAD mysql\",\"ATTACH 'host=127.0.0.1 port=$mysql_port user=wordpress password=wordpress database=wordpress' AS wp_store (TYPE mysql)\"]"
		export WP_DUCKDB_BACKEND_READ_SQL='SELECT * FROM wp_store.{table}'
		export WP_DUCKDB_BACKEND_WRITE_SQL='CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}'
		export WP_DUCKDB_BACKEND_ATOMIC_FLUSH=0
	elif is_duckdb_sidecar_backend "$backend"; then
		duckdb_transport='unix'
		duckdb_endpoint="$work_root/$backend_slug/duckdb-sidecar.sock"
		export WP_DUCKDB_CONNECTION=unix
		export WP_DUCKDB_REMOTE_SOCKET="$duckdb_endpoint"
	elif uses_duckdb_benchmark_remote_config "$backend"; then
		duckdb_transport=$(configured_duckdb_benchmark_transport)
		if [ "$duckdb_transport" = "tcp-socket" ]; then
			duckdb_transport='tcp'
		elif [ "$duckdb_transport" = "unix-socket" ] || [ "$duckdb_transport" = "socket" ]; then
			duckdb_transport='unix'
		fi
		if [ "$duckdb_transport" = "tcp" ]; then
			duckdb_port=$(free_tcp_port)
			duckdb_endpoint="127.0.0.1:$duckdb_port"
			export WP_DUCKDB_CONNECTION=tcp
			export WP_DUCKDB_REMOTE_HOST=127.0.0.1
			export WP_DUCKDB_REMOTE_PORT="$duckdb_port"
		elif [ "$duckdb_transport" = "http" ]; then
			duckdb_port=$(free_tcp_port)
			duckdb_endpoint="127.0.0.1:$duckdb_port"
			export WP_DUCKDB_CONNECTION=http
			export WP_DUCKDB_REMOTE_HOST=127.0.0.1
			export WP_DUCKDB_REMOTE_PORT="$duckdb_port"
			export WP_DUCKDB_REMOTE_URL="http://$duckdb_endpoint/query"
		elif [ "$duckdb_transport" = "unix" ]; then
			duckdb_endpoint="$work_root/$backend_slug/duckdb-sidecar.sock"
			export WP_DUCKDB_CONNECTION=unix
			export WP_DUCKDB_REMOTE_SOCKET="$duckdb_endpoint"
		else
			echo "Unsupported WP_DUCKDB_BENCHMARK_DUCKDB_TRANSPORT: $duckdb_transport" >&2
			return 2
		fi
	fi

	prepare_wordpress "$wp_root"
	if [ -z "$actual_wordpress_version" ]; then
		actual_wordpress_version=$(detect_wordpress_version "$wp_root")
	fi
	mkdir -p "$wp_root/wp-content/plugins"
	write_benchmark_mu_plugin "$wp_root"
	if is_mysql_backend "$backend"; then
		:
	elif is_sqlite_backend "$backend"; then
		prepare_sqlite_integration_plugin "$wp_root"
	else
		prepare_databases_support_plugin "$wp_root"
	fi
	write_wp_config "$wp_root" "$backend" "$site_url" "$benchmark_token"
	if [ -n "$duckdb_transport" ]; then
		start_duckdb_sidecar_server "$backend_slug" "$wp_root" "$backend" "$duckdb_transport" "$duckdb_endpoint"
		sidecar_pid=$DUCKDB_SIDECAR_PID
	fi
	php_install_site "$wp_root" "$tables_file"
	write_wp_config "$wp_root" "$backend" "$site_url" "$benchmark_token" "$tables_file"
	remove_working_database "$wp_root" "$backend" "$backend_slug"
	php_verify_site "$wp_root" "$backend" "$tables_file"

	start_server "$wp_root" "$port" "$server_log"
	server_pid=$START_SERVER_PID
	wait_for_http "$site_url"

	for concurrency in "${concurrency_levels[@]}"; do
		run_http_benchmark "$backend" front_page GET "$site_url/" "$read_requests" "$concurrency" "$stderr_log"
		run_http_benchmark "$backend" rest_read GET "$site_url/?rest_route=/duckdb-benchmark/v1/read" "$read_requests" "$concurrency" "$stderr_log" 1
		run_http_benchmark "$backend" rest_write POST "$site_url/?rest_route=/duckdb-benchmark/v1/write&token=$benchmark_token" "$write_requests" "$concurrency" "$stderr_log" 1
		expected_write_events=$(( expected_write_events + write_requests ))
	done

	stop_server "$server_pid"
	server_pid=''

	set +e
	verification_output=$(php_verify_writes "$wp_root" "$backend" "$expected_write_events")
	verification_status=$?
	set -e
	if [ -n "$verification_output" ]; then
		printf '%s\n' "$verification_output" >>"$events_file"
	fi
	if [ "$verification_status" -ne 0 ]; then
		benchmark_failed=1
	fi

	check_logs "$backend" "$wp_root" "$server_log"
	cleanup_backend "$server_pid" "$mysql_pid" "$minio_pid" "$sidecar_pid" "$backend"
	server_pid=''
	mysql_pid=''
	minio_pid=''
	sidecar_pid=''
	trap - EXIT
	echo "PASS benchmark backend=$backend root=$wp_root"
}

require_command curl
require_command tar
require_command unzip
require_command rsync
require_command composer
require_command php

mkdir -p "$output_dir" "$work_root" "$cache_dir"
: >"$events_file"

for backend in "${backends[@]}"; do
	run_backend "$backend"
done

write_meta "$actual_wordpress_version"
php "$repo_dir/bin/duckdb-benchmark-report.php" "$output_dir" "$repo_dir/docs"

echo "WordPress database benchmark run: $output_dir"
echo "WordPress database benchmark report: $repo_dir/docs/index.html"

if [ "$benchmark_failed" -ne 0 ]; then
	exit 1
fi
