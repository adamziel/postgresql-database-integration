<?php
/**
 * WordPress schema stub for standalone install-function tests.
 *
 * @package wp-postgresql-integration
 */

function wp_get_db_schema( $scope = 'all' ) {
	if ( 'global' === $scope ) {
		return "CREATE TABLE wp_site (
			id bigint(20) unsigned NOT NULL auto_increment,
			domain varchar(200) NOT NULL default '',
			PRIMARY KEY (id)
		) DEFAULT CHARACTER SET utf8mb4";
	}

	return "CREATE TABLE wp_install_probe (
		id bigint(20) unsigned NOT NULL auto_increment,
		option_name varchar(191) NOT NULL default '',
		PRIMARY KEY (id)
	) DEFAULT CHARACTER SET utf8mb4";
}
