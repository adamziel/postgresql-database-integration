---
title: SQLite
description: Configure WordPress Databases Support to use SQLite through the upstream SQLite integration package.
---

SQLite support is routed through the upstream WordPress SQLite Database
Integration package that the bootstrap installer downloads and packages with
this plugin.

## Install And Configure

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- --engine=sqlite --yes
```

By default, setup writes SQLite constants similar to:

```php
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );
```

`DB_ENGINE` also accepts `sqlite3`.

## Requirements

- PHP `pdo_sqlite`.
- A writable `wp-content/database/` directory.

## Notes

SQLite is the simplest local file backend. Use it when you want a small
deployment footprint or a fast local development database without running a
separate database server.
