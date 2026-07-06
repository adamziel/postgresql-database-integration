---
title: DuckDB
description: Configure WordPress to use DuckDB as the database adapter.
---

DuckDB support has two separate configuration axes:

- **Connection mode** controls how WordPress talks to DuckDB: embedded FFI,
  Unix socket, TCP, HTTP, or managed sidecar.
- **Storage backend** controls where WordPress tables are persisted: a native
  DuckDB file, local JSON/CSV/Parquet files, S3-compatible Parquet objects,
  attached databases, or custom DuckDB SQL templates.

Keep those axes separate when you reason about a deployment.

For copy-paste setup commands, start with
[Set Up Each Backend](../../cli-setup/#set-up-each-backend). This page explains
DuckDB-specific choices after you know which setup path you want.

## Choose A DuckDB Path

| If you want... | Read... | Why |
| --- | --- | --- |
| The simplest DuckDB setup. | [Native DuckDB File](#native-duckdb-file) | Stores WordPress in one mutable `.duckdb` file. |
| JSON, CSV, Parquet, S3, attached SQLite, or custom DuckDB SQL templates. | [DuckDB Storage Backends](../duckdb-storage-backends/) | Storage backend controls where WordPress tables persist. |
| Unix socket, TCP, HTTP, embedded FFI, or sidecar mode. | [DuckDB Connection Modes](../duckdb-connection-modes/) | Connection mode controls the process boundary and latency profile. |
| S3-compatible object storage specifically. | [S3-Compatible Storage](../duckdb-s3/) | S3 needs `httpfs`, credentials, explicit table lists, and manifest care. |
| Performance and production tradeoffs. | [Performance](../../guides/performance/) and [Production Readiness](../../guides/production-readiness/) | DuckDB works, but WordPress is a mixed read/write workload, not an analytical scan workload. |

## Native DuckDB File

The simplest DuckDB setup stores WordPress in one DuckDB database file:

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- --engine=duckdb --install-duckdb-client --yes
```

Manual constants:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_FILE', '.ht.duckdb' );
define( 'DUCKDB_PHP_AUTOLOAD', __DIR__ . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php' );
```

## Requirements

Embedded DuckDB mode requires:

- PHP 8.3 or newer;
- PHP `ffi`;
- the `satur.io/duckdb` PHP client;
- the DuckDB C library installed by that client.

Remote connection modes move DuckDB FFI out of the WordPress PHP process, but
the sidecar process still needs the same DuckDB runtime.

## Recommended Runtime Shape

Use embedded FFI for the simplest development setup. Use a long-running Unix
socket sidecar when request latency matters and you can manage a separate local
process.

Read [DuckDB Connection Modes](../duckdb-connection-modes/) for transport details
and [Performance](../../guides/performance/) before treating DuckDB as production
WordPress storage.

## External Storage

DuckDB can hydrate WordPress tables from another storage target, mutate them in
a working DuckDB database, then flush them back on close and shutdown.

Read [DuckDB Storage Backends](../duckdb-storage-backends/) for JSON, CSV, Parquet,
custom templates, attached SQLite/PostgreSQL/MySQL, and lakehouse-style
extension backends.
