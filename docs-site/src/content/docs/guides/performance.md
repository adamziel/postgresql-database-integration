---
title: Performance
description: Interpret the current WordPress-shaped performance evidence for DuckDB and other backends.
lastUpdated: 2026-07-05
---

DuckDB is usable as WordPress storage, but the current WordPress request
workload does not behave like an analytical DuckDB workload. Treat the numbers
below as local, WordPress-shaped benchmark evidence, not universal database
benchmarks.

## Evidence Context

The headline WordPress HTTP benchmark was generated on July 5, 2026 from run
`wp-duckdb-tcp-storage-comparison-20260705T094510Z`. It used WordPress 7.0,
PHP 8.5.7, four PHP server workers, MariaDB for the MySQL baseline, and DuckDB
over the TCP sidecar for the native DuckDB row. The benchmark ran front-page
requests, REST reads, and REST writes at one, two, four, and eight concurrent
requests.

Treat these as local benchmark results for this repository's WordPress-shaped
workload. Re-run the benchmark on your deployment shape before making capacity
plans.

## Practical Summary

For a write-heavy WordPress HTTP workload, the fastest DuckDB shape was a
long-running sidecar plus request-scoped transactions:

| Backend | Mode | REST write p50 | REST write p95 | REST write rps |
| --- | --- | ---: | ---: | ---: |
| SQLite | native | 33.499 ms | 55.367 ms | 27.378 |
| DuckDB | sidecar, `WP_DUCKDB_REQUEST_TRANSACTION=1` | 35.722 ms | 41.525 ms | 27.378 |
| DuckDB | embedded FFI, `WP_DUCKDB_REQUEST_TRANSACTION=1` | 107.084 ms | 157.303 ms | 9.195 |

The sidecar keeps the native DuckDB handle open outside PHP requests.
Request-scoped transactions batch several small durable writes.

## Backend Throughput

Higher requests per second is better. `mysql` is native WordPress
MySQL/MariaDB; DuckDB is not loaded there. Rows named `DuckDB -> ...` mean
WordPress talks to DuckDB, and DuckDB hydrates from or flushes to that storage
target.

| Storage path | DuckDB involved | Front page, one / eight concurrent requests | REST read, one / eight concurrent requests | REST write, one / eight concurrent requests | Practical reading |
| --- | --- | ---: | ---: | ---: | --- |
| MySQL/MariaDB native | No | 19.6 / 81.4 | 65.5 / 269.5 | 60.1 / 213.7 | Baseline for production WordPress OLTP. |
| DuckDB native over TCP sidecar | Yes | 17.8 / 18.9 | 52.9 / 48.7 | 36.2 / 34.7 | Works, but throughput is mostly flat as concurrency rises. |
| DuckDB -> JSON files | Yes | 4.2 / 4.2 | 5.1 / 4.8 | 4.8 / 4.8 | Useful for demos, interchange, and inspection; not high-throughput request storage. |
| DuckDB -> SQLite attach | Yes | 2.9 / 2.9 | 3.3 / 3.1 | 3.1 / 3.1 | Proof that attached backends can persist WordPress data, not a performance path. |

## Direct SQL Comparison

The same conclusion appears when WordPress is removed from the hot path and the
benchmark runs only WordPress-shaped SQL against copied WordPress databases:

| Direct SQL workload with eight concurrent workers | MySQL/MariaDB native SQL | DuckDB native SQL | DuckDB / MySQL |
| --- | ---: | ---: | ---: |
| Front-page-shaped reads | 241.4 rps | 145.6 rps | 60% |
| REST-read-shaped reads | 886.2 rps | 390.0 rps | 44% |
| REST-write-shaped writes | 2382.5 rps | 248.1 successful rps | 10% |

The write-shaped direct SQL case also produced DuckDB transaction conflicts
when concurrent workers updated the same option row. WordPress-level code can
retry or serialize those hot writes, but the conflict behavior is real.

## Tuning

Run connection settings through `DUCKDB_CONNECTION_SETUP_SQL`:

```php
define( 'DUCKDB_CONNECTION_SETUP_SQL', array(
  'SET threads=2',
) );
```

For an independently managed sidecar process, use the environment form:

```bash
WP_DUCKDB_CONNECTION_SETUP_SQL_JSON='["SET threads=2"]' \
php -d ffi.enable=1 wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php \
  --socket=/run/wp-duckdb/wordpress.sock \
  --path=/var/www/html/wp-content/database/.ht.duckdb
```

In local direct-SQL tuning, `threads=1` or `threads=2` was worth testing for
WordPress request serving. Increasing memory or checkpoint thresholds did not
close the WordPress gap.

| Setting | Front-page SQL with eight workers vs default | REST-read SQL with eight workers vs default | REST-write SQL with eight workers vs default | Write conflicts |
| --- | ---: | ---: | ---: | ---: |
| Default | 1.00x | 1.00x | 1.00x | 83 / 300 |
| `SET threads=1` | 1.15x | 1.14x | 0.99x | 98 / 300 |
| `SET threads=2` | 1.12x | 1.08x | 1.11x | 68 / 300 |
| `SET threads=4` | 1.08x | 1.17x | 1.07x | 83 / 300 |
| `SET preserve_insertion_order=false` | 1.01x | 1.02x | 0.98x | 76 / 300 |
| `SET wal_autocheckpoint='1GB'` | 0.99x | 0.98x | 0.95x | 79 / 300 |

## Raw Evidence

Benchmark summaries and JSONL events remain under `docs/benchmarks/runs/` in
the repository and are published with the docs site under `/benchmarks/`.

- [Latest summary JSON](../../benchmarks/latest.json)
- Repository note: `docs/benchmarks/duckdb-performance-findings.md`
