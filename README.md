# WordPress Databases Support

WordPress Databases Support is an experimental WordPress plugin and database
drop-in collection for running WordPress on non-MySQL database backends while
preserving the MySQL-facing `wpdb` API expected by WordPress core and plugins.

## Quick Start

### Try DuckDB JSON WordPress

Use the Docker example when you want the fastest proof: a local WordPress site
that stores every table as a JSON file through DuckDB.

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/archive/trunk.tar.gz | tar -xz
cd wordpress-databases-support-trunk/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080` and log in with `admin` / `password`. The Docker
files live in [`examples/duckdb-json-wordpress/`](examples/duckdb-json-wordpress/).

### One-Command Install And Setup With JSON

Run this from a WordPress root to install the plugin, install the DuckDB PHP
client, configure the drop-in, and store WordPress tables as JSON files:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=json \
  --yes \
  --force
```

The same command works before the WordPress installer runs and on an already
installed site. `--force` only allows setup to update an existing `wp-config.php`
or `wp-content/db.php`; it does not migrate existing MySQL content into JSON.
Test on a copy before touching a real site. DuckDB JSON also needs PHP FFI,
Composer/network access for `satur.io/duckdb`, and the current JSON storage path
is best treated as an experiment rather than a busy production database.

More setup commands: [CLI setup](docs-site/src/content/docs/cli-setup.md).

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

## DuckDB Deployment Decision

Read this before choosing DuckDB for mutable WordPress storage. You need
performance numbers and production tradeoffs, not just the install command:
[performance](docs-site/src/content/docs/guides/performance.md) and
[production readiness](docs-site/src/content/docs/guides/production-readiness.md).

## Documentation

Published docs: <https://adamziel.github.io/wordpress-databases-support/>

The docs source is in [`docs-site/`](docs-site/). It is built with Astro
Starlight and deployed to GitHub Pages from the `trunk` branch.

Important pages:

- [How it works](docs-site/src/content/docs/how-it-works.md)
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
