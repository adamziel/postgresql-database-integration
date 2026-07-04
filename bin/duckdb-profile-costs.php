#!/usr/bin/env php
<?php declare(strict_types = 1);

/**
 * Isolate local DuckDB connection/query costs for the WordPress DuckDB driver.
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "DuckDB cost profiler must run under PHP CLI.\n" );
	exit( 2 );
}

$repo_dir = dirname( __DIR__ );
$options  = getopt( '', array( 'iterations::', 'output::', 'path::' ) );

$iterations = max( 5, (int) ( $options['iterations'] ?? 50 ) );
$run_id     = 'wp-duckdb-cost-profile-' . gmdate( 'Ymd\THis\Z' );
$output_dir = isset( $options['output'] ) ? (string) $options['output'] : $repo_dir . '/docs/benchmarks/runs/' . $run_id;
$db_path    = isset( $options['path'] ) ? (string) $options['path'] : $output_dir . '/profile.duckdb';
$socket     = $output_dir . '/duckdb-sidecar.sock';
$sidecar_log = $output_dir . '/duckdb-sidecar.log';

if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0777, true ) && ! is_dir( $output_dir ) ) {
	fwrite( STDERR, "Failed to create output directory: $output_dir\n" );
	exit( 1 );
}

if ( is_file( $db_path ) ) {
	unlink( $db_path );
}
@unlink( $socket );

$events = array();

$measure = static function ( callable $callback ): float {
	$started = hrtime( true );
	$callback();
	return round( ( hrtime( true ) - $started ) / 1000000, 3 );
};

$record = static function ( string $name, float $elapsed_ms ) use ( &$events ): void {
	$events[] = array(
		'name'       => $name,
		'elapsed_ms' => $elapsed_ms,
	);
};

$summarize = static function ( array $values ): array {
	sort( $values, SORT_NUMERIC );
	$count = count( $values );
	$p     = static function ( float $percentile ) use ( $values, $count ): ?float {
		if ( 0 === $count ) {
			return null;
		}
		$index = (int) ceil( ( $percentile / 100 ) * $count ) - 1;
		$index = max( 0, min( $count - 1, $index ) );
		return round( (float) $values[ $index ], 3 );
	};

	return array(
		'count' => $count,
		'min'   => 0 === $count ? null : round( (float) $values[0], 3 ),
		'p50'   => $p( 50 ),
		'p95'   => $p( 95 ),
		'max'   => 0 === $count ? null : round( (float) $values[ $count - 1 ], 3 ),
		'avg'   => 0 === $count ? null : round( array_sum( $values ) / $count, 3 ),
		'sum'   => round( array_sum( $values ), 3 ),
	);
};

$autoload_ms = $measure(
	static function () use ( $repo_dir ): void {
		if ( is_file( $repo_dir . '/vendor/autoload.php' ) ) {
			require_once $repo_dir . '/vendor/autoload.php';
		}
	}
);
$load_stack_ms = $measure(
	static function () use ( $repo_dir ): void {
		require_once $repo_dir . '/wp-includes/database/load.php';
	}
);

$setup = new WP_DuckDB_Connection( array( 'path' => $db_path ) );
$setup->query( 'CREATE TABLE IF NOT EXISTS profile_costs (id BIGINT, value VARCHAR)' );
$setup->query( 'DELETE FROM profile_costs' );
$setup->query( "INSERT INTO profile_costs VALUES (1, 'seed')" );
$setup->close();

for ( $i = 0; $i < $iterations; ++$i ) {
	$connection = null;
	$record(
		'native_open',
		$measure(
			static function () use ( $db_path, &$connection ): void {
				$connection = new WP_DuckDB_Connection( array( 'path' => $db_path ) );
			}
		)
	);
	$record(
		'native_new_connection_select_1',
		$measure(
			static function () use ( $connection ): void {
				$connection->query( 'SELECT 1' )->fetchAll();
			}
		)
	);
	$record(
		'native_new_connection_count',
		$measure(
			static function () use ( $connection ): void {
				$connection->query( 'SELECT COUNT(*) FROM profile_costs' )->fetchColumn();
			}
		)
	);
	$connection->close();
}

$connection = new WP_DuckDB_Connection( array( 'path' => $db_path ) );
for ( $i = 0; $i < $iterations; ++$i ) {
	$record(
		'native_reused_connection_select_1',
		$measure(
			static function () use ( $connection ): void {
				$connection->query( 'SELECT 1' )->fetchAll();
			}
		)
	);
}
for ( $i = 0; $i < $iterations; ++$i ) {
	$id = 100000 + $i;
	$record(
		'native_reused_connection_insert_autocommit',
		$measure(
			static function () use ( $connection, $id ): void {
				$connection->query( "INSERT INTO profile_costs VALUES ($id, 'native')" );
			}
		)
	);
}
for ( $i = 0; $i < $iterations; ++$i ) {
	$id = 200000 + $i;
	$record(
		'native_reused_connection_insert_explicit_tx',
		$measure(
			static function () use ( $connection, $id ): void {
				$connection->beginTransaction();
				$connection->query( "INSERT INTO profile_costs VALUES ($id, 'native_tx')" );
				$connection->commit();
			}
		)
	);
}
$connection->close();

$descriptor_spec = array(
	0 => array( 'pipe', 'r' ),
	1 => array( 'file', $sidecar_log, 'a' ),
	2 => array( 'file', $sidecar_log, 'a' ),
);
$sidecar_command = PHP_BINARY . ' -d ffi.enable=1 ' . escapeshellarg( $repo_dir . '/bin/duckdb-sidecar.php' )
	. ' --socket=' . escapeshellarg( $socket )
	. ' --path=' . escapeshellarg( $db_path )
	. ' --cache=0';

$sidecar_process = proc_open( $sidecar_command, $descriptor_spec, $pipes, $repo_dir );
if ( ! is_resource( $sidecar_process ) ) {
	fwrite( STDERR, "Failed to start DuckDB sidecar.\n" );
	exit( 1 );
}
if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
	fclose( $pipes[0] );
}

$sidecar_start_ms = $measure(
	static function () use ( $socket ): void {
		$deadline = microtime( true ) + 10;
		while ( microtime( true ) < $deadline ) {
			if ( is_file( $socket ) || file_exists( $socket ) ) {
				return;
			}
			usleep( 10000 );
		}
		throw new RuntimeException( 'Timed out waiting for DuckDB sidecar socket.' );
	}
);

try {
	for ( $i = 0; $i < $iterations; ++$i ) {
		$remote = null;
		$record(
			'sidecar_remote_open',
			$measure(
				static function () use ( $socket, &$remote ): void {
					$remote = new WP_DuckDB_Remote_Connection( array( 'socket' => $socket ) );
				}
			)
		);
		$record(
			'sidecar_new_remote_connection_select_1',
			$measure(
				static function () use ( $remote ): void {
					$remote->query( 'SELECT 1' )->fetchAll();
				}
			)
		);
		$remote->close();
	}

	$remote = new WP_DuckDB_Remote_Connection( array( 'socket' => $socket ) );
	for ( $i = 0; $i < $iterations; ++$i ) {
		$record(
			'sidecar_reused_remote_connection_select_1',
			$measure(
				static function () use ( $remote ): void {
					$remote->query( 'SELECT 1' )->fetchAll();
				}
			)
		);
	}
	for ( $i = 0; $i < $iterations; ++$i ) {
		$id = 300000 + $i;
		$record(
			'sidecar_reused_remote_connection_insert_autocommit',
			$measure(
				static function () use ( $remote, $id ): void {
					$remote->query( "INSERT INTO profile_costs VALUES ($id, 'sidecar')" );
				}
			)
		);
	}
	for ( $i = 0; $i < $iterations; ++$i ) {
		$id = 400000 + $i;
		$record(
			'sidecar_reused_remote_connection_insert_explicit_tx',
			$measure(
				static function () use ( $remote, $id ): void {
					$remote->query( 'BEGIN' );
					$remote->query( "INSERT INTO profile_costs VALUES ($id, 'sidecar_tx')" );
					$remote->query( 'COMMIT' );
				}
			)
		);
	}
	$remote->close();
} finally {
	proc_terminate( $sidecar_process );
	proc_close( $sidecar_process );
	@unlink( $socket );
}

$grouped = array();
foreach ( $events as $event ) {
	$grouped[ $event['name'] ][] = $event['elapsed_ms'];
}

$metrics = array();
foreach ( $grouped as $name => $values ) {
	$metrics[ $name ] = $summarize( $values );
}

$native_open_p50  = $metrics['native_open']['p50'] ?? null;
$native_reuse_p50 = $metrics['native_reused_connection_select_1']['p50'] ?? null;
$sidecar_open_p50 = $metrics['sidecar_remote_open']['p50'] ?? null;
$sidecar_query_p50 = $metrics['sidecar_reused_remote_connection_select_1']['p50'] ?? null;

$summary = array(
	'run_id'             => basename( $output_dir ),
	'created_at'         => gmdate( 'c' ),
	'iterations'         => $iterations,
	'php_version'        => PHP_VERSION,
	'repo'               => $repo_dir,
	'database_path'      => $db_path,
	'sidecar_cache'      => false,
	'one_time_ms'        => array(
		'composer_autoload'       => $autoload_ms,
		'database_stack_load'     => $load_stack_ms,
		'sidecar_start_to_socket' => $sidecar_start_ms,
	),
	'metrics_ms'         => $metrics,
	'derived_ms'         => array(
		'native_open_minus_sidecar_socket_open_p50' => null === $native_open_p50 || null === $sidecar_open_p50 ? null : round( $native_open_p50 - $sidecar_open_p50, 3 ),
		'sidecar_query_minus_reused_native_query_p50' => null === $sidecar_query_p50 || null === $native_reuse_p50 ? null : round( $sidecar_query_p50 - $native_reuse_p50, 3 ),
	),
	'notes'              => array(
		'Native open measures WP_DuckDB_Connection construction around the PHP FFI DuckDB client.',
		'Sidecar cache is disabled so sidecar query timings include Unix socket JSON transport and native DuckDB execution.',
		'This is a lower-bound micro-profile; WordPress HTTP request timings are still required for end-to-end latency.',
	),
);

file_put_contents( $output_dir . '/events.jsonl', implode( "\n", array_map( 'json_encode', $events ) ) . "\n" );
file_put_contents( $output_dir . '/summary.json', json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

echo json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
