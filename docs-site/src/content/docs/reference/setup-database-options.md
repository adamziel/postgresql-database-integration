---
title: Setup CLI Options
description: Flags accepted by bin/setup-database.php.
---

Run setup after the plugin is installed:

```bash
php wp-content/plugins/wordpress-databases-support/bin/setup-database.php \
  --engine=duckdb \
  --yes
```

## Common Options

| Option | Purpose |
| --- | --- |
| `--wp-path=/path/to/wordpress` | WordPress root. Defaults to the current directory. |
| `--plugin-dir=/path/to/plugin` | Plugin root when the setup script cannot infer it. |
| `--engine=sqlite\|postgresql\|duckdb` | Backend to configure. |
| `--force` | Update an existing unmanaged `wp-config.php` or `wp-content/db.php`. |
| `--dry-run` | Show what would be written without changing files. |
| `--strict` | Fail if required PHP extensions or packaged drivers are missing. |
| `--yes` | Accept non-interactive changes. |

## PostgreSQL Options

| Option | Purpose |
| --- | --- |
| `--db-name=wordpress` | Database name. |
| `--db-user=wordpress` | Database user. |
| `--db-password=secret` | Database password. |
| `--db-host=127.0.0.1:5432` | Database host and optional port. |

## DuckDB Options

| Option | Purpose |
| --- | --- |
| `--db-dir=wp-content/database` | Local directory for SQLite files, native DuckDB files, and default DuckDB external storage paths. |
| `--duckdb-connection=ffi\|unix\|tcp\|http\|sidecar` | Configure embedded or remote DuckDB connection mode. |
| `--duckdb-socket=/path/to/duckdb.sock` | Unix socket path. |
| `--duckdb-host=127.0.0.1` | TCP sidecar host. |
| `--duckdb-port=9901` | TCP sidecar port. |
| `--duckdb-url=http://127.0.0.1:9902/query` | HTTP sidecar URL. |
| `--duckdb-backend=json\|csv\|parquet\|custom_name` | Store WordPress tables through a DuckDB external backend. Built-in file backends are `json`, `csv`, and `parquet`. |
| `--duckdb-external-storage-dir=wp-content/database/duckdb-json` | Directory or URI prefix for one external file per WordPress table. Defaults to `wp-content/database/duckdb-{backend}` when `--duckdb-backend` is set. |
| `--duckdb-working-database-file=wp-content/database/.ht.duckdb-working` | Mutable DuckDB working database used while hydrating and flushing external storage. Defaults to this path when `--duckdb-backend` is set. |
| `--duckdb-backend-file-extension=psv` | File extension for a custom path-based backend. |
| `--duckdb-backend-read-sql="SELECT * FROM read_csv_auto({path})"` | Custom SQL relation template used to hydrate one table. |
| `--duckdb-backend-write-sql="COPY {table} TO {path}"` | Custom SQL statement template used to flush one table. |
| `--duckdb-backend-setup-sql="INSTALL httpfs"` | SQL statement to run after connecting. Repeat the flag for multiple statements. |
| `--duckdb-backend-tables=wp_options,wp_posts` | Explicit table list for remote/object/attached backends that cannot be discovered by scanning local files. |
| `--duckdb-backend-atomic-flush=0` | Disable temporary-file-and-rename flushes for local path-based backends. |
| `--duckdb-metadata-manifest-file=wp-content/database/.wp-duckdb-json-metadata` | Local metadata manifest path for WordPress schema/index metadata. |

For custom SQL placeholders, see [Configuration Constants](../configuration-constants/#sql-template-placeholders).
