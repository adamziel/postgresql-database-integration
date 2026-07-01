# WordPress Databases Support

WordPress Databases Support is an experimental WordPress plugin and database
drop-in collection for running WordPress on non-MySQL database backends while
preserving the MySQL-facing `wpdb` API expected by WordPress core and plugins.

Current backends:

- PostgreSQL, using the local PostgreSQL driver.
- DuckDB, using the local DuckDB driver.
- SQLite, routed through the upstream WordPress SQLite Database Integration
  project included as a Git submodule.

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

SQLite:

```php
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
```

`DB_ENGINE` also accepts `postgres`, `pgsql`, `duck`, `sqlite3`, and the
backward-compatible `DATABASE_ENGINE` constant.

## DuckDB External Storage Proof

The DuckDB suite includes a focused proof that Parquet, CSV, and JSON files can
act as durable WordPress table storage through DuckDB:

- It creates WordPress-shaped `wptests_posts`, `wptests_postmeta`, and
  `wptests_options` data.
- It proves read-only file-backed views can serve WordPress-style reads,
  including `SQL_CALC_FOUND_ROWS`, joins, ordering, and autoloaded options.
- It proves mutable storage by hydrating real DuckDB tables from Parquet, CSV,
  and JSON files, mutating them through `WP_DuckDB_Driver`, flushing them back
  to the external files with `COPY`, and reloading fresh DuckDB connections to
  verify the mutations persisted.

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

The external-format test class is
`tests/duckdb/WP_DuckDB_External_Format_Backend_Tests.php`.

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

## CI

GitHub Actions workflow: `.github/workflows/ci.yml`.

The workflow runs on `trunk` and pull requests. It validates Composer metadata,
lints root-owned PHP files, runs the PostgreSQL PHPUnit suite and smoke test
against PostgreSQL 16, runs the DuckDB PHPUnit suite with the native DuckDB PHP
client, and keeps the existing WordPress core PostgreSQL PHPUnit job.

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
- SQLite support is routed to the upstream submodule rather than imported as
  root-owned source.
- Full WordPress E2E coverage for DuckDB and SQLite is not included yet.
