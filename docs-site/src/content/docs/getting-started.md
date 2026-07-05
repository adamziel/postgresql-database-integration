---
title: Getting Started
description: Choose the fastest path for trying or installing WordPress Databases Support.
---

This page gives you the shortest working path for each common starting point.

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

## Install Into A New WordPress Site

Run this from the root of a new WordPress site:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php
```

The installer places the plugin under `wp-content/plugins/`, downloads the
pinned SQLite package, and prints the setup wizard URL.

## Configure From The CLI

Pass setup flags after `php --` when you want a one-command install and backend
configuration:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes
```

Use [CLI Setup](../cli-setup/) for backend-specific examples.

## Choose A Backend

| Choose | When |
| --- | --- |
| SQLite | You want the simplest non-MySQL local database file. |
| PostgreSQL | You want WordPress backed by a PostgreSQL server. |
| DuckDB native file | You want one DuckDB database file and can accept current DuckDB tradeoffs. |
| DuckDB external storage | You want proof-of-concept WordPress table persistence in JSON, CSV, Parquet, S3 Parquet, attached SQLite, or custom DuckDB SQL templates. |

Read [DuckDB](../backends/duckdb/) before choosing DuckDB for a mutable site.
