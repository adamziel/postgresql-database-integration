---
title: CI
description: Understand the GitHub Actions jobs for plugin tests, docs, packaging, and WordPress core compatibility.
---

GitHub Actions workflows live under `.github/workflows/`.

## Main CI

`.github/workflows/ci.yml` runs on `trunk` and pull requests. It validates
Composer metadata, lints PHP files, builds the plugin package, and runs the
PostgreSQL, DuckDB, DuckDB parity, WordPress core, and WordPress plugin smoke
jobs.

The WordPress plugin smoke matrix runs WooCommerce and Query Monitor against:

- native DuckDB;
- JSON, CSV, and Parquet storage;
- a custom pipe-delimited file backend;
- DuckDB's attached SQLite extension;
- S3-compatible Parquet object storage backed by MinIO.

The full WordPress core PHPUnit suite also runs against DuckDB as a required CI
gate.

## Docs CI And Pages

`.github/workflows/docs.yml` builds the Astro Starlight docs site on pull
requests and `trunk` pushes. On `trunk`, it deploys `docs-site/dist` to GitHub
Pages through the official Pages artifact flow.

Build locally:

```bash
cd docs-site
npm ci
npm run build
```
