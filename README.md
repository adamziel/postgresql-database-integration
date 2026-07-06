# WordPress Databases Support: SQLite, PostgreSQL, DuckDB, JSON, CSV, Parquet, S3

WordPress Databases Support is an experimental WordPress plugin and database
drop-in collection for running WordPress on SQLite, PostgreSQL, DuckDB native
files, DuckDB-backed JSON, CSV, Parquet, S3-compatible object storage, attached
SQLite databases, and custom DuckDB SQL backends while preserving the
MySQL-facing `wpdb` API expected by WordPress core and plugins.

## Quick Start

### Try WordPress Stored As JSON Files

Use the Docker example when you want the fastest proof: a local WordPress site
whose database is a directory of JSON files managed through DuckDB.

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/archive/trunk.tar.gz | tar -xz
cd wordpress-databases-support-trunk/examples/duckdb-json-wordpress
docker compose up --build
```

Open `http://localhost:8080` and log in with `admin` / `password`. The Docker
files live in [`examples/duckdb-json-wordpress/`](examples/duckdb-json-wordpress/).
After startup, table files are written under
`examples/duckdb-json-wordpress/data/duckdb-json/`; for example,
`wp_options.json` stores `wp_options` rows as newline-delimited JSON:

```json
{"option_id":1,"option_name":"siteurl","option_value":"http://localhost:8080","autoload":"yes"}
{"option_id":2,"option_name":"home","option_value":"http://localhost:8080","autoload":"yes"}
```

### Install On A WordPress Site With JSON File Storage

Run this from a WordPress root to install the plugin, install the DuckDB PHP
client, configure the drop-in, and store WordPress tables as JSON files. A
WordPress root is a directory that already contains WordPress core files such as
`wp-load.php` and `wp-content/`; this command does not download WordPress core.

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- \
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

More setup commands: [CLI setup](https://adamziel.github.io/wordpress-databases-support/cli-setup/).

### Download The Plugin Zip

Download the latest release package:

<https://github.com/adamziel/wordpress-databases-support/releases/latest/download/wordpress-databases-support.zip>

The one-command installer above downloads that same latest release package by
default. Use `--release=v0.1.0` to pin a release, or
`--plugin-zip=/path/to/wordpress-databases-support.zip` to install a package you
already downloaded.

## Status

This project is suitable for local development, backend experiments, and
compatibility testing. Read the production and performance docs before using it
as mutable storage for a busy live WordPress site.

Current backends:

| Backend | Status | Start here |
| --- | --- | --- |
| SQLite | Routed through the upstream WordPress SQLite Database Integration package. | [SQLite docs](https://adamziel.github.io/wordpress-databases-support/backends/sqlite/) |
| PostgreSQL | Local PostgreSQL driver with WordPress core test coverage. | [PostgreSQL docs](https://adamziel.github.io/wordpress-databases-support/backends/postgresql/) |
| DuckDB | Local DuckDB driver with native files, sidecar transports, and external storage formats. | [DuckDB docs](https://adamziel.github.io/wordpress-databases-support/backends/duckdb/) |

## DuckDB Deployment Decision

Read this before choosing DuckDB for mutable WordPress storage. You need
performance numbers and production tradeoffs, not just the install command:
[performance](https://adamziel.github.io/wordpress-databases-support/guides/performance/) and
[production readiness](https://adamziel.github.io/wordpress-databases-support/guides/production-readiness/).

## Documentation

Published docs: <https://adamziel.github.io/wordpress-databases-support/>

The docs source is in [`docs-site/`](docs-site/). It is built with Astro
Starlight and deployed to GitHub Pages from the `trunk` branch.

Important pages:

- [How it works](https://adamziel.github.io/wordpress-databases-support/how-it-works/)
- [Installation](https://adamziel.github.io/wordpress-databases-support/installation/)
- [CLI setup](https://adamziel.github.io/wordpress-databases-support/cli-setup/)
- [Releases](https://adamziel.github.io/wordpress-databases-support/releases/)
- [Configuration constants](https://adamziel.github.io/wordpress-databases-support/reference/configuration-constants/)
- [DuckDB performance](https://adamziel.github.io/wordpress-databases-support/guides/performance/)
- [Testing](https://adamziel.github.io/wordpress-databases-support/development/testing/)

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
