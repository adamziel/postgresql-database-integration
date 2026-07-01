<?php
/**
 * First-run installer for the DuckDB JSON Docker example.
 *
 * @package wordpress-databases-support
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'WP_INSTALLING', true );

$wordpress_root = getenv( 'WORDPRESS_ROOT' ) ?: '/var/www/html';
$wordpress_root = rtrim( $wordpress_root, '/\\' );

require_once $wordpress_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$site_title     = getenv( 'WORDPRESS_SITE_TITLE' ) ?: 'DuckDB JSON WordPress';
$admin_user     = getenv( 'WORDPRESS_ADMIN_USER' ) ?: 'admin';
$admin_password = getenv( 'WORDPRESS_ADMIN_PASSWORD' ) ?: 'password';
$admin_email    = getenv( 'WORDPRESS_ADMIN_EMAIL' ) ?: 'admin@example.test';

/**
 * Check install state without calling is_blog_installed().
 *
 * WordPress intentionally dies with a repair page when one or more tables exist
 * but the siteurl option is missing. This example treats that state as an
 * incomplete local install instead.
 *
 * @return bool Whether the WordPress siteurl option exists.
 */
function duckdb_json_example_has_siteurl() {
	global $wpdb;

	$suppress_errors = $wpdb->suppress_errors( true );
	$siteurl         = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
			'siteurl'
		)
	);
	$wpdb->suppress_errors( $suppress_errors );

	return is_string( $siteurl ) && '' !== $siteurl;
}

if ( ! duckdb_json_example_has_siteurl() ) {
	wp_install( $site_title, $admin_user, $admin_email, true, '', $admin_password );
	update_option( 'siteurl', WP_SITEURL );
	update_option( 'home', WP_HOME );
	update_option( 'blogdescription', 'WordPress backed by DuckDB JSON files.' );

	echo "Installed WordPress for the DuckDB JSON example.\n";
} else {
	update_option( 'siteurl', WP_SITEURL );
	update_option( 'home', WP_HOME );
	echo "WordPress is already installed for the DuckDB JSON example.\n";
}

if ( ! duckdb_json_example_has_siteurl() ) {
	fwrite( STDERR, "DuckDB JSON WordPress install did not create a siteurl option.\n" );
	exit( 1 );
}

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
	echo "Flushed DuckDB tables to JSON storage.\n";
}
