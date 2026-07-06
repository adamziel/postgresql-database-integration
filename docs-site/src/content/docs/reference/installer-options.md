---
title: Installer Options
description: Flags accepted by bin/install-database-support.php.
---

The bootstrap installer is designed for one-command setup from a WordPress root,
whether WordPress has not run its installer yet or is already installed:

```bash
curl -fsSL https://raw.githubusercontent.com/adamziel/wordpress-databases-support/trunk/bin/install-database-support.php | php -- \
  --engine=duckdb \
  --install-duckdb-client \
  --duckdb-backend=json \
  --yes \
  --force
```

`--force` allows setup to update an existing `wp-config.php` or
`wp-content/db.php`. It does not migrate existing MySQL content into the new
backend.

## Bootstrap Options

| Option | Purpose |
| --- | --- |
| `--wp-path=/path/to/wordpress` | WordPress root. Defaults to the current directory. |
| `--plugin-dir=/path/to/plugin` | Plugin install directory. Defaults to `wp-content/plugins/wordpress-databases-support`. |
| `--setup=browser\|cli\|none` | Print the browser wizard URL, run CLI setup, or only install the plugin. If `--engine` is present, the installer defaults to CLI setup. |
| `--ref=trunk` | Repository branch, tag, or commit to install from GitHub archives. |
| `--plugin-zip=/path/to/wordpress-databases-support.zip` | Install from a packaged plugin zip instead of GitHub source archives. |
| `--source-zip=/path/to/source.zip` | Install from a local source archive. |
| `--sqlite-ref=v3.0.0-rc.7` | SQLite integration release tag to package with the install. |
| `--sqlite-zip=/path/to/plugin-sqlite-database-integration.zip` | Use a local SQLite integration package zip. |
| `--force-install` | Replace an existing plugin directory. |
| `--install-duckdb-client` | Run Composer inside the installed plugin and install `satur.io/duckdb` plus the DuckDB C library. |

## Forwarded Setup Options

Database setup flags such as `--engine`, `--db-name`, `--duckdb-connection`,
`--duckdb-backend`, `--duckdb-external-storage-dir`,
`--duckdb-working-database-file`, `--force`, `--dry-run`, `--strict`, and
`--yes` are forwarded to `bin/setup-database.php`.

`--force-install` and `--force` are intentionally separate. Use
`--force-install` to replace the plugin directory. Use `--force` to allow setup
to update existing database configuration files.
