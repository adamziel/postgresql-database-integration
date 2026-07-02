# WordPress Databases Support

WordPress Databases Support is an experimental WordPress plugin and database
drop-in collection for running WordPress on non-MySQL database backends while
preserving the MySQL-facing `wpdb` API expected by WordPress core and plugins.

Current backends:

- PostgreSQL, using the local PostgreSQL driver.
- DuckDB, using the local DuckDB driver.
- SQLite, routed through the upstream WordPress SQLite Database Integration
  project included as a Git submodule.

## Quick Start: WordPress On DuckDB JSON

Try the DuckDB backend without setting up MySQL, PostgreSQL, PHP extensions, or
WordPress by hand. This Docker example starts WordPress with `DB_ENGINE=duckdb`
and `DUCKDB_BACKEND=json`, then stores each WordPress table as a JSON file.

```bash
git clone --recurse-submodules https://github.com/adamziel/wordpress-databases-support.git
cd wordpress-databases-support/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080` and log in with `admin` / `password`.

The JSON table files are written under:

```text
examples/duckdb-json-wordpress/data/duckdb-json/
```

See [examples/duckdb-json-wordpress/README.md](examples/duckdb-json-wordpress/README.md)
for port, admin-account, and reset options.

## Requirements

- PHP 7.2 or newer for the plugin shell and PostgreSQL/SQLite drivers.
- PHP `pdo`.
- PostgreSQL: PHP `pdo_pgsql` and a PostgreSQL server.
- DuckDB: PHP 8.3 or newer, PHP `ffi`, and the `satur.io/duckdb` PHP client.
- SQLite: PHP `pdo_sqlite`.

## Installation

Clone with submodules so SQLite support is available:

```bash
git clone --recurse-submodules https://github.com/adamziel/wordpress-databases-support.git
```

Place the repository at:

```text
wp-content/plugins/wordpress-databases-support
```

Install the database drop-in:

```bash
cp wp-content/plugins/wordpress-databases-support/db.copy wp-content/db.php
```

Configure one backend in `wp-config.php`.

PostgreSQL:

```php
define( 'DB_ENGINE', 'postgresql' );
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', '127.0.0.1:5432' );
```

DuckDB:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_FILE', '.ht.duckdb' );
define( 'DUCKDB_PHP_AUTOLOAD', __DIR__ . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php' );
```

### DuckDB Backend Setup

DuckDB can use many data sources, including file formats, network protocols,
object storage, lakehouse formats, and attached database systems. See DuckDB's
[data sources](https://duckdb.org/docs/current/data/data_sources) and
[extensions](https://duckdb.org/docs/current/extensions/overview) docs for the
full current surface.

This repository's automated WordPress-site smoke currently proves these DuckDB
storage backends with WordPress 7.0, WooCommerce, and Query Monitor:

| Backend | Status | Notes |
| --- | --- | --- |
| Native DuckDB file | Proven in CI | One mutable DuckDB database file. |
| Local JSON files | Proven in CI | One JSON file per WordPress table. |
| Local CSV files | Proven in CI | One CSV file per WordPress table. |
| Local Parquet files | Proven in CI | One Parquet file per WordPress table. |
| Custom file templates | Proven in CI | The CI smoke uses pipe-delimited `.psv` files through custom read/write SQL. |
| Attached SQLite via DuckDB | Proven in CI | DuckDB hydrates from and flushes to an attached SQLite database file. |
| S3-compatible object storage | Configurable, not CI-proven yet | Requires `httpfs`, writable S3 credentials, a table source strategy, and backend-specific testing. |
| Attached PostgreSQL/MySQL via DuckDB | Configurable, not CI-proven yet | Prefer the native PostgreSQL backend when PostgreSQL should be the actual WordPress database. |
| Lakehouse/extension backends | Configurable, not CI-proven yet | Only usable when the extension can fully hydrate and flush every WordPress table. |

This plugin supports two DuckDB backend modes:

- Native DuckDB file storage: use `DUCKDB_BACKEND` unset, `duckdb`, `duck`,
  `native`, or `file`.
- External storage: hydrate WordPress tables into a mutable working DuckDB file,
  let WordPress write through the normal `wpdb` path, then flush tables back
  through DuckDB SQL on close and shutdown.

External storage is only a usable WordPress backend when DuckDB can both read
the source and write a complete table back to it. Plain HTTP(S) files are useful
for reads, but DuckDB documents them as read-only through `httpfs`; S3-compatible
object storage supports reads and writes through the S3 API.

Common constants:

| Constant | Purpose |
| --- | --- |
| `DUCKDB_BACKEND` | Backend name. Built-in presets: `parquet`, `csv`, `json`. Any other name uses custom SQL templates. |
| `DUCKDB_EXTERNAL_STORAGE_DIR` | Directory or URI prefix that stores one file per WordPress table, such as `wp_options.parquet`. |
| `DUCKDB_WORKING_DATABASE_FILE` | Mutable DuckDB working database. Defaults to `FQDUCKDB`; keep this even for external backends. |
| `DUCKDB_BACKEND_FILE_EXTENSION` | File extension for path-based custom backends, such as `psv` or `parquet`. |
| `DUCKDB_BACKEND_READ_SQL` | SQL relation template used to hydrate one local working table. |
| `DUCKDB_BACKEND_WRITE_SQL` | SQL statement template used to flush one local working table. |
| `DUCKDB_BACKEND_SETUP_SQL` | SQL statements to run after connecting, such as `INSTALL`, `LOAD`, `CREATE SECRET`, or `ATTACH`. |
| `DUCKDB_BACKEND_TABLES` | Explicit table list. Use this for remote/object/attached backends that PHP cannot discover by scanning a local directory. |
| `DUCKDB_BACKEND_ATOMIC_FLUSH` | Whether to write local path-based output to a temporary file first. Defaults to on for local paths and off for URIs. |

SQL templates support these placeholders:

| Placeholder | Replaced with |
| --- | --- |
| `{table}` or `{table_identifier}` | The local DuckDB table as a quoted identifier, for example `"wp_options"`. |
| `{table_name}` | The table name as a quoted string literal, for example `'wp_options'`. |
| `{path}` or `{source}` | The per-table path/URI as a quoted string literal, for example `'s3://bucket/wp/wp_options.parquet'`. |

#### Native DuckDB File

Use this when you want the simplest setup: one DuckDB database file under
`wp-content/database/`.

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_FILE', '.ht.duckdb' );
define( 'DUCKDB_PHP_AUTOLOAD', __DIR__ . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php' );
```

#### Local Parquet, CSV, Or JSON Files

Use a built-in preset when each WordPress table should be stored as one local
file. The directory can start empty for a fresh install; existing files are
hydrated when WordPress connects.

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_BACKEND', 'parquet' ); // Also accepts csv or json.
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-parquet/' );
define( 'DUCKDB_WORKING_DATABASE_FILE', __DIR__ . '/wp-content/database/.ht.duckdb-working' );
define( 'DUCKDB_PHP_AUTOLOAD', __DIR__ . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php' );
```

That produces files such as:

```text
wp-content/database/duckdb-parquet/wp_options.parquet
wp-content/database/duckdb-parquet/wp_posts.parquet
wp-content/database/duckdb-parquet/wp_postmeta.parquet
```

Use a matching directory name for CSV or JSON:

```php
define( 'DUCKDB_BACKEND', 'csv' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-csv/' );
```

```php
define( 'DUCKDB_BACKEND', 'json' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-json/' );
```

#### Custom File Format Or COPY Options

Use custom templates when DuckDB can read and write the storage with SQL but the
format is not one of the presets. DuckDB's
[COPY statement](https://duckdb.org/docs/lts/sql/statements/copy) selects common
formats by extension and can gain more copy functions through extensions. This
example stores pipe-delimited files with a `.psv` extension.

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'pipe_text' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/pipe-text/' );
define( 'DUCKDB_BACKEND_FILE_EXTENSION', 'psv' );
define( 'DUCKDB_BACKEND_READ_SQL', "SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')" );
define( 'DUCKDB_BACKEND_WRITE_SQL', "COPY {table} TO {path} (HEADER, DELIMITER '|')" );
```

#### S3, Cloudflare R2, MinIO, Or Other S3-Compatible Storage

Use [`httpfs`](https://duckdb.org/docs/current/core_extensions/httpfs/overview)
for S3-compatible object storage. DuckDB's
[S3 API support](https://duckdb.org/docs/lts/core_extensions/httpfs/s3api)
handles reading, writing, and globbing files. PHP cannot scan an `s3://` prefix
like a local directory, so provide the WordPress table list.

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 's3_parquet' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', 's3://example-bucket/wordpress/' );
define( 'DUCKDB_BACKEND_FILE_EXTENSION', 'parquet' );
define( 'DUCKDB_BACKEND_SETUP_SQL', array(
	'INSTALL httpfs',
	'LOAD httpfs',
	"CREATE OR REPLACE SECRET wp_s3 (
		TYPE s3,
		PROVIDER credential_chain,
		REGION 'us-east-1',
		SCOPE 's3://example-bucket/wordpress/'
	)",
) );
define( 'DUCKDB_BACKEND_TABLES', array(
	'wp_options',
	'wp_posts',
	'wp_postmeta',
	'wp_terms',
	'wp_term_taxonomy',
	'wp_term_relationships',
	'wp_users',
	'wp_usermeta',
) );
define( 'DUCKDB_BACKEND_READ_SQL', 'SELECT * FROM read_parquet({path})' );
define( 'DUCKDB_BACKEND_WRITE_SQL', 'COPY {table} TO {path} (FORMAT PARQUET)' );
define( 'DUCKDB_BACKEND_ATOMIC_FLUSH', false );
```

The table list above is intentionally short. Add every WordPress and plugin table
that should survive a fresh connection; tables that are not listed cannot be
hydrated when PHP cannot scan the backend and the persistent working DuckDB file
does not already know about them.

For public HTTPS files, DuckDB can read files through `httpfs`, but regular
HTTP(S) does not provide a write API. Do not use plain HTTPS as the only mutable
WordPress storage backend unless your `DUCKDB_BACKEND_WRITE_SQL` writes to a
different writable target.

#### Attached SQLite Database

Use DuckDB's [SQLite extension](https://duckdb.org/docs/current/core_extensions/sqlite)
when you want DuckDB to hydrate from and flush to a SQLite database file. The
write template below replaces each remote table on flush; use a dedicated SQLite
database, not an unrelated production file.

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'sqlite_attach' );
define( 'DUCKDB_BACKEND_SETUP_SQL', array(
	'INSTALL sqlite',
	'LOAD sqlite',
	"ATTACH '" . __DIR__ . "/wp-content/database/wordpress.sqlite' AS wp_store (TYPE sqlite)",
) );
define( 'DUCKDB_BACKEND_TABLES', array( 'wp_options', 'wp_posts', 'wp_postmeta' ) );
define( 'DUCKDB_BACKEND_READ_SQL', 'SELECT * FROM wp_store.{table}' );
define( 'DUCKDB_BACKEND_WRITE_SQL', 'CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}' );
define( 'DUCKDB_BACKEND_ATOMIC_FLUSH', false );
```

DuckDB's SQLite extension can read and write attached SQLite files, but SQLite
and DuckDB have different typing rules. Test your schema before using this for
real WordPress data.

#### Attached PostgreSQL Or MySQL Database

DuckDB's [PostgreSQL](https://duckdb.org/docs/current/core_extensions/postgres/overview)
and [MySQL](https://duckdb.org/docs/lts/core_extensions/mysql) extensions can
attach running database servers and read/write through DuckDB SQL. Use this
pattern only with an isolated schema or database because the example flush
strategy replaces tables.

The examples below are full-table replacement recipes. They are not incremental
replication and should not point at unrelated production tables.

PostgreSQL:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'postgres_attach' );
define( 'DUCKDB_BACKEND_SETUP_SQL', array(
	'INSTALL postgres',
	'LOAD postgres',
	"ATTACH 'host=127.0.0.1 port=5432 dbname=wordpress_duck user=wordpress password=secret' AS wp_store (TYPE postgres, SCHEMA 'public')",
) );
define( 'DUCKDB_BACKEND_TABLES', array( 'wp_options', 'wp_posts', 'wp_postmeta' ) );
define( 'DUCKDB_BACKEND_READ_SQL', 'SELECT * FROM wp_store.{table}' );
define( 'DUCKDB_BACKEND_WRITE_SQL', 'CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}' );
define( 'DUCKDB_BACKEND_ATOMIC_FLUSH', false );
```

MySQL:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'mysql_attach' );
define( 'DUCKDB_BACKEND_SETUP_SQL', array(
	'INSTALL mysql',
	'LOAD mysql',
	"ATTACH 'host=127.0.0.1 user=wordpress password=secret database=wordpress_duck' AS wp_store (TYPE mysql)",
) );
define( 'DUCKDB_BACKEND_TABLES', array( 'wp_options', 'wp_posts', 'wp_postmeta' ) );
define( 'DUCKDB_BACKEND_READ_SQL', 'SELECT * FROM wp_store.{table}' );
define( 'DUCKDB_BACKEND_WRITE_SQL', 'CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}' );
define( 'DUCKDB_BACKEND_ATOMIC_FLUSH', false );
```

If you want PostgreSQL as the actual WordPress database, prefer the native
`DB_ENGINE=postgresql` backend instead of routing WordPress through DuckDB and
then through DuckDB's PostgreSQL extension.

#### Lakehouse And Extension Backends

DuckDB supports [lakehouse formats](https://duckdb.org/docs/lts/lakehouse_formats)
such as Delta, Iceberg, Lance, and DuckLake through extensions. Configure them
with the same constants: load the required extension, list the WordPress tables,
and provide templates that fully hydrate and flush one table.

Do not copy a generic lakehouse block without checking the extension's write
support. Some formats expose full reads but only limited writes. For WordPress
storage, the write template must be able to replace or otherwise faithfully
synchronize each table.

SQLite:

```php
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
```

`DB_ENGINE` also accepts `postgres`, `pgsql`, `duck`, `sqlite3`, and the
backward-compatible `DATABASE_ENGINE` constant.

## DuckDB External Storage Backends

The DuckDB suite includes focused proof that Parquet, CSV, JSON, and custom
DuckDB SQL-template backends can act as durable WordPress table storage through
the configured backend:

- It creates WordPress-shaped `wptests_posts`, `wptests_postmeta`, and
  `wptests_options` data.
- It proves read-only file-backed views can serve WordPress-style reads,
  including `SQL_CALC_FOUND_ROWS`, joins, ordering, and autoloaded options.
- It proves mutable storage by hydrating real DuckDB tables from Parquet, CSV,
  and JSON files, mutating them through `WP_DuckDB_Driver`, flushing them back
  to the external files with `COPY`, and reloading fresh DuckDB connections to
  verify the mutations persisted.
- It proves `DUCKDB_BACKEND`-style configuration by connecting through
  `WP_DuckDB_Storage_Backend`, mutating WordPress tables and a custom table,
  flushing the configured backend, and reconnecting from the same external
  files.
- It proves an arbitrary custom backend name using custom DuckDB read/write
  templates, so support is not limited to the built-in presets.

This is a mutable WordPress storage model backed by external files. It is not
in-place mutation of Parquet/CSV/JSON scan functions.

Run the proof locally:

```bash
composer install
composer config --no-plugins allow-plugins.satur.io/duckdb-auto true
composer require --dev --no-plugins --with-all-dependencies satur.io/duckdb-auto
composer dump-autoload
./vendor/bin/install-c-lib

WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run test-duckdb
```

The external-format and configured-backend test classes are
`tests/duckdb/WP_DuckDB_External_Format_Backend_Tests.php` and
`tests/duckdb/WP_DuckDB_Storage_Backend_Tests.php`.

For a runnable WordPress site using DuckDB JSON storage, start with the
[Docker quick start](#quick-start-wordpress-on-duckdb-json).

## Development

Install Composer metadata and run local checks:

```bash
composer validate --strict --no-check-publish
composer install
composer run lint
```

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

Run the DuckDB suite:

```bash
WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run test-duckdb
```

Run the focused WordPress core DB tests against DuckDB:

```bash
git clone https://github.com/WordPress/wordpress-develop.git ../wordpress
( cd ../wordpress && git checkout 6.7.2 && composer update -W --no-interaction --no-progress --prefer-dist )
./bin/prepare-wordpress-core-duckdb-tests.sh ../wordpress "$PWD" duckdb
( cd ../wordpress && php -d ffi.enable=1 ./vendor/bin/phpunit --configuration phpunit.xml.dist --filter '^Tests_DB' )
```

The DuckDB core harness installs an isolated production DuckDB PHP runtime under
the copied plugin in the WordPress checkout. This avoids loading this
repository's dev dependencies into WordPress core's PHPUnit process.

Run a disposable WordPress site with WooCommerce and Query Monitor against the
verifiable DuckDB backends:

```bash
WORDPRESS_VERSION=7.0 \
WOOCOMMERCE_VERSION=10.9.1 \
QUERY_MONITOR_VERSION=4.0.7 \
./bin/duckdb-wordpress-plugin-smoke.sh duckdb json csv parquet
```

Run the same smoke against a custom file backend:

```bash
WORDPRESS_VERSION=7.0 \
WOOCOMMERCE_VERSION=10.9.1 \
QUERY_MONITOR_VERSION=4.0.7 \
WP_DUCKDB_BACKEND_FILE_EXTENSION=psv \
WP_DUCKDB_BACKEND_READ_SQL="SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')" \
WP_DUCKDB_BACKEND_WRITE_SQL="COPY {table} TO {path} (HEADER, DELIMITER '|')" \
./bin/duckdb-wordpress-plugin-smoke.sh pipe_text
```

Run it against DuckDB's SQLite extension:

```bash
WORDPRESS_VERSION=7.0 \
WOOCOMMERCE_VERSION=10.9.1 \
QUERY_MONITOR_VERSION=4.0.7 \
WP_DUCKDB_BACKEND_SETUP_SQL_JSON='["INSTALL sqlite","LOAD sqlite","ATTACH '\''{database_dir}/wordpress.sqlite'\'' AS wp_store (TYPE sqlite)"]' \
WP_DUCKDB_BACKEND_READ_SQL='SELECT * FROM wp_store.{table}' \
WP_DUCKDB_BACKEND_WRITE_SQL='CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}' \
WP_DUCKDB_BACKEND_ATOMIC_FLUSH=0 \
./bin/duckdb-wordpress-plugin-smoke.sh sqlite_attach
```

The smoke installs WordPress, activates WooCommerce and Query Monitor, creates a
simple WooCommerce product, verifies WooCommerce custom tables, performs HTTP
requests against the front page, login page, and product archive, and fails if
WordPress logs DuckDB/database errors. Custom backend setup SQL can use
`{root}`, `{database_dir}`, `{backend}`, and `{backend_slug}` placeholders in
this smoke harness.

## CI

GitHub Actions workflow: `.github/workflows/ci.yml`.

The workflow runs on `trunk` and pull requests. It validates Composer metadata,
lints root-owned PHP files, runs the PostgreSQL PHPUnit suite and smoke test
against PostgreSQL 16, runs the DuckDB PHPUnit suite with the native DuckDB PHP
client, keeps the existing WordPress core PostgreSQL PHPUnit job, and runs the
focused WordPress core DB test class against DuckDB. It also runs the
WooCommerce and Query Monitor smoke matrix against native DuckDB, JSON, CSV, and
Parquet storage, a custom pipe-delimited file backend, and DuckDB's attached
SQLite extension.

An experimental full WordPress core PHPUnit job also runs against DuckDB. That
job is capped at 30 minutes and uploads the full PHPUnit log plus JUnit output
for compatibility discovery; it is not yet a passing support gate.

## Releases

Build a plugin zip:

```bash
git submodule update --init --recursive
./bin/build-plugin-zip.sh
```

The release workflow builds `build/wordpress-databases-support.zip` on manual
runs and publishes that zip to GitHub Releases for tags matching `v*`.

## Current Limitations

- Existing MySQL databases are not migrated.
- DuckDB support requires a separately installed PHP client and native library.
- DuckDB external file storage currently uses an explicit hydrate/mutate/flush
  cycle for Parquet, CSV, and JSON.
- External DuckDB storage depends on the persistent working DuckDB database for
  schema metadata and table discovery. The external files or attached database
  hold table data, but the working database is still part of the backend state.
- SQLite support is routed to the upstream submodule rather than imported as
  root-owned source.
- Full browser/editor E2E coverage for DuckDB and SQLite is not included yet.
