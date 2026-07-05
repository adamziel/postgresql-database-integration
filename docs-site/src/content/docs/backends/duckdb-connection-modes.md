---
title: DuckDB Connection Modes
description: Choose how WordPress talks to DuckDB.
---

DuckDB connection mode says how WordPress talks to DuckDB. It is separate from
DuckDB storage backend configuration.

| Mode | WordPress config | DuckDB runtime lives in | Use when |
| --- | --- | --- | --- |
| Embedded FFI | default or `DUCKDB_CONNECTION=ffi` | The WordPress PHP request process. | You want the simplest development setup. |
| Unix socket | `DUCKDB_CONNECTION=unix` and `DUCKDB_REMOTE_SOCKET=/path/to/duckdb.sock` | A separately started sidecar process. | You want local sidecar performance without TCP overhead. |
| TCP socket | `DUCKDB_CONNECTION=tcp`, `DUCKDB_REMOTE_HOST`, `DUCKDB_REMOTE_PORT` | A separately started sidecar process. | You need a process or container boundary. |
| HTTP | `DUCKDB_CONNECTION=http`, `DUCKDB_REMOTE_URL` | A separately started sidecar process. | You need a simple HTTP transport for local/private networks. |
| Managed sidecar | `DUCKDB_CONNECTION=sidecar`, `DUCKDB_SIDECAR_COMMAND` | A child process started by each WordPress PHP process. | You need a simple fallback for local/shared-host experiments. |

## Unix Socket Sidecar

Start the sidecar:

```bash
php -d ffi.enable=1 wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php \
  --socket=/run/wp-duckdb/wordpress.sock \
  --path=/var/www/html/wp-content/database/.ht.duckdb
```

Configure WordPress:

```php
define( 'DB_ENGINE', 'duckdb' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DUCKDB_FILE', '.ht.duckdb' );
define( 'DUCKDB_CONNECTION', 'unix' );
define( 'DUCKDB_REMOTE_SOCKET', '/run/wp-duckdb/wordpress.sock' );
```

## TCP Sidecar

```bash
php -d ffi.enable=1 wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php \
  --tcp=127.0.0.1:9901 \
  --path=/var/www/html/wp-content/database/.ht.duckdb
```

```php
define( 'DUCKDB_CONNECTION', 'tcp' );
define( 'DUCKDB_REMOTE_HOST', '127.0.0.1' );
define( 'DUCKDB_REMOTE_PORT', 9901 );
```

## HTTP Sidecar

```bash
php -d ffi.enable=1 wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php \
  --http=127.0.0.1:9902 \
  --path=/var/www/html/wp-content/database/.ht.duckdb
```

```php
define( 'DUCKDB_CONNECTION', 'http' );
define( 'DUCKDB_REMOTE_URL', 'http://127.0.0.1:9902/query' );
```

## Managed Sidecar

```php
define( 'DUCKDB_CONNECTION', 'sidecar' );
define(
  'DUCKDB_SIDECAR_COMMAND',
  'php -d ffi.enable=1 ' . __DIR__ . '/wp-content/plugins/wordpress-databases-support/bin/duckdb-sidecar.php --stdio --path=' . __DIR__ . '/wp-content/database/.ht.duckdb'
);
```

## Security Boundary

Bind TCP and HTTP transports to loopback or a private network. The test sidecar
does not implement authentication.

## Performance Reading

Local profiles showed small transport overhead when the sidecar cache was
disabled:

| Transport | `SELECT 1` p50 | Added client/transport p50 |
| --- | ---: | ---: |
| Embedded, already open | 0.232 ms | 0 ms |
| Unix sidecar | 0.287 ms | 0.047 ms |
| TCP sidecar | 0.302 ms | 0.062 ms |
| HTTP sidecar, new connection | 0.327 ms | 0.084 ms |

The main benefit of the sidecar is keeping the native DuckDB handle open outside
PHP requests. See [Performance](../../guides/performance/) for request-level
results.
