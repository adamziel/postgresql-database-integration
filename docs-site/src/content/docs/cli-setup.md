---
title: CLI Setup
description: Configure the database drop-in and backend constants from a shell command.
---

CLI setup is the repeatable path for scripted WordPress installs, local test
harnesses, and environments where a browser wizard is not appropriate.

## One-Command Install And Setup With JSON

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

The same command works before the WordPress installer runs and on an already
installed site. `--force` only allows setup to update an existing `wp-config.php`
or `wp-content/db.php`; it does not migrate existing MySQL content into JSON.
Test on a copy before touching a real site. DuckDB JSON also needs PHP FFI,
Composer/network access for `satur.io/duckdb`, and the current JSON storage path
is best treated as an experiment rather than a busy production database.

## Backend Variants

SQLite:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes --force
```

DuckDB native file:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=duckdb --install-duckdb-client --yes --force
```

PostgreSQL:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=postgresql \
  --db-name=wordpress \
  --db-user=wordpress \
  --db-password=secret \
  --db-host=127.0.0.1:5432 \
  --yes
```

## Setup After The Plugin Is Installed

Run the setup script directly:

```bash
php wp-content/plugins/wordpress-databases-support/bin/setup-database.php \
  --engine=duckdb \
  --yes
```

Or require it in a one-liner:

```bash
php -r "require 'wp-content/plugins/wordpress-databases-support/bin/setup-database.php';" -- --engine=sqlite --yes
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
| `--duckdb-backend=json\|csv\|parquet\|custom_name` | Store DuckDB tables through an external backend instead of only a native DuckDB file. |
| `--duckdb-external-storage-dir=wp-content/database/duckdb-json` | Directory or URI prefix for DuckDB external table files. |
| `--duckdb-working-database-file=wp-content/database/.ht.duckdb-working` | Mutable DuckDB working database used while hydrating and flushing external storage. |
| `--duckdb-connection=ffi\|unix\|tcp\|http\|sidecar` | Configure DuckDB embedded or sidecar connection mode. |
| `--yes` | Accept non-interactive changes. |

See [Setup CLI Options](../reference/setup-database-options/) for the full
reference.
