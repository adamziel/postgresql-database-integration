---
title: Overview
description: Run WordPress on SQLite, PostgreSQL, DuckDB, and DuckDB-backed file or object storage.
---

WordPress Databases Support is an experimental database drop-in collection for
running WordPress on non-MySQL backends while preserving the MySQL-facing
`wpdb` API expected by WordPress core and plugins.

## Start With Your Goal

| Goal | Page |
| --- | --- |
| Install into a new WordPress site. | [Installation](installation/) |
| Choose a backend in a browser before WordPress install. | [Setup Wizard](setup-wizard/) |
| Configure everything from a shell script. | [CLI Setup](cli-setup/) |
| Try a local DuckDB JSON site. | [DuckDB JSON WordPress](examples/duckdb-json-wordpress/) |
| Compare DuckDB performance and production tradeoffs. | [Performance](guides/performance/) |

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
