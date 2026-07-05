---
title: Configuration Constants
description: WordPress constants used by the database drop-in and DuckDB backend.
---

## Common Constants

| Constant | Purpose |
| --- | --- |
| `DB_ENGINE` | Database backend. Accepts `sqlite`, `postgresql`, `duckdb`, and aliases such as `postgres`, `pgsql`, `duck`, and `sqlite3`. |
| `DATABASE_ENGINE` | Backward-compatible database engine constant. |
| `DB_DIR` | Directory used by file-backed engines. |
| `DB_FILE` | SQLite database file name. |

## DuckDB Storage Constants

| Constant | Purpose |
| --- | --- |
| `DUCKDB_FILE` | Native DuckDB database file name. |
| `DUCKDB_BACKEND` | Backend name. Built-in presets: `parquet`, `csv`, `json`. Any other name uses custom SQL templates. |
| `DUCKDB_EXTERNAL_STORAGE_DIR` | Directory or URI prefix that stores one file per WordPress table, such as `wp_options.parquet`. |
| `DUCKDB_WORKING_DATABASE_FILE` | Mutable DuckDB working database. Keep this even for external backends. |
| `DUCKDB_METADATA_MANIFEST_FILE` | Optional local file for WordPress schema/index metadata. |
| `DUCKDB_BACKEND_FILE_EXTENSION` | File extension for path-based custom backends, such as `psv` or `parquet`. |
| `DUCKDB_BACKEND_READ_SQL` | SQL relation template used to hydrate one local working table. |
| `DUCKDB_BACKEND_WRITE_SQL` | SQL statement template used to flush one local working table. |
| `DUCKDB_BACKEND_SETUP_SQL` | SQL statements to run after connecting, such as `INSTALL`, `LOAD`, `CREATE SECRET`, or `ATTACH`. |
| `DUCKDB_BACKEND_TABLES` | Explicit table list for remote/object/attached backends that PHP cannot discover by scanning a local directory. |
| `DUCKDB_BACKEND_ATOMIC_FLUSH` | Whether to write local path-based output to a temporary file first. Defaults to on for local paths and off for URIs. |

## DuckDB Connection Constants

| Constant | Purpose |
| --- | --- |
| `DUCKDB_PHP_AUTOLOAD` | Composer autoload path for the DuckDB PHP client. |
| `DUCKDB_CONNECTION` | Native DuckDB file connection mode: `ffi`, `embedded`, `unix`, `tcp`, `http`, or `sidecar`. |
| `DUCKDB_REMOTE_SOCKET` | Unix socket path for `DUCKDB_CONNECTION=unix`. |
| `DUCKDB_REMOTE_HOST` / `DUCKDB_REMOTE_PORT` | Host and port for `DUCKDB_CONNECTION=tcp`. |
| `DUCKDB_REMOTE_URL` | HTTP endpoint for `DUCKDB_CONNECTION=http`. |
| `DUCKDB_SIDECAR_COMMAND` | Stdio sidecar command for `DUCKDB_CONNECTION=sidecar`. |
| `DUCKDB_CONNECTION_SETUP_SQL` | Optional SQL string or array of SQL strings to run on each DuckDB connection, such as `SET threads=2`. |
| `WP_DUCKDB_CONNECTION_SETUP_SQL_JSON` | Environment variable form for sidecar process setup SQL. |
| `WP_DUCKDB_REQUEST_TRANSACTION` | Optional performance flag. Set to `1` to batch request writes into one DuckDB transaction. |

## SQL Template Placeholders

| Placeholder | Replaced with |
| --- | --- |
| `{table}` or `{table_identifier}` | The local DuckDB table as a quoted identifier, for example `"wp_options"`. |
| `{table_name}` | The table name as a quoted string literal, for example `'wp_options'`. |
| `{path}` or `{source}` | The per-table path or URI as a quoted string literal, for example `'s3://bucket/wp/wp_options.parquet'`. |

External storage metadata is stored outside formats such as JSON, CSV, and
Parquet because those formats do not preserve all MySQL DDL semantics by
themselves.
