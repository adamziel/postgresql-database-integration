---
title: Setup Wizard
description: Use the browser setup wizard to choose SQLite, PostgreSQL, or DuckDB before installing WordPress.
---

The setup wizard is for a new WordPress site before `wp-config.php` exists.
Install the plugin, then open:

```text
/wp-content/plugins/wordpress-databases-support/setup-database.php
```

The wizard presents SQLite, PostgreSQL, and DuckDB choices. After you submit the
form, it writes a managed block in `wp-config.php`, installs the database
drop-in, and sends you back to WordPress' normal `wp-admin/install.php` screen.

## When To Use It

Use the wizard when:

- you are setting up a fresh WordPress site;
- you want the admin install flow to present database choices;
- you prefer a browser form over CLI flags.

Use [CLI Setup](../cli-setup/) instead when `wp-config.php` already exists or when
you need repeatable scripted setup.

## Safety Boundary

The wizard is intentionally limited to first-install setup. It does not migrate
an existing MySQL site and it does not rewrite an established production
configuration.
