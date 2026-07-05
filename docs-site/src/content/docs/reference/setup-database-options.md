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
| `--duckdb-connection=ffi\|unix\|tcp\|http\|sidecar` | Configure embedded or remote DuckDB connection mode. |
| `--duckdb-socket=/path/to/duckdb.sock` | Unix socket path. |
| `--duckdb-host=127.0.0.1` | TCP sidecar host. |
| `--duckdb-port=9901` | TCP sidecar port. |
| `--duckdb-url=http://127.0.0.1:9902/query` | HTTP sidecar URL. |

For storage backend constants such as `DUCKDB_BACKEND`, edit `wp-config.php` or
set them through your deployment automation.
