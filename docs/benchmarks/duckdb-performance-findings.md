# DuckDB WordPress Performance Findings

This note summarizes the benchmark evidence behind the DuckDB performance PR.
The raw benchmark events are stored under `docs/benchmarks/runs/`.

## Conclusion

DuckDB itself is not the slow part. DuckDB native through PHP FFI is not
SQLite-parity for WordPress request latency because WordPress opens a fresh PHP
DuckDB client during request handling. The practical parity path is DuckDB
sidecar plus request-scoped write transactions.

For the current write-heavy WordPress workload, DuckDB sidecar with request
transactions matches SQLite write latency:

| backend | mode | REST write p50 | REST write p95 | REST write rps | errors |
| --- | --- | ---: | ---: | ---: | ---: |
| SQLite | native | 33.499 ms | 55.367 ms | 27.378 | 0 |
| DuckDB | sidecar, request tx | 35.722 ms | 41.525 ms | 27.378 | 0 |
| DuckDB | sidecar, request tx, cache disabled | 37.821 ms | 42.624 ms | 26.458 | 0 |
| DuckDB | native PHP FFI, request tx | 107.084 ms | 157.303 ms | 9.195 | 0 |

## What Is Slow

There are two distinct costs:

1. Native PHP DuckDB has high per-request overhead. In the current traced
   WordPress HTTP run, 13 requests opened DuckDB 13 times. `open_end` had p50
   15.073 ms, p95 21.203 ms, and 259.534 ms total in the benchmark process.
   A CLI micro-profile shows the first native open at 72.587 ms, repeated opens
   in one process at p50 8.126 ms, and reused native `SELECT 1` at p50 0.245 ms.
   The sidecar removes the native open from each PHP request.
2. File-backed DuckDB small writes are expensive when WordPress issues several
   tiny durable writes. Request-scoped transactions batch those writes and reduce
   the number of durable commits per request.

The sidecar addresses the first cost. `WP_DUCKDB_REQUEST_TRANSACTION=1` addresses
the second cost. Using only one of them is not enough for WordPress parity.
The sidecar cache is not required for write parity in this workload: disabling
it still produced p50 37.821 ms and p95 42.624 ms.

## Evidence

Current comparison runs used WordPress 7.0, PHP 8.5.7, concurrency 1, 5 read
requests per read scenario, and 50 REST write requests.

| run | purpose |
| --- | --- |
| `wp-duckdb-current-parity-check` | Current SQLite/native DuckDB/sidecar comparison |
| `wp-duckdb-current-sidecar-nocache` | Sidecar with exact SELECT cache disabled |
| `wp-duckdb-current-native-trace` | Native DuckDB trace with `WP_DUCKDB_TRACE_NATIVE_QUERIES=1` |

Raw files:

- `docs/benchmarks/runs/wp-duckdb-current-parity-check/events.jsonl`
- `docs/benchmarks/runs/wp-duckdb-current-sidecar-nocache/events.jsonl`
- `docs/benchmarks/runs/wp-duckdb-current-native-trace/events.jsonl`
- `docs/benchmarks/duckdb-native-trace-summary.json`

## PR Direction

Ship the sidecar path as the recommended production-like DuckDB runtime. The
sidecar keeps the native DuckDB handle open across PHP requests, which removes
the native-open cost that blocks SQLite parity. Keep native PHP FFI as a
compatibility/development path, not the WordPress request-latency target.

Keep request-scoped transactions opt-in with `WP_DUCKDB_REQUEST_TRANSACTION=1`.
They improve write-heavy sidecar latency, but they intentionally change
cross-connection visibility: writes are visible within the current request and
commit at close, shutdown, or external-storage flush.

## Verification

Current regression suites:

```sh
composer run test-duckdb-parity -- --display-warnings
composer run test-duckdb -- --display-warnings
```

Current result:

```text
DuckDB parity: OK, but some tests were skipped! Tests: 611, Assertions: 8577, Skipped: 1.
DuckDB suite: OK, but some tests were skipped! Tests: 118, Assertions: 745, Skipped: 3.
```
