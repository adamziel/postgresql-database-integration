<?php declare(strict_types = 1);

/**
 * DuckDB connection wrapper.
 */
class WP_DuckDB_Connection {
	/**
	 * @var object
	 */
	private $duckdb;

	/**
	 * @var callable(string,array):void|null
	 */
	private $query_logger;

	/**
	 * Cached native query trace flag.
	 *
	 * @var bool|null
	 */
	private static $trace_native_queries_enabled;

	/**
	 * Whether the native DuckDB client has been closed.
	 *
	 * @var bool
	 */
	private $closed = false;

	/**
	 * Whether this connection is inside an explicit transaction.
	 *
	 * @var bool
	 */
	private $in_transaction = false;

	/**
	 * Constructor.
	 *
	 * @param array $options Connection options.
	 *
	 * @throws InvalidArgumentException When options are invalid.
	 * @throws WP_DuckDB_Driver_Exception When DuckDB is unavailable or cannot connect.
	 */
	public function __construct( array $options = array() ) {
		if ( isset( $options['duckdb'] ) && is_object( $options['duckdb'] ) ) {
			$this->duckdb = $options['duckdb'];
			return;
		}

		WP_DuckDB_Runtime::assert_available();

		$path = $options['path'] ?? null;
		if ( ':memory:' === $path ) {
			$path = null;
		}
		if ( null !== $path && ! is_string( $path ) ) {
			throw new InvalidArgumentException( 'DuckDB option "path" must be a string or null.' );
		}

		try {
			$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
			$trace_start  = $this->native_trace_start_time();
			$this->trace_native_connection( 'open_start', $path );
			$this->duckdb = $client_class::create( $path );
			$this->trace_native_connection(
				'open_end',
				$path,
				null === $trace_start ? null : microtime( true ) - $trace_start
			);
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to open DuckDB database: ' . $e->getMessage(), 0, $e );
		}

		$this->apply_connection_setup_sql( $options );
	}

	/**
	 * Run optional per-connection DuckDB setup SQL.
	 *
	 * This is for connection-level DuckDB settings such as SET threads=2. It is
	 * intentionally separate from DUCKDB_BACKEND_SETUP_SQL, which configures
	 * external storage backends with INSTALL, LOAD, ATTACH, and secrets.
	 *
	 * @param array $options Connection options.
	 * @return void
	 *
	 * @throws InvalidArgumentException When setup SQL configuration is invalid.
	 * @throws WP_DuckDB_Driver_Exception When a setup statement fails.
	 */
	protected function apply_connection_setup_sql( array $options = array() ): void {
		$setup_sql = $this->connection_setup_sql_from_options( $options );
		foreach ( $setup_sql as $sql ) {
			try {
				$this->query( $sql );
			} catch ( Throwable $e ) {
				throw new WP_DuckDB_Driver_Exception(
					'DuckDB connection setup failed for statement "' . $this->format_setup_sql_for_error( $sql ) . '": ' . $e->getMessage(),
					0,
					$e
				);
			}
		}
	}

	/**
	 * Resolve setup SQL from options, constants, or environment variables.
	 *
	 * @param array $options Connection options.
	 * @return string[] SQL statements.
	 *
	 * @throws InvalidArgumentException When setup SQL configuration is invalid.
	 */
	protected function connection_setup_sql_from_options( array $options ): array {
		if ( array_key_exists( 'connection_setup_sql', $options ) ) {
			return $this->normalize_connection_setup_sql( $options['connection_setup_sql'], 'connection option "connection_setup_sql"' );
		}

		$json = $this->first_non_empty_environment_value(
			array(
				'WP_DUCKDB_CONNECTION_SETUP_SQL_JSON',
				'DUCKDB_CONNECTION_SETUP_SQL_JSON',
			)
		);
		if ( null !== $json ) {
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				throw new InvalidArgumentException( 'DuckDB connection setup SQL JSON must decode to an array of SQL strings.' );
			}

			return $this->normalize_connection_setup_sql( $decoded, 'DuckDB connection setup SQL JSON' );
		}

		$value = $this->first_non_empty_constant_or_environment_value(
			array(
				'WP_DUCKDB_CONNECTION_SETUP_SQL',
				'DUCKDB_CONNECTION_SETUP_SQL',
			)
		);

		return $this->normalize_connection_setup_sql( $value, 'DuckDB connection setup SQL' );
	}

	/**
	 * Normalize setup SQL into a list of non-empty statements.
	 *
	 * @param mixed  $value Configuration value.
	 * @param string $source Human-readable source.
	 * @return string[] SQL statements.
	 *
	 * @throws InvalidArgumentException When setup SQL configuration is invalid.
	 */
	private function normalize_connection_setup_sql( $value, string $source ): array {
		if ( null === $value || false === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return array();
		}

		if ( is_string( $value ) ) {
			return array( trim( $value ) );
		}

		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( $source . ' must be a SQL string or an array of SQL strings.' );
		}

		$statements = array();
		foreach ( $value as $statement ) {
			if ( ! is_string( $statement ) ) {
				throw new InvalidArgumentException( $source . ' must contain only SQL strings.' );
			}
			$statement = trim( $statement );
			if ( '' !== $statement ) {
				$statements[] = $statement;
			}
		}

		return $statements;
	}

	/**
	 * Read the first non-empty constant or environment value.
	 *
	 * @param string[] $names Constant/environment names.
	 * @return mixed|null Value.
	 */
	private function first_non_empty_constant_or_environment_value( array $names ) {
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$value = constant( $name );
				if ( ! $this->is_empty_config_value( $value ) ) {
					return $value;
				}
			}
		}

		return $this->first_non_empty_environment_value( $names );
	}

	/**
	 * Read the first non-empty environment value.
	 *
	 * @param string[] $names Environment names.
	 * @return string|null Value.
	 */
	private function first_non_empty_environment_value( array $names ): ?string {
		foreach ( $names as $name ) {
			$value = getenv( $name );
			if ( is_string( $value ) && ! $this->is_empty_config_value( $value ) ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Check whether a configured value is empty.
	 *
	 * @param mixed $value Configured value.
	 * @return bool Whether the value is empty.
	 */
	private function is_empty_config_value( $value ): bool {
		return false === $value || null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	/**
	 * Format setup SQL for a compact exception message.
	 *
	 * @param string $sql SQL statement.
	 * @return string Bounded SQL.
	 */
	private function format_setup_sql_for_error( string $sql ): string {
		$sql = preg_replace( '/\s+/', ' ', str_replace( array( "\r", "\n" ), ' ', $sql ) );
		$sql = trim( (string) $sql );
		if ( strlen( $sql ) > 200 ) {
			$sql = substr( $sql, 0, 197 ) . '...';
		}

		return $sql;
	}

	/**
	 * Execute a DuckDB query.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Optional parameters.
	 * @return WP_DuckDB_Result_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When execution fails.
	 */
	public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
		$this->assert_open();

		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}

		if ( count( $params ) > 0 ) {
			return $this->prepare( $sql )->execute( $params );
		}

		try {
			$this->trace_native_query( 'query', $sql );
			$trace_start = $this->native_trace_start_time();
			$result      = $this->duckdb->query( $sql );
			$this->trace_native_query_timing( 'query_native', $sql, $trace_start );
			$statement = $this->create_statement_from_result( $result, $sql );
			$this->trace_native_query_timing( 'query_total', $sql, $trace_start );
			return $statement;
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB query failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Prepare a DuckDB query.
	 *
	 * @param string $sql SQL query.
	 * @return WP_DuckDB_Prepared_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When preparation fails.
	 */
	public function prepare( string $sql ): WP_DuckDB_Prepared_Statement {
		$this->assert_open();

		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, array() );
		}

		try {
			$this->trace_native_query( 'prepare', $sql );
			$trace_start = $this->native_trace_start_time();
			$statement   = $this->duckdb->preparedStatement( $sql );
			$this->trace_native_query_timing( 'prepare_native', $sql, $trace_start );
			return new WP_DuckDB_Prepared_Statement( $this, $statement, $sql );
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to prepare DuckDB query: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Emit a bounded native DuckDB query trace line when requested.
	 *
	 * @param string $phase  Query execution phase.
	 * @param string $sql    Native DuckDB SQL.
	 * @param array  $params Optional parameter values.
	 */
	public function trace_native_query( string $phase, string $sql, array $params = array() ): void {
		if ( ! self::trace_native_queries_enabled() ) {
			return;
		}

		error_log(
			sprintf(
				'WP_DUCKDB_NATIVE_QUERY pid=%d phase=%s params=%d sql=%s',
				getmypid(),
				$phase,
				count( $params ),
				$this->format_trace_sql( $sql )
			)
		);
	}

	/**
	 * Emit a bounded native DuckDB query timing line when requested.
	 *
	 * @param string     $phase         Query execution phase.
	 * @param string     $sql           Native DuckDB SQL.
	 * @param float|null $started_at    Start time from microtime(true), or null when tracing is off.
	 * @param array      $params        Optional parameter values.
	 */
	public function trace_native_query_timing( string $phase, string $sql, ?float $started_at, array $params = array() ): void {
		if ( null === $started_at || ! self::trace_native_queries_enabled() ) {
			return;
		}

		error_log(
			sprintf(
				'WP_DUCKDB_NATIVE_QUERY_TIMING pid=%d phase=%s params=%d elapsed_ms=%.3f sql=%s',
				getmypid(),
				$phase,
				count( $params ),
				1000 * ( microtime( true ) - $started_at ),
				$this->format_trace_sql( $sql )
			)
		);
	}

	/**
	 * Return a native trace start time when native tracing is enabled.
	 *
	 * @return float|null Start time from microtime(true), or null when tracing is off.
	 */
	public function native_trace_start_time(): ?float {
		return self::trace_native_queries_enabled() ? microtime( true ) : null;
	}

	/**
	 * Format SQL for one bounded trace line.
	 *
	 * @param string $sql SQL.
	 * @return string Formatted SQL.
	 */
	private function format_trace_sql( string $sql ): string {
		$sql = preg_replace( '/\s+/', ' ', str_replace( array( "\r", "\n" ), ' ', $sql ) );
		$sql = trim( (string) $sql );
		if ( strlen( $sql ) > 2000 ) {
			$sql = substr( $sql, 0, 1997 ) . '...';
		}

		return '' === $sql ? '<empty>' : $sql;
	}

	/**
	 * Emit a bounded native DuckDB connection trace line when requested.
	 *
	 * @param string      $phase Connection phase.
	 * @param string|null $path  DuckDB database path.
	 */
	private function trace_native_connection( string $phase, ?string $path, ?float $elapsed_seconds = null ): void {
		if ( ! self::trace_native_queries_enabled() ) {
			return;
		}

		error_log(
			sprintf(
				'WP_DUCKDB_NATIVE_CONNECT pid=%d phase=%s elapsed_ms=%s path=%s',
				getmypid(),
				$phase,
				null === $elapsed_seconds ? 'n/a' : sprintf( '%.3f', 1000 * $elapsed_seconds ),
				null === $path ? ':memory:' : $path
			)
		);
	}

	/**
	 * Whether native DuckDB query tracing is enabled.
	 *
	 * @return bool
	 */
	private static function trace_native_queries_enabled(): bool {
		if ( null === self::$trace_native_queries_enabled ) {
			$value                              = getenv( 'WP_DUCKDB_TRACE_NATIVE_QUERIES' );
			self::$trace_native_queries_enabled = is_string( $value )
				&& '' !== $value
				&& '0' !== $value
				&& 'false' !== strtolower( $value )
				&& 'no' !== strtolower( $value );
		}

		return self::$trace_native_queries_enabled;
	}

	/**
	 * Begin a transaction.
	 *
	 * @return bool
	 *
	 * @throws WP_DuckDB_Driver_Exception When a transaction is already active or BEGIN fails.
	 */
	public function beginTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		if ( $this->in_transaction ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB transaction is already active.' );
		}

		$this->query( 'BEGIN TRANSACTION' );
		$this->in_transaction = true;
		return true;
	}

	/**
	 * WordPress-style transaction alias.
	 *
	 * @return void
	 */
	public function begin_transaction(): void {
		$this->beginTransaction();
	}

	/**
	 * Commit the active transaction.
	 *
	 * @return bool
	 *
	 * @throws WP_DuckDB_Driver_Exception When no transaction is active or COMMIT fails.
	 */
	public function commit(): bool {
		if ( ! $this->in_transaction ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB transaction is not active.' );
		}

		try {
			$this->query( 'COMMIT' );
		} finally {
			$this->in_transaction = false;
		}

		return true;
	}

	/**
	 * Roll back the active transaction.
	 *
	 * @return bool
	 *
	 * @throws WP_DuckDB_Driver_Exception When no transaction is active or ROLLBACK fails.
	 */
	public function rollback(): bool {
		if ( ! $this->in_transaction ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB transaction is not active.' );
		}

		try {
			$this->query( 'ROLLBACK' );
		} finally {
			$this->in_transaction = false;
		}

		return true;
	}

	/**
	 * Roll back a native DuckDB transaction even if this wrapper did not open it.
	 *
	 * Some recovery paths need to clean up an aborted DuckDB transaction after
	 * raw SQL opened it through query() without updating the wrapper flag.
	 *
	 * @return bool
	 *
	 * @throws WP_DuckDB_Driver_Exception When DuckDB rejects ROLLBACK.
	 */
	public function rollbackNativeTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		try {
			$this->query( 'ROLLBACK' );
		} finally {
			$this->in_transaction = false;
		}

		return true;
	}

	/**
	 * Check whether a transaction is active.
	 *
	 * @return bool
	 */
	public function inTransaction(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->in_transaction;
	}

	/**
	 * Create a statement wrapper from a DuckDB PHP ResultSet.
	 *
	 * @param object      $result DuckDB PHP ResultSet.
	 * @param string|null $sql    SQL query that produced the result.
	 * @return WP_DuckDB_Result_Statement
	 */
	public function create_statement_from_result( $result, ?string $sql = null ): WP_DuckDB_Result_Statement {
		$columns = iterator_to_array( $result->columnNames() );
		$columns = array_values( $columns );
		$rows    = array();

		foreach ( $result->rows( true ) as $row ) {
			$rows[] = $this->normalize_row( $columns, $row );
		}

		$affected_rows = 0;
		if ( array( 'Count' ) === $columns && $this->is_affected_row_statement( $sql ) ) {
			$affected_rows = isset( $rows[0][0] ) ? (int) $rows[0][0] : 0;
			return new WP_DuckDB_Result_Statement( array(), array(), $affected_rows );
		}
		if ( array( 'Count' ) === $columns && $this->is_success_statement( $sql ) ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}
		if ( array( 'Success' ) === $columns && $this->is_success_statement( $sql ) ) {
			return new WP_DuckDB_Result_Statement( array(), array(), 0 );
		}

		return new WP_DuckDB_Result_Statement( $columns, $rows, $affected_rows );
	}

	/**
	 * Check whether SQL is expected to produce a DuckDB affected-row Count result.
	 *
	 * @param string|null $sql SQL query.
	 * @return bool
	 */
	private function is_affected_row_statement( ?string $sql ): bool {
		return in_array(
			$this->get_statement_verb( $sql ),
			array( 'insert', 'update', 'delete', 'replace' ),
			true
		);
	}

	/**
	 * Check whether SQL is expected to produce a DuckDB command-success result.
	 *
	 * @param string|null $sql SQL query.
	 * @return bool
	 */
	private function is_success_statement( ?string $sql ): bool {
		return in_array(
			$this->get_statement_verb( $sql ),
			array(
				'alter',
				'attach',
				'begin',
				'checkpoint',
				'commit',
				'create',
				'detach',
				'drop',
				'reset',
				'rollback',
				'set',
				'truncate',
				'use',
				'vacuum',
			),
			true
		);
	}

	/**
	 * Get the first SQL statement verb after leading comments.
	 *
	 * @param string|null $sql SQL query.
	 * @return string|null
	 */
	private function get_statement_verb( ?string $sql ): ?string {
		if ( null === $sql ) {
			return null;
		}

		if (
			1 !== preg_match(
				'/^\s*(?:(?:\/\*.*?\*\/|--[^\r\n]*|#[^\r\n]*)\s*)*([a-z]+)/is',
				$sql,
				$matches
			)
		) {
			return null;
		}

		return strtolower( $matches[1] );
	}

	/**
	 * Quote a value for SQL.
	 *
	 * @param mixed $value Value to quote.
	 * @return string
	 */
	public function quote( $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$value = (string) $value;
		if ( false === strpos( $value, "\0" ) ) {
			return "'" . str_replace( "'", "''", $value ) . "'";
		}

		$pieces = array();
		foreach ( explode( "\0", $value ) as $offset => $part ) {
			if ( 0 !== $offset ) {
				$pieces[] = 'chr(0)';
			}
			if ( '' !== $part ) {
				$pieces[] = "'" . str_replace( "'", "''", $part ) . "'";
			}
		}

		return implode( ' || ', $pieces );
	}

	/**
	 * Quote a DuckDB identifier.
	 *
	 * @param string $unquoted_identifier Unquoted identifier.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the identifier contains a NUL byte.
	 */
	public function quote_identifier( string $unquoted_identifier ): string {
		if ( false !== strpos( $unquoted_identifier, "\0" ) ) {
			throw new InvalidArgumentException( 'DuckDB identifiers cannot contain NUL bytes.' );
		}
		return '"' . str_replace( '"', '""', $unquoted_identifier ) . '"';
	}

	/**
	 * Set a query logger.
	 *
	 * @param callable(string,array):void $logger Query logger.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}

	/**
	 * Close the native DuckDB client.
	 *
	 * DuckDB only allows one writer process to open a database file at a time.
	 * Closing explicitly lets storage backends release their lock after the
	 * native handle is gone, instead of relying on PHP object teardown order.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( $this->closed ) {
			return;
		}

		$this->duckdb         = null;
		$this->in_transaction = false;
		$this->closed         = true;
	}

	/**
	 * Whether the native DuckDB client is closed.
	 *
	 * @return bool
	 */
	public function is_closed(): bool {
		return $this->closed || ! is_object( $this->duckdb );
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Get the raw DuckDB PHP client object.
	 *
	 * @return object
	 */
	public function get_client() {
		$this->assert_open();
		return $this->duckdb;
	}

	/**
	 * Assert the connection is still open.
	 *
	 * @return void
	 */
	private function assert_open(): void {
		if ( $this->is_closed() ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB connection is closed.' );
		}
	}

	/**
	 * Normalize a DuckDB PHP row into column order.
	 *
	 * @param string[] $columns Column names.
	 * @param array    $row     DuckDB row keyed by column name.
	 * @return array
	 */
	private function normalize_row( array $columns, array $row ): array {
		$normalized = array();
		foreach ( $columns as $column ) {
			$normalized[] = $this->normalize_value( $row[ $column ] ?? null );
		}
		return $normalized;
	}

	/**
	 * Normalize DuckDB PHP typed values to scalar values where possible.
	 *
	 * @param mixed $value DuckDB PHP value.
	 * @return mixed
	 */
	private function normalize_value( $value ) {
		if ( is_object( $value ) ) {
			if ( method_exists( $value, 'data' ) ) {
				return $value->data();
			}
			if ( method_exists( $value, '__toString' ) ) {
				return (string) $value;
			}
		}
		return $value;
	}
}
