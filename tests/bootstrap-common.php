<?php
/**
 * Shared PHPUnit bootstrap for the standalone PostgreSQL integration tests.
 *
 * @package wp-postgresql-integration
 */

if ( ! defined( 'WP_POSTGRESQL_TEST_ROOT' ) ) {
	define( 'WP_POSTGRESQL_TEST_ROOT', dirname( __DIR__ ) );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', WP_POSTGRESQL_TEST_ROOT . '/tests/wordpress-stubs/' );
}

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_deprecated_argument' ) ) {
	function _deprecated_argument( $function, $version, $message = '' ) {
		$GLOBALS['wp_postgresql_test_deprecated_arguments'][] = array( $function, $version, $message );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		throw new RuntimeException( wp_strip_all_tags( (string) $message ) );
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get the first error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'mbstring_binary_safe_encoding' ) ) {
	function mbstring_binary_safe_encoding() {}
}

if ( ! function_exists( 'reset_mbstring_encoding' ) ) {
	function reset_mbstring_encoding() {}
}

if ( ! class_exists( 'wpdb', false ) ) {
	class wpdb {
		/**
		 * Database name.
		 *
		 * @var string
		 */
		public $dbname;

		/**
		 * Database user.
		 *
		 * @var string
		 */
		public $dbuser;

		/**
		 * Database password.
		 *
		 * @var string
		 */
		public $dbpassword;

		/**
		 * Database host.
		 *
		 * @var string
		 */
		public $dbhost;

		/**
		 * Last error message.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Last query result.
		 *
		 * @var mixed
		 */
		public $last_result;

		/**
		 * Last SQL query.
		 *
		 * @var string
		 */
		public $last_query = '';

		/**
		 * Whether the database connection is ready.
		 *
		 * @var bool
		 */
		public $ready = false;

		/**
		 * Number of rows returned or affected.
		 *
		 * @var int
		 */
		public $num_rows = 0;

		/**
		 * Number of queries executed.
		 *
		 * @var int
		 */
		public $num_queries = 0;

		/**
		 * Query function call description.
		 *
		 * @var string
		 */
		public $func_call = '';

		/**
		 * Last raw result set.
		 *
		 * @var mixed
		 */
		public $result;

		/**
		 * Column info cache.
		 *
		 * @var mixed
		 */
		public $col_info;

		/**
		 * Saved query log.
		 *
		 * @var array
		 */
		public $queries = array();

		/**
		 * Whether errors are suppressed.
		 *
		 * @var bool
		 */
		public $suppress_errors = false;

		/**
		 * Whether errors should be shown.
		 *
		 * @var bool
		 */
		public $show_errors = false;

		/**
		 * Number of rows affected.
		 *
		 * @var int
		 */
		public $rows_affected = 0;

		/**
		 * Insert id.
		 *
		 * @var int|string
		 */
		public $insert_id = 0;

		/**
		 * MySQL compatibility marker used by WordPress metadata helpers.
		 *
		 * @var bool
		 */
		public $is_mysql = true;

		/**
		 * Current charset.
		 *
		 * @var string
		 */
		public $charset = 'utf8mb4';

		/**
		 * Current collation.
		 *
		 * @var string
		 */
		public $collate = 'utf8mb4_unicode_ci';

		/**
		 * Table charset cache.
		 *
		 * @var array
		 */
		public $table_charset = array();

		/**
		 * Column metadata cache.
		 *
		 * @var array
		 */
		public $col_meta = array();

		/**
		 * Prefix used by test table names.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Constructor.
		 *
		 * @param string $dbuser     Database user.
		 * @param string $dbpassword Database password.
		 * @param string $dbname     Database name.
		 * @param string $dbhost     Database host.
		 */
		public function __construct( $dbuser = '', $dbpassword = '', $dbname = '', $dbhost = '' ) {
			$this->dbuser     = $dbuser;
			$this->dbpassword = $dbpassword;
			$this->dbname     = $dbname;
			$this->dbhost     = $dbhost;
		}

		/**
		 * Report whether a WordPress database capability is available.
		 *
		 * @param string $db_cap Capability name.
		 * @return bool
		 */
		public function has_cap( $db_cap ) {
			return in_array( $db_cap, array( 'collation', 'utf8mb4', 'utf8mb4_520' ), true );
		}

		/**
		 * Basic query passthrough for tests.
		 *
		 * @param string $query SQL query.
		 * @return mixed
		 */
		public function query( $query ) {
			$this->last_query = $query;
			if ( $this->dbh instanceof WP_PostgreSQL_Driver ) {
				$result              = $this->dbh->query( $query );
				$this->last_result   = $result;
				$this->rows_affected = is_int( $result ) ? $result : 0;
				$this->num_rows      = is_array( $result ) ? count( $result ) : $this->rows_affected;
				$this->insert_id     = $this->dbh->get_insert_id();
				return $result;
			}

			return false;
		}

		/**
		 * Preserve the base method shape expected by the drop-in.
		 */
		public function flush() {
			$this->last_result   = null;
			$this->last_query    = '';
			$this->num_rows      = 0;
			$this->rows_affected = 0;
		}

		/**
		 * Simple error printer replacement for tests.
		 *
		 * @param string $str Error message.
		 * @return false
		 */
		public function print_error( $str = '' ) {
			$this->last_error = (string) $str;
			return false;
		}
	}
}

require_once WP_POSTGRESQL_TEST_ROOT . '/wp-includes/database/load.php';
require_once WP_POSTGRESQL_TEST_ROOT . '/tests/WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO.php';
