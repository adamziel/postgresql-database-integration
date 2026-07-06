---
title: How It Works
description: Understand the WordPress database drop-in, wpdb replacement classes, SQL compatibility layer, plugin compatibility limits, and DuckDB storage model.
---

WordPress Databases Support works because WordPress has an official escape hatch
for replacing the database layer: the `wp-content/db.php` drop-in.

That drop-in is loaded during WordPress bootstrap, before core creates the normal
MySQL `wpdb` object. This project installs its own drop-in, creates a compatible
`$wpdb` implementation for the selected backend, and translates the MySQL-shaped
SQL that WordPress still emits.

The important point is that WordPress core, themes, and plugins do not learn a
new database API. They keep calling WordPress APIs and `$wpdb`. The compatibility
work happens underneath that surface.

## Request Path

When a configured site receives a request, the database path looks like this:

1. `wp-config.php` defines the selected engine.
2. WordPress sees `wp-content/db.php` and loads it instead of constructing the
   built-in MySQL `wpdb` instance directly.
3. The drop-in normalizes `DB_ENGINE` and loads this plugin's dispatcher.
4. The dispatcher loads the selected backend bootstrap file.
5. The backend creates `$GLOBALS['wpdb']` as a WordPress-compatible database
   object.
6. WordPress core and plugins keep using `get_option()`, `WP_Query`, `$wpdb`,
   `dbDelta()`, and the rest of the normal WordPress database surface.

The selected engine starts with one constant:

```php
define( 'DB_ENGINE', 'duckdb' ); // sqlite, postgresql, or duckdb.
```

This repository includes backend bootstraps for PostgreSQL and DuckDB. SQLite is
handled by the upstream WordPress SQLite integration package that this project
vendors and installs.

## Why This Is Possible

Most WordPress database access goes through `wpdb` or higher-level APIs that call
`wpdb`. That makes `wpdb` the compatibility boundary.

The built-in `wpdb` class talks to MySQL or MariaDB. This project provides
backend-specific classes that preserve the WordPress-facing behavior while
changing what happens below it:

| Backend | WordPress-facing object | What happens underneath |
| --- | --- | --- |
| SQLite | Upstream SQLite drop-in | The upstream SQLite integration translates and executes queries against SQLite. |
| PostgreSQL | `WP_PostgreSQL_DB` | A `wpdb`-compatible class uses PDO and a PostgreSQL driver. |
| DuckDB | `WP_DuckDB_DB` | A `wpdb`-compatible class uses a DuckDB driver and either embedded or remote DuckDB connections. |

The replacement classes preserve the details WordPress expects from `wpdb`, not
just the `query()` method. That includes result shapes, `insert_id`,
`rows_affected`, error reporting, charset and collation behavior, capability
checks, table metadata, and the handful of MySQL behaviors that WordPress probes
at runtime.

The database engine still receives SQL. The difference is that the SQL is parsed,
validated, adapted where possible, and then executed against the chosen backend.

## What The Plugin Provides

The installed plugin is the code library. The `wp-content/db.php` drop-in is the
entry point WordPress actually loads during bootstrap.

That matters because a database drop-in runs earlier than normal active plugins.
The site can use a different database before WordPress has loaded the plugin
list, options table, current theme, or regular plugin hooks.

The repository provides:

- the drop-in template copied into `wp-content/db.php`
- backend bootstraps for SQLite, PostgreSQL, and DuckDB
- `wpdb`-compatible database classes
- SQL compatibility drivers and metadata handling
- setup commands and the one-command installer
- DuckDB connection transports and storage backend support

Only one database drop-in can own `wp-content/db.php`. Another plugin that needs
its own database drop-in must be integrated deliberately; two independent
drop-ins cannot both control the same file.

## What Gets Translated

WordPress and many plugins emit MySQL-flavored SQL. DuckDB and PostgreSQL do not
accept all of that syntax directly, so this project has backend drivers that
understand the subset WordPress needs.

For DuckDB, the driver tokenizes and validates incoming MySQL-shaped SQL before
dispatching it. It handles common WordPress statements such as:

- `SELECT`, `INSERT`, `REPLACE`, `UPDATE`, and `DELETE`
- `CREATE TABLE`, `ALTER TABLE`, `DROP TABLE`, and `TRUNCATE`
- `SHOW`, `DESCRIBE`, and metadata queries WordPress expects from MySQL
- `ON DUPLICATE KEY UPDATE` patterns used by options, transients, and caches
- auto-increment behavior and `insert_id`
- index, primary key, unique key, charset, and collation metadata
- limited transaction and lock statements that WordPress or plugins may issue

Unsupported SQL should fail loudly. Silent partial compatibility is dangerous for
a database layer because it can corrupt data while appearing to work.

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

## Can It Work With Plugins?

Yes, but compatibility depends on how the plugin talks to the database.

Plugins are most likely to work when they use standard WordPress APIs:

- options and transients through `get_option()`, `update_option()`, and related APIs
- content queries through `WP_Query`, taxonomy APIs, user APIs, and metadata APIs
- custom tables through `$wpdb`, `dbDelta()`, and normal WordPress schema patterns
- prepared statements through `$wpdb->prepare()`

Plugins are riskier when they bypass WordPress or depend on MySQL-specific
behavior:

- direct `mysqli_*` calls, direct MySQL PDO connections, or shelling out to the
  `mysql` client
- hard requirements for MySQL server variables, storage engines, procedures,
  triggers, events, spatial indexes, full-text indexes, or InnoDB locking
- SQL that relies on MySQL syntax outside the supported compatibility subset
- installing their own `wp-content/db.php` drop-in
- assuming the database speaks the MySQL wire protocol

The practical rule is simple: test the exact plugin set against the backend you
plan to run. A plugin that sticks to WordPress APIs has a much better chance of
working than a plugin that treats MySQL as part of its public runtime.

## Does It Migrate Existing Data?

No. The setup command configures the database layer; it is not a MySQL migration
tool.

For a new site, WordPress creates its tables in the selected backend during the
normal installer flow. For an existing site, you need a separate export/import or
migration process if you want to move existing MySQL or MariaDB content into a
new backend.

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

DuckDB configuration has two independent decisions: how WordPress connects to
DuckDB, and where DuckDB persists WordPress tables.

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

Connection mode affects process boundaries and performance. Storage backend
affects durability, portability, and write behavior. They are separate choices.

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

This is why JSON, CSV, Parquet, and S3-compatible object storage are useful for
portable datasets and experiments, but they should not be treated like
high-concurrency row stores.

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

For production decisions, read the performance and production-readiness guides
before choosing DuckDB as mutable WordPress storage. DuckDB is excellent at
analytical workloads and portable local data. A normal WordPress site is a
small-row, mixed read/write, concurrency-sensitive workload, so the tradeoffs are
different from MySQL or MariaDB.

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
