# DuckDB production-readiness artifacts

`bin/duckdb-production-readiness.php` writes structured run artifacts here:

- `runs/<run-id>/events.jsonl` has one JSON object per scenario.
- `runs/<run-id>/summary.json` has aggregate counts, environment details, and artifact paths.
- `runs/<run-id>/wordpress-smoke-native-duckdb.log` has the full WordPress smoke output when that scenario runs.

The `runs/` directory is ignored by Git because the outputs are machine- and
runtime-specific. Keep run directories when comparing or aggregating production
readiness evidence over time.

Run it from the repository root:

```bash
WP_DUCKDB_TESTS=1 \
WP_DUCKDB_AUTOLOAD="$PWD/vendor/autoload.php" \
DUCKDB_PHP_AUTOLOAD="$PWD/vendor/autoload.php" \
composer run readiness-duckdb
```

Every event includes `schema_version`, `run_id`, `timestamp`, `scenario`,
`status`, `readiness`, `duration_ms`, `metrics`, `observations`, and
`artifacts`. Treat `status=fail` as a harness or backend failure. Treat
`readiness=warn` as evidence of an operational risk that still completed
successfully enough to record.

Scratch databases and WordPress worktrees default to
`/tmp/wp-duckdb-production-readiness-work/<run-id>` and are not part of the
aggregateable report artifacts. Override with `WP_DUCKDB_READINESS_WORK_DIR`
when you need to preserve them for debugging.
