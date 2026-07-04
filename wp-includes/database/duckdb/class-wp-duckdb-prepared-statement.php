<?php declare(strict_types = 1);

/**
 * Prepared statement wrapper for DuckDB PHP prepared statements.
 */
class WP_DuckDB_Prepared_Statement {
	/**
	 * @var WP_DuckDB_Connection
	 */
	private $connection;

	/**
	 * @var object
	 */
	private $statement;

	/**
	 * @var string
	 */
	private $sql;

	/**
	 * @param WP_DuckDB_Connection $connection DuckDB connection.
	 * @param object               $statement  Native DuckDB PHP prepared statement.
	 * @param string               $sql        SQL query.
	 */
	public function __construct( WP_DuckDB_Connection $connection, $statement, string $sql ) {
		$this->connection = $connection;
		$this->statement  = $statement;
		$this->sql        = $sql;
	}

	/**
	 * Execute the prepared statement.
	 *
	 * @param array|null $params Parameters to bind before execution.
	 * @return WP_DuckDB_Result_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When binding or execution fails.
	 */
	public function execute( $params = null ): WP_DuckDB_Result_Statement {
		try {
			foreach ( (array) $params as $key => $value ) {
				$parameter = is_string( $key ) ? ltrim( $key, ':$' ) : $key + 1;
				$this->statement->bindParam( $parameter, $value );
			}
			$this->connection->trace_native_query( 'execute_prepared', $this->sql, (array) $params );
			$trace_start = $this->connection->native_trace_start_time();
			$result      = $this->statement->execute();
			$this->connection->trace_native_query_timing( 'execute_prepared_native', $this->sql, $trace_start, (array) $params );
			$statement = $this->connection->create_statement_from_result( $result, $this->sql );
			$this->connection->trace_native_query_timing( 'execute_prepared_total', $this->sql, $trace_start, (array) $params );
			return $statement;
		} catch ( Throwable $e ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB prepared statement failed: ' . $e->getMessage(), 0, $e );
		}
	}
}
