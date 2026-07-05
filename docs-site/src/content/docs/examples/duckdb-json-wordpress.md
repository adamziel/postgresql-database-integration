---
title: DuckDB JSON WordPress Example
description: Run a local WordPress site that stores every table as a JSON file through DuckDB.
---

This Docker example starts WordPress with `DB_ENGINE=duckdb` and
`DUCKDB_BACKEND=json`, then stores each WordPress table as a JSON file.

## Run It

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/archive/trunk.tar.gz | tar -xz
cd wordpress-databases-support-trunk/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080`. The admin login is `admin` / `password`.

The JSON table files are written to:

```text
examples/duckdb-json-wordpress/data/duckdb-json/
```

You should see files such as `wp_options.json`, `wp_posts.json`, and
`wp_users.json` after the first startup finishes.

## What This Runs

The image is based on `wordpress:php8.3-apache`. It installs PHP FFI, Composer,
the `satur.io/duckdb` package, the DuckDB C library, and this plugin as
`wp-content/plugins/wordpress-databases-support`.

The bundled `wp-config.php` sets:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DUCKDB_BACKEND', 'json' );
define( 'DUCKDB_EXTERNAL_STORAGE_DIR', __DIR__ . '/wp-content/database/duckdb-json/' );
define( 'DUCKDB_WORKING_DATABASE_FILE', __DIR__ . '/wp-content/database/.ht.duckdb-working' );
```

On container startup, `entrypoint.sh` copies the drop-in to `wp-content/db.php`,
runs the WordPress installer if needed, and flushes the DuckDB working tables
back to JSON storage.

## Options

Change the exposed port:

```bash
WORDPRESS_PORT=8081 WORDPRESS_SITE_URL=http://localhost:8081 docker compose up --build
```

Change the generated admin account:

```bash
WORDPRESS_ADMIN_USER=editor WORDPRESS_ADMIN_PASSWORD=secret docker compose up --build
```

Reset the example:

```bash
docker compose down
rm -rf data
```

## Concurrency Boundary

The Apache container is intentionally configured with one request worker and
WP-Cron is disabled. DuckDB uses a mutable working database while hydrating and
flushing the JSON files, so this local example serializes web requests instead
of opening the same working file from multiple PHP processes.
