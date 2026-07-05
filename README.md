# WordPress Databases Support

WordPress Databases Support is an experimental WordPress plugin and database
drop-in collection for running WordPress on non-MySQL database backends while
preserving the MySQL-facing `wpdb` API expected by WordPress core and plugins.

## Quick Start

Run these commands from the WordPress root directory. The examples use SQLite
because it has the fewest external requirements; use [CLI setup](docs-site/src/content/docs/cli-setup.md)
for PostgreSQL and DuckDB variants.

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

## Status

This project is suitable for local development, backend experiments, and
compatibility testing. Read the production and performance docs before using it
as mutable storage for a busy live WordPress site.

Current backends:

| Backend | Status | Start here |
| --- | --- | --- |
| SQLite | Routed through the upstream WordPress SQLite Database Integration package. | [SQLite docs](docs-site/src/content/docs/backends/sqlite.md) |
| PostgreSQL | Local PostgreSQL driver with WordPress core test coverage. | [PostgreSQL docs](docs-site/src/content/docs/backends/postgresql.md) |
| DuckDB | Local DuckDB driver with native files, sidecar transports, and external storage formats. | [DuckDB docs](docs-site/src/content/docs/backends/duckdb.md) |

## Other Paths

Use these when you need a different setup flow or more context.

| Starting point | Use this when | Start here |
| --- | --- | --- |
| Local DuckDB demo | You want a Docker-based WordPress site storing tables as DuckDB-backed JSON files. | [DuckDB JSON Docker example](examples/duckdb-json-wordpress/README.md) |
| Browser setup | You want to choose SQLite, PostgreSQL, or DuckDB before running WordPress install. | [Setup wizard](docs-site/src/content/docs/setup-wizard.md) |
| More CLI examples | You need PostgreSQL, DuckDB, custom paths, or repeatable provisioning flags. | [CLI setup](docs-site/src/content/docs/cli-setup.md) |
| DuckDB mode selection | You need to compare DuckDB connection transports and storage formats. | [DuckDB connection modes](docs-site/src/content/docs/backends/duckdb-connection-modes.md) and [storage backends](docs-site/src/content/docs/backends/duckdb-storage-backends.md) |
| Production decision | You need performance numbers and operational tradeoffs before choosing a backend. | [Production readiness](docs-site/src/content/docs/guides/production-readiness.md) and [performance](docs-site/src/content/docs/guides/performance.md) |
| Contributor setup | You want to run the test suites or work on the drivers. | [Development testing](docs-site/src/content/docs/development/testing.md) |

See [installer options](docs-site/src/content/docs/reference/installer-options.md)
for `--wp-path`, `--setup`, `--engine`, DuckDB client installation, and local zip
options.

## Quick Demo: DuckDB JSON

Try a local WordPress site that stores each table as a JSON file through DuckDB:

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/archive/trunk.tar.gz | tar -xz
cd wordpress-databases-support-trunk/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080` and log in with `admin` / `password`.

## Documentation

Published docs: <https://adamziel.github.io/wordpress-databases-support/>

The docs source is in [`docs-site/`](docs-site/). It is built with Astro
Starlight and deployed to GitHub Pages from the `trunk` branch.

Important pages:

- [Getting started](docs-site/src/content/docs/getting-started.md)
- [Installation](docs-site/src/content/docs/installation.md)
- [CLI setup](docs-site/src/content/docs/cli-setup.md)
- [Configuration constants](docs-site/src/content/docs/reference/configuration-constants.md)
- [DuckDB performance](docs-site/src/content/docs/guides/performance.md)
- [Testing](docs-site/src/content/docs/development/testing.md)

## Current Limitations

- Existing MySQL databases are not migrated.
- DuckDB support requires a DuckDB PHP client and native library, either in the
  WordPress PHP process or in a separately managed sidecar process.
- DuckDB external storage uses a hydrate/mutate/flush cycle for Parquet, CSV,
  JSON, S3-compatible object storage, and custom SQL templates.
- Non-scannable DuckDB storage backends need an explicit table list and a durable
  local metadata manifest for WordPress schema/index metadata.
- Full browser/editor E2E coverage for DuckDB and SQLite is not included yet.

## Development

Install Composer metadata and run local checks:

```bash
composer validate --strict --no-check-publish
composer install
composer run lint
```

Build the docs:

```bash
cd docs-site
npm ci
npm run build
```

Build a plugin zip:

```bash
./bin/build-plugin-zip.sh
```
