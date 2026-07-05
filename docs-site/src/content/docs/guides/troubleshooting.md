---
title: Troubleshooting
description: Common setup and runtime checks for WordPress Databases Support.
---

Start with the exact backend and setup path you used. Most failures come from a
missing PHP extension, an unwritable database directory, or DuckDB runtime setup.

## Plugin Installed But WordPress Still Uses MySQL

Check that the drop-in exists:

```bash
ls -l wp-content/db.php
```

Check that `wp-config.php` contains a managed database support block and a
backend constant such as:

```php
define( 'DB_ENGINE', 'sqlite' );
```

Run setup again with `--dry-run` to see what it would change:

```bash
php wp-content/plugins/wordpress-databases-support/bin/setup-database.php --engine=sqlite --dry-run
```

## SQLite Fails To Open

Verify PHP has `pdo_sqlite`:

```bash
php -m | grep pdo_sqlite
```

Verify the database directory is writable by the PHP user:

```bash
mkdir -p wp-content/database
```

## DuckDB Runtime Is Missing

Embedded DuckDB needs PHP FFI and the DuckDB PHP client:

```bash
php -m | grep FFI
```

If the plugin was installed without the client, reinstall or run Composer in
the plugin directory:

```bash
cd wp-content/plugins/wordpress-databases-support
composer require --no-interaction --with-all-dependencies satur.io/duckdb
php -r 'require "vendor/autoload.php"; Saturio\DuckDB\CLib\Installer::install();'
```

## DuckDB External Storage Does Not Reload Tables

For remote, object, and attached backends, set `DUCKDB_BACKEND_TABLES`. PHP
cannot scan an `s3://` prefix or an attached database the way it scans a local
directory.

Also keep `DUCKDB_METADATA_MANIFEST_FILE` on durable disk. The manifest stores
WordPress-facing schema and index metadata that file formats do not preserve.

## DuckDB JSON Docker Example Fails

The Docker example prints a diagnostics block before it exits. That block
includes PHP/FFI runtime state, permissions, JSON table file sizes, connection
status, table count, `wp_options` count, and `siteurl`.

Include that diagnostic block when reporting a failure.
