---
title: Testing
description: Run the local PostgreSQL, DuckDB, smoke, benchmark, and readiness suites.
---

Install Composer metadata and run basic checks:

```bash
composer validate --strict --no-check-publish
composer install
composer run lint
```

## PostgreSQL Tests

Run the PostgreSQL PHPUnit suite:

```bash
PGSQL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=wordpress_test' \
PGSQL_TEST_USER='wordpress' \
PGSQL_TEST_PASSWORD='wordpress' \
composer run test-postgresql
```

Run the focused PostgreSQL smoke test:

```bash
PGSQL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=wordpress_test' \
PGSQL_TEST_USER='wordpress' \
PGSQL_TEST_PASSWORD='wordpress' \
composer run test-smoke
```

## DuckDB Tests

Install the DuckDB PHP client and C library:

```bash
composer config --no-plugins allow-plugins.satur.io/duckdb-auto true
composer require --dev --no-plugins --with-all-dependencies satur.io/duckdb-auto
composer dump-autoload
./vendor/bin/install-c-lib
```

Run the DuckDB suite:

```bash
WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run test-duckdb
```

Run the extended DuckDB parity suite:

```bash
WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run test-duckdb-parity
```

## WordPress Plugin Smoke

Run a disposable WordPress site with WooCommerce and Query Monitor against the
verifiable DuckDB backends:

```bash
WORDPRESS_VERSION=7.0 \
WOOCOMMERCE_VERSION=10.9.1 \
QUERY_MONITOR_VERSION=4.0.7 \
./bin/duckdb-wordpress-plugin-smoke.sh duckdb json csv parquet
```

Run a custom pipe-delimited backend:

```bash
WORDPRESS_VERSION=7.0 \
WOOCOMMERCE_VERSION=10.9.1 \
QUERY_MONITOR_VERSION=4.0.7 \
WP_DUCKDB_BACKEND_FILE_EXTENSION=psv \
WP_DUCKDB_BACKEND_READ_SQL="SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')" \
WP_DUCKDB_BACKEND_WRITE_SQL="COPY {table} TO {path} (HEADER, DELIMITER '|')" \
./bin/duckdb-wordpress-plugin-smoke.sh pipe_text
```

The smoke installs WordPress, activates WooCommerce and Query Monitor, creates a
product, verifies custom tables, performs HTTP requests, checks cart/session
state, verifies Query Monitor output, and fails on WordPress database errors.

## Benchmarks

Run a local WordPress HTTP benchmark:

```bash
WORDPRESS_VERSION=7.0 \
WP_DUCKDB_BENCHMARK_BACKENDS="mysql sqlite duckdb sqlite_attach mysql_attach json csv parquet s3_parquet" \
WP_DUCKDB_BENCHMARK_CONCURRENCY="1 2 4 8" \
WP_DUCKDB_BENCHMARK_SERVER_WORKERS=4 \
composer run benchmark-databases
```

Each run writes raw JSONL events under
`artifacts/duckdb-benchmarks/runs/<run-id>/` and regenerates the benchmark
report data under `docs/benchmarks/`.

## Readiness

Run the production-readiness evidence harness:

```bash
WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run readiness-duckdb
```

Structured results are written under
`artifacts/duckdb-production-readiness/runs/`.
