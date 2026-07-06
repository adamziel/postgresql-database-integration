---
title: Installation
description: Install WordPress Databases Support into a new or already-installed WordPress site with one command.
---

Run this command from a WordPress root to install the plugin, install the DuckDB
PHP client, configure the drop-in, and store WordPress tables as JSON files:

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

Use [CLI Setup](../cli-setup/) for SQLite, PostgreSQL, native DuckDB files,
remote DuckDB connection modes, custom paths, and repeatable provisioning flags.

## Backend Variants

Pass setup flags after `php --`.

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

See [Installer Options](../reference/installer-options/) for every bootstrap flag.

## Requirements

- PHP 7.2 or newer for the plugin shell and PostgreSQL/SQLite drivers.
- PHP `pdo`.
- PostgreSQL: PHP `pdo_pgsql` and a PostgreSQL server.
- SQLite: PHP `pdo_sqlite`.
- DuckDB embedded mode: PHP 8.3 or newer, PHP `ffi`, and the `satur.io/duckdb`
  PHP client in the WordPress PHP process.
- DuckDB remote modes: the WordPress PHP process can run without DuckDB FFI, but
  the sidecar process still needs PHP 8.3 or newer, PHP `ffi`, and
  `satur.io/duckdb`.

## Existing Plugin Directory

The bootstrap installer refuses to replace an existing plugin directory unless
you pass `--force-install`. Database setup has a separate `--force` flag for
updating an existing `wp-config.php` or `wp-content/db.php`.
