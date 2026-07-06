import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';

export default defineConfig({
  site: 'https://adamziel.github.io',
  base: '/wordpress-databases-support',
  integrations: [
    starlight({
      title: 'WordPress Databases Support',
      description: 'Run WordPress on SQLite, PostgreSQL, DuckDB, and DuckDB-backed storage formats.',
      editLink: {
        baseUrl: 'https://github.com/adamziel/wordpress-databases-support/edit/trunk/docs-site/',
      },
      social: [
        {
          icon: 'github',
          label: 'GitHub',
          href: 'https://github.com/adamziel/wordpress-databases-support',
        },
      ],
      customCss: ['./src/styles/custom.css'],
      sidebar: [
        {
          label: 'Start Here',
          items: [
            { label: 'Overview', slug: 'index' },
            { label: 'Installation', slug: 'installation' },
            { label: 'CLI Setup', slug: 'cli-setup' },
          ],
        },
        {
          label: 'Backends',
          items: [
            { label: 'SQLite', slug: 'backends/sqlite' },
            { label: 'PostgreSQL', slug: 'backends/postgresql' },
            { label: 'DuckDB', slug: 'backends/duckdb' },
            { label: 'DuckDB Storage Backends', slug: 'backends/duckdb-storage-backends' },
            { label: 'DuckDB Connection Modes', slug: 'backends/duckdb-connection-modes' },
            { label: 'S3-Compatible Storage', slug: 'backends/duckdb-s3' },
          ],
        },
        {
          label: 'Guides',
          items: [
            { label: 'Performance', slug: 'guides/performance' },
            { label: 'Production Readiness', slug: 'guides/production-readiness' },
            { label: 'Troubleshooting', slug: 'guides/troubleshooting' },
          ],
        },
        {
          label: 'Reference',
          items: [
            { label: 'Installer Options', slug: 'reference/installer-options' },
            { label: 'Setup CLI Options', slug: 'reference/setup-database-options' },
            { label: 'Configuration Constants', slug: 'reference/configuration-constants' },
          ],
        },
        {
          label: 'Examples',
          items: [
            { label: 'DuckDB JSON WordPress', slug: 'examples/duckdb-json-wordpress' },
          ],
        },
        {
          label: 'Development',
          items: [
            { label: 'Testing', slug: 'development/testing' },
            { label: 'CI', slug: 'development/ci' },
            { label: 'Releases', slug: 'development/releases' },
          ],
        },
      ],
    }),
  ],
});
