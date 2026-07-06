---
title: CLI Setup
description: Install the database drop-in and configure a WordPress database backend from a shell command.
---

CLI setup is the repeatable path for scripted WordPress installs, local test
harnesses, and environments where a browser wizard is not appropriate. The
commands on this page use the installer, so they do not require a `git clone`.

Run them from a WordPress root. They work before the WordPress installer runs
and on an already installed site.

`--force` allows setup to update an existing `wp-config.php` or `wp-content/db.php`.
It does not migrate existing MySQL content into the new backend. Test on a copy
before changing a real site.

This page separates three choices:

- Database engine: SQLite, PostgreSQL, or DuckDB.
- DuckDB storage backend: native DuckDB, JSON, CSV, Parquet, S3-compatible
  Parquet, attached SQLite, or a custom DuckDB SQL template.
- DuckDB connection mode: embedded FFI, Unix socket, TCP, HTTP, or managed
  sidecar.

## Start With JSON

Run this from a WordPress root to install the plugin, install the DuckDB PHP
client, configure the drop-in, and store WordPress tables as JSON files:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=json \
  --yes \
  --force
```

DuckDB JSON needs PHP FFI and Composer/network access for `satur.io/duckdb`.
The JSON storage path is useful for local experiments and demos, but should not
be treated as a busy production database.

## Database Engines

### SQLite

Use SQLite when you want a single local database file and no external database
server:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=sqlite \
  --yes \
  --force
```

The command configures `DB_ENGINE=sqlite`, writes the `wp-content/db.php`
drop-in, and uses `wp-content/database/.ht.sqlite` by default. Direct setup
needs the packaged SQLite integration files to be present.

### PostgreSQL

Use PostgreSQL when WordPress should talk directly to a running PostgreSQL
server:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=postgresql \
  --db-name=wordpress \
  --db-user=wordpress \
  --db-password=secret \
  --db-host=127.0.0.1:5432 \
  --yes \
  --force
```

Create the database and user before running the command. PHP also needs PDO and
`pdo_pgsql`.

### DuckDB Native File

Use native DuckDB when WordPress should store data in one mutable DuckDB file:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --yes \
  --force
```

This uses `wp-content/database/.ht.duckdb` by default. It is the simplest DuckDB
setup because it does not hydrate from or flush to an external file format.

## DuckDB Storage Backends

DuckDB storage backends control where WordPress tables are persisted. The local
JSON, CSV, and Parquet presets store one file per WordPress table. On each
request, the plugin hydrates mutable DuckDB tables, lets WordPress query and
write those tables through `wpdb`, and flushes them back on shutdown.

### JSON Files

The [Start With JSON](#start-with-json) command above configures:

```text
wp-content/database/duckdb-json/wp_options.json
wp-content/database/duckdb-json/wp_posts.json
wp-content/database/duckdb-json/wp_postmeta.json
```

Use JSON when you want inspectable files for local development or a small demo.

### CSV Files

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=csv \
  --duckdb-external-storage-dir=wp-content/database/duckdb-csv \
  --yes \
  --force
```

Use CSV when another tool needs table-shaped text files. CSV has weak typing, so
the metadata manifest beside the files is important.

### Parquet Files

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=parquet \
  --duckdb-external-storage-dir=wp-content/database/duckdb-parquet \
  --yes \
  --force
```

Use Parquet when downstream analytics tools should read the WordPress tables.
Parquet is not mutated in place; the plugin rewrites table files on flush.

### Custom File Format

Use a custom backend when DuckDB can read and write the storage with SQL, but it
is not one of the presets. This example stores pipe-delimited `.psv` files:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=pipe_text \
  --duckdb-external-storage-dir=wp-content/database/pipe-text \
  --duckdb-backend-file-extension=psv \
  --duckdb-backend-read-sql="SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')" \
  --duckdb-backend-write-sql="COPY {table} TO {path} (HEADER, DELIMITER '|')" \
  --yes \
  --force
```

`{table}` is replaced with the current WordPress table name. `{path}` is replaced
with the quoted source path for that table.

### S3-Compatible Parquet

Use S3-compatible Parquet when DuckDB should persist table files through
`httpfs` to S3, R2, MinIO, or another S3-compatible service:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=s3_parquet \
  --duckdb-external-storage-dir=s3://example-bucket/wordpress/ \
  --duckdb-backend-file-extension=parquet \
  --duckdb-backend-setup-sql="INSTALL httpfs" \
  --duckdb-backend-setup-sql="LOAD httpfs" \
  --duckdb-backend-setup-sql="CREATE OR REPLACE SECRET wp_s3 (TYPE s3, PROVIDER credential_chain, REGION 'us-east-1', SCOPE 's3://example-bucket/wordpress/')" \
  --duckdb-backend-read-sql="SELECT * FROM read_parquet({path})" \
  --duckdb-backend-write-sql="COPY {table} TO {path} (FORMAT PARQUET, OVERWRITE_OR_IGNORE true)" \
  --duckdb-backend-tables=wp_options,wp_posts,wp_postmeta,wp_terms,wp_term_taxonomy,wp_term_relationships,wp_users,wp_usermeta \
  --duckdb-backend-atomic-flush=0 \
  --yes \
  --force
```

S3 paths cannot be discovered by scanning a local directory, so list every
WordPress and plugin table that should survive a fresh connection. The example
uses DuckDB's credential chain. For MinIO or explicit credentials, see
[S3-Compatible DuckDB Storage](../backends/duckdb-s3/).

### Attached SQLite Through DuckDB

Use attached SQLite when DuckDB should hydrate from and flush to a separate
SQLite database through DuckDB's `sqlite` extension:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=sqlite_attach \
  --duckdb-backend-setup-sql="INSTALL sqlite" \
  --duckdb-backend-setup-sql="LOAD sqlite" \
  --duckdb-backend-setup-sql="ATTACH '$(pwd)/wp-content/database/wordpress.sqlite' AS wp_store (TYPE sqlite)" \
  --duckdb-backend-read-sql="SELECT * FROM wp_store.{table}" \
  --duckdb-backend-write-sql="CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}" \
  --duckdb-backend-tables=wp_options,wp_posts,wp_postmeta,wp_terms,wp_term_taxonomy,wp_term_relationships,wp_users,wp_usermeta \
  --duckdb-metadata-manifest-file=wp-content/database/.wp-duckdb-sqlite_attach-metadata \
  --duckdb-backend-atomic-flush=0 \
  --yes \
  --force
```

Use a dedicated SQLite file. The write template replaces each attached table on
flush.

### Other DuckDB Backends

The CLI is not limited to the presets. Any DuckDB extension or storage system can
be configured when you can provide:

- setup SQL, such as `INSTALL httpfs`, `LOAD httpfs`, or `ATTACH ...`;
- a read SQL template that returns one table;
- a write SQL template that persists one table;
- an explicit table list when the backend cannot be scanned like a local
  directory.

That is how the S3 and attached SQLite examples work.

## DuckDB Connection Modes

Connection mode controls how WordPress talks to DuckDB. Add one of these flag
groups to any DuckDB command above.

Embedded FFI is the default:

```bash
--duckdb-connection=ffi
```

Unix socket uses a separately managed sidecar process on the same machine:

```bash
--duckdb-connection=unix \
--duckdb-socket=/run/wp-duckdb/wordpress.sock
```

Start the matching sidecar separately:

```bash
php -d ffi.enable=1 wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php \
  --socket=/run/wp-duckdb/wordpress.sock \
  --path=wp-content/database/.ht.duckdb
```

TCP uses a sidecar over a TCP socket:

```bash
--duckdb-connection=tcp \
--duckdb-host=127.0.0.1 \
--duckdb-port=9901
```

HTTP uses a sidecar over HTTP:

```bash
--duckdb-connection=http \
--duckdb-url=http://127.0.0.1:9902/query
```

Managed sidecar lets PHP start a child sidecar process:

```bash
--duckdb-connection=sidecar \
--duckdb-sidecar="php -d ffi.enable=1 $(pwd)/wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php --stdio --path=$(pwd)/wp-content/database/.ht.duckdb"
```

For production-like deployments, prefer a separately managed Unix, TCP, or HTTP
sidecar and keep it on loopback or a private network. The test sidecar does not
implement authentication. See [DuckDB Connection Modes](../backends/duckdb-connection-modes/)
for details and performance notes.

## Setup After The Plugin Is Installed

If the plugin is already installed, run the setup script directly with the same
database flags:

```bash
php wp-content/plugins/wordpress-databases-support/bin/setup-database.php \
  --engine=duckdb \
  --duckdb-backend=json \
  --yes \
  --force
```

Or require it in a one-liner:

```bash
php -r "require 'wp-content/plugins/wordpress-databases-support/bin/setup-database.php';" -- \
  --engine=sqlite \
  --yes \
  --force
```

Do not pass installer-only flags such as `--install-duckdb-client` to
`setup-database.php`. Install the DuckDB PHP client first when you use embedded
DuckDB.

## Dry Run First

Add `--dry-run` to any setup command to see which files would be written:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --duckdb-backend=parquet \
  --dry-run \
  --yes
```

## Useful Flags

| Option | Purpose |
| --- | --- |
| `--wp-path=/path/to/wordpress` | WordPress root. Defaults to the current directory. |
| `--plugin-dir=/path/to/plugin` | Plugin root when the setup script cannot infer it. |
| `--engine=sqlite\|postgresql\|duckdb` | Backend to configure. |
| `--force` | Update an existing unmanaged `wp-config.php` or `wp-content/db.php`. |
| `--dry-run` | Show what would be written without changing files. |
| `--strict` | Fail if required PHP extensions or packaged drivers are missing. |
| `--install-duckdb-client` | Installer-only flag that installs `satur.io/duckdb` and the DuckDB C library. |
| `--db-name=wordpress` | PostgreSQL database name. |
| `--db-user=wordpress` | PostgreSQL database user. |
| `--db-password=secret` | PostgreSQL database password. |
| `--db-host=127.0.0.1:5432` | PostgreSQL host and optional port. |
| `--db-dir=wp-content/database` | Local directory for SQLite, native DuckDB, and default DuckDB external storage paths. |
| `--sqlite-file=.ht.sqlite` | SQLite database file name inside `--db-dir`. |
| `--duckdb-file=.ht.duckdb` | Native DuckDB file name inside `--db-dir`. |
| `--duckdb-backend=json\|csv\|parquet\|custom_name` | Store DuckDB tables through an external backend instead of only a native DuckDB file. |
| `--duckdb-external-storage-dir=wp-content/database/duckdb-json` | Directory or URI prefix for DuckDB external table files. |
| `--duckdb-working-database-file=wp-content/database/.ht.duckdb-working` | Mutable DuckDB working database used while hydrating and flushing external storage. |
| `--duckdb-backend-file-extension=psv` | File extension for custom path-based DuckDB backends. |
| `--duckdb-backend-read-sql="SELECT * FROM read_csv_auto({path})"` | Custom SQL relation template used to hydrate one table. |
| `--duckdb-backend-write-sql="COPY {table} TO {path}"` | Custom SQL statement template used to flush one table. |
| `--duckdb-backend-setup-sql="INSTALL httpfs"` | SQL statement to run after connecting. Repeat this flag for multiple statements. |
| `--duckdb-backend-tables=wp_options,wp_posts` | Explicit table list for remote, object, or attached backends that cannot be discovered by scanning local files. |
| `--duckdb-backend-atomic-flush=0` | Disable temporary-file-and-rename flushes for non-local storage. |
| `--duckdb-metadata-manifest-file=wp-content/database/.wp-duckdb-json-metadata` | Local metadata manifest path for schema/index metadata. |
| `--duckdb-connection=ffi\|unix\|tcp\|http\|sidecar` | Configure DuckDB embedded or sidecar connection mode. |
| `--duckdb-socket=/run/wp-duckdb/wordpress.sock` | Unix socket path for `--duckdb-connection=unix`. |
| `--duckdb-host=127.0.0.1` | TCP host for `--duckdb-connection=tcp`. |
| `--duckdb-port=9901` | TCP port for `--duckdb-connection=tcp`. |
| `--duckdb-url=http://127.0.0.1:9902/query` | HTTP endpoint for `--duckdb-connection=http`. |
| `--duckdb-sidecar="php ... duckdb-sidecar.php --stdio --path=..."` | Managed sidecar command for `--duckdb-connection=sidecar`. |
| `--yes` | Accept non-interactive changes. |

See [Installer Options](../reference/installer-options/) and
[Setup CLI Options](../reference/setup-database-options/) for the full
references.
