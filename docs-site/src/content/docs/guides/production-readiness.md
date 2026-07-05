---
title: Production Readiness
description: Understand what is proven, what is risky, and what to test before using these backends for a real site.
---

This project proves that WordPress can run against multiple non-MySQL backends.
That is not the same as proving every backend is a production replacement for
MySQL/MariaDB on a busy mutable site.

## Current Reading

| Backend shape | Production reading |
| --- | --- |
| SQLite | The simplest file-backed path. Validate the upstream SQLite integration behavior for your plugin set. |
| PostgreSQL | The most conventional server-backed non-MySQL path in this repository. |
| DuckDB native sidecar | Viable for experiments and controlled deployments, but concurrency and write conflicts need workload-specific testing. |
| DuckDB JSON/CSV/Parquet | Good for demos, inspection, interchange, and proofs. Not a high-throughput WordPress request storage path. |
| DuckDB S3 Parquet | Proven against MinIO in CI, but object-storage latency and manifest durability are operational concerns. |
| DuckDB attached databases | Proof that DuckDB can hydrate and flush attached stores. Use isolated databases because simple examples replace tables. |

## Readiness Harness

Run the structured DuckDB readiness harness when you want evidence that can be
aggregated into later reports:

```bash
WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run readiness-duckdb
```

Each run writes one directory under
`artifacts/duckdb-production-readiness/runs/` with:

- `events.jsonl`: one JSON object per scenario;
- `summary.json`: aggregate status/readiness counts, environment details, and artifact paths;
- `wordpress-smoke-native-duckdb.log`: full smoke output when the WordPress smoke scenario runs.

The harness records native DuckDB backup/restore, external JSON backup/restore,
plugin-created table discovery, missing-manifest behavior, simulated flush
failure atomicity, serialized multi-process writes, bounded large-table
workloads, external JSON schema upgrade/cold reload, and native DuckDB WordPress
smoke.

## Operational Risks To Test

- Existing MySQL data migration is not included.
- DuckDB external storage needs a durable metadata manifest.
- External storage hydrate/flush is not the same as direct row-level OLTP writes.
- Concurrent writes to hot rows can conflict in DuckDB.
- Sidecar transports must be bound to loopback or a private network.
- Plugins that depend on immediate cross-connection visibility should be tested
  before enabling `WP_DUCKDB_REQUEST_TRANSACTION=1`.
