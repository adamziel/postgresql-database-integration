<?php
/**
 * Front-page smoke check for the DuckDB JSON Docker example.
 *
 * @package wordpress-databases-support
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wordpress_root = getenv( 'WORDPRESS_ROOT' ) ?: '/var/www/html';
$wordpress_root = rtrim( $wordpress_root, '/\\' );
$site_url       = getenv( 'WORDPRESS_SITE_URL' ) ?: 'http://localhost:8080';
$url_parts      = parse_url( $site_url );
$host           = isset( $url_parts['host'] ) ? $url_parts['host'] : 'localhost';
$port           = isset( $url_parts['port'] ) ? (string) $url_parts['port'] : ( ( isset( $url_parts['scheme'] ) && 'https' === $url_parts['scheme'] ) ? '443' : '80' );

$_SERVER['HTTP_HOST']      = $host . ( in_array( $port, array( '80', '443' ), true ) ? '' : ':' . $port );
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['PHP_SELF']       = '/index.php';
$_SERVER['SERVER_NAME']    = $host;
$_SERVER['SERVER_PORT']    = $port;
if ( isset( $url_parts['scheme'] ) && 'https' === $url_parts['scheme'] ) {
	$_SERVER['HTTPS'] = 'on';
}

define( 'WP_USE_THEMES', true );

ob_start();
require $wordpress_root . '/wp-blog-header.php';
$output = ob_get_clean();

if ( preg_match( '/Error establishing a database connection|One or more database tables are unavailable|Database Error|WordPress &rsaquo; Error/i', $output ) ) {
	fwrite( STDERR, $output );
	exit( 1 );
}

echo "Verified WordPress front page can load through DuckDB JSON storage.\n";
