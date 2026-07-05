import { cpSync, existsSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const docsSiteRoot = resolve(scriptDir, '..');
const repoRoot = resolve(docsSiteRoot, '..');
const source = resolve(repoRoot, 'docs/benchmarks');
const destination = resolve(docsSiteRoot, 'public/benchmarks');

if (!existsSync(source)) {
  console.warn(`Benchmark source directory not found: ${source}`);
  process.exit(0);
}

rmSync(destination, { force: true, recursive: true });
cpSync(source, destination, { recursive: true });
