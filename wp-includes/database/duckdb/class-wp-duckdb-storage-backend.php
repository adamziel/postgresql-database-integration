<?php declare(strict_types = 1);

/**
 * Configurable DuckDB storage backend.
 */
class WP_DuckDB_Storage_Backend {
	const DEFAULT_BACKEND = 'duckdb';

	/**
	 * Canonical backend name.
	 *
	 * @var string
	 */
	private $backend;

	/**
	 * Working DuckDB database path.
	 *
	 * @var string|null
	 */
	private $database_path;

	/**
	 * Directory containing external table files.
	 *
	 * @var string|null
	 */
	private $external_storage_dir;

	/**
	 * File extension used for path-based backend discovery.
	 *
	 * @var string|null
	 */
	private $file_extension;

	/**
	 * SQL relation template used to hydrate one table.
	 *
	 * @var string|null
	 */
	private $read_sql_template;

	/**
	 * SQL statement template used to flush one table.
	 *
	 * @var string|null
	 */
	private $write_sql_template;

	/**
	 * SQL statements to run after connecting.
	 *
	 * @var string[]
	 */
	private $setup_sql;

	/**
	 * Explicit table list for backends that cannot be discovered locally.
	 *
	 * @var string[]
	 */
	private $tables;

	/**
	 * Whether path-based writes should go through a local temporary file.
	 *
	 * @var bool
	 */
	private $atomic_flush;

	/**
	 * DuckDB connection used by this backend.
	 *
	 * @var WP_DuckDB_Connection|null
	 */
	private $connection;

	/**
	 * Whether external files were already hydrated.
	 *
	 * @var bool
	 */
	private $hydrated = false;

	/**
	 * Whether setup SQL statements were already executed.
	 *
	 * @var bool
	 */
	private $setup_complete = false;

	/**
	 * Constructor.
	 *
	 * @param array $options Backend options.
	 *
	 * @throws InvalidArgumentException When the options are invalid.
	 */
	public function __construct( array $options = array() ) {
		$backend = isset( $options['backend'] ) ? (string) $options['backend'] : self::DEFAULT_BACKEND;
		$spec    = self::builtin_backend_spec( $backend );

		$this->backend              = self::normalize_backend( $backend );
		$this->database_path        = isset( $options['database_path'] ) ? $this->normalize_optional_path( $options['database_path'], 'database_path' ) : null;
		$this->external_storage_dir = isset( $options['external_storage_dir'] ) ? $this->normalize_optional_path( $options['external_storage_dir'], 'external_storage_dir' ) : null;
		$this->file_extension       = isset( $options['file_extension'] ) ? $this->normalize_file_extension( $options['file_extension'] ) : ( $spec['file_extension'] ?? null );
		$this->read_sql_template    = isset( $options['read_sql_template'] ) ? $this->normalize_sql_template( $options['read_sql_template'], 'read_sql_template' ) : ( $spec['read_sql_template'] ?? null );
		$this->write_sql_template   = isset( $options['write_sql_template'] ) ? $this->normalize_sql_template( $options['write_sql_template'], 'write_sql_template' ) : ( $spec['write_sql_template'] ?? null );
		$this->setup_sql            = isset( $options['setup_sql'] ) ? $this->normalize_sql_statements( $options['setup_sql'], 'setup_sql' ) : array();
		$this->tables               = isset( $options['tables'] ) ? $this->normalize_table_names( $options['tables'] ) : array();

		if ( null === $this->database_path ) {
			$this->database_path = ':memory:';
		}

		if ( $this->is_external() ) {
			$this->validate_external_backend_options();
		}

		$default_atomic_flush = $this->is_external() && null !== $this->external_storage_dir && $this->is_local_storage_dir();
		$this->atomic_flush   = isset( $options['atomic_flush'] ) ? (bool) $options['atomic_flush'] : $default_atomic_flush;
		if ( null === $this->external_storage_dir || ! $this->is_local_storage_dir() ) {
			$this->atomic_flush = false;
		}
	}

	/**
	 * Create a backend from WordPress constants.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When configured paths are invalid.
	 */
	public static function from_constants(): self {
		$backend = self::DEFAULT_BACKEND;
		if ( defined( 'DUCKDB_BACKEND' ) ) {
			$backend = (string) DUCKDB_BACKEND;
		} elseif ( defined( 'WP_DUCKDB_BACKEND' ) ) {
			$backend = (string) WP_DUCKDB_BACKEND;
		}

		$database_path = null;
		if ( defined( 'DUCKDB_WORKING_DATABASE_FILE' ) ) {
			$database_path = (string) DUCKDB_WORKING_DATABASE_FILE;
		} elseif ( defined( 'FQDUCKDB' ) ) {
			$database_path = (string) FQDUCKDB;
		}

		$external_storage_dir = null;
		if ( defined( 'DUCKDB_EXTERNAL_STORAGE_DIR' ) ) {
			$external_storage_dir = (string) DUCKDB_EXTERNAL_STORAGE_DIR;
		} elseif ( defined( 'WP_DUCKDB_EXTERNAL_STORAGE_DIR' ) ) {
			$external_storage_dir = (string) WP_DUCKDB_EXTERNAL_STORAGE_DIR;
		}

		$normalized_backend = self::normalize_backend( $backend );
		if ( self::builtin_backend_uses_file_storage( $normalized_backend ) && ( null === $external_storage_dir || '' === $external_storage_dir ) ) {
			if ( null !== $database_path && '' !== $database_path && ':memory:' !== $database_path ) {
				$external_storage_dir = dirname( $database_path ) . '/duckdb-' . $normalized_backend;
			} elseif ( defined( 'FQDBDIR' ) ) {
				$external_storage_dir = rtrim( (string) FQDBDIR, '/\\' ) . '/duckdb-' . $normalized_backend;
			}
		}

		return new self(
			array(
				'backend'              => $normalized_backend,
				'database_path'        => $database_path,
				'external_storage_dir' => $external_storage_dir,
				'file_extension'       => self::constant_value( array( 'DUCKDB_BACKEND_FILE_EXTENSION', 'DUCKDB_EXTERNAL_FILE_EXTENSION', 'WP_DUCKDB_BACKEND_FILE_EXTENSION' ) ),
				'read_sql_template'    => self::constant_value( array( 'DUCKDB_BACKEND_READ_SQL', 'WP_DUCKDB_BACKEND_READ_SQL' ) ),
				'write_sql_template'   => self::constant_value( array( 'DUCKDB_BACKEND_WRITE_SQL', 'WP_DUCKDB_BACKEND_WRITE_SQL' ) ),
				'setup_sql'            => self::constant_value( array( 'DUCKDB_BACKEND_SETUP_SQL', 'WP_DUCKDB_BACKEND_SETUP_SQL' ), array() ),
				'tables'               => self::constant_value( array( 'DUCKDB_BACKEND_TABLES', 'WP_DUCKDB_BACKEND_TABLES' ), array() ),
				'atomic_flush'         => self::constant_value( array( 'DUCKDB_BACKEND_ATOMIC_FLUSH', 'WP_DUCKDB_BACKEND_ATOMIC_FLUSH' ) ),
			)
		);
	}

	/**
	 * Get the configured backend.
	 *
	 * @return string
	 */
	public function get_backend(): string {
		return $this->backend;
	}

	/**
	 * Get the working DuckDB database path.
	 *
	 * @return string|null
	 */
	public function get_database_path(): ?string {
		return $this->database_path;
	}

	/**
	 * Get the external storage directory.
	 *
	 * @return string|null
	 */
	public function get_external_storage_dir(): ?string {
		return $this->external_storage_dir;
	}

	/**
	 * Whether this backend stores tables in external files.
	 *
	 * @return bool
	 */
	public function is_external(): bool {
		return ! self::is_native_backend( $this->backend );
	}

	/**
	 * Create a WordPress-compatible DuckDB driver for this backend.
	 *
	 * @param string $database Database name exposed to WordPress.
	 * @return WP_DuckDB_Driver
	 *
	 * @throws WP_DuckDB_Driver_Exception When DuckDB cannot connect or hydrate.
	 */
	public function create_driver( string $database ): WP_DuckDB_Driver {
		$this->connection = new WP_DuckDB_Connection( array( 'path' => $this->database_path ) );
		$this->run_setup_sql();

		if ( $this->is_external() ) {
			$this->hydrate_external_storage();
		}

		return new WP_DuckDB_Driver(
			array(
				'connection' => $this->connection,
				'database'   => $database,
			)
		);
	}

	/**
	 * Flush mutable DuckDB tables back to external storage files.
	 *
	 * @return void
	 *
	 * @throws WP_DuckDB_Driver_Exception When copying tables fails.
	 */
	public function flush(): void {
		if ( ! $this->is_external() || null === $this->connection ) {
			return;
		}

		$this->ensure_external_storage_dir();
		foreach ( $this->list_mutable_tables() as $table ) {
			$this->copy_table_to_external_storage( $table, $this->source_for_table( $table ) );
		}
	}

	/**
	 * Normalize a backend name.
	 *
	 * @param string $backend Backend name.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the backend is unsupported.
	 */
	private static function normalize_backend( string $backend ): string {
		$backend = strtolower( trim( $backend ) );
		if ( '' === $backend ) {
			$backend = self::DEFAULT_BACKEND;
		}

		if ( self::is_native_backend( $backend ) ) {
			return self::DEFAULT_BACKEND;
		}

		if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $backend ) ) {
			throw new InvalidArgumentException( 'DuckDB backend names may only contain lowercase letters, numbers, underscores, and hyphens.' );
		}

		return $backend;
	}

	/**
	 * Whether a backend is the native DuckDB file backend.
	 *
	 * @param string $backend Backend name.
	 * @return bool
	 */
	private static function is_native_backend( string $backend ): bool {
		return in_array( $backend, array( 'duckdb', 'duck', 'native', 'file' ), true );
	}

	/**
	 * Get a built-in backend spec.
	 *
	 * @param string $backend Backend name.
	 * @return array<string,string>
	 */
	private static function builtin_backend_spec( string $backend ): array {
		$backend = strtolower( trim( $backend ) );
		switch ( $backend ) {
			case 'parquet':
				return array(
					'file_extension'     => 'parquet',
					'read_sql_template'  => 'SELECT * FROM read_parquet({path})',
					'write_sql_template' => 'COPY {table} TO {path} (FORMAT PARQUET)',
				);
			case 'csv':
				return array(
					'file_extension'     => 'csv',
					'read_sql_template'  => 'SELECT * FROM read_csv_auto({path}, HEADER = true)',
					'write_sql_template' => "COPY {table} TO {path} (HEADER, DELIMITER ',')",
				);
			case 'json':
				return array(
					'file_extension'     => 'json',
					'read_sql_template'  => 'SELECT * FROM read_json_auto({path})',
					'write_sql_template' => 'COPY {table} TO {path} (FORMAT JSON)',
				);
		}

		return array();
	}

	/**
	 * Whether a built-in backend uses path-based file storage.
	 *
	 * @param string $backend Backend name.
	 * @return bool
	 */
	private static function builtin_backend_uses_file_storage( string $backend ): bool {
		return array() !== self::builtin_backend_spec( $backend );
	}

	/**
	 * Get the first defined constant value from a list.
	 *
	 * @param string[] $names   Constant names.
	 * @param mixed    $default Default value.
	 * @return mixed
	 */
	private static function constant_value( array $names, $default = null ) {
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				return constant( $name );
			}
		}

		return $default;
	}

	/**
	 * Normalize a nullable path option.
	 *
	 * @param mixed  $path Path value.
	 * @param string $name Option name.
	 * @return string|null
	 *
	 * @throws InvalidArgumentException When the path is invalid.
	 */
	private function normalize_optional_path( $path, string $name ): ?string {
		if ( null === $path ) {
			return null;
		}
		if ( ! is_string( $path ) ) {
			throw new InvalidArgumentException( 'DuckDB backend option "' . $name . '" must be a string or null.' );
		}
		return $path;
	}

	/**
	 * Normalize a file extension.
	 *
	 * @param mixed $extension File extension.
	 * @return string|null
	 *
	 * @throws InvalidArgumentException When the extension is invalid.
	 */
	private function normalize_file_extension( $extension ): ?string {
		if ( null === $extension || '' === $extension ) {
			return null;
		}
		if ( ! is_string( $extension ) ) {
			throw new InvalidArgumentException( 'DuckDB backend option "file_extension" must be a string or null.' );
		}

		$extension = ltrim( trim( $extension ), '.' );
		if ( '' === $extension || false !== strpos( $extension, '/' ) || false !== strpos( $extension, '\\' ) || false !== strpos( $extension, "\0" ) ) {
			throw new InvalidArgumentException( 'DuckDB backend file extensions cannot contain path separators.' );
		}

		return $extension;
	}

	/**
	 * Normalize a SQL template.
	 *
	 * @param mixed  $template SQL template.
	 * @param string $name     Option name.
	 * @return string|null
	 *
	 * @throws InvalidArgumentException When the template is invalid.
	 */
	private function normalize_sql_template( $template, string $name ): ?string {
		if ( null === $template ) {
			return null;
		}
		if ( ! is_string( $template ) ) {
			throw new InvalidArgumentException( 'DuckDB backend option "' . $name . '" must be a string or null.' );
		}

		$template = trim( $template );
		return '' === $template ? null : $template;
	}

	/**
	 * Normalize SQL setup statements.
	 *
	 * @param mixed  $statements SQL statements.
	 * @param string $name       Option name.
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When the statements are invalid.
	 */
	private function normalize_sql_statements( $statements, string $name ): array {
		if ( null === $statements || '' === $statements ) {
			return array();
		}
		if ( is_string( $statements ) ) {
			$statements = array( $statements );
		}
		if ( ! is_array( $statements ) ) {
			throw new InvalidArgumentException( 'DuckDB backend option "' . $name . '" must be a string or array of strings.' );
		}

		$normalized = array();
		foreach ( $statements as $statement ) {
			if ( ! is_string( $statement ) ) {
				throw new InvalidArgumentException( 'DuckDB backend option "' . $name . '" must contain only strings.' );
			}
			$statement = trim( $statement );
			if ( '' !== $statement ) {
				$normalized[] = $statement;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize explicit table names.
	 *
	 * @param mixed $tables Table names.
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When the table list is invalid.
	 */
	private function normalize_table_names( $tables ): array {
		if ( null === $tables || '' === $tables ) {
			return array();
		}
		if ( is_string( $tables ) ) {
			$tables = preg_split( '/\s*,\s*/', $tables, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $tables ) ) {
			throw new InvalidArgumentException( 'DuckDB backend option "tables" must be a comma-separated string or array of strings.' );
		}

		$normalized = array();
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) ) {
				throw new InvalidArgumentException( 'DuckDB backend option "tables" must contain only strings.' );
			}
			$table = trim( $table );
			if ( '' === $table ) {
				continue;
			}
			if ( $this->is_internal_table_name( $table ) ) {
				continue;
			}
			$normalized[] = $table;
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Validate external backend options.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When required templates or paths are missing.
	 */
	private function validate_external_backend_options(): void {
		if ( null === $this->read_sql_template ) {
			throw new InvalidArgumentException( 'DuckDB external backends require a read_sql_template option or DUCKDB_BACKEND_READ_SQL constant.' );
		}
		if ( null === $this->write_sql_template ) {
			throw new InvalidArgumentException( 'DuckDB external backends require a write_sql_template option or DUCKDB_BACKEND_WRITE_SQL constant.' );
		}

		if ( null !== $this->external_storage_dir && '' !== $this->external_storage_dir ) {
			$this->external_storage_dir = rtrim( $this->external_storage_dir, '/\\' );
		}

		if ( $this->template_uses_path( $this->read_sql_template ) || $this->template_uses_path( $this->write_sql_template ) ) {
			if ( null === $this->external_storage_dir || '' === $this->external_storage_dir ) {
				throw new InvalidArgumentException( 'DuckDB path-based external backends require an external_storage_dir option.' );
			}
			if ( null === $this->file_extension || '' === $this->file_extension ) {
				throw new InvalidArgumentException( 'DuckDB path-based external backends require a file_extension option.' );
			}
		}
	}

	/**
	 * Hydrate external table files into mutable DuckDB tables.
	 *
	 * @return void
	 *
	 * @throws WP_DuckDB_Driver_Exception When hydration fails.
	 */
	private function hydrate_external_storage(): void {
		if ( $this->hydrated ) {
			return;
		}

		$this->ensure_external_storage_dir();
		$this->clear_mutable_tables();

		foreach ( $this->external_sources() as $table => $source ) {
			if ( $this->is_internal_table_name( $table ) ) {
				continue;
			}
			$this->connection->query(
				'CREATE TABLE ' . $this->connection->quote_identifier( $table ) .
				' AS ' . $this->render_sql_template( $this->read_sql_template, $table, $source )
			);
		}

		$this->hydrated = true;
	}

	/**
	 * Run configured setup SQL statements.
	 *
	 * @return void
	 */
	private function run_setup_sql(): void {
		if ( $this->setup_complete || null === $this->connection ) {
			return;
		}

		foreach ( $this->setup_sql as $statement ) {
			$this->connection->query( $statement );
		}

		$this->setup_complete = true;
	}

	/**
	 * Drop mutable working tables before hydrating from the configured source.
	 *
	 * @return void
	 */
	private function clear_mutable_tables(): void {
		foreach ( $this->list_mutable_tables() as $table ) {
			$this->connection->query( 'DROP TABLE IF EXISTS ' . $this->connection->quote_identifier( $table ) );
		}
	}

	/**
	 * List mutable user tables in the working DuckDB database.
	 *
	 * @return string[]
	 */
	private function list_mutable_tables(): array {
		$rows   = $this->connection->query(
			"SELECT table_name
			FROM information_schema.tables
			WHERE table_schema = 'main'
				AND table_type = 'BASE TABLE'
			ORDER BY table_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$tables = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row['table_name'] ) ) {
				continue;
			}

			$table = (string) $row['table_name'];
			if ( $this->is_internal_table_name( $table ) ) {
				continue;
			}

			$tables[] = $table;
		}

		return $tables;
	}

	/**
	 * List external table files for the configured backend.
	 *
	 * @return string[]
	 */
	private function external_table_files(): array {
		if ( null === $this->external_storage_dir || null === $this->file_extension || ! $this->is_local_storage_dir() ) {
			return array();
		}

		$paths = glob( $this->external_storage_dir . '/*.' . $this->file_extension );
		if ( false === $paths ) {
			return array();
		}

		sort( $paths, SORT_STRING );
		return $paths;
	}

	/**
	 * Build the configured external table sources.
	 *
	 * @return array<string,string|null>
	 */
	private function external_sources(): array {
		if ( array() !== $this->tables ) {
			$sources = array();
			foreach ( $this->tables as $table ) {
				$sources[ $table ] = $this->source_for_table( $table );
			}
			return $sources;
		}

		$sources = array();
		foreach ( $this->external_table_files() as $path ) {
			$sources[ basename( $path, '.' . $this->file_extension ) ] = $path;
		}

		return $sources;
	}

	/**
	 * Copy a working table to an external file.
	 *
	 * @param string      $table  Table name.
	 * @param string|null $source Optional destination source.
	 * @return void
	 *
	 * @throws WP_DuckDB_Driver_Exception When the copy fails.
	 */
	private function copy_table_to_external_storage( string $table, ?string $source ): void {
		$temp_path = $this->atomic_flush && null !== $source ? $source . '.tmp-' . getmypid() . '-' . uniqid( '', true ) : null;
		$target    = null === $temp_path ? $source : $temp_path;

		try {
			$this->connection->query( $this->render_sql_template( $this->write_sql_template, $table, $target ) );

			if ( null !== $temp_path ) {
				if ( file_exists( $source ) && ! unlink( $source ) ) {
					throw new RuntimeException( 'Failed to replace DuckDB external storage file: ' . $source );
				}
				if ( ! rename( $temp_path, $source ) ) {
					throw new RuntimeException( 'Failed to move DuckDB external storage file into place: ' . $source );
				}
			}
		} catch ( Throwable $e ) {
			if ( null !== $temp_path && file_exists( $temp_path ) ) {
				unlink( $temp_path );
			}
			if ( $e instanceof WP_DuckDB_Driver_Exception ) {
				throw $e;
			}
			throw new WP_DuckDB_Driver_Exception( 'Failed to flush DuckDB table "' . $table . '" to external storage: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Build the external file path for a table.
	 *
	 * @param string $table Table name.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the table cannot map to a local file.
	 */
	private function external_file_path_for_table( string $table ): string {
		if ( null === $this->external_storage_dir || null === $this->file_extension ) {
			throw new InvalidArgumentException( 'DuckDB backend cannot build an external file path without external_storage_dir and file_extension options.' );
		}
		if ( false !== strpos( $table, '/' ) || false !== strpos( $table, '\\' ) || false !== strpos( $table, "\0" ) ) {
			throw new InvalidArgumentException( 'DuckDB table names used with external storage cannot contain path separators.' );
		}
		return $this->external_storage_dir . '/' . $table . '.' . $this->file_extension;
	}

	/**
	 * Get the source path for a table when the backend is path-based.
	 *
	 * @param string $table Table name.
	 * @return string|null
	 */
	private function source_for_table( string $table ): ?string {
		if ( null === $this->external_storage_dir || null === $this->file_extension ) {
			return null;
		}

		return $this->external_file_path_for_table( $table );
	}

	/**
	 * Render a backend SQL template for one table.
	 *
	 * @param string|null $template SQL template.
	 * @param string      $table    Table name.
	 * @param string|null $source   Optional table source path.
	 *
	 * @return string
	 */
	private function render_sql_template( ?string $template, string $table, ?string $source ): string {
		if ( null === $template || '' === trim( $template ) ) {
			throw new InvalidArgumentException( 'DuckDB backend SQL template is not configured.' );
		}
		if ( $this->template_uses_path( $template ) && null === $source ) {
			throw new InvalidArgumentException( 'DuckDB backend SQL template uses {path}, but no table source path is available.' );
		}

		$source_literal = null === $source ? 'NULL' : $this->connection->quote( $source );
		return strtr(
			$template,
			array(
				'{table}'            => $this->connection->quote_identifier( $table ),
				'{table_identifier}' => $this->connection->quote_identifier( $table ),
				'{table_name}'       => $this->connection->quote( $table ),
				'{path}'             => $source_literal,
				'{source}'           => $source_literal,
			)
		);
	}

	/**
	 * Ensure the external storage directory exists.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the directory is unavailable.
	 */
	private function ensure_external_storage_dir(): void {
		if ( ! $this->is_external() ) {
			return;
		}
		if ( null === $this->external_storage_dir || '' === $this->external_storage_dir || ! $this->is_local_storage_dir() ) {
			return;
		}
		if ( ! is_dir( $this->external_storage_dir ) && ! mkdir( $this->external_storage_dir, 0777, true ) && ! is_dir( $this->external_storage_dir ) ) {
			throw new InvalidArgumentException( 'Failed to create DuckDB external storage directory: ' . $this->external_storage_dir );
		}
	}

	/**
	 * Whether a table is private driver metadata.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function is_internal_table_name( string $table ): bool {
		return 0 === stripos( $table, '__wp_duckdb_' );
	}

	/**
	 * Whether the configured storage directory is a local filesystem path.
	 *
	 * @return bool
	 */
	private function is_local_storage_dir(): bool {
		return null !== $this->external_storage_dir && 1 !== preg_match( '#^[a-z][a-z0-9+.-]*://#i', $this->external_storage_dir );
	}

	/**
	 * Whether a SQL template uses a path/source placeholder.
	 *
	 * @param string|null $template SQL template.
	 * @return bool
	 */
	private function template_uses_path( ?string $template ): bool {
		return null !== $template && ( false !== strpos( $template, '{path}' ) || false !== strpos( $template, '{source}' ) );
	}
}
