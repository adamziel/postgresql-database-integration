#!/usr/bin/env bash
set -euo pipefail

wordpress_src=/usr/src/wordpress
wordpress_dest=/var/www/html
database_dir="$wordpress_dest/wp-content/database"
json_dir="$database_dir/duckdb-json"
dropin_path="$wordpress_dest/wp-content/db.php"
install_marker="$database_dir/.duckdb-json-installed"

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

reset_incomplete_storage() {
	first_entry=$(find "$database_dir" -mindepth 1 -maxdepth 1 -print -quit)

	if [ -e "$install_marker" ]; then
		return
	fi
	if [ -z "$first_entry" ]; then
		return
	fi

	echo "Resetting incomplete DuckDB JSON example storage."
	find "$database_dir" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
}

reset_storage() {
	echo "Resetting DuckDB JSON example storage."
	find "$database_dir" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
	mkdir -p "$json_dir"
}

ensure_web_writable_paths() {
	if [ "$(id -u)" != "0" ]; then
		return
	fi

	chown -R www-data:www-data "$database_dir" "$wordpress_dest/wp-content/uploads" "$dropin_path"
}

run_as_web_user() {
	command_to_run="$1"

	if [ "$(id -u)" = "0" ] && command -v su >/dev/null 2>&1; then
		su -s /bin/sh -c "$command_to_run" www-data
		return
	fi

	sh -c "$command_to_run"
}

run_installer() {
	failure_output="${1:-summary}"
	installer_output=$(mktemp)

	if run_as_web_user "php -d ffi.enable=1 /usr/local/bin/duckdb-json-install.php" >"$installer_output" 2>&1 \
		&& ! grep -Eq 'One or more database tables are unavailable|Error establishing a database connection' "$installer_output"; then
		cat "$installer_output"
		rm -f "$installer_output"
		return 0
	fi

	if [ "$failure_output" = "full" ]; then
		cat "$installer_output"
	else
		echo "DuckDB JSON installer could not load the current example storage."
	fi
	rm -f "$installer_output"
	return 1
}

run_front_page_check() {
	failure_output="${1:-summary}"
	check_output=$(mktemp)

	if run_as_web_user "php -d ffi.enable=1 /usr/local/bin/duckdb-json-smoke.php" >"$check_output" 2>&1 \
		&& ! grep -Eq 'One or more database tables are unavailable|Error establishing a database connection|Database Error' "$check_output"; then
		cat "$check_output"
		rm -f "$check_output"
		return 0
	fi

	if [ "$failure_output" = "full" ]; then
		cat "$check_output"
	else
		echo "DuckDB JSON front-page check failed against the current example storage."
	fi
	rm -f "$check_output"
	return 1
}

copy_wordpress
mkdir -p "$database_dir" "$wordpress_dest/wp-content/uploads"
reset_incomplete_storage
mkdir -p "$json_dir"
install_dropin
ensure_web_writable_paths

if ! run_installer summary; then
	reset_storage
	ensure_web_writable_paths
	run_installer full
fi
touch "$install_marker"
ensure_web_writable_paths

if ! run_front_page_check summary; then
	reset_storage
	ensure_web_writable_paths
	run_installer full
	touch "$install_marker"
	ensure_web_writable_paths
	run_front_page_check full
fi

echo "WordPress is configured for DuckDB JSON storage."
echo "Open ${WORDPRESS_SITE_URL:-http://localhost:8080}"
echo "JSON table files are stored in /var/www/html/wp-content/database/duckdb-json"

exec "$@"
