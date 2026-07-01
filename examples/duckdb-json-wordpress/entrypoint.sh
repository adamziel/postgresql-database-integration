#!/usr/bin/env bash
set -euo pipefail

wordpress_src=/usr/src/wordpress
wordpress_dest=/var/www/html
database_dir="$wordpress_dest/wp-content/database"
json_dir="$database_dir/duckdb-json"
dropin_path="$wordpress_dest/wp-content/db.php"

copy_wordpress() {
	if [ -e "$wordpress_dest/wp-includes/version.php" ]; then
		return
	fi

	mkdir -p "$wordpress_dest"
	tar -C "$wordpress_src" -cf - . | tar -C "$wordpress_dest" -xf -
}

install_dropin() {
	if [ -e "$dropin_path" ]; then
		return
	fi

	cp "$wordpress_dest/wp-content/plugins/wordpress-databases-support/db.copy" "$dropin_path"
}

copy_wordpress
mkdir -p "$json_dir" "$wordpress_dest/wp-content/uploads"
install_dropin

php -d ffi.enable=1 /usr/local/bin/duckdb-json-install.php

if [ "$(id -u)" = "0" ]; then
	chown -R www-data:www-data "$database_dir" "$wordpress_dest/wp-content/uploads" "$dropin_path"
fi

echo "WordPress is configured for DuckDB JSON storage."
echo "Open ${WORDPRESS_SITE_URL:-http://localhost:8080}"
echo "JSON table files are stored in /var/www/html/wp-content/database/duckdb-json"

exec "$@"
