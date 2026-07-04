#!/usr/bin/env php
<?php declare(strict_types = 1);

/**
 * Tiny DuckDB sidecar for DuckDB remote connection experiments.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "DuckDB sidecar must run under PHP CLI.\n" );
	exit( 2 );
}

$repo_dir = dirname( __DIR__ );

if ( is_file( $repo_dir . '/vendor/autoload.php' ) ) {
	require_once $repo_dir . '/vendor/autoload.php';
}
require_once $repo_dir . '/wp-includes/database/load.php';

$options       = getopt( '', array( 'socket:', 'tcp::', 'http::', 'host:', 'port:', 'stdio', 'path:', 'cache::' ) );
$mode          = duckdb_sidecar_mode( $options );
$path          = isset( $options['path'] ) ? (string) $options['path'] : '';
$cache_enabled = duckdb_sidecar_cache_enabled( $options['cache'] ?? null );

if ( '' === $mode || '' === $path ) {
	duckdb_sidecar_print_usage();
	exit( 2 );
}

duckdb_sidecar_prepare_database_dir( $path );
set_time_limit( 0 );

$connection  = new WP_DuckDB_Connection( array( 'path' => $path ) );
$query_cache = array(
	'data'     => array(),
	'metadata' => array(),
);

if ( 'stdio' === $mode ) {
	fwrite( STDERR, 'DuckDB sidecar listening on stdio for ' . $path . ' cache=' . ( $cache_enabled ? 'on' : 'off' ) . "\n" );
	duckdb_sidecar_handle_line_stream( STDIN, STDOUT, $connection, $cache_enabled, $query_cache );
	exit( 0 );
}

if ( 'unix' === $mode ) {
	$socket = (string) $options['socket'];
	duckdb_sidecar_prepare_socket_dir( $socket );
	@unlink( $socket );

	$server = @stream_socket_server( 'unix://' . $socket, $errno, $errstr );
	if ( false === $server ) {
		fwrite( STDERR, "Failed to listen on DuckDB sidecar socket $socket: $errstr\n" );
		exit( 1 );
	}
	chmod( $socket, 0600 );
	register_shutdown_function(
		static function () use ( $socket ): void {
			@unlink( $socket );
		}
	);
	fwrite( STDERR, 'DuckDB sidecar listening on unix://' . $socket . ' for ' . $path . ' cache=' . ( $cache_enabled ? 'on' : 'off' ) . "\n" );
	duckdb_sidecar_run_line_server( $server, $connection, $cache_enabled, $query_cache );
	exit( 0 );
}

$listen = duckdb_sidecar_tcp_endpoint( $options, 'tcp' === $mode ? 'tcp' : 'http' );
$server = @stream_socket_server( 'tcp://' . $listen['host'] . ':' . $listen['port'], $errno, $errstr );
if ( false === $server ) {
	fwrite( STDERR, 'Failed to listen on DuckDB sidecar ' . $mode . ' endpoint ' . $listen['host'] . ':' . $listen['port'] . ": $errstr\n" );
	exit( 1 );
}
fwrite( STDERR, 'DuckDB sidecar listening on ' . $mode . '://' . $listen['host'] . ':' . $listen['port'] . ' for ' . $path . ' cache=' . ( $cache_enabled ? 'on' : 'off' ) . "\n" );

if ( 'tcp' === $mode ) {
	duckdb_sidecar_run_line_server( $server, $connection, $cache_enabled, $query_cache );
	exit( 0 );
}

duckdb_sidecar_run_http_server( $server, $connection, $cache_enabled, $query_cache );

/**
 * Select the requested sidecar mode.
 *
 * @param array<string,mixed> $options CLI options.
 * @return string Mode name.
 */
function duckdb_sidecar_mode( array $options ): string {
	if ( array_key_exists( 'stdio', $options ) ) {
		return 'stdio';
	}
	if ( isset( $options['http'] ) || array_key_exists( 'http', $options ) ) {
		return 'http';
	}
	if ( isset( $options['tcp'] ) || array_key_exists( 'tcp', $options ) ) {
		return 'tcp';
	}
	if ( isset( $options['socket'] ) && '' !== trim( (string) $options['socket'] ) ) {
		return 'unix';
	}

	return '';
}

/**
 * Print CLI usage.
 *
 * @return void
 */
function duckdb_sidecar_print_usage(): void {
	fwrite(
		STDERR,
		"Usage:\n"
		. "  php -d ffi.enable=1 bin/duckdb-sidecar.php --socket=/path/to.sock --path=/path/to.duckdb\n"
		. "  php -d ffi.enable=1 bin/duckdb-sidecar.php --tcp=127.0.0.1:9901 --path=/path/to.duckdb\n"
		. "  php -d ffi.enable=1 bin/duckdb-sidecar.php --http=127.0.0.1:9902 --path=/path/to.duckdb\n"
		. "  php -d ffi.enable=1 bin/duckdb-sidecar.php --stdio --path=/path/to.duckdb\n"
	);
}

/**
 * Ensure the DuckDB database directory exists.
 *
 * @param string $path Database path.
 * @return void
 */
function duckdb_sidecar_prepare_database_dir( string $path ): void {
	if ( ':memory:' === $path ) {
		return;
	}

	$database_dir = dirname( $path );
	if ( ! is_dir( $database_dir ) && ! mkdir( $database_dir, 0777, true ) && ! is_dir( $database_dir ) ) {
		fwrite( STDERR, "Failed to create DuckDB database directory: $database_dir\n" );
		exit( 1 );
	}
}

/**
 * Ensure the Unix socket directory exists.
 *
 * @param string $socket Socket path.
 * @return void
 */
function duckdb_sidecar_prepare_socket_dir( string $socket ): void {
	$socket_dir = dirname( $socket );
	if ( ! is_dir( $socket_dir ) && ! mkdir( $socket_dir, 0777, true ) && ! is_dir( $socket_dir ) ) {
		fwrite( STDERR, "Failed to create sidecar socket directory: $socket_dir\n" );
		exit( 1 );
	}
}

/**
 * Resolve a TCP or HTTP listen endpoint.
 *
 * @param array<string,mixed> $options CLI options.
 * @param string              $mode    tcp or http.
 * @return array{host:string,port:int}
 */
function duckdb_sidecar_tcp_endpoint( array $options, string $mode ): array {
	$host = isset( $options['host'] ) && '' !== trim( (string) $options['host'] )
		? trim( (string) $options['host'] )
		: '127.0.0.1';
	$port = isset( $options['port'] ) && '' !== trim( (string) $options['port'] )
		? (int) $options['port']
		: ( 'tcp' === $mode ? 9901 : 9902 );

	$value = $options[ $mode ] ?? null;
	if ( is_string( $value ) && '' !== trim( $value ) ) {
		$value = trim( $value );
		if ( preg_match( '/^\d+$/', $value ) ) {
			$port = (int) $value;
		} elseif ( preg_match( '/^([^:]+):(\d+)$/', $value, $matches ) ) {
			$host = $matches[1];
			$port = (int) $matches[2];
		} else {
			$host = $value;
		}
	}

	if ( $port <= 0 || $port > 65535 ) {
		fwrite( STDERR, "Invalid DuckDB sidecar $mode port: $port\n" );
		exit( 2 );
	}

	return array(
		'host' => $host,
		'port' => $port,
	);
}

/**
 * Accept line-protocol clients forever.
 *
 * @param resource             $server        Server socket.
 * @param WP_DuckDB_Connection $connection    Native DuckDB connection.
 * @param bool                 $cache_enabled Whether exact SELECT response caching is enabled.
 * @param array<string,array>  $query_cache   Exact SELECT response caches.
 * @return void
 */
function duckdb_sidecar_run_line_server( $server, WP_DuckDB_Connection $connection, bool $cache_enabled, array &$query_cache ): void {
	while ( true ) {
		$client = @stream_socket_accept( $server, -1 );
		if ( false === $client ) {
			continue;
		}

		stream_set_timeout( $client, 120 );
		duckdb_sidecar_handle_line_stream( $client, $client, $connection, $cache_enabled, $query_cache );
		fclose( $client );
	}
}

/**
 * Handle line-delimited JSON requests on an open stream pair.
 *
 * @param resource             $read_stream    Read stream.
 * @param resource             $write_stream   Write stream.
 * @param WP_DuckDB_Connection $connection     Native DuckDB connection.
 * @param bool                 $cache_enabled  Whether exact SELECT response caching is enabled.
 * @param array<string,array>  $query_cache    Exact SELECT response caches.
 * @return void
 */
function duckdb_sidecar_handle_line_stream( $read_stream, $write_stream, WP_DuckDB_Connection $connection, bool $cache_enabled, array &$query_cache ): void {
	while ( ! feof( $read_stream ) ) {
		$line = fgets( $read_stream );
		if ( false === $line ) {
			break;
		}
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		$response = duckdb_sidecar_handle_request( $connection, $line, $cache_enabled, $query_cache );
		fwrite( $write_stream, json_encode( $response, JSON_UNESCAPED_SLASHES ) . "\n" );
		fflush( $write_stream );
	}
}

/**
 * Accept HTTP clients forever.
 *
 * @param resource             $server        Server socket.
 * @param WP_DuckDB_Connection $connection    Native DuckDB connection.
 * @param bool                 $cache_enabled Whether exact SELECT response caching is enabled.
 * @param array<string,array>  $query_cache   Exact SELECT response caches.
 * @return void
 */
function duckdb_sidecar_run_http_server( $server, WP_DuckDB_Connection $connection, bool $cache_enabled, array &$query_cache ): void {
	while ( true ) {
		$client = @stream_socket_accept( $server, -1 );
		if ( false === $client ) {
			continue;
		}

		stream_set_timeout( $client, 120 );
		duckdb_sidecar_handle_http_client( $client, $connection, $cache_enabled, $query_cache );
		fclose( $client );
	}
}

/**
 * Handle one HTTP request.
 *
 * @param resource             $client        Client stream.
 * @param WP_DuckDB_Connection $connection    Native DuckDB connection.
 * @param bool                 $cache_enabled Whether exact SELECT response caching is enabled.
 * @param array<string,array>  $query_cache   Exact SELECT response caches.
 * @return void
 */
function duckdb_sidecar_handle_http_client( $client, WP_DuckDB_Connection $connection, bool $cache_enabled, array &$query_cache ): void {
	$request = duckdb_sidecar_read_http_request( $client );
	if ( null === $request ) {
		return;
	}

	if ( 'POST' !== $request['method'] ) {
		duckdb_sidecar_write_http_response(
			$client,
			405,
			array(
				'ok'      => false,
				'message' => 'DuckDB sidecar HTTP endpoint only accepts POST.',
			)
		);
		return;
	}

	$response = duckdb_sidecar_handle_request( $connection, $request['body'], $cache_enabled, $query_cache );
	duckdb_sidecar_write_http_response( $client, empty( $response['ok'] ) ? 500 : 200, $response );
}

/**
 * Read one minimal HTTP request.
 *
 * @param resource $client Client stream.
 * @return array{method:string,path:string,body:string}|null Request, or null when the client closed early.
 */
function duckdb_sidecar_read_http_request( $client ): ?array {
	$request_line = fgets( $client );
	if ( false === $request_line ) {
		return null;
	}

	$parts = preg_split( '/\s+/', trim( $request_line ) );
	if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
		return array(
			'method' => '',
			'path'   => '',
			'body'   => '',
		);
	}

	$headers = array();
	while ( ! feof( $client ) ) {
		$line = fgets( $client );
		if ( false === $line ) {
			break;
		}
		$line = rtrim( $line, "\r\n" );
		if ( '' === $line ) {
			break;
		}
		$separator = strpos( $line, ':' );
		if ( false === $separator ) {
			continue;
		}
		$name             = strtolower( trim( substr( $line, 0, $separator ) ) );
		$headers[ $name ] = trim( substr( $line, $separator + 1 ) );
	}

	$content_length = isset( $headers['content-length'] ) ? (int) $headers['content-length'] : 0;
	$body           = '';
	while ( strlen( $body ) < $content_length && ! feof( $client ) ) {
		$chunk = fread( $client, $content_length - strlen( $body ) );
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		$body .= $chunk;
	}

	return array(
		'method' => strtoupper( $parts[0] ),
		'path'   => $parts[1],
		'body'   => $body,
	);
}

/**
 * Write one JSON HTTP response.
 *
 * @param resource            $client   Client stream.
 * @param int                 $status   HTTP status.
 * @param array<string,mixed> $response Response payload.
 * @return void
 */
function duckdb_sidecar_write_http_response( $client, int $status, array $response ): void {
	$body   = json_encode( $response, JSON_UNESCAPED_SLASHES );
	$body   = is_string( $body ) ? $body : '{"ok":false,"message":"Failed to encode DuckDB sidecar response."}';
	$reason = 200 === $status ? 'OK' : ( 405 === $status ? 'Method Not Allowed' : 'Internal Server Error' );
	fwrite(
		$client,
		'HTTP/1.1 ' . $status . ' ' . $reason . "\r\n"
		. "Content-Type: application/json\r\n"
		. 'Content-Length: ' . strlen( $body ) . "\r\n"
		. "Connection: close\r\n"
		. "\r\n"
		. $body
	);
	fflush( $client );
}

/**
 * Handle one JSON sidecar request.
 *
 * @param WP_DuckDB_Connection $connection    Native DuckDB connection.
 * @param string               $line          JSON request line.
 * @param bool                 $cache_enabled Whether exact SELECT response caching is enabled.
 * @param array<string,array>  $query_cache   Exact SELECT response caches.
 * @return array<string,mixed> Response payload.
 */
function duckdb_sidecar_handle_request( WP_DuckDB_Connection $connection, string $line, bool $cache_enabled, array &$query_cache ): array {
	$request = json_decode( $line, true );
	if ( ! is_array( $request ) ) {
		return array(
			'ok'      => false,
			'message' => 'DuckDB sidecar request was not valid JSON.',
		);
	}

	$sql = $request['sql'] ?? null;
	if ( ! is_string( $sql ) ) {
		return array(
			'ok'      => false,
			'message' => 'DuckDB sidecar request is missing SQL.',
		);
	}

	$params = isset( $request['params'] ) && is_array( $request['params'] ) ? $request['params'] : array();
	$cache_key = null;
	$cache_bucket = null;
	if ( $cache_enabled && duckdb_sidecar_is_cacheable_select( $sql ) ) {
		$cache_bucket = duckdb_sidecar_query_cache_bucket( $sql );
		$cache_key    = duckdb_sidecar_query_cache_key( $sql, $params );
		if ( array_key_exists( $cache_key, $query_cache[ $cache_bucket ] ) ) {
			$response              = $query_cache[ $cache_bucket ][ $cache_key ];
			$response['cache_hit'] = true;
			return $response;
		}
	} elseif ( $cache_enabled && duckdb_sidecar_invalidates_query_cache( $sql ) ) {
		duckdb_sidecar_invalidate_query_cache( $query_cache, $sql );
	}

	try {
		$stmt        = $connection->query( $sql, $params );
		$columns     = array();
		$column_meta = array();
		for ( $i = 0; $i < $stmt->columnCount(); ++$i ) {
			$meta = $stmt->getColumnMeta( $i );
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}
			$columns[]      = isset( $meta['name'] ) ? (string) $meta['name'] : 'column_' . $i;
			$column_meta[]  = $meta;
		}

		$response = array(
			'ok'            => true,
			'columns'       => $columns,
			'rows'          => $stmt->fetchAll( PDO::FETCH_NUM ),
			'affected_rows' => $stmt->rowCount(),
			'column_meta'   => $column_meta,
		);

		if ( null !== $cache_key && null !== $cache_bucket && duckdb_sidecar_response_is_cacheable( $response ) ) {
			duckdb_sidecar_store_query_cache_entry( $query_cache[ $cache_bucket ], $cache_key, $response );
		}

		return $response;
	} catch ( Throwable $e ) {
		return array(
			'ok'      => false,
			'message' => get_class( $e ) . ': ' . $e->getMessage(),
		);
	}
}

/**
 * Pick the cache bucket for a SELECT.
 *
 * @param string $sql DuckDB SQL.
 * @return string Cache bucket.
 */
function duckdb_sidecar_query_cache_bucket( string $sql ): string {
	return duckdb_sidecar_is_metadata_select( $sql ) ? 'metadata' : 'data';
}

/**
 * Determine whether the sidecar query cache should be enabled.
 *
 * @param mixed $option CLI --cache option value.
 * @return bool Whether to cache exact SELECT responses.
 */
function duckdb_sidecar_cache_enabled( $option ): bool {
	if ( null !== $option && false !== $option ) {
		return duckdb_sidecar_truthy( $option );
	}

	$env = getenv( 'WP_DUCKDB_SIDECAR_QUERY_CACHE' );
	if ( is_string( $env ) && '' !== $env ) {
		return duckdb_sidecar_truthy( $env );
	}

	return true;
}

/**
 * Interpret a CLI/env toggle.
 *
 * @param mixed $value Toggle value.
 * @return bool Whether the value is truthy.
 */
function duckdb_sidecar_truthy( $value ): bool {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) ) {
		return 0 !== $value;
	}
	if ( ! is_string( $value ) ) {
		return ! empty( $value );
	}

	return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
}

/**
 * Check whether a SQL statement can be cached by exact text.
 *
 * @param string $sql DuckDB SQL.
 * @return bool Whether the statement is a conservative SELECT.
 */
function duckdb_sidecar_is_cacheable_select( string $sql ): bool {
	$trimmed = ltrim( $sql );
	if ( ! preg_match( '/^SELECT\b/i', $trimmed ) ) {
		return false;
	}

	return ! preg_match(
		'/\b(nextval|currval|random|uuid|now|current_timestamp|current_time|localtimestamp|localtime)\s*\(/i',
		$trimmed
	);
}

/**
 * Check whether a SQL statement invalidates cached SELECT results.
 *
 * @param string $sql DuckDB SQL.
 * @return bool Whether the cache should be cleared before execution.
 */
function duckdb_sidecar_invalidates_query_cache( string $sql ): bool {
	return ! preg_match( '/^\s*(SELECT|PRAGMA\s+show_tables_expanded)\b/i', $sql );
}

/**
 * Clear data cache for writes and metadata cache for DDL/metadata writes.
 *
 * @param array<string,array> $query_cache Exact SELECT response caches.
 * @param string              $sql         DuckDB SQL.
 * @return void
 */
function duckdb_sidecar_invalidate_query_cache( array &$query_cache, string $sql ): void {
	$query_cache['data'] = array();
	if ( duckdb_sidecar_invalidates_metadata_cache( $sql ) ) {
		$query_cache['metadata'] = array();
	}
}

/**
 * Check whether a SELECT reads schema metadata.
 *
 * @param string $sql DuckDB SQL.
 * @return bool Whether this is a metadata lookup.
 */
function duckdb_sidecar_is_metadata_select( string $sql ): bool {
	return (bool) preg_match(
		'/\b(information_schema\.|__wp_duckdb_(?:temp_)?(?:index|column|table|check|foreign_key)_metadata)\b/i',
		$sql
	);
}

/**
 * Check whether a mutating statement changes schema metadata.
 *
 * @param string $sql DuckDB SQL.
 * @return bool Whether metadata SELECT cache should be cleared.
 */
function duckdb_sidecar_invalidates_metadata_cache( string $sql ): bool {
	if ( preg_match( '/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql ) ) {
		return true;
	}

	return (bool) preg_match(
		'/\b__wp_duckdb_(?:temp_)?(?:index|column|table|check|foreign_key)_metadata\b/i',
		$sql
	);
}

/**
 * Build a stable exact-query cache key.
 *
 * @param string $sql    DuckDB SQL.
 * @param array  $params Query parameters.
 * @return string Cache key.
 */
function duckdb_sidecar_query_cache_key( string $sql, array $params ): string {
	return hash( 'sha256', json_encode( array( $sql, array_values( $params ) ), JSON_UNESCAPED_SLASHES ) );
}

/**
 * Keep very large result sets out of the in-memory sidecar cache.
 *
 * @param array<string,mixed> $response Query response.
 * @return bool Whether the response is cacheable.
 */
function duckdb_sidecar_response_is_cacheable( array $response ): bool {
	$encoded = json_encode( $response, JSON_UNESCAPED_SLASHES );
	return is_string( $encoded ) && strlen( $encoded ) <= 262144;
}

/**
 * Store one bounded cache entry.
 *
 * @param array<string,array> $query_cache Exact SELECT response cache.
 * @param string              $cache_key   Cache key.
 * @param array<string,mixed> $response    Query response.
 * @return void
 */
function duckdb_sidecar_store_query_cache_entry( array &$query_cache, string $cache_key, array $response ): void {
	$query_cache[ $cache_key ] = $response;
	if ( count( $query_cache ) <= 1024 ) {
		return;
	}

	reset( $query_cache );
	$oldest_key = key( $query_cache );
	if ( is_string( $oldest_key ) || is_int( $oldest_key ) ) {
		unset( $query_cache[ $oldest_key ] );
	}
}
