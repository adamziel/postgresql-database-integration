<?php declare(strict_types = 1);

/**
 * DuckDB connection proxy backed by a long-lived sidecar process.
 */
class WP_DuckDB_Remote_Connection extends WP_DuckDB_Connection {
	/**
	 * @var resource|null
	 */
	private $read_handle;

	/**
	 * @var resource|null
	 */
	private $write_handle;

	/**
	 * @var resource|null
	 */
	private $sidecar_process;

	/**
	 * @var resource[]
	 */
	private $sidecar_pipes = array();

	/**
	 * @var string
	 */
	private $transport = 'unix';

	/**
	 * @var string|null
	 */
	private $socket_path;

	/**
	 * @var string|null
	 */
	private $host;

	/**
	 * @var int|null
	 */
	private $port;

	/**
	 * @var string|null
	 */
	private $url;

	/**
	 * @var string|null
	 */
	private $command;

	/**
	 * @var float
	 */
	private $timeout = 120.0;

	/**
	 * @var bool
	 */
	private $remote_closed = false;

	/**
	 * @param array $options Remote connection options.
	 *
	 * @throws InvalidArgumentException When options are invalid.
	 * @throws WP_DuckDB_Driver_Exception When the sidecar cannot be reached.
	 */
	public function __construct( array $options = array() ) {
		$this->transport = $this->normalize_transport( $options['transport'] ?? $this->infer_transport( $options ) );
		$this->timeout   = $this->normalize_timeout( $options['timeout'] ?? 120 );
		$this->configure_transport( $options );
		parent::__construct( array( 'duckdb' => new stdClass() ) );

		if ( 'http' === $this->transport ) {
			$this->remote_closed = false;
		} elseif ( 'sidecar' === $this->transport ) {
			$this->connect_sidecar_process();
		} else {
			$this->connect_line_socket();
		}

		$this->apply_connection_setup_sql( $options );
	}

	/**
	 * Execute a DuckDB query through the sidecar.
	 *
	 * @param string $sql    SQL query.
	 * @param array  $params Optional parameters.
	 * @return WP_DuckDB_Result_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception When execution fails.
	 */
	public function query( string $sql, array $params = array() ): WP_DuckDB_Result_Statement {
		$this->assert_remote_open();

		$request = json_encode(
			array(
				'sql'    => $sql,
				'params' => array_values( $params ),
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $request ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to encode DuckDB sidecar request.' );
		}

		$trace_start = $this->native_trace_start_time();
		$this->trace_native_query( 'remote_query', $sql, $params );
		$response = $this->request( $request, $sql );
		$this->trace_native_query_timing( 'remote_query_total', $sql, $trace_start, $params );

		if ( empty( $response['ok'] ) ) {
			$message = isset( $response['message'] ) && is_string( $response['message'] )
				? $response['message']
				: 'DuckDB sidecar query failed.';
			throw new WP_DuckDB_Driver_Exception( $message );
		}

		$columns       = isset( $response['columns'] ) && is_array( $response['columns'] ) ? $response['columns'] : array();
		$rows          = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : array();
		$affected_rows = isset( $response['affected_rows'] ) ? (int) $response['affected_rows'] : 0;
		$column_meta   = isset( $response['column_meta'] ) && is_array( $response['column_meta'] ) ? $response['column_meta'] : array();

		return new WP_DuckDB_Result_Statement( $columns, $rows, $affected_rows, $column_meta );
	}

	/**
	 * Prepare is intentionally unsupported across the sidecar boundary.
	 *
	 * @param string $sql SQL query.
	 * @return WP_DuckDB_Prepared_Statement
	 *
	 * @throws WP_DuckDB_Driver_Exception Always.
	 */
	public function prepare( string $sql ): WP_DuckDB_Prepared_Statement {
		throw new WP_DuckDB_Driver_Exception( 'DuckDB sidecar prepared statements are not supported.' );
	}

	/**
	 * Close the sidecar socket.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->write_handle ) ) {
			fclose( $this->write_handle );
		}
		if ( is_resource( $this->read_handle ) && $this->read_handle !== $this->write_handle ) {
			fclose( $this->read_handle );
		}
		foreach ( $this->sidecar_pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		if ( is_resource( $this->sidecar_process ) ) {
			proc_terminate( $this->sidecar_process );
			proc_close( $this->sidecar_process );
		}
		$this->read_handle     = null;
		$this->write_handle    = null;
		$this->sidecar_process = null;
		$this->sidecar_pipes   = array();
		$this->remote_closed   = true;
	}

	/**
	 * Whether the remote connection is closed.
	 *
	 * @return bool
	 */
	public function is_closed(): bool {
		if ( $this->remote_closed ) {
			return true;
		}
		if ( 'http' === $this->transport ) {
			return false;
		}

		return ! is_resource( $this->read_handle ) || ! is_resource( $this->write_handle );
	}

	/**
	 * Infer the remote transport from legacy and nested options.
	 *
	 * @param array $options Remote connection options.
	 * @return string Transport name.
	 */
	private function infer_transport( array $options ): string {
		if ( isset( $options['socket'] ) ) {
			return 'unix';
		}
		if ( isset( $options['url'] ) ) {
			return 'http';
		}
		if ( isset( $options['command'] ) ) {
			return 'sidecar';
		}
		if ( isset( $options['host'] ) || isset( $options['port'] ) ) {
			return 'tcp';
		}

		return 'unix';
	}

	/**
	 * Normalize a configured transport name.
	 *
	 * @param mixed $transport Transport option.
	 * @return string Normalized transport.
	 *
	 * @throws InvalidArgumentException When the transport is invalid.
	 */
	private function normalize_transport( $transport ): string {
		if ( ! is_string( $transport ) || '' === trim( $transport ) ) {
			throw new InvalidArgumentException( 'DuckDB remote connection option "transport" must be a non-empty string.' );
		}

		$transport = strtolower( str_replace( '_', '-', trim( $transport ) ) );
		$aliases   = array(
			'unix-socket'     => 'unix',
			'socket'          => 'unix',
			'tcp-socket'      => 'tcp',
			'http-json'       => 'http',
			'stdio'           => 'sidecar',
			'managed-sidecar' => 'sidecar',
		);
		if ( isset( $aliases[ $transport ] ) ) {
			$transport = $aliases[ $transport ];
		}

		if ( ! in_array( $transport, array( 'unix', 'tcp', 'http', 'sidecar' ), true ) ) {
			throw new InvalidArgumentException( 'DuckDB remote connection transport must be unix, tcp, http, or sidecar.' );
		}

		return $transport;
	}

	/**
	 * Normalize request timeout.
	 *
	 * @param mixed $timeout Timeout option.
	 * @return float Timeout in seconds.
	 */
	private function normalize_timeout( $timeout ): float {
		if ( is_int( $timeout ) || is_float( $timeout ) || ( is_string( $timeout ) && is_numeric( $timeout ) ) ) {
			$timeout = (float) $timeout;
			if ( $timeout > 0 ) {
				return $timeout;
			}
		}

		throw new InvalidArgumentException( 'DuckDB remote connection option "timeout" must be a positive number.' );
	}

	/**
	 * Configure transport-specific values.
	 *
	 * @param array $options Remote connection options.
	 * @return void
	 */
	private function configure_transport( array $options ): void {
		if ( 'unix' === $this->transport ) {
			$socket = $options['socket'] ?? null;
			if ( ! is_string( $socket ) || '' === trim( $socket ) ) {
				throw new InvalidArgumentException( 'DuckDB remote connection option "socket" must be a non-empty string.' );
			}
			$this->socket_path = trim( $socket );
			return;
		}

		if ( 'tcp' === $this->transport ) {
			$this->host = $this->normalize_host( $options['host'] ?? '127.0.0.1' );
			$this->port = $this->normalize_port( $options['port'] ?? null, 'port' );
			return;
		}

		if ( 'http' === $this->transport ) {
			$url = $options['url'] ?? null;
			if ( null === $url || false === $url || '' === trim( (string) $url ) ) {
				$host = $this->normalize_host( $options['host'] ?? '127.0.0.1' );
				$port = $this->normalize_port( $options['port'] ?? null, 'port' );
				$path = isset( $options['path'] ) && is_string( $options['path'] ) && '' !== trim( $options['path'] )
					? trim( $options['path'] )
					: '/query';
				if ( '/' !== $path[0] ) {
					$path = '/' . $path;
				}
				$url = 'http://' . $host . ':' . $port . $path;
			}
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				throw new InvalidArgumentException( 'DuckDB remote connection option "url" must be a non-empty string.' );
			}
			$this->url = trim( $url );
			return;
		}

		$command = $options['command'] ?? null;
		if ( null === $command && isset( $options['database_path'] ) && is_string( $options['database_path'] ) ) {
			$command = $this->default_sidecar_command( $options['database_path'] );
		}
		if ( ! is_string( $command ) || '' === trim( $command ) ) {
			throw new InvalidArgumentException( 'DuckDB remote connection option "command" must be a non-empty string for sidecar transport.' );
		}
		$this->command = trim( $command );
	}

	/**
	 * Build a default stdio sidecar command.
	 *
	 * @param string $database_path DuckDB database path.
	 * @return string Sidecar command.
	 */
	private function default_sidecar_command( string $database_path ): string {
		$repo_dir = dirname( __DIR__, 3 );
		return PHP_BINARY
			. ' -d ffi.enable=1 '
			. escapeshellarg( $repo_dir . '/bin/duckdb-sidecar.php' )
			. ' --stdio --path=' . escapeshellarg( $database_path );
	}

	/**
	 * Normalize a host option.
	 *
	 * @param mixed $host Host option.
	 * @return string Host.
	 */
	private function normalize_host( $host ): string {
		if ( ! is_string( $host ) || '' === trim( $host ) ) {
			throw new InvalidArgumentException( 'DuckDB remote connection option "host" must be a non-empty string.' );
		}

		return trim( $host );
	}

	/**
	 * Normalize a TCP/HTTP port option.
	 *
	 * @param mixed  $port Port option.
	 * @param string $name Option name.
	 * @return int Port.
	 */
	private function normalize_port( $port, string $name ): int {
		if ( is_int( $port ) || ( is_string( $port ) && preg_match( '/^\d+$/', $port ) ) ) {
			$port = (int) $port;
			if ( $port > 0 && $port <= 65535 ) {
				return $port;
			}
		}

		throw new InvalidArgumentException( sprintf( 'DuckDB remote connection option "%s" must be a TCP port number.', $name ) );
	}

	/**
	 * Send one sidecar request.
	 *
	 * @param string $request JSON request.
	 * @param string $sql     SQL query.
	 * @return array<string,mixed> Response.
	 */
	private function request( string $request, string $sql ): array {
		if ( 'http' === $this->transport ) {
			return $this->request_http( $request );
		}

		return $this->request_line_protocol( $request, $sql );
	}

	/**
	 * Send one line-protocol sidecar request.
	 *
	 * @param string $request JSON request.
	 * @param string $sql     SQL query.
	 * @return array<string,mixed> Response.
	 */
	private function request_line_protocol( string $request, string $sql ): array {
		$line   = $request . "\n";
		$offset = 0;
		$length = strlen( $line );
		while ( $offset < $length ) {
			$written = fwrite( $this->write_handle, substr( $line, $offset ) );
			if ( false === $written || 0 === $written ) {
				$this->close();
				throw new WP_DuckDB_Driver_Exception( 'Failed to write DuckDB sidecar request.' );
			}
			$offset += $written;
		}

		$response = fgets( $this->read_handle );
		if ( false === $response ) {
			$this->close();
			throw new WP_DuckDB_Driver_Exception( 'DuckDB sidecar closed the connection without a response.' );
		}

		return $this->decode_response( $response, $sql );
	}

	/**
	 * Send one HTTP sidecar request.
	 *
	 * @param string $request JSON request.
	 * @return array<string,mixed> Response.
	 */
	private function request_http( string $request ): array {
		$headers = "Content-Type: application/json\r\n"
			. 'Content-Length: ' . strlen( $request ) . "\r\n"
			. "Connection: close\r\n";
		$context = stream_context_create(
			array(
				'http' => array(
					'method'        => 'POST',
					'header'        => $headers,
					'content'       => $request,
					'timeout'       => $this->timeout,
					'ignore_errors' => true,
				),
			)
		);

		$response = @file_get_contents( $this->url, false, $context );
		if ( false === $response ) {
			$error = error_get_last();
			throw new WP_DuckDB_Driver_Exception(
				'Failed to send DuckDB sidecar HTTP request: '
				. ( isset( $error['message'] ) ? $error['message'] : 'unknown error' )
			);
		}

		return $this->decode_response( $response, '' );
	}

	/**
	 * Decode a sidecar response.
	 *
	 * @param string $response JSON response.
	 * @param string $sql      SQL query.
	 * @return array<string,mixed> Response.
	 */
	private function decode_response( string $response, string $sql ): array {
		$decoded = json_decode( $response, true );
		if ( ! is_array( $decoded ) ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB sidecar returned an invalid response.' );
		}

		return $decoded;
	}

	/**
	 * Open a Unix or TCP socket connection to the sidecar.
	 *
	 * @return void
	 *
	 * @throws WP_DuckDB_Driver_Exception When the socket cannot be opened.
	 */
	private function connect_line_socket(): void {
		$target = 'unix' === $this->transport
			? 'unix://' . $this->socket_path
			: 'tcp://' . $this->host . ':' . $this->port;
		$errno  = 0;
		$errstr = '';
		$handle = @stream_socket_client( $target, $errno, $errstr, 5 );
		if ( false === $handle ) {
			throw new WP_DuckDB_Driver_Exception(
				sprintf(
					'Failed to connect to DuckDB sidecar at %s: %s',
					$target,
					'' === $errstr ? 'unknown error' : $errstr
				)
			);
		}

		stream_set_timeout( $handle, (int) ceil( $this->timeout ) );
		$this->read_handle   = $handle;
		$this->write_handle  = $handle;
		$this->remote_closed = false;
	}

	/**
	 * Start a managed stdio sidecar process.
	 *
	 * @return void
	 */
	private function connect_sidecar_process(): void {
		if ( ! function_exists( 'proc_open' ) ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB managed sidecar transport requires proc_open().' );
		}

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( $this->command, $descriptor_spec, $pipes );
		if ( ! is_resource( $process ) ) {
			throw new WP_DuckDB_Driver_Exception( 'Failed to start DuckDB sidecar process.' );
		}

		stream_set_timeout( $pipes[0], (int) ceil( $this->timeout ) );
		stream_set_timeout( $pipes[1], (int) ceil( $this->timeout ) );
		$this->sidecar_process = $process;
		$this->sidecar_pipes   = $pipes;
		$this->write_handle    = $pipes[0];
		$this->read_handle     = $pipes[1];
		$this->remote_closed = false;
	}

	/**
	 * Assert the sidecar socket is open.
	 *
	 * @return void
	 *
	 * @throws WP_DuckDB_Driver_Exception When the socket is closed.
	 */
	private function assert_remote_open(): void {
		if ( $this->is_closed() ) {
			throw new WP_DuckDB_Driver_Exception( 'DuckDB sidecar connection is closed.' );
		}
	}
}
