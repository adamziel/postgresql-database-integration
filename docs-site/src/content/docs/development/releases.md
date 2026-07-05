---
title: Releases
description: Build the distributable plugin zip.
---

Build a plugin zip:

```bash
./bin/build-plugin-zip.sh
```

The build script includes the plugin drop-in, setup scripts, installer
bootstrap, DuckDB sidecar runtime, and packaged SQLite integration dependency.

The release workflow builds `build/wordpress-databases-support.zip` on manual
runs and publishes that zip to GitHub Releases for tags matching `v*`.

## Verify A Package

```bash
./bin/build-plugin-zip.sh
unzip -t build/wordpress-databases-support.zip
```
