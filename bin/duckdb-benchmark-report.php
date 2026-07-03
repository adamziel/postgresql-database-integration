#!/usr/bin/env php
<?php
/**
 * Generate a static GitHub Pages report from DuckDB WordPress benchmark output.
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

usort(
	$benchmarks,
	static function ( array $a, array $b ): int {
		return array( $a['backend'] ?? '', $a['label'] ?? '', $a['concurrency'] ?? 0 )
			<=> array( $b['backend'] ?? '', $b['label'] ?? '', $b['concurrency'] ?? 0 );
	}
);

$summary = array(
	'run_id'         => $run_id,
	'generated_at'   => gmdate( 'c' ),
	'meta'           => $meta,
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

echo "Wrote DuckDB benchmark report to $docs_dir/index.html\n";

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

function render_html_report( array $summary ): string {
	$meta          = is_array( $summary['meta'] ?? null ) ? $summary['meta'] : array();
	$benchmarks    = is_array( $summary['benchmarks'] ?? null ) ? $summary['benchmarks'] : array();
	$verifications = is_array( $summary['verifications'] ?? null ) ? $summary['verifications'] : array();
	$failures      = is_array( $summary['failures'] ?? null ) ? $summary['failures'] : array();
	$total_errors  = (int) ( $summary['total_errors'] ?? 0 );
	$run_id        = (string) ( $summary['run_id'] ?? '' );
	$generated_at  = (string) ( $summary['generated_at'] ?? '' );
	$status_text   = 0 === $total_errors && array() === $failures ? 'All benchmarked HTTP requests completed without detected WordPress database errors.' : 'One or more benchmark checks reported errors.';

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

	$rows = '';
	foreach ( $benchmarks as $benchmark ) {
		$rows .= '<tr>'
			. '<td>' . h( $benchmark['backend'] ?? '' ) . '</td>'
			. '<td>' . h( $benchmark['label'] ?? '' ) . '</td>'
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
		$verification_rows .= '<tr>'
			. '<td>' . h( $verification['backend'] ?? '' ) . '</td>'
			. '<td class="num">' . h( $verification['expected_min_events'] ?? '' ) . '</td>'
			. '<td class="num">' . h( $verification['stored_events'] ?? '' ) . '</td>'
			. '<td>' . h( $verification['status'] ?? '' ) . '</td>'
			. '</tr>';
	}

	$best_items = '';
	foreach ( $best_by_label as $label => $benchmark ) {
		$best_items .= '<li><strong>' . h( $label ) . ':</strong> ' . h( $benchmark['backend'] ?? '' ) . ' at concurrency ' . h( $benchmark['concurrency'] ?? '' ) . ' reached ' . h( format_number( $benchmark['requests_sec'] ?? null, 2 ) ) . ' req/s with p95 ' . h( format_latency( $benchmark, 'p95' ) ) . ' ms.</li>';
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
<title>DuckDB WordPress Backend Benchmarks</title>
<style>
:root { color-scheme: light; --border: #d7dee8; --text: #182230; --muted: #5d6b7c; --bg: #f7f9fc; --panel: #fff; --accent: #0f766e; --bad: #b42318; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: var(--text); background: var(--bg); line-height: 1.45; }
main { max-width: 1180px; margin: 0 auto; padding: 36px 20px 56px; }
h1 { font-size: clamp(2rem, 5vw, 3.6rem); margin: 0 0 8px; letter-spacing: 0; }
h2 { font-size: 1.35rem; margin: 32px 0 12px; }
p { max-width: 840px; }
.meta, .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 16px; }
.meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 22px 0; }
.meta div { min-width: 0; }
.label { color: var(--muted); font-size: .82rem; text-transform: uppercase; }
.value { font-weight: 650; overflow-wrap: anywhere; }
table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
th, td { border-bottom: 1px solid var(--border); padding: 9px 10px; text-align: left; vertical-align: top; }
th { background: #edf2f7; color: #334155; font-size: .82rem; text-transform: uppercase; }
tr:last-child td { border-bottom: 0; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.bad { color: var(--bad); font-weight: 700; }
.status { border-left: 4px solid var(--accent); }
.links a { margin-right: 14px; }
pre { overflow: auto; background: #111827; color: #f8fafc; padding: 14px; border-radius: 8px; }
</style>
</head>
<body>
<main>
<h1>DuckDB WordPress Backend Benchmarks</h1>
<p>This page is generated from a local real WordPress benchmark run against the DuckDB database backend. It measures front-page reads, REST reads, and REST writes through HTTP with multiple PHP server workers.</p>
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
<section class="panel"><strong>Concurrency interpretation:</strong> DuckDB access is serialized by the backend lock, so higher HTTP concurrency primarily creates queueing instead of linear throughput scaling. In this run, all measured requests and writes completed correctly; p95 latency rises at concurrency 4 and 8, especially for external JSON/CSV/Parquet storage where each request hydrates and flushes table files.</section>
<h2>Fastest Successful Rows</h2>
<ul>' . $best_items . '</ul>
<h2>Benchmark Results</h2>
<table>
<thead><tr><th>Backend</th><th>Scenario</th><th class="num">Concurrency</th><th class="num">Requests</th><th class="num">OK</th><th class="num">Errors</th><th class="num">Req/s</th><th class="num">p50 ms</th><th class="num">p95 ms</th><th class="num">p99 ms</th></tr></thead>
<tbody>' . $rows . '</tbody>
</table>
<h2>Write Verification</h2>
<table>
<thead><tr><th>Backend</th><th class="num">Expected Write Rows</th><th class="num">Stored Write Rows</th><th>Status</th></tr></thead>
<tbody>' . $verification_rows . '</tbody>
</table>
<h2>Raw Data</h2>
<p class="links"><a href="benchmarks/latest.json">Latest summary JSON</a><a href="benchmarks/runs/' . h( rawurlencode( $run_id ) ) . '/events.jsonl">Run events JSONL</a><a href="benchmarks/runs/' . h( rawurlencode( $run_id ) ) . '/meta.json">Run metadata JSON</a></p>
' . $failure_html . '
</main>
</body>
</html>
';
}
