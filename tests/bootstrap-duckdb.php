<?php

require_once __DIR__ . '/bootstrap-common.php';

if ( ! defined( 'FQDBDIR' ) ) {
	define( 'FQDBDIR', sys_get_temp_dir() . '/wordpress-databases-support-duckdb/' );
}

if ( ! defined( 'FQDUCKDB' ) ) {
	define( 'FQDUCKDB', FQDBDIR . '.ht.duckdb' );
}
