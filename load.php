<?php
/**
 * Plugin Name: PostgreSQL Database Integration
 * Description: PostgreSQL wpdb drop-in and MySQL-compatible PostgreSQL driver for WordPress.
 * Author: The WordPress Team
 * Version: 0.1.0
 * Requires PHP: 7.2
 * Textdomain: postgresql-database-integration
 *
 * @package wp-postgresql-integration
 */

require_once __DIR__ . '/wp-includes/database/version.php';
require_once __DIR__ . '/constants.php';

define( 'POSTGRESQL_DATABASE_INTEGRATION_MAIN_FILE', __FILE__ );
