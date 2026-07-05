#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR="$ROOT_DIR/build"
PLUGIN_SLUG="wordpress-databases-support"
PLUGIN_DIR="$BUILD_DIR/$PLUGIN_SLUG"
ZIP_FILE="$BUILD_DIR/$PLUGIN_SLUG.zip"
SQLITE_SUBMODULE_DIR="$ROOT_DIR/external/sqlite-database-integration"
SQLITE_PACKAGE_SRC="$SQLITE_SUBMODULE_DIR/packages/plugin-sqlite-database-integration"
SQLITE_DRIVER_SRC="$SQLITE_SUBMODULE_DIR/packages/mysql-on-sqlite/src"
SQLITE_PACKAGE_DEST="$PLUGIN_DIR/external/sqlite-database-integration/packages/plugin-sqlite-database-integration"

if [ ! -d "$SQLITE_PACKAGE_SRC" ]; then
	git -C "$ROOT_DIR" submodule update --init --recursive external/sqlite-database-integration
fi

if [ ! -d "$SQLITE_PACKAGE_SRC" ] || [ ! -d "$SQLITE_DRIVER_SRC" ]; then
	echo "SQLite submodule is missing. Run: git submodule update --init --recursive" >&2
	exit 1
fi

rm -rf "$PLUGIN_DIR" "$ZIP_FILE"
mkdir -p "$PLUGIN_DIR" "$BUILD_DIR"

cp "$ROOT_DIR/LICENSE" "$PLUGIN_DIR/"
cp "$ROOT_DIR/README.md" "$PLUGIN_DIR/"
cp "$ROOT_DIR/constants.php" "$PLUGIN_DIR/"
cp "$ROOT_DIR/db.copy" "$PLUGIN_DIR/"
cp "$ROOT_DIR/load.php" "$PLUGIN_DIR/"
cp "$ROOT_DIR/setup-database.php" "$PLUGIN_DIR/"
cp "$ROOT_DIR/wordpress-databases-support.php" "$PLUGIN_DIR/"
mkdir -p "$PLUGIN_DIR/bin"
cp "$ROOT_DIR/bin/duckdb-sidecar.php" "$PLUGIN_DIR/bin/"
cp "$ROOT_DIR/bin/install-database-support.php" "$PLUGIN_DIR/bin/"
cp "$ROOT_DIR/bin/setup-database.php" "$PLUGIN_DIR/bin/"
cp -R "$ROOT_DIR/wp-includes" "$PLUGIN_DIR/wp-includes"

mkdir -p "$(dirname "$SQLITE_PACKAGE_DEST")"
cp -R "$SQLITE_PACKAGE_SRC" "$SQLITE_PACKAGE_DEST"

rm -rf "$SQLITE_PACKAGE_DEST/wp-includes/database"
cp -R "$SQLITE_DRIVER_SRC" "$SQLITE_PACKAGE_DEST/wp-includes/database"
rm -rf "$SQLITE_PACKAGE_DEST/composer.json" "$SQLITE_PACKAGE_DEST/vendor" "$SQLITE_PACKAGE_DEST/node_modules"

cd "$BUILD_DIR"
zip -qr "$ZIP_FILE" "$PLUGIN_SLUG/" -x "*.DS_Store"

echo "Built: $ZIP_FILE"
