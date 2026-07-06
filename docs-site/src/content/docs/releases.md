---
title: Releases
description: Download the plugin zip or use the release installer.
---

Use releases when you want a packaged plugin download instead of a source
checkout. Each tagged release publishes two assets:

- `wordpress-databases-support.zip`, the installable plugin package.
- `install-database-support.php`, the standalone bootstrap installer.

## Download The Plugin

Download the latest plugin package:

```text
https://github.com/adamziel/wordpress-databases-support/releases/latest/download/wordpress-databases-support.zip
```

You can upload that zip through the WordPress plugin installer or unzip it into:

```text
wp-content/plugins/wordpress-databases-support
```

Then open:

```text
/wp-content/plugins/wordpress-databases-support/setup-database.php
```

## Run The Installer

Run this from a WordPress root to install the latest released plugin package and
configure JSON storage through DuckDB:

```bash
curl -fsSL https://github.com/adamziel/wordpress-databases-support/releases/latest/download/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=json \
  --yes \
  --force
```

By default, the installer downloads the latest released
`wordpress-databases-support.zip`. Use `--release=v0.1.0` to pin a release, or
use `--plugin-zip=/path/to/wordpress-databases-support.zip` when you already
downloaded the package.

Use `--ref=trunk` only for development snapshots. Source snapshots are not the
same thing as release packages; the installer has to package the SQLite
dependency separately when you use a source ref.

## Build A Package Locally

Maintainers can build the same plugin zip locally:

```bash
./bin/build-plugin-zip.sh
```

The build script includes the plugin drop-in, setup scripts, installer
bootstrap, DuckDB sidecar runtime, and packaged SQLite integration dependency.

The release workflow builds `build/wordpress-databases-support.zip` on manual
runs and publishes the plugin zip plus `bin/install-database-support.php` to
GitHub Releases for tags matching `v*`.

## Verify A Package

```bash
./bin/build-plugin-zip.sh
unzip -t build/wordpress-databases-support.zip
```
