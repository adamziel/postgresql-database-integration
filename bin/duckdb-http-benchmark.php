#!/usr/bin/env php
<?php
/**
 * Small dependency-free HTTP benchmark helper for local WordPress benchmarks.
 *
 * This intentionally uses curl_multi instead of a system load-test binary so
 * contributors can reproduce benchmark runs with the repo's PHP dependencies.
 */

declare( strict_types=1 );

$options = getopt(
	'',
	array(
		'url:',
		'method::',
		'requests::',
		'concurrency::',
		'timeout::',
		'label::',
		'backend::',
		'expect-json',
	)
);

if ( ! isset( $options['url'] ) ) {
	fwrite( STDERR, "Usage: php bin/duckdb-http-benchmark.php --url=<url> [--method=GET] [--requests=100] [--concurrency=4]\n" );
	exit( 2 );
}

if ( ! extension_loaded( 'curl' ) ) {
	fwrite( STDERR, "The PHP curl extension is required.\n" );
	exit( 2 );
}

$url         = (string) $options['url'];
$method      = strtoupper( (string) ( $options['method'] ?? 'GET' ) );
$requests    = max( 1, (int) ( $options['requests'] ?? 100 ) );
$concurrency = max( 1, min( $requests, (int) ( $options['concurrency'] ?? 4 ) ) );
$timeout     = max( 1, (int) ( $options['timeout'] ?? 30 ) );
$label       = (string) ( $options['label'] ?? 'request' );
$backend     = (string) ( $options['backend'] ?? '' );
$expect_json = array_key_exists( 'expect-json', $options );

$queue      = range( 1, $requests );
$active     = array();
$durations  = array();
$errors     = array();
$ok         = 0;
$multi      = curl_multi_init();
$started_at = hrtime( true );

if ( false === $multi ) {
	fwrite( STDERR, "Failed to create curl multi handle.\n" );
	exit( 2 );
}

$start_one = static function ( int $sequence ) use ( $url, $method, $timeout ): CurlHandle {
	$handle = curl_init( $url );
	if ( false === $handle ) {
		throw new RuntimeException( 'Failed to create curl handle.' );
	}

	curl_setopt_array(
		$handle,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => false,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => min( 5, $timeout ),
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_HTTPHEADER     => array(
				'Connection: close',
				'User-Agent: duckdb-wordpress-benchmark/1',
				'X-DuckDB-Benchmark-Request: ' . $sequence,
			),
		)
	);

	if ( 'POST' === $method ) {
		curl_setopt( $handle, CURLOPT_POST, true );
		curl_setopt( $handle, CURLOPT_POSTFIELDS, 'sequence=' . rawurlencode( (string) $sequence ) );
	} elseif ( 'GET' !== $method ) {
		curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
	}

	return $handle;
};

$add_pending = static function () use ( &$queue, &$active, $multi, $start_one, $concurrency ): void {
	while ( count( $active ) < $concurrency && array() !== $queue ) {
		$sequence = array_shift( $queue );
		$handle   = $start_one( $sequence );
		$id       = (int) $handle;
		$active[ $id ] = array(
			'handle'  => $handle,
			'start'   => hrtime( true ),
			'sequence'=> $sequence,
		);
		curl_multi_add_handle( $multi, $handle );
	}
};

$has_wordpress_error = static function ( string $body ): bool {
	return 1 === preg_match(
		'/Error establishing a database connection|One or more database tables are unavailable|Database Error|WordPress &rsaquo; Error|Fatal error|DuckDB query failed/i',
		$body
	);
};

$has_expected_body = static function ( string $body ) use ( $expect_json ): bool {
	if ( ! $expect_json ) {
		return true;
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		return false;
	}

	return ! array_key_exists( 'ok', $decoded ) || true === $decoded['ok'];
};

$add_pending();

do {
	do {
		$status = curl_multi_exec( $multi, $running );
	} while ( CURLM_CALL_MULTI_PERFORM === $status );

	if ( CURLM_OK !== $status ) {
		$errors[] = array(
			'type'    => 'curl_multi',
			'message' => curl_multi_strerror( $status ),
		);
		break;
	}

	while ( false !== ( $info = curl_multi_info_read( $multi ) ) ) {
		$handle   = $info['handle'];
		$id       = (int) $handle;
		$metadata = $active[ $id ] ?? null;
		if ( null === $metadata ) {
			curl_multi_remove_handle( $multi, $handle );
			curl_close( $handle );
			continue;
		}

		$duration_ms = ( hrtime( true ) - $metadata['start'] ) / 1000000;
		$body        = (string) curl_multi_getcontent( $handle );
		$http_code   = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		$curl_errno  = (int) curl_errno( $handle );
		$curl_error  = (string) curl_error( $handle );
		$valid       = 0 === $curl_errno && 200 === $http_code && ! $has_wordpress_error( $body ) && $has_expected_body( $body );

		$durations[] = $duration_ms;
		if ( $valid ) {
			++$ok;
		} elseif ( count( $errors ) < 12 ) {
			$errors[] = array(
				'sequence'  => $metadata['sequence'],
				'http_code' => $http_code,
				'curl_errno'=> $curl_errno,
				'message'   => '' !== $curl_error ? $curl_error : substr( trim( strip_tags( $body ) ), 0, 240 ),
			);
		}

		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
		unset( $active[ $id ] );
	}

	$add_pending();

	if ( $running > 0 ) {
		curl_multi_select( $multi, 0.1 );
	}
} while ( $running > 0 || array() !== $active || array() !== $queue );

curl_multi_close( $multi );

$total_ms = ( hrtime( true ) - $started_at ) / 1000000;
sort( $durations, SORT_NUMERIC );

$percentile = static function ( array $values, float $p ): ?float {
	$count = count( $values );
	if ( 0 === $count ) {
		return null;
	}

	$index = (int) ceil( ( $p / 100 ) * $count ) - 1;
	$index = max( 0, min( $count - 1, $index ) );
	return round( (float) $values[ $index ], 3 );
};

$result = array(
	'type'          => 'benchmark',
	'backend'       => $backend,
	'label'         => $label,
	'method'        => $method,
	'expect_json'   => $expect_json,
	'url_path'      => parse_url( $url, PHP_URL_PATH ) ?: '/',
	'requests'      => $requests,
	'concurrency'   => $concurrency,
	'ok'            => $ok,
	'errors'        => $requests - $ok,
	'total_ms'      => round( $total_ms, 3 ),
	'requests_sec'  => round( $requests / max( 0.001, $total_ms / 1000 ), 3 ),
	'latency_ms'    => array(
		'min'    => null === $percentile( $durations, 0 ) ? null : round( (float) $durations[0], 3 ),
		'p50'    => $percentile( $durations, 50 ),
		'p95'    => $percentile( $durations, 95 ),
		'p99'    => $percentile( $durations, 99 ),
		'max'    => array() === $durations ? null : round( (float) $durations[ count( $durations ) - 1 ], 3 ),
	),
	'error_samples' => $errors,
);

echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";

exit( 0 === $result['errors'] ? 0 : 1 );
