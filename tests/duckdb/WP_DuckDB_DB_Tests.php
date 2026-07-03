<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

#[PHPUnit\Framework\Attributes\Group( 'duckdb' )]
class WP_DuckDB_DB_Tests extends WP_DuckDB_TestCase {
	public function test_close_clears_cached_driver_and_check_connection_reconnects(): void {
		$this->requireDuckDBRuntime();

		$result = $this->run_isolated_wpdb_script(
			<<<'PHP'
$database_dir = sys_get_temp_dir() . '/wp-duckdb-db-lifecycle-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) . '/';
mkdir( $database_dir, 0777, true );

define( 'FQDBDIR', $database_dir );
define( 'FQDUCKDB', $database_dir . '.ht.duckdb' );

require_once getcwd() . '/vendor/autoload.php';
require_once getcwd() . '/wp-includes/database/load.php';
require_once getcwd() . '/wp-includes/duckdb/class-wp-duckdb-db.php';

$db           = new WP_DuckDB_DB( 'wordpress' );
$first_driver = $db->dbh;
$first_driver_open = $first_driver instanceof WP_DuckDB_Driver && ! $first_driver->is_closed();

$first_close  = $db->close();
$ready_after_first_close = $db->ready;
$has_connected_after_close = $db->has_connected;
$second_close = $db->close();
$reconnected  = $db->check_connection( false );
$second_driver = $db->dbh;

$payload = array(
	'first_driver_open'        => $first_driver_open,
	'first_close'              => $first_close,
	'second_close'             => $second_close,
	'ready_after_first_close'  => $ready_after_first_close,
	'has_connected_after_close'=> $has_connected_after_close,
	'cached_driver_cleared'    => ! isset( $GLOBALS['@duckdb_driver'] ) || $GLOBALS['@duckdb_driver'] !== $first_driver,
	'first_driver_closed'      => $first_driver instanceof WP_DuckDB_Driver && $first_driver->is_closed(),
	'reconnected'              => $reconnected,
	'second_driver_open'       => $second_driver instanceof WP_DuckDB_Driver && ! $second_driver->is_closed(),
	'second_driver_is_fresh'   => $second_driver instanceof WP_DuckDB_Driver && $second_driver !== $first_driver,
);

$db->close();
wp_duckdb_db_test_remove_dir( $database_dir );
wp_duckdb_db_test_respond( $payload );
PHP
		);

		$this->assertSame(
			array(
				'first_driver_open'         => true,
				'first_close'               => true,
				'second_close'              => false,
				'ready_after_first_close'   => false,
				'has_connected_after_close' => false,
				'cached_driver_cleared'     => true,
				'first_driver_closed'       => true,
				'reconnected'               => true,
				'second_driver_open'        => true,
				'second_driver_is_fresh'    => true,
			),
			$result
		);
	}

	/**
	 * Runs a DuckDB wpdb script in a separate PHP process.
	 *
	 * @param string $script Script body without the opening PHP tag.
	 * @return array Decoded JSON response from the script.
	 */
	private function run_isolated_wpdb_script( string $script ): array {
		$script_file = tempnam( sys_get_temp_dir(), 'wp_duckdb_db_' );
		if ( false === $script_file ) {
			$this->fail( 'Could not create temporary DuckDB wpdb test script.' );
		}

		$script_written = file_put_contents(
			$script_file,
			"<?php\n" . $this->get_isolated_script_prelude() . "\n" . $script
		);
		if ( false === $script_written ) {
			unlink( $script_file );
			$this->fail( 'Could not write temporary DuckDB wpdb test script.' );
		}

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process         = proc_open(
			escapeshellarg( PHP_BINARY ) . ' -d ffi.enable=1 ' . escapeshellarg( $script_file ),
			$descriptor_spec,
			$pipes,
			dirname( __DIR__, 2 )
		);

		if ( ! is_resource( $process ) ) {
			unlink( $script_file );
			$this->fail( 'Could not start isolated DuckDB wpdb test process.' );
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exitcode = proc_close( $process );
		unlink( $script_file );

		$this->assertSame(
			0,
			$exitcode,
			"Isolated DuckDB wpdb script failed.\nSTDOUT:\n" . $stdout . "\nSTDERR:\n" . $stderr
		);

		$decoded = json_decode( $stdout, true );
		$this->assertIsArray(
			$decoded,
			"Isolated DuckDB wpdb script did not return JSON.\nSTDOUT:\n" . $stdout . "\nSTDERR:\n" . $stderr
		);

		return $decoded;
	}

	/**
	 * Gets helper code prepended to every isolated script.
	 *
	 * @return string PHP script body.
	 */
	private function get_isolated_script_prelude(): string {
		return <<<'PHP'
class wpdb {
	public $ready = false;
	public $has_connected = false;
	public $is_mysql = false;
	public $dbname = '';
	public $dbhost = '';
	public $charset = '';
	public $collate = '';
	public $last_error = '';
	public $last_query = null;
	public $last_result = array();
	public $rows_affected = 0;
	public $num_rows = 0;
	public $result = null;
	public $insert_id = 0;
	public $suppress_errors = true;
	public $show_errors = false;
	public $check_current_query = true;
	public $func_call = '';
	protected $dbh = null;
	private $allow_unsafe_unquoted_parameters = true;

	public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {
		$this->dbname = $dbname;
		$this->dbhost = $dbhost;
		$this->db_connect( false );
	}

	public function __get( $name ) {
		if ( 'dbh' === $name ) {
			return $this->dbh;
		}
		if ( 'allow_unsafe_unquoted_parameters' === $name ) {
			return $this->allow_unsafe_unquoted_parameters;
		}
		return property_exists( $this, $name ) ? $this->$name : null;
	}

	public function __set( $name, $value ) {
		if ( 'dbh' === $name ) {
			$this->dbh = $value;
			return;
		}
		$this->$name = $value;
	}

	public function __isset( $name ) {
		return null !== $this->__get( $name );
	}

	public function __unset( $name ) {
		if ( 'dbh' === $name ) {
			$this->dbh = null;
		}
	}

	public function db_connect( $allow_bail = true ) {
		return false;
	}

	public function add_placeholder_escape( $query ) {
		return $query;
	}
}

function apply_filters( $tag, $value ) {
	return $value;
}

function wp_load_translations_early() {}

function __( $text ) {
	return $text;
}

function is_multisite() {
	return false;
}

function wp_die( $message = '' ) {
	throw new RuntimeException( is_scalar( $message ) ? (string) $message : 'wp_die' );
}

function wp_duckdb_db_test_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $path ) {
		if ( $path->isDir() ) {
			rmdir( $path->getPathname() );
		} else {
			unlink( $path->getPathname() );
		}
	}
	rmdir( $dir );
}

function wp_duckdb_db_test_respond( array $payload ): void {
	echo json_encode( $payload );
}
PHP;
	}
}
