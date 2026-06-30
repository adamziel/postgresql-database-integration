# PostgreSQL Database Integration

PostgreSQL Database Integration is an experimental WordPress database drop-in
that lets WordPress run against PostgreSQL while preserving the MySQL-facing
`wpdb` API expected by WordPress core and plugins.

This standalone repository contains only the PostgreSQL WordPress drop-in,
PostgreSQL driver, and the shared MySQL parser/lexer code required to translate
WordPress MySQL-flavored SQL to PostgreSQL. It does not include SQLite driver
code, SQLite drop-ins, native parser extensions, or the MySQL proxy packages
from the source monorepo.

## Requirements

- PHP 7.2 or newer.
- PHP extensions: `pdo` and `pdo_pgsql`.
- PostgreSQL 16 is used by CI. Other supported versions have not yet been
  finalized for this standalone package.
- A fresh WordPress site. Existing MySQL sites are not migrated by this package.

## Installation

1. Copy this repository to:

   ```text
   wp-content/plugins/postgresql-database-integration
   ```

2. Copy the drop-in file into place:

   ```bash
   cp wp-content/plugins/postgresql-database-integration/db.copy wp-content/db.php
   ```

3. Configure `wp-config.php` with PostgreSQL connection constants:

   ```php
   define( 'DB_ENGINE', 'postgresql' );
   define( 'DB_NAME', 'wordpress' );
   define( 'DB_USER', 'wordpress' );
   define( 'DB_PASSWORD', 'wordpress' );
   define( 'DB_HOST', '127.0.0.1:5432' );
   ```

   `DB_ENGINE` also accepts `postgres` and `pgsql` aliases. The drop-in
   normalizes them to `postgresql`.

4. Visit the WordPress installer. The drop-in replaces `wpdb`, translates the
   WordPress install schema, and creates the PostgreSQL-backed WordPress tables.

The plugin file (`load.php`) is intentionally small. The critical integration
point is the WordPress `wp-content/db.php` drop-in copied from `db.copy`.

## Development

Install Composer metadata and run local checks:

```bash
composer validate --strict --no-check-publish
composer install
composer run lint
```

Run the full PostgreSQL PHPUnit suite against a PostgreSQL database:

```bash
PGSQL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=wordpress_test' \
PGSQL_TEST_USER='wordpress' \
PGSQL_TEST_PASSWORD='wordpress' \
composer run test-postgresql
```

`composer run test` is an alias for the full PostgreSQL PHPUnit suite.

Run the focused PostgreSQL smoke test against a PostgreSQL database:

```bash
PGSQL_TEST_DSN='pgsql:host=127.0.0.1;port=5432;dbname=wordpress_test' \
PGSQL_TEST_USER='wordpress' \
PGSQL_TEST_PASSWORD='wordpress' \
composer run test-smoke
```

The smoke test creates an isolated schema, loads the standalone driver, creates
a WordPress-shaped `wp_options` table from MySQL DDL, inserts and queries option
rows, verifies a MySQL function rewrite, and checks `SHOW TABLES` support.

## CI

GitHub Actions workflow: `.github/workflows/ci.yml`.

The workflow starts a PostgreSQL 16 service, validates Composer metadata, installs
Composer development dependencies, lints all PHP files, runs the full PostgreSQL
PHPUnit suite with `composer run test-postgresql`, and keeps the standalone
PostgreSQL smoke test in the CI path.

## Current Limitations

- This is an extraction of the PostgreSQL work from the SQLite Database
  Integration monorepo and is not a published WordPress.org plugin.
- Existing MySQL databases are not migrated.
- Full WordPress E2E coverage is not included yet; CI runs the standalone
  PostgreSQL PHPUnit suite and a focused driver smoke test.
- PostgreSQL version support policy is not finalized beyond the PostgreSQL 16
  CI target.
- The driver still translates MySQL-flavored SQL because WordPress and many
  plugins issue SQL through the MySQL-oriented `wpdb` API.
