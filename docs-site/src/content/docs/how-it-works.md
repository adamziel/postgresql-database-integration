---
title: How It Works
description: Understand the installer, database drop-in, drivers, DuckDB connection modes, and storage backends.
---

WordPress Databases Support is a database drop-in layer. It does not fork
WordPress, replace `wpdb`, or make plugins call a new database API. WordPress
continues to issue MySQL-shaped queries through `wpdb`; this project intercepts
those calls and sends them to the configured backend.

## Request Path

A configured site has a `wp-content/db.php` drop-in. WordPress loads that file
early, before it creates the normal MySQL `wpdb` instance.

The drop-in reads `DB_ENGINE` from `wp-config.php` and creates the matching
driver:

```php
define( 'DB_ENGINE', 'duckdb' ); // sqlite, postgresql, or duckdb.
```

After that point, WordPress core and plugins still call the global `$wpdb`
object. The selected driver receives those queries, handles WordPress-specific
SQL compatibility, runs the query against the backend, and returns results in
the shape WordPress expects.

## What The Installer Changes

The one-command installer has two jobs:

1. Download this plugin into `wp-content/plugins/wordpress-databases-support`.
2. Run the setup command that writes `wp-config.php` constants and installs
   `wp-content/db.php`.

For DuckDB JSON, the command also installs the DuckDB PHP client when you pass
`--install-duckdb-client`:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=json \
  --yes \
  --force
```

If WordPress is not installed yet, the command prepares the database layer so the
normal WordPress installer creates its tables in the selected backend. If
WordPress is already installed, the command changes the database layer for future
requests. It does not copy existing MySQL content into the new backend.

## Backend Drivers

Each backend has a different execution model:

| Backend | Runtime model |
| --- | --- |
| SQLite | Uses the upstream WordPress SQLite integration package. |
| PostgreSQL | Uses this repository's PostgreSQL driver through PHP PDO. |
| DuckDB | Uses this repository's DuckDB driver through embedded FFI or a DuckDB sidecar transport. |

The common contract is WordPress compatibility, not identical database behavior.
Plugins that depend on specific MySQL behavior still need to be tested against
the backend you choose.

## DuckDB Has Two Axes

DuckDB configuration has two independent decisions.

Connection mode controls how WordPress talks to DuckDB:

| Mode | What it means |
| --- | --- |
| Embedded FFI | DuckDB runs inside the WordPress PHP request process. |
| Unix socket | WordPress talks to a long-running local DuckDB sidecar over a Unix socket. |
| TCP socket | WordPress talks to a long-running DuckDB sidecar over TCP. |
| HTTP | WordPress talks to a long-running DuckDB sidecar over HTTP. |
| Managed sidecar | WordPress starts a child DuckDB sidecar process. |

Storage backend controls where tables persist:

| Storage | What it means |
| --- | --- |
| Native DuckDB file | WordPress tables live in one mutable `.duckdb` file. |
| JSON, CSV, or Parquet | Each WordPress table is flushed to a file in that format. |
| S3-compatible Parquet | Tables are flushed to Parquet objects through DuckDB's S3 support. |
| Attached databases or custom templates | DuckDB reads and writes through configured SQL templates. |

You can combine these axes. For example, WordPress can talk to DuckDB over a Unix
socket while DuckDB persists tables as JSON files.

## External Storage Is Hydrate, Mutate, Flush

DuckDB does not mutate JSON, CSV, or Parquet files row by row. The external
storage path works like this:

1. DuckDB hydrates external files or attached tables into mutable working tables.
2. WordPress reads and writes the working tables through the normal `$wpdb` path.
3. The driver flushes each working table back to the configured storage backend.

For the JSON quick start, setup writes constants like these:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'json' );
define( 'DUCKDB_WORKING_DATABASE_FILE', __DIR__ . '/wp-content/database/.ht.duckdb-working' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-json/' );
```

The working database is the mutable runtime database. The external storage
directory is where files such as `wp_options.json`, `wp_posts.json`, and
`wp_users.json` are written.

## Operational Boundaries

This project proves that WordPress can run on these backends. That is different
from saying every backend is a production replacement for MySQL or MariaDB.

The main boundaries are:

- Existing MySQL data migration is not included.
- DuckDB external storage needs a durable metadata manifest for schema and index
  metadata.
- JSON, CSV, Parquet, and object storage are hydrate/flush targets, not
  high-concurrency row stores.
- DuckDB write concurrency needs workload-specific testing.
- Sidecar transports should bind to loopback or a private network.
- Plugins should be tested against the exact backend and connection mode you
  plan to use.

## Where To Go Next

- Use [Installation](installation/) for the shortest setup path.
- Use [CLI Setup](cli-setup/) for repeatable provisioning flags.
- Read [DuckDB Storage Backends](backends/duckdb-storage-backends/) before using
  JSON, CSV, Parquet, S3-compatible storage, attached databases, or custom SQL.
- Read [DuckDB Connection Modes](backends/duckdb-connection-modes/) before
  choosing embedded FFI, Unix socket, TCP, HTTP, or sidecar mode.
- Read [Production Readiness](guides/production-readiness/) and
  [Performance](guides/performance/) before using DuckDB for mutable production
  WordPress storage.
