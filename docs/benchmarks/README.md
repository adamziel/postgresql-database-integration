# Benchmark Artifacts

This directory stores raw benchmark evidence that is safe to publish with the
documentation site.

- `latest.json` is the latest aggregate benchmark summary.
- `runs/<run-id>/summary.json` stores per-run aggregate results.
- `runs/<run-id>/events.jsonl` stores raw benchmark events.
- `duckdb-performance-findings.md` is the current written summary.

The Astro docs build copies this directory into the GitHub Pages artifact at
`/benchmarks/` so reports can link to raw JSON without scraping rendered HTML.
