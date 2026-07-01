#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
	echo "Usage: $0 <wordpress-develop-dir> [postgresql-database-integration-dir]" >&2
	exit 2
fi

wp_dir=$(cd "$1" && pwd)
plugin_dir=${2:-$(cd "$(dirname "$0")/.." && pwd)}
plugin_dir=$(cd "$plugin_dir" && pwd)
plugin_dest="$wp_dir/src/wp-content/plugins/postgresql-database-integration"
patch_file="$plugin_dir/tests/wordpress-core-phpunit-postgresql-6.7.2.patch"

if [ ! -f "$wp_dir/wp-tests-config-sample.php" ] || [ ! -f "$wp_dir/phpunit.xml.dist" ]; then
	echo "Expected a wordpress-develop checkout at $wp_dir." >&2
	exit 2
fi

if [ ! -f "$plugin_dir/db.copy" ] || [ ! -f "$patch_file" ]; then
	echo "Expected a postgresql-database-integration checkout at $plugin_dir." >&2
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

export WP_CORE_DIR="$wp_dir"
export WP_CORE_TEST_DB_NAME="${WP_CORE_TEST_DB_NAME:-wordpress_develop_tests}"
export WP_CORE_TEST_DB_USER="${WP_CORE_TEST_DB_USER:-wordpress}"
export WP_CORE_TEST_DB_PASSWORD="${WP_CORE_TEST_DB_PASSWORD:-wordpress}"
export WP_CORE_TEST_DB_HOST="${WP_CORE_TEST_DB_HOST:-127.0.0.1:5432}"
export WP_CORE_TEST_YEAR="${WP_CORE_TEST_YEAR:-$(date -u +%Y)}"

php <<'PHP'
<?php
$wp_dir      = getenv( 'WP_CORE_DIR' );
$db_name     = getenv( 'WP_CORE_TEST_DB_NAME' );
$db_user     = getenv( 'WP_CORE_TEST_DB_USER' );
$db_password = getenv( 'WP_CORE_TEST_DB_PASSWORD' );
$db_host     = getenv( 'WP_CORE_TEST_DB_HOST' );
$year        = getenv( 'WP_CORE_TEST_YEAR' );

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
$config .= "\ndefine( 'DB_ENGINE', 'postgresql' );\n";
$config .= "define( 'DATABASE_ENGINE', 'postgresql' );\n";
$config .= "define( 'FS_METHOD', 'direct' );\n";
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
		echo "WordPress core PostgreSQL PHPUnit patch already applied."
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
