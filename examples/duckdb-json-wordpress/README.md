# Run WordPress With DuckDB JSON Storage

Start a local WordPress site that uses DuckDB as the WordPress database adapter
and stores each WordPress table as a JSON file.

```bash
docker compose up --build
```

Open `http://localhost:8080`. The admin login is `admin` / `password`.

The JSON table files are written to:

```text
examples/duckdb-json-wordpress/data/duckdb-json/
```

You should see files such as `wp_options.json`, `wp_posts.json`, and
`wp_users.json` after the first startup finishes.

The same `data/` directory also contains `.ht.duckdb-working`, DuckDB's mutable
working database. The external WordPress table storage is the JSON files under
`data/duckdb-json/`.

## What This Runs

The image is based on `wordpress:php8.3-apache`. It installs PHP FFI, Composer,
the `satur.io/duckdb-auto` package, and this plugin as
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
