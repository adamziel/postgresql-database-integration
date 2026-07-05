# WordPress Databases Support

WordPress Databases Support is an experimental WordPress plugin and database
drop-in collection for running WordPress on non-MySQL database backends while
preserving the MySQL-facing `wpdb` API expected by WordPress core and plugins.

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

## Choose Your Path

| I want to... | Start here |
| --- | --- |
| Try WordPress on DuckDB JSON without setting up PHP extensions by hand. | [DuckDB JSON Docker example](examples/duckdb-json-wordpress/README.md) |
| Install the plugin into a new WordPress site. | [Installation](docs-site/src/content/docs/installation.md) |
| Choose SQLite, PostgreSQL, or DuckDB in a browser. | [Setup wizard](docs-site/src/content/docs/setup-wizard.md) |
| Configure the drop-in from a script or shell. | [CLI setup](docs-site/src/content/docs/cli-setup.md) |
| Compare DuckDB connection and storage modes. | [DuckDB connection modes](docs-site/src/content/docs/backends/duckdb-connection-modes.md) and [storage backends](docs-site/src/content/docs/backends/duckdb-storage-backends.md) |
| Understand production risks and benchmark results. | [Production readiness](docs-site/src/content/docs/guides/production-readiness.md) and [performance](docs-site/src/content/docs/guides/performance.md) |
| Run the test suites or contribute. | [Development testing](docs-site/src/content/docs/development/testing.md) |

## Quick Install

Run this from the root of a new WordPress site:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php
```

That command installs the plugin at
`wp-content/plugins/wordpress-databases-support`, downloads the pinned SQLite
integration package, and prints the setup wizard URL.

You can also install and configure the drop-in in one command:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- --engine=sqlite --yes
```

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
