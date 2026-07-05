---
title: DuckDB Storage Backends
description: Store WordPress tables through DuckDB native files, local formats, object storage, attached databases, and custom SQL templates.
---

DuckDB storage backend mode says where WordPress tables are persisted. It is
separate from [connection mode](../duckdb-connection-modes/).

This repository's WordPress-site smoke tests currently prove these storage
targets with WordPress, WooCommerce, and Query Monitor:

| Backend | Status | Notes |
| --- | --- | --- |
| Native DuckDB file | Proven in CI | One mutable DuckDB database file. |
| Local JSON files | Proven in CI | One JSON file per WordPress table. |
| Local CSV files | Proven in CI | One CSV file per WordPress table. |
| Local Parquet files | Proven in CI | One Parquet file per WordPress table. |
| Custom file templates | Proven in CI | CI uses pipe-delimited `.psv` files through custom SQL. |
| Attached SQLite via DuckDB | Proven in CI | DuckDB hydrates from and flushes to an attached SQLite database file. |
| S3-compatible Parquet object storage | Proven in CI against MinIO | Uses `httpfs`, S3 secrets, `s3://` paths, and a local metadata manifest. |
| Attached PostgreSQL/MySQL via DuckDB | Configurable, not CI-proven yet | Prefer the native PostgreSQL backend when PostgreSQL should be the actual WordPress database. |
| Lakehouse/extension backends | Configurable, not CI-proven yet | Usable only when the extension can fully hydrate and flush every WordPress table. |

## External Storage Model

External storage is a hydrate/mutate/flush model:

1. DuckDB reads external table files or attached tables.
2. The plugin creates mutable working DuckDB tables.
3. WordPress writes through the normal `wpdb` path.
4. The plugin flushes each table back through DuckDB SQL on close and shutdown.

This is not in-place mutation of Parquet, CSV, or JSON scan functions.

## Local Parquet, CSV, Or JSON

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_BACKEND', 'parquet' ); // Also accepts csv or json.
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-parquet/' );
define( 'DUCKDB_WORKING_DATABASE_FILE', __DIR__ . '/wp-content/database/.ht.duckdb-working' );
define( 'DUCKDB_PHP_AUTOLOAD', __DIR__ . '/wp-content/plugins/wordpress-databases-support/vendor/autoload.php' );
```

That produces one file per WordPress table:

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

## Custom File Format Or COPY Options

Use custom templates when DuckDB can read and write the storage with SQL but the
format is not one of the presets. This example stores pipe-delimited files with
a `.psv` extension.

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'pipe_text' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/pipe-text/' );
define( 'DUCKDB_BACKEND_FILE_EXTENSION', 'psv' );
define( 'DUCKDB_BACKEND_READ_SQL', "SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')" );
define( 'DUCKDB_BACKEND_WRITE_SQL', "COPY {table} TO {path} (HEADER, DELIMITER '|')" );
```

## Attached SQLite

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
define( 'DUCKDB_METADATA_MANIFEST_FILE', __DIR__ . '/wp-content/database/.wp-duckdb-sqlite_attach-metadata' );
define( 'DUCKDB_BACKEND_ATOMIC_FLUSH', false );
```

Use a dedicated attached SQLite file. The write template replaces each remote
table on flush.

## Attached PostgreSQL Or MySQL

DuckDB's PostgreSQL and MySQL extensions can attach running database servers.
Use this pattern only with an isolated schema or database because the simple
flush strategy replaces tables.

If you want PostgreSQL as the actual WordPress database, prefer
`DB_ENGINE=postgresql` instead of routing WordPress through DuckDB and then
through DuckDB's PostgreSQL extension.

## Metadata Manifest

External backends persist WordPress-facing schema metadata, including column
types, primary keys, unique indexes, and auto-increment state. Keep
`DUCKDB_METADATA_MANIFEST_FILE` on durable disk when the working DuckDB database
can be deleted or rebuilt.
