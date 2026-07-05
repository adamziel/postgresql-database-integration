<?php
/**
 * Standalone browser setup for first-install database driver selection.
 *
 * Open this file before running WordPress' normal installer:
 * wp-content/plugins/wordpress-databases-support/setup-database.php
 *
 * @package wordpress-databases-support
 */

if ( PHP_SAPI === 'cli' ) {
	fwrite( STDERR, "Open setup-database.php in a browser, or use bin/setup-database.php from the CLI.\n" );
	exit( 2 );
}

require_once __DIR__ . '/wp-includes/database/setup/class-wp-databases-support-setup-installer.php';

$wp_databases_support_setup_installer = new WP_Databases_Support_Setup_Installer( __DIR__ );
$wp_databases_support_setup_installer->run_web( $_SERVER, $_POST );
