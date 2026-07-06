---
title: Getting Started
description: Choose the fastest path for trying or installing WordPress Databases Support.
---

This page gives you the shortest working path for each common starting point.

## Add Support With One Command

Run these commands from the WordPress root directory. These are the shortest
[CLI Setup](../cli-setup/) examples and use SQLite because it has the fewest
external requirements. Use [CLI Setup](../cli-setup/) for PostgreSQL, DuckDB,
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

## Try DuckDB JSON With Docker

Use this when you want to see a real WordPress site storing tables as JSON files
through DuckDB without preparing PHP extensions or a database server.

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/archive/trunk.tar.gz | tar -xz
cd wordpress-databases-support-trunk/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080` and log in with `admin` / `password`.

The JSON table files appear under:

```text
examples/duckdb-json-wordpress/data/duckdb-json/
```

## Choose A Backend

| Choose | When |
| --- | --- |
| SQLite | You want the simplest non-MySQL local database file. |
| PostgreSQL | You want WordPress backed by a PostgreSQL server. |
| DuckDB native file | You want one DuckDB database file and can accept current DuckDB tradeoffs. |
| DuckDB external storage | You want proof-of-concept WordPress table persistence in JSON, CSV, Parquet, S3 Parquet, attached SQLite, or custom DuckDB SQL templates. |

Read [DuckDB](../backends/duckdb/) before choosing DuckDB for a mutable site.
