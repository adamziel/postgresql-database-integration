#!/usr/bin/env php
<?php
/**
 * Generate a static GitHub Pages report from WordPress database benchmark output.
 */

declare( strict_types=1 );

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php bin/duckdb-benchmark-report.php <run-dir> [docs-dir]\n" );
	exit( 2 );
}

$run_dir  = rtrim( $argv[1], '/\\' );
$docs_dir = isset( $argv[2] ) ? rtrim( $argv[2], '/\\' ) : dirname( __DIR__ ) . '/docs';
$run_id   = basename( $run_dir );

$events_file = $run_dir . '/events.jsonl';
$meta_file   = $run_dir . '/meta.json';

if ( ! is_file( $events_file ) ) {
	fwrite( STDERR, "Missing benchmark events file: $events_file\n" );
	exit( 2 );
}

$meta = array();
if ( is_file( $meta_file ) ) {
	$decoded = json_decode( (string) file_get_contents( $meta_file ), true );
	if ( is_array( $decoded ) ) {
		$meta = $decoded;
	}
}

$events = array();
foreach ( file( $events_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line_number => $line ) {
	$decoded = json_decode( $line, true );
	if ( ! is_array( $decoded ) ) {
		fwrite( STDERR, 'Invalid JSON in ' . $events_file . ':' . ( $line_number + 1 ) . "\n" );
		exit( 2 );
	}
	$events[] = $decoded;
}

$benchmarks    = array_values(
	array_filter(
		$events,
		static function ( array $event ): bool {
			return 'benchmark' === ( $event['type'] ?? null );
		}
	)
);
$verifications = array_values(
	array_filter(
		$events,
		static function ( array $event ): bool {
			return 'verification' === ( $event['type'] ?? null );
		}
	)
);
$failures      = array_values(
	array_filter(
		$events,
		static function ( array $event ): bool {
			return in_array( $event['type'] ?? null, array( 'benchmark_failure', 'backend_failure' ), true );
		}
	)
);

foreach ( $benchmarks as &$benchmark ) {
	$info                         = backend_info( (string) ( $benchmark['backend'] ?? '' ) );
	$benchmark['backend_display'] = $info['display'];
	$benchmark['backend_family']  = $info['family'];
	$benchmark['duckdb_involved'] = $info['duckdb_involved'];
	$benchmark['storage_target']  = $info['storage_target'];
}
unset( $benchmark );

$backend_names = array_values(
	array_unique(
		array_merge(
			array_map(
				static function ( array $benchmark ): string {
					return (string) ( $benchmark['backend'] ?? '' );
				},
				$benchmarks
			),
			array_map(
				static function ( array $verification ): string {
					return (string) ( $verification['backend'] ?? '' );
				},
				$verifications
			)
		)
	)
);
usort(
	$backend_names,
	static function ( string $a, string $b ): int {
		return backend_sort_key( $a ) <=> backend_sort_key( $b );
	}
);
$backend_details = array_map( 'backend_info', $backend_names );

usort(
	$benchmarks,
	static function ( array $a, array $b ): int {
		return array( backend_sort_key( (string) ( $a['backend'] ?? '' ) ), scenario_sort_key( (string) ( $a['label'] ?? '' ) ), (int) ( $a['concurrency'] ?? 0 ) )
			<=> array( backend_sort_key( (string) ( $b['backend'] ?? '' ) ), scenario_sort_key( (string) ( $b['label'] ?? '' ) ), (int) ( $b['concurrency'] ?? 0 ) );
	}
);

$summary = array(
	'run_id'         => $run_id,
	'generated_at'   => gmdate( 'c' ),
	'meta'           => $meta,
	'backend_details' => $backend_details,
	'benchmarks'     => $benchmarks,
	'verifications'  => $verifications,
	'failures'       => $failures,
	'total_requests' => array_sum(
		array_map(
			static function ( array $benchmark ): int {
				return (int) ( $benchmark['requests'] ?? 0 );
			},
			$benchmarks
		)
	),
	'total_errors'   => array_sum(
		array_map(
			static function ( array $benchmark ): int {
				return (int) ( $benchmark['errors'] ?? 0 );
			},
			$benchmarks
		)
	),
);

$run_docs_dir = $docs_dir . '/benchmarks/runs/' . $run_id;
foreach ( array( $docs_dir, $docs_dir . '/benchmarks', $docs_dir . '/benchmarks/runs', $run_docs_dir ) as $dir ) {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		fwrite( STDERR, "Failed to create directory: $dir\n" );
		exit( 1 );
	}
}

write_json_file( $run_docs_dir . '/summary.json', $summary );
write_json_file( $docs_dir . '/benchmarks/latest.json', $summary );
copy( $events_file, $run_docs_dir . '/events.jsonl' );
if ( is_file( $meta_file ) ) {
	copy( $meta_file, $run_docs_dir . '/meta.json' );
}
file_put_contents( $docs_dir . '/.nojekyll', '' );
file_put_contents( $docs_dir . '/index.html', render_html_report( $summary ) );

echo "Wrote WordPress database benchmark report to $docs_dir/index.html\n";

function write_json_file( string $path, array $data ): void {
	$encoded = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $encoded ) {
		throw new RuntimeException( 'Failed to encode JSON.' );
	}
	file_put_contents( $path, $encoded . "\n" );
}

function h( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function format_number( $value, int $decimals = 1 ): string {
	if ( null === $value || '' === $value ) {
		return '';
	}
	return number_format( (float) $value, $decimals );
}

function format_latency( array $benchmark, string $key ): string {
	$latency = $benchmark['latency_ms'] ?? array();
	return is_array( $latency ) && array_key_exists( $key, $latency ) ? format_number( $latency[ $key ], 1 ) : '';
}

function backend_info( string $backend ): array {
	$backend = strtolower( $backend );
	$known   = array(
		'mysql'         => array(
			'backend'         => 'mysql',
			'display'         => 'MySQL',
			'family'          => 'Native WordPress database',
			'duckdb_involved' => false,
			'storage_target'  => 'MariaDB server',
			'role'            => 'Baseline',
			'notes'           => 'WordPress uses its normal MySQL driver path. DuckDB is not loaded.',
			'sort'            => 10,
		),
		'sqlite'        => array(
			'backend'         => 'sqlite',
			'display'         => 'SQLite',
			'family'          => 'Native WordPress database',
			'duckdb_involved' => false,
			'storage_target'  => 'SQLite file via SQLite Database Integration',
			'role'            => 'Baseline',
			'notes'           => 'WordPress uses the SQLite Database Integration plugin. DuckDB is not loaded.',
			'sort'            => 20,
		),
		'duckdb'        => array(
			'backend'         => 'duckdb',
			'display'         => 'DuckDB',
			'family'          => 'DuckDB native',
			'duckdb_involved' => true,
			'storage_target'  => 'DuckDB database file',
			'role'            => 'DuckDB baseline',
			'notes'           => 'WordPress uses the DuckDB backend directly with a DuckDB database file.',
			'sort'            => 30,
		),
		'duckdb_sidecar' => array(
			'backend'         => 'duckdb_sidecar',
			'display'         => 'DuckDB sidecar',
			'family'          => 'DuckDB sidecar',
			'duckdb_involved' => true,
			'storage_target'  => 'DuckDB database file through a long-lived sidecar process',
			'role'            => 'DuckDB performance path',
			'notes'           => 'WordPress uses the DuckDB driver over a local Unix socket. The sidecar owns the native DuckDB handle across PHP requests.',
			'sort'            => 35,
		),
		'sqlite_attach' => array(
			'backend'         => 'sqlite_attach',
			'display'         => 'DuckDB -> SQLite attach',
			'family'          => 'DuckDB attached database',
			'duckdb_involved' => true,
			'storage_target'  => 'SQLite file attached through DuckDB',
			'role'            => 'DuckDB-mediated SQLite',
			'notes'           => 'WordPress uses DuckDB; DuckDB reads from and flushes to an attached SQLite database.',
			'sort'            => 40,
		),
		'mysql_attach'  => array(
			'backend'         => 'mysql_attach',
			'display'         => 'DuckDB -> MySQL attach',
			'family'          => 'DuckDB attached database',
			'duckdb_involved' => true,
			'storage_target'  => 'MariaDB server attached through DuckDB',
			'role'            => 'DuckDB-mediated MySQL',
			'notes'           => 'WordPress uses DuckDB; DuckDB reads from and flushes to an attached MySQL-compatible database.',
			'sort'            => 50,
		),
		'json'          => array(
			'backend'         => 'json',
			'display'         => 'DuckDB -> JSON',
			'family'          => 'DuckDB external files',
			'duckdb_involved' => true,
			'storage_target'  => 'Local JSON table files',
			'role'            => 'DuckDB file-format storage',
			'notes'           => 'WordPress uses DuckDB; tables hydrate from and flush to JSON files.',
			'sort'            => 60,
		),
		'csv'           => array(
			'backend'         => 'csv',
			'display'         => 'DuckDB -> CSV',
			'family'          => 'DuckDB external files',
			'duckdb_involved' => true,
			'storage_target'  => 'Local CSV table files',
			'role'            => 'DuckDB file-format storage',
			'notes'           => 'WordPress uses DuckDB; tables hydrate from and flush to CSV files.',
			'sort'            => 70,
		),
		'parquet'       => array(
			'backend'         => 'parquet',
			'display'         => 'DuckDB -> Parquet',
			'family'          => 'DuckDB external files',
			'duckdb_involved' => true,
			'storage_target'  => 'Local Parquet table files',
			'role'            => 'DuckDB file-format storage',
			'notes'           => 'WordPress uses DuckDB; tables hydrate from and flush to Parquet files.',
			'sort'            => 80,
		),
		's3_parquet'    => array(
			'backend'         => 's3_parquet',
			'display'         => 'DuckDB -> S3 Parquet',
			'family'          => 'DuckDB object storage',
			'duckdb_involved' => true,
			'storage_target'  => 'MinIO S3-compatible Parquet table files',
			'role'            => 'DuckDB object-store storage',
			'notes'           => 'WordPress uses DuckDB; tables hydrate from and flush to Parquet files in a local S3-compatible object store.',
			'sort'            => 90,
		),
	);

	if ( isset( $known[ $backend ] ) ) {
		return $known[ $backend ];
	}

	return array(
		'backend'         => $backend,
		'display'         => $backend,
		'family'          => 'DuckDB custom backend',
		'duckdb_involved' => true,
		'storage_target'  => 'Custom DuckDB backend',
		'role'            => 'Custom',
		'notes'           => 'WordPress uses DuckDB with custom backend configuration.',
		'sort'            => 1000,
	);
}

function backend_sort_key( string $backend ): array {
	$info = backend_info( $backend );
	return array( (int) $info['sort'], $backend );
}

function scenario_sort_key( string $scenario ): int {
	$order = array(
		'front_page' => 10,
		'rest_read'  => 20,
		'rest_write' => 30,
	);
	return $order[ $scenario ] ?? 100;
}

function scenario_label( string $scenario ): string {
	$labels = array(
		'front_page' => 'Front page',
		'rest_read'  => 'REST read',
		'rest_write' => 'REST write',
	);
	return $labels[ $scenario ] ?? $scenario;
}

function benchmark_cell( ?array $benchmark ): string {
	if ( null === $benchmark ) {
		return '<span class="muted">-</span>';
	}

	$errors = (int) ( $benchmark['errors'] ?? 0 );
	$class  = 0 === $errors ? '' : ' bad';

	return '<span class="metric' . $class . '"><strong>' . h( format_number( $benchmark['requests_sec'] ?? null, 2 ) ) . '</strong> req/s<br><span class="muted">p95 ' . h( format_latency( $benchmark, 'p95' ) ) . ' ms</span></span>';
}

function render_html_report( array $summary ): string {
	$meta           = is_array( $summary['meta'] ?? null ) ? $summary['meta'] : array();
	$backend_infos = is_array( $summary['backend_details'] ?? null ) ? $summary['backend_details'] : array();
	$benchmarks    = is_array( $summary['benchmarks'] ?? null ) ? $summary['benchmarks'] : array();
	$verifications = is_array( $summary['verifications'] ?? null ) ? $summary['verifications'] : array();
	$failures      = is_array( $summary['failures'] ?? null ) ? $summary['failures'] : array();
	$total_errors  = (int) ( $summary['total_errors'] ?? 0 );
	$run_id        = (string) ( $summary['run_id'] ?? '' );
	$generated_at  = (string) ( $summary['generated_at'] ?? '' );
	$status_text   = 0 === $total_errors && array() === $failures ? 'All benchmarked HTTP requests completed without detected WordPress database errors.' : 'One or more benchmark checks reported errors.';

	if ( array() === $backend_infos ) {
		$backend_names = array_values(
			array_unique(
				array_map(
					static function ( array $benchmark ): string {
						return (string) ( $benchmark['backend'] ?? '' );
					},
					$benchmarks
				)
			)
		);
		usort(
			$backend_names,
			static function ( string $a, string $b ): int {
				return backend_sort_key( $a ) <=> backend_sort_key( $b );
			}
		);
		$backend_infos = array_map( 'backend_info', $backend_names );
	}

	$concurrency_levels = array_values(
		array_unique(
			array_map(
				static function ( array $benchmark ): int {
					return (int) ( $benchmark['concurrency'] ?? 0 );
				},
				$benchmarks
			)
		)
	);
	sort( $concurrency_levels, SORT_NUMERIC );
	$low_concurrency  = $concurrency_levels[0] ?? 1;
	$high_concurrency = $concurrency_levels[ count( $concurrency_levels ) - 1 ] ?? $low_concurrency;

	$benchmark_index = array();
	foreach ( $benchmarks as $benchmark ) {
		$backend     = (string) ( $benchmark['backend'] ?? '' );
		$label       = (string) ( $benchmark['label'] ?? '' );
		$concurrency = (int) ( $benchmark['concurrency'] ?? 0 );

		if ( '' !== $backend && '' !== $label && $concurrency > 0 ) {
			$benchmark_index[ $backend ][ $label ][ $concurrency ] = $benchmark;
		}
	}

	$verification_map = array();
	foreach ( $verifications as $verification ) {
		$backend = (string) ( $verification['backend'] ?? '' );
		if ( '' !== $backend ) {
			$verification_map[ $backend ] = $verification;
		}
	}

	$best_by_label = array();
	foreach ( $benchmarks as $benchmark ) {
		$label = (string) ( $benchmark['label'] ?? '' );
		if ( '' === $label || (int) ( $benchmark['errors'] ?? 0 ) > 0 ) {
			continue;
		}
		if ( ! isset( $best_by_label[ $label ] ) || (float) ( $benchmark['requests_sec'] ?? 0 ) > (float) ( $best_by_label[ $label ]['requests_sec'] ?? 0 ) ) {
			$best_by_label[ $label ] = $benchmark;
		}
	}

	$backend_rows = '';
	foreach ( $backend_infos as $info ) {
		$backend_rows .= '<tr>'
			. '<td><strong>' . h( $info['display'] ?? $info['backend'] ?? '' ) . '</strong><br><code>' . h( $info['backend'] ?? '' ) . '</code></td>'
			. '<td>' . h( $info['family'] ?? '' ) . '</td>'
			. '<td><span class="pill ' . ( ! empty( $info['duckdb_involved'] ) ? 'yes' : 'no' ) . '">' . ( ! empty( $info['duckdb_involved'] ) ? 'Yes' : 'No' ) . '</span></td>'
			. '<td>' . h( $info['storage_target'] ?? '' ) . '</td>'
			. '<td><strong>' . h( $info['role'] ?? '' ) . '</strong><br><span class="muted">' . h( $info['notes'] ?? '' ) . '</span></td>'
			. '</tr>';
	}

	$summary_rows = '';
	$scenarios    = array( 'front_page', 'rest_read', 'rest_write' );
	foreach ( $backend_infos as $info ) {
		$backend      = (string) ( $info['backend'] ?? '' );
		$verification = $verification_map[ $backend ] ?? null;
		$status       = is_array( $verification )
			? h( $verification['status'] ?? '' ) . '<br><span class="muted">' . h( $verification['stored_events'] ?? '' ) . '/' . h( $verification['expected_min_events'] ?? '' ) . ' rows</span>'
			: '<span class="muted">not available</span>';

		$summary_rows .= '<tr>'
			. '<td><strong>' . h( $info['display'] ?? $backend ) . '</strong><br><span class="muted">' . h( $info['family'] ?? '' ) . '</span></td>'
			. '<td>' . h( $info['storage_target'] ?? '' ) . '</td>';

		foreach ( $scenarios as $scenario ) {
			$summary_rows .= '<td class="num">' . benchmark_cell( $benchmark_index[ $backend ][ $scenario ][ $low_concurrency ] ?? null ) . '</td>';
			$summary_rows .= '<td class="num">' . benchmark_cell( $benchmark_index[ $backend ][ $scenario ][ $high_concurrency ] ?? null ) . '</td>';
		}

		$summary_rows .= '<td>' . $status . '</td></tr>';
	}

	$rows = '';
	foreach ( $benchmarks as $benchmark ) {
		$info = backend_info( (string) ( $benchmark['backend'] ?? '' ) );
		$rows .= '<tr>'
			. '<td><strong>' . h( $benchmark['backend_display'] ?? $info['display'] ?? $benchmark['backend'] ?? '' ) . '</strong><br><code>' . h( $benchmark['backend'] ?? '' ) . '</code></td>'
			. '<td>' . h( $benchmark['backend_family'] ?? $info['family'] ?? '' ) . '</td>'
			. '<td><span class="pill ' . ( ! empty( $benchmark['duckdb_involved'] ) ? 'yes' : 'no' ) . '">' . ( ! empty( $benchmark['duckdb_involved'] ) ? 'Yes' : 'No' ) . '</span></td>'
			. '<td>' . h( $benchmark['storage_target'] ?? $info['storage_target'] ?? '' ) . '</td>'
			. '<td>' . h( scenario_label( (string) ( $benchmark['label'] ?? '' ) ) ) . '</td>'
			. '<td class="num">' . h( $benchmark['concurrency'] ?? '' ) . '</td>'
			. '<td class="num">' . h( $benchmark['requests'] ?? '' ) . '</td>'
			. '<td class="num">' . h( $benchmark['ok'] ?? '' ) . '</td>'
			. '<td class="num ' . ( (int) ( $benchmark['errors'] ?? 0 ) > 0 ? 'bad' : '' ) . '">' . h( $benchmark['errors'] ?? '' ) . '</td>'
			. '<td class="num">' . format_number( $benchmark['requests_sec'] ?? null, 2 ) . '</td>'
			. '<td class="num">' . format_latency( $benchmark, 'p50' ) . '</td>'
			. '<td class="num">' . format_latency( $benchmark, 'p95' ) . '</td>'
			. '<td class="num">' . format_latency( $benchmark, 'p99' ) . '</td>'
			. '</tr>';
	}

	$verification_rows = '';
	foreach ( $verifications as $verification ) {
		$info = backend_info( (string) ( $verification['backend'] ?? '' ) );
		$verification_rows .= '<tr>'
			. '<td><strong>' . h( $info['display'] ?? $verification['backend'] ?? '' ) . '</strong><br><code>' . h( $verification['backend'] ?? '' ) . '</code></td>'
			. '<td>' . h( $info['family'] ?? '' ) . '</td>'
			. '<td class="num">' . h( $verification['expected_min_events'] ?? '' ) . '</td>'
			. '<td class="num">' . h( $verification['stored_events'] ?? '' ) . '</td>'
			. '<td>' . h( $verification['status'] ?? '' ) . '</td>'
			. '</tr>';
	}

	$best_items = '';
	foreach ( $best_by_label as $label => $benchmark ) {
		$best_items .= '<li><strong>' . h( scenario_label( (string) $label ) ) . ':</strong> ' . h( $benchmark['backend_display'] ?? $benchmark['backend'] ?? '' ) . ' at concurrency ' . h( $benchmark['concurrency'] ?? '' ) . ' reached ' . h( format_number( $benchmark['requests_sec'] ?? null, 2 ) ) . ' req/s with p95 ' . h( format_latency( $benchmark, 'p95' ) ) . ' ms.</li>';
	}
	if ( '' === $best_items ) {
		$best_items = '<li>No successful benchmark rows were available for comparison.</li>';
	}

	$failure_html = '';
	if ( array() !== $failures ) {
		$failure_html .= '<h2>Failures</h2><pre>';
		$failure_html .= h( json_encode( $failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$failure_html .= '</pre>';
	}

	$wp_version  = h( $meta['wordpress_version'] ?? 'unknown' );
	$php_version = h( $meta['php_version'] ?? 'unknown' );
	$commit      = h( $meta['commit'] ?? 'unknown' );
	$workers     = h( $meta['server_workers'] ?? 'unknown' );
	$host        = h( $meta['host'] ?? 'unknown' );

	return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WordPress Database Backend Benchmarks</title>
<style>
:root { color-scheme: light; --border: #d7dee8; --text: #182230; --muted: #5d6b7c; --bg: #f7f9fc; --panel: #fff; --accent: #0f766e; --bad: #b42318; --good-bg: #dcfce7; --good: #166534; --neutral-bg: #e0f2fe; --neutral: #075985; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--text); background: var(--bg); line-height: 1.45; }
main { max-width: 1320px; margin: 0 auto; padding: 36px 20px 56px; }
h1 { font-size: clamp(2rem, 5vw, 3.6rem); margin: 0 0 8px; letter-spacing: 0; }
h2 { font-size: 1.35rem; margin: 32px 0 12px; }
p { max-width: 960px; }
.meta, .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 16px; }
.meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 22px 0; }
.meta div { min-width: 0; }
.label { color: var(--muted); font-size: .82rem; text-transform: uppercase; }
.value { font-weight: 650; overflow-wrap: anywhere; }
.table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; background: var(--panel); }
table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.table-wrap table { border: 0; border-radius: 0; min-width: 980px; }
th, td { border-bottom: 1px solid var(--border); padding: 9px 10px; text-align: left; vertical-align: top; }
th { background: #edf2f7; color: #334155; font-size: .82rem; text-transform: uppercase; }
tr:last-child td { border-bottom: 0; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.bad { color: var(--bad); font-weight: 700; }
.status { border-left: 4px solid var(--accent); }
.links a { margin-right: 14px; }
.muted { color: var(--muted); }
.metric strong { font-size: 1.02rem; }
.pill { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.pill.yes { background: var(--neutral-bg); color: var(--neutral); }
.pill.no { background: var(--good-bg); color: var(--good); }
code { font-size: .86em; background: #eef2f7; padding: 1px 5px; border-radius: 4px; }
pre { overflow: auto; background: #111827; color: #f8fafc; padding: 14px; border-radius: 8px; }
</style>
</head>
<body>
<main>
<h1>WordPress Database Backend Benchmarks</h1>
<p>This page is generated from a real local WordPress HTTP benchmark run. It compares native WordPress database baselines, native DuckDB, DuckDB attached databases, local DuckDB file formats, and MinIO-backed S3-compatible Parquet storage using the same front-page reads, REST reads, REST writes, fixture data, and PHP server worker count.</p>
<div class="meta">
<div><div class="label">Run</div><div class="value">' . h( $run_id ) . '</div></div>
<div><div class="label">Generated</div><div class="value">' . h( $generated_at ) . '</div></div>
<div><div class="label">Commit</div><div class="value">' . $commit . '</div></div>
<div><div class="label">WordPress</div><div class="value">' . $wp_version . '</div></div>
<div><div class="label">PHP</div><div class="value">' . $php_version . '</div></div>
<div><div class="label">Workers</div><div class="value">' . $workers . '</div></div>
<div><div class="label">Host</div><div class="value">' . $host . '</div></div>
</div>
<section class="panel status"><strong>Status:</strong> ' . h( $status_text ) . '</section>
<section class="panel"><strong>How to read this:</strong> Rows labeled <code>mysql</code> and <code>sqlite</code> are native WordPress database baselines and do not use DuckDB. Rows labeled <code>mysql_attach</code> and <code>sqlite_attach</code> are DuckDB rows: WordPress talks to DuckDB, and DuckDB talks to the attached database. DuckDB external file/object-store rows hydrate tables into DuckDB and flush them back to the target storage.</section>
<section class="panel"><strong>Concurrency interpretation:</strong> MySQL is the server-backed baseline. SQLite and DuckDB are file-backed paths where writes are constrained by backend locking. DuckDB external storage adds a hydrate/flush cycle, so higher HTTP concurrency primarily creates queueing instead of linear throughput scaling. Compare the low-concurrency and high-concurrency p95 values in the summary table to see queueing pressure.</section>
<h2>Backend Paths</h2>
<div class="table-wrap">
<table>
<thead><tr><th>Backend</th><th>Family</th><th>DuckDB?</th><th>Storage Target</th><th>Role / Notes</th></tr></thead>
<tbody>' . $backend_rows . '</tbody>
</table>
</div>
<h2>At A Glance</h2>
<p>Each metric cell shows throughput and p95 latency. The first metric column for each scenario uses concurrency ' . h( $low_concurrency ) . '; the second uses concurrency ' . h( $high_concurrency ) . '.</p>
<div class="table-wrap">
<table>
<thead><tr><th>Backend</th><th>Storage Target</th><th class="num">Front c' . h( $low_concurrency ) . '</th><th class="num">Front c' . h( $high_concurrency ) . '</th><th class="num">Read c' . h( $low_concurrency ) . '</th><th class="num">Read c' . h( $high_concurrency ) . '</th><th class="num">Write c' . h( $low_concurrency ) . '</th><th class="num">Write c' . h( $high_concurrency ) . '</th><th>Write Verification</th></tr></thead>
<tbody>' . $summary_rows . '</tbody>
</table>
</div>
<h2>Fastest Successful Rows</h2>
<ul>' . $best_items . '</ul>
<h2>Detailed Results</h2>
<div class="table-wrap">
<table>
<thead><tr><th>Backend</th><th>Family</th><th>DuckDB?</th><th>Storage Target</th><th>Scenario</th><th class="num">Concurrency</th><th class="num">Requests</th><th class="num">OK</th><th class="num">Errors</th><th class="num">Req/s</th><th class="num">p50 ms</th><th class="num">p95 ms</th><th class="num">p99 ms</th></tr></thead>
<tbody>' . $rows . '</tbody>
</table>
</div>
<h2>Write Verification</h2>
<div class="table-wrap">
<table>
<thead><tr><th>Backend</th><th>Family</th><th class="num">Expected Write Rows</th><th class="num">Stored Write Rows</th><th>Status</th></tr></thead>
<tbody>' . $verification_rows . '</tbody>
</table>
</div>
<h2>Raw Data</h2>
<p class="links"><a href="benchmarks/latest.json">Latest summary JSON</a><a href="benchmarks/runs/' . h( rawurlencode( $run_id ) ) . '/events.jsonl">Run events JSONL</a><a href="benchmarks/runs/' . h( rawurlencode( $run_id ) ) . '/meta.json">Run metadata JSON</a></p>
' . $failure_html . '
</main>
</body>
</html>
';
}
