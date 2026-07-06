---
title: PostgreSQL
description: Configure WordPress Databases Support to use a PostgreSQL server.
---

The PostgreSQL backend uses this repository's local PostgreSQL driver. WordPress
still calls `wpdb`; the drop-in translates the MySQL-facing query surface to the
PostgreSQL driver.

## Install And Configure

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- \
  --engine=postgresql \
  --db-name=wordpress \
  --db-user=wordpress \
  --db-password=secret \
  --db-host=127.0.0.1:5432 \
  --yes
```

Manual constants:

```php
define( 'DB_ENGINE', 'postgresql' );
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'secret' );
define( 'DB_HOST', '127.0.0.1:5432' );
```

`DB_ENGINE` also accepts `postgres` and `pgsql`.

## Requirements

- PHP `pdo_pgsql`.
- A reachable PostgreSQL server.
- A database/user with permission to create and update WordPress tables.

## Test Coverage

The CI suite runs the PostgreSQL PHPUnit suite, a focused smoke test, and a
WordPress core PHPUnit job against PostgreSQL.
