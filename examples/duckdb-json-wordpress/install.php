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

require_once '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$site_title     = getenv( 'WORDPRESS_SITE_TITLE' ) ?: 'DuckDB JSON WordPress';
$admin_user     = getenv( 'WORDPRESS_ADMIN_USER' ) ?: 'admin';
$admin_password = getenv( 'WORDPRESS_ADMIN_PASSWORD' ) ?: 'password';
$admin_email    = getenv( 'WORDPRESS_ADMIN_EMAIL' ) ?: 'admin@example.test';

if ( ! is_blog_installed() ) {
	wp_install( $site_title, $admin_user, $admin_email, true, '', $admin_password );
	update_option( 'blogdescription', 'WordPress backed by DuckDB JSON files.' );

	echo "Installed WordPress for the DuckDB JSON example.\n";
} else {
	echo "WordPress is already installed for the DuckDB JSON example.\n";
}

if ( isset( $GLOBALS['wpdb'] ) && method_exists( $GLOBALS['wpdb'], 'flush_storage_backend' ) ) {
	$GLOBALS['wpdb']->flush_storage_backend();
	echo "Flushed DuckDB tables to JSON storage.\n";
}
