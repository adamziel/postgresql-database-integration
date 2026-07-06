---
title: Overview
description: Run WordPress on SQLite, PostgreSQL, DuckDB, and DuckDB-backed file or object storage.
---

WordPress Databases Support is an experimental database drop-in collection for
running WordPress on non-MySQL backends while preserving the MySQL-facing
`wpdb` API expected by WordPress core and plugins.

## Quick Start

Run these commands from the WordPress root directory. These are the shortest
[CLI Setup](cli-setup/) examples and use SQLite because it has the fewest
external requirements. Use [CLI Setup](cli-setup/) for PostgreSQL, DuckDB,
custom paths, and repeatable provisioning flags.

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

## Other Paths

Use these when you need a different setup flow or more context.

| Starting point | Use this when | Start here |
| --- | --- | --- |
| More CLI examples | You need PostgreSQL, DuckDB, custom paths, or repeatable provisioning flags. | [CLI Setup](cli-setup/) |
| Local DuckDB demo | You want a Docker-based WordPress site storing tables as DuckDB-backed JSON files. | [DuckDB JSON WordPress](examples/duckdb-json-wordpress/) |

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
