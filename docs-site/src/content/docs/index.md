---
title: Overview
description: Run WordPress on SQLite, PostgreSQL, DuckDB, and DuckDB-backed file or object storage.
---

WordPress Databases Support is an experimental database drop-in collection for
running WordPress on non-MySQL backends while preserving the MySQL-facing
`wpdb` API expected by WordPress core and plugins.

## Quick Start

### Try DuckDB JSON WordPress

Use the Docker example when you want the fastest proof: a local WordPress site
that stores every table as a JSON file through DuckDB.

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/archive/trunk.tar.gz | tar -xz
cd wordpress-databases-support-trunk/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080` and log in with `admin` / `password`. See
the `examples/duckdb-json-wordpress/` directory in the repository for the
Docker files.

### One-Command Install And Setup With JSON

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

More setup commands: [CLI Setup](cli-setup/).

## How It Works

If you want the mental model before changing a site, read
[How It Works](how-it-works/). It explains the installer, the `db.php` drop-in,
backend drivers, DuckDB connection modes, and DuckDB external storage.

## DuckDB Deployment Decision

Read this before choosing DuckDB for mutable WordPress storage. You need
performance numbers and production tradeoffs, not just the install command:
[Performance](guides/performance/) and [Production Readiness](guides/production-readiness/).

## Supported Backends

| Backend | What it is best for | Status |
| --- | --- | --- |
| SQLite | Small sites, local development, and simple deployments. | Routed through the upstream WordPress SQLite Database Integration package. |
| PostgreSQL | PostgreSQL-backed WordPress experiments and compatibility testing. | Local driver with WordPress core test coverage. |
| DuckDB | Embedded analytics-style storage, local file formats, S3-compatible object storage, and backend research. | Local driver with native files, sidecar transports, and external storage proofs. |

## What This Project Does

The plugin installs `wp-content/db.php`, then routes WordPress database calls to
the configured backend. WordPress core and plugins still use `wpdb`; the drop-in
translates and executes the queries through the selected driver.

## What This Project Does Not Do

- It does not migrate existing MySQL databases.
- It does not make every DuckDB storage format a production OLTP database.
- It does not remove the need to test plugins against the backend you choose.

For production-like DuckDB deployments, read [Production Readiness](guides/production-readiness/)
and [Performance](guides/performance/) before deciding on a runtime shape.
