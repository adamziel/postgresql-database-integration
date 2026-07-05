---
title: S3-Compatible DuckDB Storage
description: Configure DuckDB to persist WordPress tables as Parquet objects in S3-compatible storage.
---

Use DuckDB's `httpfs` extension for S3-compatible object storage such as S3,
Cloudflare R2, or MinIO. PHP cannot scan an `s3://` prefix like a local
directory, so you must provide the WordPress table list.

## Configuration

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
define( 'DUCKDB_BACKEND_WRITE_SQL', 'COPY {table} TO {path} (FORMAT PARQUET, OVERWRITE_OR_IGNORE true)' );
define( 'DUCKDB_BACKEND_ATOMIC_FLUSH', false );
```

Add every WordPress and plugin table that should survive a fresh connection.
Tables that are not listed cannot be hydrated when PHP cannot scan the backend.

## Local MinIO Smoke

The CI smoke proves S3-compatible Parquet against MinIO. You can run the same
backend locally with:

```bash
WORDPRESS_VERSION=7.0 \
WOOCOMMERCE_VERSION=10.9.1 \
QUERY_MONITOR_VERSION=4.0.7 \
WP_DUCKDB_EXTERNAL_STORAGE_DIR='s3://duckdb-wordpress-smoke/wordpress/' \
WP_DUCKDB_BACKEND_FILE_EXTENSION=parquet \
WP_DUCKDB_BACKEND_SETUP_SQL_JSON='["INSTALL httpfs","LOAD httpfs","CREATE OR REPLACE SECRET wp_s3 (TYPE s3, PROVIDER config, KEY_ID '\''minioadmin'\'', SECRET '\''minioadmin'\'', REGION '\''us-east-1'\'', ENDPOINT '\''127.0.0.1:9000'\'', URL_STYLE '\''path'\'', USE_SSL false, SCOPE '\''s3://duckdb-wordpress-smoke/wordpress/'\'')"]' \
WP_DUCKDB_BACKEND_READ_SQL='SELECT * FROM read_parquet({path})' \
WP_DUCKDB_BACKEND_WRITE_SQL='COPY {table} TO {path} (FORMAT PARQUET, OVERWRITE_OR_IGNORE true)' \
WP_DUCKDB_BACKEND_ATOMIC_FLUSH=0 \
./bin/duckdb-wordpress-plugin-smoke.sh s3_parquet
```

## HTTPS Is Not Mutable Storage

DuckDB can read public HTTPS files through `httpfs`, but regular HTTP(S) does
not provide a write API. Do not use plain HTTPS as the only mutable WordPress
storage backend unless your write template writes to a different writable
target.
