---
title: Installation
description: Choose the right setup path for installing WordPress Databases Support.
---

Installation is the entry point. Use this page to choose a path, then follow
[CLI Setup](../cli-setup/) for the full copy-paste commands and backend flags.

A WordPress root is a directory that already contains WordPress core files such
as `wp-config.php`, `wp-load.php`, and `wp-content/`. The installer installs this
database support plugin and writes the database drop-in. It does not download or
install WordPress core.

## Choose A Path

| Goal | Start here |
| --- | --- |
| Try the project without an existing site. | Use the Docker-based DuckDB JSON quick start on the [Overview](../#try-duckdb-json-wordpress). |
| Configure a new or existing WordPress site from a shell. | Use [CLI Setup](../cli-setup/). |
| Download the packaged plugin zip. | Use [Releases](../releases/). |
| Use SQLite, PostgreSQL, DuckDB native files, JSON, CSV, Parquet, S3, attached SQLite, or custom DuckDB SQL templates. | Use [Set Up Each Backend](../cli-setup/#set-up-each-backend). |
| Understand what the installer changes before running it. | Read [How It Works](../how-it-works/#what-the-installer-changes). |
| Choose DuckDB for production-like mutable storage. | Read [Performance](../guides/performance/) and [Production Readiness](../guides/production-readiness/) first. |

## Shortest CLI Path

Run this from a WordPress root to install the plugin, install the DuckDB PHP
client, configure the drop-in, and store WordPress tables as JSON files:

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- \
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

Use [Set Up Each Backend](../cli-setup/#set-up-each-backend) for every backend
variant and repeatable provisioning flag.

The bootstrap installer downloads the latest released plugin zip by default. To
pin a release, add `--release=v0.1.0`. To test an unreleased development
snapshot, add `--ref=trunk`.

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
