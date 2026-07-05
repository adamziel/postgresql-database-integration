#!/usr/bin/env php
<?php
/**
 * Configure WordPress to use a supported database drop-in.
 *
 * This file intentionally runs when required from `php -r` so a new site can
 * be configured with a one-liner.
 *
 * @package wordpress-databases-support
 */

$wp_databases_support_setup_class = dirname( __DIR__ ) . '/wp-includes/database/setup/class-wp-databases-support-setup-installer.php';
if ( ! is_file( $wp_databases_support_setup_class ) ) {
	fwrite( STDERR, "Could not locate setup installer class.\n" );
	exit( 1 );
}

require_once $wp_databases_support_setup_class;

if ( PHP_SAPI === 'cli' && ! defined( 'WP_DATABASES_SUPPORT_SETUP_NO_RUN' ) ) {
	$installer = new WP_Databases_Support_Setup_Installer( dirname( __DIR__ ) );
	exit( $installer->run_cli( isset( $argv ) && is_array( $argv ) ? $argv : array( __FILE__ ) ) );
}
