---
title: CLI Setup
description: Configure the database drop-in and backend constants from a shell command.
---

CLI setup is the repeatable path for scripted WordPress installs, local test
harnesses, and environments where a browser wizard is not appropriate.

## One-Command Install And Setup With SQLite

Run these commands from the WordPress root directory.

### New WordPress Site, Before Install

Use this when WordPress files exist but the WordPress installer has not run yet:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes
```

Then open WordPress and run the normal installer. WordPress will create its
tables through the configured database drop-in.

### Already Installed WordPress Site

Use this when WordPress is already installed and you want to add the database
drop-in to the existing site:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes --force
```

This installs the plugin and allows setup to update an existing `wp-config.php`
or `wp-content/db.php`. It does not migrate existing MySQL content into the new
backend, so test on a copy of the site before using it on a live install.

## Backend Variants

DuckDB:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=duckdb --install-duckdb-client --yes
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
| `--duckdb-connection=ffi\|unix\|tcp\|http\|sidecar` | Configure DuckDB embedded or sidecar connection mode. |
| `--yes` | Accept non-interactive changes. |

See [Setup CLI Options](../reference/setup-database-options/) for the full
reference.
