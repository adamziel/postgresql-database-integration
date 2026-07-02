#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ] || [ "$#" -gt 3 ]; then
	echo "Usage: $0 <wordpress-develop-dir> [wordpress-databases-support-dir] [duckdb-backend]" >&2
	exit 2
fi

wp_dir=$(cd "$1" && pwd)
plugin_dir=${2:-$(cd "$(dirname "$0")/.." && pwd)}
plugin_dir=$(cd "$plugin_dir" && pwd)
backend=${3:-${WP_CORE_DUCKDB_BACKEND:-duckdb}}
plugin_dest="$wp_dir/src/wp-content/plugins/wordpress-databases-support"
patch_file="$plugin_dir/tests/wordpress-core-phpunit-duckdb-6.7.2.patch"
autoload_file="${DUCKDB_PHP_AUTOLOAD:-}"

backend=$(printf '%s' "$backend" | tr '[:upper:]' '[:lower:]')
case "$backend" in
	duck|duckdb|file|native)
		backend=duckdb
		;;
esac

backend_slug=$(printf '%s' "$backend" | tr -c 'a-z0-9_-' '-')

if [ ! -f "$wp_dir/wp-tests-config-sample.php" ] || [ ! -f "$wp_dir/phpunit.xml.dist" ]; then
	echo "Expected a wordpress-develop checkout at $wp_dir." >&2
	exit 2
fi

if [ ! -f "$plugin_dir/db.copy" ] || [ ! -f "$patch_file" ]; then
	echo "Expected a wordpress-databases-support checkout at $plugin_dir." >&2
	exit 2
fi

mkdir -p "$plugin_dest"
if command -v rsync >/dev/null 2>&1; then
	rsync -a --delete \
		--exclude='.git' \
		--exclude='vendor' \
		--exclude='.phpunit.result.cache' \
		"$plugin_dir/" "$plugin_dest/"
else
	rm -rf "$plugin_dest"
	mkdir -p "$plugin_dest"
	tar -C "$plugin_dir" \
		--exclude='./.git' \
		--exclude='./vendor' \
		--exclude='./.phpunit.result.cache' \
		-cf - . | tar -C "$plugin_dest" -xf -
fi

cp "$plugin_dest/db.copy" "$wp_dir/src/wp-content/db.php"

if [ -z "$autoload_file" ]; then
	(
		cd "$plugin_dest"
		COMPOSER_NO_DEV=1 composer require --no-interaction --no-progress --with-all-dependencies satur.io/duckdb
		php -d ffi.enable=1 -r 'require "vendor/autoload.php"; Saturio\DuckDB\CLib\Installer::install();'
		composer dump-autoload --no-dev --no-interaction --optimize
	)
	autoload_file="$plugin_dest/vendor/autoload.php"
fi

if [ ! -f "$autoload_file" ]; then
	echo "DuckDB PHP autoload file not found: $autoload_file" >&2
	exit 2
fi

php -d ffi.enable=1 -r 'require $argv[1]; if (! class_exists("Saturio\\DuckDB\\DuckDB")) { fwrite(STDERR, "satur.io/duckdb is not available from " . $argv[1] . "\n"); exit(2); }' "$autoload_file"

database_dir="$wp_dir/src/wp-content/database"
mkdir -p "$database_dir"
rm -f "$database_dir/.ht.duckdb-core-tests" "$database_dir/.ht.duckdb-core-tests.lock"
rm -f "$database_dir/.ht.duckdb-core-${backend_slug}-working" "$database_dir/.ht.duckdb-core-${backend_slug}-working.lock"
rm -rf "$database_dir/duckdb-core-${backend_slug}"

export WP_CORE_DIR="$wp_dir"
export WP_CORE_TEST_DB_NAME="${WP_CORE_TEST_DB_NAME:-wordpress_develop_tests}"
export WP_CORE_TEST_DB_USER="${WP_CORE_TEST_DB_USER:-wordpress}"
export WP_CORE_TEST_DB_PASSWORD="${WP_CORE_TEST_DB_PASSWORD:-wordpress}"
export WP_CORE_TEST_DB_HOST="${WP_CORE_TEST_DB_HOST:-localhost}"
export WP_CORE_TEST_YEAR="${WP_CORE_TEST_YEAR:-$(date -u +%Y)}"
export WP_CORE_DUCKDB_BACKEND="$backend"
export WP_CORE_DUCKDB_BACKEND_SLUG="$backend_slug"
export WP_CORE_DUCKDB_AUTOLOAD="$autoload_file"
export WP_CORE_DUCKDB_DATABASE_DIR="$database_dir"
export WP_CORE_DUCKDB_BACKEND_FILE_EXTENSION="${DUCKDB_BACKEND_FILE_EXTENSION:-}"
export WP_CORE_DUCKDB_BACKEND_READ_SQL="${DUCKDB_BACKEND_READ_SQL:-}"
export WP_CORE_DUCKDB_BACKEND_WRITE_SQL="${DUCKDB_BACKEND_WRITE_SQL:-}"
export WP_CORE_DUCKDB_BACKEND_SETUP_SQL="${DUCKDB_BACKEND_SETUP_SQL:-}"
export WP_CORE_DUCKDB_BACKEND_TABLES="${DUCKDB_BACKEND_TABLES:-}"
export WP_CORE_DUCKDB_BACKEND_ATOMIC_FLUSH="${DUCKDB_BACKEND_ATOMIC_FLUSH:-}"

php <<'PHP'
<?php
$wp_dir      = getenv( 'WP_CORE_DIR' );
$db_name     = getenv( 'WP_CORE_TEST_DB_NAME' );
$db_user     = getenv( 'WP_CORE_TEST_DB_USER' );
$db_password = getenv( 'WP_CORE_TEST_DB_PASSWORD' );
$db_host     = getenv( 'WP_CORE_TEST_DB_HOST' );
$year        = getenv( 'WP_CORE_TEST_YEAR' );
$backend     = getenv( 'WP_CORE_DUCKDB_BACKEND' );
$backend_slug = getenv( 'WP_CORE_DUCKDB_BACKEND_SLUG' );
$autoload    = getenv( 'WP_CORE_DUCKDB_AUTOLOAD' );
$database_dir = rtrim( getenv( 'WP_CORE_DUCKDB_DATABASE_DIR' ), '/\\' ) . '/';

$config = file_get_contents( $wp_dir . '/wp-tests-config-sample.php' );
$config = str_replace(
	array(
		'youremptytestdbnamehere',
		'yourusernamehere',
		'yourpasswordhere',
		'localhost',
	),
	array(
		$db_name,
		$db_user,
		$db_password,
		$db_host,
	),
	$config
);
$config .= "\ndefine( 'DB_ENGINE', 'duckdb' );\n";
$config .= "define( 'DATABASE_ENGINE', 'duckdb' );\n";
$config .= 'define( \'DB_DIR\', ' . var_export( $database_dir, true ) . " );\n";
$config .= "define( 'DUCKDB_FILE', '.ht.duckdb-core-tests' );\n";
$config .= 'define( \'DUCKDB_PHP_AUTOLOAD\', ' . var_export( $autoload, true ) . " );\n";
$config .= "define( 'FS_METHOD', 'direct' );\n";

if ( 'duckdb' !== $backend ) {
	$external_dir = $database_dir . 'duckdb-core-' . $backend_slug . '/';
	$working_db   = $database_dir . '.ht.duckdb-core-' . $backend_slug . '-working';

	$config .= 'define( \'DUCKDB_BACKEND\', ' . var_export( $backend, true ) . " );\n";
	$config .= 'define( \'DUCKDB_EXTERNAL_STORAGE_DIR\', ' . var_export( $external_dir, true ) . " );\n";
	$config .= 'define( \'DUCKDB_WORKING_DATABASE_FILE\', ' . var_export( $working_db, true ) . " );\n";

	$custom_constants = array(
		'DUCKDB_BACKEND_FILE_EXTENSION' => getenv( 'WP_CORE_DUCKDB_BACKEND_FILE_EXTENSION' ),
		'DUCKDB_BACKEND_READ_SQL'       => getenv( 'WP_CORE_DUCKDB_BACKEND_READ_SQL' ),
		'DUCKDB_BACKEND_WRITE_SQL'      => getenv( 'WP_CORE_DUCKDB_BACKEND_WRITE_SQL' ),
		'DUCKDB_BACKEND_SETUP_SQL'      => getenv( 'WP_CORE_DUCKDB_BACKEND_SETUP_SQL' ),
		'DUCKDB_BACKEND_TABLES'         => getenv( 'WP_CORE_DUCKDB_BACKEND_TABLES' ),
		'DUCKDB_BACKEND_ATOMIC_FLUSH'   => getenv( 'WP_CORE_DUCKDB_BACKEND_ATOMIC_FLUSH' ),
	);
	foreach ( $custom_constants as $name => $value ) {
		if ( false !== $value && '' !== $value ) {
			$config .= 'define( \'' . $name . '\', ' . var_export( $value, true ) . " );\n";
		}
	}
}

file_put_contents( $wp_dir . '/wp-tests-config.php', $config );

$license_file = $wp_dir . '/src/license.txt';
if ( file_exists( $license_file ) ) {
	$license = file_get_contents( $license_file );
	$license = preg_replace(
		'/Copyright 2011-\d{4} by the contributors/',
		'Copyright 2011-' . $year . ' by the contributors',
		$license
	);
	file_put_contents( $license_file, $license );
}

foreach ( glob( $wp_dir . '/src/wp-content/themes/*/readme.txt' ) as $readme_file ) {
	$readme = file_get_contents( $readme_file );
	$readme = preg_replace(
		'/Copyright 20\d\d-\d{4} WordPress\.org/',
		'Copyright 2011-' . $year . ' WordPress.org',
		$readme
	);
	$readme = preg_replace(
		'/Copyright \d{4} WordPress\.org/',
		'Copyright ' . $year . ' WordPress.org',
		$readme
	);
	file_put_contents( $readme_file, $readme );
}
PHP

(
	cd "$wp_dir"
	if git apply --check "$patch_file" >/dev/null 2>&1; then
		git apply "$patch_file"
	elif git apply --reverse --check "$patch_file" >/dev/null 2>&1; then
		echo "WordPress core DuckDB PHPUnit patch already applied."
	else
		echo "Unable to apply $patch_file to $wp_dir." >&2
		exit 1
	fi
)

importer_dir="$wp_dir/tests/phpunit/data/plugins/wordpress-importer"
if [ ! -f "$importer_dir/wordpress-importer.php" ]; then
	rm -rf "$importer_dir"
	git clone --depth=1 https://github.com/WordPress/wordpress-importer.git "$importer_dir"
fi

echo "Prepared WordPress core PHPUnit for DuckDB backend: $backend"
