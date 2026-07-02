<?php
/**
 * DuckDB JSON backend diagnostics for the Docker example.
 *
 * @package wordpress-databases-support
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wordpress_root = getenv( 'WORDPRESS_ROOT' ) ?: '/var/www/html';
$wordpress_root = rtrim( $wordpress_root, '/\\' );
$plugin_root    = $wordpress_root . '/wp-content/plugins/wordpress-databases-support';
$database_dir   = $wordpress_root . '/wp-content/database';
$json_dir       = $database_dir . '/duckdb-json';
$working_db     = $database_dir . '/.ht.duckdb-working';
$lock_file      = $working_db . '.lock';
$autoload       = $plugin_root . '/vendor/autoload.php';
$loader         = $plugin_root . '/wp-includes/database/load.php';
$exit_code      = 0;

function duckdb_json_diag_bool( bool $value ): string {
	return $value ? 'yes' : 'no';
}

function duckdb_json_diag_user(): string {
	if ( function_exists( 'posix_geteuid' ) ) {
		$uid  = posix_geteuid();
		$user = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;
		return $uid . ( is_array( $user ) && isset( $user['name'] ) ? ' (' . $user['name'] . ')' : '' );
	}

	return (string) getmyuid();
}

function duckdb_json_diag_owner( string $path ): string {
	$owner = @fileowner( $path );
	$group = @filegroup( $path );

	if ( false === $owner || false === $group ) {
		return 'unknown';
	}

	$owner_name = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $owner ) : false;
	$group_name = function_exists( 'posix_getgrgid' ) ? posix_getgrgid( $group ) : false;

	return sprintf(
		'%d%s:%d%s',
		$owner,
		is_array( $owner_name ) && isset( $owner_name['name'] ) ? ' (' . $owner_name['name'] . ')' : '',
		$group,
		is_array( $group_name ) && isset( $group_name['name'] ) ? ' (' . $group_name['name'] . ')' : ''
	);
}

function duckdb_json_diag_path( string $label, string $path ): void {
	echo '- ' . $label . ': ' . $path . "\n";

	if ( ! file_exists( $path ) ) {
		$parent = dirname( $path );
		echo '  exists=no';
		echo ' parent_exists=' . duckdb_json_diag_bool( file_exists( $parent ) );
		echo ' parent_writable=' . duckdb_json_diag_bool( is_writable( $parent ) );
		echo "\n";
		return;
	}

	$perms = @fileperms( $path );
	echo '  exists=yes';
	echo ' type=' . ( is_dir( $path ) ? 'dir' : ( is_file( $path ) ? 'file' : 'other' ) );
	echo ' perms=' . ( false === $perms ? 'unknown' : substr( sprintf( '%o', $perms ), -4 ) );
	echo ' owner=' . duckdb_json_diag_owner( $path );
	echo ' readable=' . duckdb_json_diag_bool( is_readable( $path ) );
	echo ' writable=' . duckdb_json_diag_bool( is_writable( $path ) );
	if ( is_file( $path ) ) {
		echo ' size=' . filesize( $path );
	}
	echo "\n";
}

function duckdb_json_diag_json_files( string $json_dir ): void {
	echo "[json files]\n";
	if ( ! is_dir( $json_dir ) ) {
		echo "directory_missing=yes\n";
		return;
	}

	$files = glob( $json_dir . '/*.json' );
	if ( false === $files ) {
		echo "glob_failed=yes\n";
		return;
	}

	sort( $files );
	echo 'count=' . count( $files ) . "\n";
	foreach ( array_slice( $files, 0, 20 ) as $file ) {
		echo '- ' . basename( $file ) . ' size=' . filesize( $file ) . ' writable=' . duckdb_json_diag_bool( is_writable( $file ) ) . "\n";
	}
	if ( count( $files ) > 20 ) {
		echo '- ... ' . ( count( $files ) - 20 ) . " more\n";
	}
}

echo "[runtime]\n";
echo 'php=' . PHP_VERSION . ' sapi=' . PHP_SAPI . ' uid=' . duckdb_json_diag_user() . "\n";
echo 'ffi_extension=' . duckdb_json_diag_bool( extension_loaded( 'ffi' ) ) . ' ffi.enable=' . ini_get( 'ffi.enable' ) . "\n";

echo "[paths]\n";
duckdb_json_diag_path( 'wordpress_root', $wordpress_root );
duckdb_json_diag_path( 'plugin_root', $plugin_root );
duckdb_json_diag_path( 'autoload', $autoload );
duckdb_json_diag_path( 'database_dir', $database_dir );
duckdb_json_diag_path( 'json_dir', $json_dir );
duckdb_json_diag_path( 'working_db', $working_db );
duckdb_json_diag_path( 'lock_file', $lock_file );
duckdb_json_diag_json_files( $json_dir );

echo "[duckdb]\n";
if ( ! file_exists( $autoload ) ) {
	echo 'autoload_error=missing vendor autoload file' . "\n";
	exit( 1 );
}
if ( ! file_exists( $loader ) ) {
	echo 'loader_error=missing database loader' . "\n";
	exit( 1 );
}

require_once $autoload;
require_once $loader;

$runtime_reason = WP_DuckDB_Runtime::get_unavailable_reason( true );
if ( null !== $runtime_reason ) {
	echo 'runtime_available=no' . "\n";
	echo 'runtime_reason=' . $runtime_reason . "\n";
	exit( 1 );
}
echo 'runtime_available=yes' . "\n";

try {
	$backend = new WP_DuckDB_Storage_Backend(
		array(
			'backend'              => 'json',
			'database_path'        => $working_db,
			'external_storage_dir' => $json_dir,
		)
	);
	echo 'backend=' . $backend->get_backend() . "\n";
	echo 'database_path=' . $backend->get_database_path() . "\n";
	echo 'external_storage_dir=' . $backend->get_external_storage_dir() . "\n";

	$driver = $backend->create_driver( 'wordpress' );
	echo 'connect=yes' . "\n";

	$table_rows = $driver->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_NUM );
	$tables     = array();
	foreach ( $table_rows as $row ) {
		if ( isset( $row[0] ) ) {
			$tables[] = (string) $row[0];
		}
	}
	sort( $tables );
	echo 'table_count=' . count( $tables ) . "\n";
	echo 'tables=' . implode( ',', array_slice( $tables, 0, 30 ) ) . "\n";

	$options_count = $driver->query( 'SELECT COUNT(*) FROM wp_options' )->fetchColumn();
	echo 'wp_options_count=' . $options_count . "\n";

	$siteurl = $driver->query( "SELECT option_value FROM wp_options WHERE option_name = 'siteurl' LIMIT 1" )->fetchColumn();
	echo 'siteurl=' . ( false === $siteurl ? 'missing' : $siteurl ) . "\n";

	$post_count = $driver->query( 'SELECT COUNT(*) FROM wp_posts' )->fetchColumn();
	echo 'wp_posts_count=' . $post_count . "\n";

	$backend->flush();
	echo 'flush=yes' . "\n";
} catch ( Throwable $e ) {
	$exit_code = 1;
	echo 'connect_or_query_error=' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	echo 'trace=' . str_replace( "\n", ' | ', $e->getTraceAsString() ) . "\n";
}

exit( $exit_code );
