<?php
/**
 * DuckDB integration file.
 *
 * @package wordpress-databases-support
 */

require_once __DIR__ . '/../../constants.php';

if ( ! defined( 'DB_ENGINE' ) || 'duckdb' !== wp_databases_support_normalize_db_engine( DB_ENGINE ) ) {
	return;
}

$duckdb_uses_remote_connection = wp_duckdb_uses_remote_connection();

if ( ! $duckdb_uses_remote_connection && defined( 'DUCKDB_PHP_AUTOLOAD' ) && file_exists( DUCKDB_PHP_AUTOLOAD ) ) {
	require_once DUCKDB_PHP_AUTOLOAD;
}

require_once __DIR__ . '/../database/load.php';

if ( ! $duckdb_uses_remote_connection ) {
	$duckdb_unavailable_reason = WP_DuckDB_Runtime::get_unavailable_reason();
	if ( null !== $duckdb_unavailable_reason ) {
		wp_die(
			new WP_Error(
				'duckdb_runtime_unavailable',
				sprintf(
					'<h1>%1$s</h1><p>%2$s</p>',
					'DuckDB runtime is unavailable',
					$duckdb_unavailable_reason
				)
			),
			'DuckDB runtime is unavailable.'
		);
	}
}

require_once __DIR__ . '/class-wp-duckdb-db.php';

$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';

$GLOBALS['wpdb'] = new WP_DuckDB_DB( $db_name );

/**
 * Check whether WordPress should connect to DuckDB through a remote sidecar.
 *
 * Remote transports avoid loading the DuckDB FFI runtime inside the WordPress
 * request process. External DuckDB table backends can use the same remote
 * handle for hydrate/flush work when the storage backend is configured with a
 * remote transport.
 *
 * @return bool Whether a remote DuckDB connection is configured.
 */
function wp_duckdb_uses_remote_connection(): bool {
	$connection = wp_duckdb_config_value(
		array( 'WP_DUCKDB_CONNECTION', 'DUCKDB_CONNECTION', 'WP_DUCKDB_REMOTE_TRANSPORT', 'DUCKDB_REMOTE_TRANSPORT' )
	);
	if ( null !== $connection ) {
		$connection = strtolower( str_replace( '_', '-', trim( (string) $connection ) ) );
		if ( in_array( $connection, array( 'embedded', 'embedded-ffi', 'ffi', 'direct', 'native' ), true ) ) {
			return false;
		}

		return in_array( $connection, array( 'unix', 'unix-socket', 'socket', 'tcp', 'tcp-socket', 'http', 'sidecar', 'stdio', 'managed-sidecar' ), true );
	}

	return null !== wp_duckdb_config_value(
		array(
			'WP_DUCKDB_REMOTE_SOCKET',
			'DUCKDB_REMOTE_SOCKET',
			'WP_DUCKDB_REMOTE_URL',
			'DUCKDB_REMOTE_URL',
			'WP_DUCKDB_REMOTE_HOST',
			'DUCKDB_REMOTE_HOST',
			'WP_DUCKDB_REMOTE_PORT',
			'DUCKDB_REMOTE_PORT',
			'WP_DUCKDB_SIDECAR_COMMAND',
			'DUCKDB_SIDECAR_COMMAND',
		)
	);
}

/**
 * Check whether DuckDB is using native file storage.
 *
 * @return bool Whether native file storage is selected.
 */
function wp_duckdb_uses_native_file_backend(): bool {
	$backend = wp_duckdb_config_value( array( 'DUCKDB_BACKEND', 'WP_DUCKDB_BACKEND' ) );
	if ( null === $backend ) {
		return true;
	}

	return in_array( strtolower( trim( (string) $backend ) ), array( '', 'duckdb', 'duck', 'native', 'file' ), true );
}

/**
 * Read a DuckDB setting from constants or environment variables.
 *
 * @param string[] $names Constant/env names.
 * @return mixed|null Configured value.
 */
function wp_duckdb_config_value( array $names ) {
	foreach ( $names as $name ) {
		if ( defined( $name ) ) {
			$value = constant( $name );
			if ( ! wp_duckdb_empty_config_value( $value ) ) {
				return $value;
			}
		}
	}
	foreach ( $names as $name ) {
		$value = getenv( $name );
		if ( ! wp_duckdb_empty_config_value( $value ) ) {
			return $value;
		}
	}

	return null;
}

/**
 * Check whether a configured value is empty.
 *
 * @param mixed $value Configured value.
 * @return bool Whether the value is empty.
 */
function wp_duckdb_empty_config_value( $value ): bool {
	return false === $value || null === $value || ( is_string( $value ) && '' === trim( $value ) );
}
