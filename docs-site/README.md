# Documentation Site

This directory contains the Astro Starlight source for the published
documentation site:

```bash
npm ci
npm run build
```

The build copies raw benchmark artifacts from `../docs/benchmarks/` into
`public/benchmarks/` before Astro builds, so the GitHub Pages artifact includes
both the documentation pages and the raw benchmark JSON/JSONL files.

The generated `dist/` directory is ignored by Git.
