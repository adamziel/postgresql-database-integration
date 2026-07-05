---
title: Installation
description: Install WordPress Databases Support into a new or already-installed WordPress site with one command.
---

Run these commands from the WordPress root directory. The examples use SQLite
because it has the fewest external requirements; use [CLI Setup](../cli-setup/)
for PostgreSQL and DuckDB variants.

## New WordPress Site, Before Install

Use this when WordPress files exist but the WordPress installer has not run yet:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes
```

Then open WordPress and run the normal installer. WordPress will create its
tables through the configured database drop-in.

## Already Installed WordPress Site

Use this when WordPress is already installed and you want to add the database
drop-in to the existing site:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes --force
```

This installs the plugin and allows setup to update an existing `wp-config.php`
or `wp-content/db.php`. It does not migrate existing MySQL content into the new
backend, so test on a copy of the site before using it on a live install.

## Browser Setup Instead

Run the installer without `--engine` when you want the web setup wizard:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php
```

The installer:

1. Downloads the WordPress Databases Support plugin archive.
2. Installs it at `wp-content/plugins/wordpress-databases-support`.
3. Downloads the pinned SQLite integration package.
4. Prints the setup wizard URL.

Open the printed URL before running WordPress' standard installer. The wizard
writes the database constants and installs the `wp-content/db.php` drop-in.

## Backend Variants

Pass setup flags after `php --`.

SQLite:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes
```

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
