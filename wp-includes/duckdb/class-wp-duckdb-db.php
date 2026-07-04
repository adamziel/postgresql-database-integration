<?php
/**
 * Extend and replace the wpdb class for DuckDB.
 *
 * @package wordpress-databases-support
 */

/**
 * DuckDB wpdb adapter.
 *
 * This first-stage adapter exposes the DuckDB connection through the wpdb
 * surface. Full MySQL dialect emulation is implemented in the shared driver
 * package in later stages.
 */
class WP_DuckDB_DB extends wpdb {

	/**
	 * Database handle.
	 *
	 * @var WP_DuckDB_Driver
	 */
	protected $dbh;

	/**
	 * Backward compatibility, see wpdb::$allow_unsafe_unquoted_parameters.
	 *
	 * This property mirrors "wpdb::$allow_unsafe_unquoted_parameters" because
	 * some tests access it externally using PHP reflection.
	 *
	 * @var bool
	 */
	private $allow_unsafe_unquoted_parameters = true;

	/**
	 * Last DuckDB statement.
	 *
	 * @var WP_DuckDB_Result_Statement|null
	 */
	private $last_statement;

	/**
	 * Configured DuckDB storage backend.
	 *
	 * @var WP_DuckDB_Storage_Backend|null
	 */
	private $storage_backend;

	/**
	 * Whether the external storage shutdown flush was registered.
	 *
	 * @var bool
	 */
	private $storage_backend_shutdown_registered = false;

	/**
	 * Whether the PHP shutdown fallback flush was registered.
	 *
	 * WordPress' `shutdown` action is preferred because it runs after plugins
	 * have saved request state, but the drop-in can connect before add_action()
	 * is available.
	 *
	 * @var bool
	 */
	private $storage_backend_php_shutdown_registered = false;

	/**
	 * Cached WordPress column length metadata by table/column.
	 *
	 * @var array<string,array<string,array|false>>
	 */
	private $duckdb_column_length_cache = array();

	/**
	 * Whether the alloptions file cache is suspended for this request.
	 *
	 * @var bool
	 */
	private $duckdb_alloptions_file_cache_suspended = false;

	/**
	 * Whether this object created a driver with WordPress-compatible SQL modes.
	 *
	 * @var bool
	 */
	private $duckdb_sql_mode_bootstrapped = false;

	/**
	 * Constructor.
	 *
	 * @param string $dbname Database name.
	 */
	public function __construct( $dbname ) {
		$GLOBALS['wpdb'] = $this;

		parent::__construct( '', '', $dbname, '' );
		$this->charset = 'utf8mb4';
	}

	/**
	 * Set WordPress table prefix and refresh driver-known core table metadata.
	 *
	 * @param string $prefix          Table prefix.
	 * @param bool   $set_table_names Whether to update table-name properties.
	 * @return string|WP_Error|null Parent return value when available.
	 */
	public function set_prefix( $prefix, $set_table_names = true ) {
		if ( method_exists( 'wpdb', 'set_prefix' ) ) {
			$result = func_num_args() > 1
				? parent::set_prefix( $prefix, $set_table_names )
				: parent::set_prefix( $prefix );
		} else {
			$this->prefix = $prefix;
			$result       = null;
		}

		$this->update_duckdb_known_auto_increment_columns();
		return $result;
	}

	/**
	 * Destructor.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Determine the best charset and collation to use.
	 *
	 * This mirrors wpdb::determine_charset() without requiring a mysqli handle.
	 *
	 * @param string $charset Character set.
	 * @param string $collate Collation.
	 * @return array{charset:string,collate:string}
	 */
	public function determine_charset( $charset, $collate ) {
		if ( ! $this->ready || ! ( $this->dbh instanceof WP_DuckDB_Driver ) ) {
			return compact( 'charset', 'collate' );
		}

		if ( 'utf8' === $charset ) {
			$charset = 'utf8mb4';
		}

		if ( 'utf8mb4' === $charset ) {
			if ( ! $collate || 'utf8_general_ci' === $collate ) {
				$collate = 'utf8mb4_unicode_ci';
			} else {
				$collate = str_replace( 'utf8_', 'utf8mb4_', $collate );
			}
		}

		if ( $this->has_cap( 'utf8mb4_520' ) && 'utf8mb4_unicode_ci' === $collate ) {
			$collate = 'utf8mb4_unicode_520_ci';
		}

		return compact( 'charset', 'collate' );
	}

	/**
	 * Track connection charset state without calling mysqli functions.
	 *
	 * @param resource $dbh     Database handle.
	 * @param string   $charset Optional charset.
	 * @param string   $collate Optional collation.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		if ( ! isset( $charset ) ) {
			$charset = $this->charset;
		}
		if ( ! isset( $collate ) ) {
			$collate = $this->collate;
		}

		$this->charset = $charset;
		$this->collate = $collate ? $collate : $this->get_default_collation_for_charset( $charset );
	}

	/**
	 * Return the column charset.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return string|false|WP_Error
	 */
	public function get_col_charset( $table, $column ) {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver ) {
			return parent::get_col_charset( $table, $column );
		}

		$tablekey  = $this->get_duckdb_metadata_key( (string) $table );
		$columnkey = $this->get_duckdb_metadata_key( (string) $column );

		if ( function_exists( 'apply_filters' ) ) {
			$charset = apply_filters( 'pre_get_col_charset', null, $table, $column );
			if ( null !== $charset ) {
				return $charset;
			}
		}

		if ( empty( $this->is_mysql ) ) {
			return false;
		}

		if ( ! array_key_exists( $tablekey, $this->table_charset ) ) {
			$table_charset = $this->get_table_charset( $table );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $table_charset ) ) {
				return $table_charset;
			}
		}

		if ( empty( $this->col_meta[ $tablekey ] ) ) {
			return $this->table_charset[ $tablekey ];
		}

		if ( empty( $this->col_meta[ $tablekey ][ $columnkey ] ) ) {
			return $this->table_charset[ $tablekey ];
		}

		if ( empty( $this->col_meta[ $tablekey ][ $columnkey ]->Collation ) ) {
			return false;
		}

		list( $charset ) = explode( '_', $this->col_meta[ $tablekey ][ $columnkey ]->Collation );
		return $charset;
	}

	/**
	 * Retrieves the MySQL-compatible table charset from DuckDB metadata.
	 *
	 * @param string $table Table name.
	 * @return string|false|WP_Error Table charset, false for non-text tables, or an error.
	 */
	protected function get_table_charset( $table ) {
		$tablekey = $this->get_duckdb_metadata_key( (string) $table );

		if ( function_exists( 'apply_filters' ) ) {
			$charset = apply_filters( 'pre_get_table_charset', null, $table );
			if ( null !== $charset ) {
				return $charset;
			}
		}

		if ( array_key_exists( $tablekey, $this->table_charset ) ) {
			return $this->table_charset[ $tablekey ];
		}

		$columns = $this->get_duckdb_column_charset_metadata( (string) $table );
		if ( false === $columns ) {
			return new WP_Error( 'wpdb_get_table_charset_failure', __( 'Could not retrieve table charset.' ) );
		}

		$this->col_meta[ $tablekey ]      = $columns;
		$this->table_charset[ $tablekey ] = $this->get_duckdb_table_charset_from_columns( $columns );

		return $this->table_charset[ $tablekey ];
	}

	/**
	 * Gets the maximum string length for a DuckDB-backed column.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return array|false Maximum length data, or false when unrestricted/unknown.
	 */
	public function get_col_length( $table, $column ) {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver ) {
			return false;
		}

		$table     = $this->normalize_duckdb_table_name( (string) $table );
		$column    = trim( (string) $column, "`\" \t\n\r\0\x0B" );
		$tablekey  = $this->get_duckdb_metadata_key( $table );
		$columnkey = $this->get_duckdb_metadata_key( $column );

		$columns = array_key_exists( $tablekey, $this->col_meta )
			? $this->col_meta[ $tablekey ]
			: $this->get_duckdb_column_charset_metadata( $table );
		if ( false !== $columns && isset( $columns[ $columnkey ] ) ) {
			$length = $this->get_duckdb_column_length_from_mysql_type(
				(string) $columns[ $columnkey ]->Type
			);
			if ( false !== $length ) {
				return $length;
			}
		}

		if (
			isset( $this->duckdb_column_length_cache[ $tablekey ] )
			&& array_key_exists( $columnkey, $this->duckdb_column_length_cache[ $tablekey ] )
		) {
			return $this->duckdb_column_length_cache[ $tablekey ][ $columnkey ];
		}

		$this->duckdb_column_length_cache[ $tablekey ][ $columnkey ] = false;
		return false;
	}

	/**
	 * Load MySQL-compatible column metadata for a DuckDB table.
	 *
	 * @param string $table Table name.
	 * @return array|false Column metadata keyed by lowercase column name, or false.
	 */
	private function get_duckdb_column_charset_metadata( string $table ) {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver ) {
			return false;
		}

		$tablekey = $this->get_duckdb_metadata_key( $table );
		if ( array_key_exists( $tablekey, $this->col_meta ) ) {
			return $this->col_meta[ $tablekey ];
		}

		$table_name = $this->normalize_duckdb_table_name( $table );
		if ( '' === $table_name || ! method_exists( $this->dbh, 'get_mysql_column_charset_metadata_for_table' ) ) {
			return false;
		}

		try {
			$metadata_rows = $this->dbh->get_mysql_column_charset_metadata_for_table( $table_name );
		} catch ( Throwable $e ) {
			return false;
		}

		if ( ! is_array( $metadata_rows ) || empty( $metadata_rows ) ) {
			return false;
		}

		$this->col_meta[ $tablekey ] = $this->format_duckdb_charset_column_rows( $metadata_rows );
		return $this->col_meta[ $tablekey ];
	}

	/**
	 * Convert metadata rows into wpdb col_meta objects.
	 *
	 * @param array $rows Metadata rows.
	 * @return array Column metadata keyed by lowercase column name.
	 */
	private function format_duckdb_charset_column_rows( array $rows ): array {
		$columns = array();

		foreach ( $rows as $row ) {
			$field = (string) ( $row['column_name'] ?? '' );
			if ( '' === $field ) {
				continue;
			}

			$columns[ $this->get_duckdb_metadata_key( $field ) ] = (object) array(
				'Field'     => $field,
				'Type'      => (string) ( $row['column_type'] ?? '' ),
				'Collation' => $row['collation_name'] ?? null,
			);
		}

		return $columns;
	}

	/**
	 * Convert a MySQL-facing column type into WordPress length metadata.
	 *
	 * @param string $column_type MySQL column type.
	 * @return array|false Column length metadata, or false when unrestricted/unknown.
	 */
	private function get_duckdb_column_length_from_mysql_type( string $column_type ) {
		$typeinfo = explode( '(', $column_type, 2 );
		$type     = strtolower( trim( $typeinfo[0] ) );
		$length   = false;

		if ( ! empty( $typeinfo[1] ) ) {
			$length = (int) trim( $typeinfo[1], ") \t\n\r\0\x0B" );
		}

		switch ( $type ) {
			case 'char':
			case 'varchar':
				if ( false === $length || $length <= 0 ) {
					return false;
				}

				return array(
					'type'   => 'char',
					'length' => $length,
				);

			case 'binary':
			case 'varbinary':
				if ( false === $length || $length <= 0 ) {
					return false;
				}

				return array(
					'type'   => 'byte',
					'length' => $length,
				);

			case 'tinyblob':
			case 'tinytext':
				return array(
					'type'   => 'byte',
					'length' => 255,
				);

			case 'blob':
			case 'text':
				return array(
					'type'   => 'byte',
					'length' => 65535,
				);

			case 'mediumblob':
			case 'mediumtext':
				return array(
					'type'   => 'byte',
					'length' => 16777215,
				);

			case 'longblob':
			case 'longtext':
				return array(
					'type'   => 'byte',
					'length' => 4294967295,
				);

			default:
				return false;
		}
	}

	/**
	 * Calculate WordPress's table charset value from column metadata.
	 *
	 * @param array $columns Column metadata.
	 * @return string|false Table charset, or false for tables without text columns.
	 */
	private function get_duckdb_table_charset_from_columns( array $columns ) {
		$charsets = array();

		foreach ( $columns as $column ) {
			$collation = $column->{'Collation'};
			if ( ! empty( $collation ) ) {
				list( $charset ) = explode( '_', $collation );
				$charsets[ strtolower( $charset ) ] = true;
			}

			$column_type = $column->{'Type'};
			list( $type ) = explode( '(', $column_type );
			if ( in_array( strtoupper( $type ), array( 'BINARY', 'VARBINARY', 'TINYBLOB', 'MEDIUMBLOB', 'BLOB', 'LONGBLOB' ), true ) ) {
				return 'binary';
			}
		}

		if ( isset( $charsets['utf8mb3'] ) ) {
			$charsets['utf8'] = true;
			unset( $charsets['utf8mb3'] );
		}

		$count = count( $charsets );
		if ( 1 === $count ) {
			return key( $charsets );
		}

		if ( 0 === $count ) {
			return false;
		}

		unset( $charsets['latin1'] );
		$count = count( $charsets );
		if ( 1 === $count ) {
			return key( $charsets );
		}

		if ( 2 === $count && isset( $charsets['utf8'], $charsets['utf8mb4'] ) ) {
			return 'utf8';
		}

		return 'ascii';
	}

	/**
	 * Normalize a table name for DuckDB metadata lookups.
	 *
	 * @param string $table Table identifier.
	 * @return string Table name.
	 */
	private function normalize_duckdb_table_name( string $table ): string {
		$table = trim( $table, "`\" \t\n\r\0\x0B" );
		if ( false !== strpos( $table, '.' ) ) {
			$table = substr( $table, strrpos( $table, '.' ) + 1 );
			$table = trim( $table, "`\" \t\n\r\0\x0B" );
		}

		return $table;
	}

	/**
	 * Normalize an identifier for wpdb metadata cache keys.
	 *
	 * @param string $identifier Identifier.
	 * @return string Metadata key.
	 */
	private function get_duckdb_metadata_key( string $identifier ): string {
		return strtolower( $this->normalize_duckdb_table_name( $identifier ) );
	}

	/**
	 * Clear all derived DuckDB charset metadata caches.
	 */
	private function clear_all_duckdb_table_charset_cache(): void {
		$this->table_charset              = array();
		$this->col_meta                   = array();
		$this->duckdb_column_length_cache = array();

		if ( $this->dbh instanceof WP_DuckDB_Driver && method_exists( $this->dbh, 'clear_metadata_caches' ) ) {
			$this->dbh->clear_metadata_caches();
		}
	}

	/**
	 * Returns the first SQL statement keyword.
	 *
	 * @param string $query SQL query.
	 * @return string Lowercase statement keyword, or empty string.
	 */
	private function get_statement_keyword( $query ) {
		if ( ! is_string( $query ) ) {
			return '';
		}

		$query  = ltrim( $query );
		$length = strlen( $query );
		$i      = 0;
		while ( $i < $length && ( ctype_alpha( $query[ $i ] ) || '_' === $query[ $i ] ) ) {
			++$i;
		}

		return strtolower( substr( $query, 0, $i ) );
	}

	/**
	 * Changes the current SQL mode, and ensures its WordPress compatibility.
	 *
	 * @param array $modes Optional. A list of SQL modes to set. Default empty array.
	 */
	public function set_sql_mode( $modes = array() ) {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver ) {
			return;
		}

		if ( empty( $modes ) ) {
			$result = $this->dbh->query( 'SELECT @@SESSION.sql_mode' );
			$row    = $result->fetch( PDO::FETCH_OBJ ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

			if ( ! $row || ! isset( $row->{'@@SESSION.sql_mode'} ) ) {
				throw new RuntimeException( 'DuckDB SQL mode bootstrap did not return @@SESSION.sql_mode.' );
			}

			$modes_str = $row->{'@@SESSION.sql_mode'};
			if ( empty( $modes_str ) ) {
				return;
			}
			$modes = explode( ',', $modes_str );
		}

		$modes = array_change_key_case( $modes, CASE_UPPER );

		/**
		 * Filters the list of incompatible SQL modes to exclude.
		 *
		 * @since 3.9.0
		 *
		 * @param array $incompatible_modes An array of incompatible modes.
		 */
		$incompatible_modes = $this->get_incompatible_sql_modes();

		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $incompatible_modes, true ) ) {
				unset( $modes[ $i ] );
			}
		}
		$modes_str = implode( ',', $modes );

		$this->dbh->query( "SET SESSION sql_mode='" . str_replace( "'", "''", $modes_str ) . "'" );
	}

	/**
	 * Get the SQL modes WordPress would leave active after db_connect().
	 *
	 * @return array<int,string> SQL modes.
	 */
	private function get_wordpress_compatible_sql_modes(): array {
		$modes = array(
			'ERROR_FOR_DIVISION_BY_ZERO',
			'NO_ENGINE_SUBSTITUTION',
			'NO_ZERO_DATE',
			'NO_ZERO_IN_DATE',
			'ONLY_FULL_GROUP_BY',
			'STRICT_TRANS_TABLES',
		);
		$modes = array_change_key_case( $modes, CASE_UPPER );

		$incompatible_modes = $this->get_incompatible_sql_modes();
		foreach ( $modes as $i => $mode ) {
			if ( in_array( $mode, $incompatible_modes, true ) ) {
				unset( $modes[ $i ] );
			}
		}

		return array_values( $modes );
	}

	/**
	 * Refresh exact WordPress table AUTO_INCREMENT metadata on the active driver.
	 *
	 * @return void
	 */
	private function update_duckdb_known_auto_increment_columns(): void {
		if ( $this->dbh instanceof WP_DuckDB_Driver ) {
			$this->dbh->set_known_auto_increment_columns( $this->get_duckdb_known_auto_increment_columns() );
		}
	}

	/**
	 * Get exact AUTO_INCREMENT columns known to the adapter.
	 *
	 * This combines immutable WordPress core table knowledge with custom table
	 * metadata learned by the DuckDB driver during previous successful DDL.
	 *
	 * @return array<string,string> AUTO_INCREMENT columns keyed by table name.
	 */
	private function get_duckdb_known_auto_increment_columns(): array {
		return array_merge(
			$this->read_duckdb_auto_increment_file_cache_columns(),
			$this->get_wordpress_known_auto_increment_columns()
		);
	}

	/**
	 * Get exact WordPress core table AUTO_INCREMENT columns for this install.
	 *
	 * @return array<string,string> AUTO_INCREMENT columns keyed by table name.
	 */
	private function get_wordpress_known_auto_increment_columns(): array {
		$core_auto_increment_columns = array(
			'blogmeta'         => 'meta_id',
			'blogs'            => 'blog_id',
			'commentmeta'      => 'meta_id',
			'comments'         => 'comment_ID',
			'links'            => 'link_id',
			'options'          => 'option_id',
			'postmeta'         => 'meta_id',
			'posts'            => 'ID',
			'registration_log' => 'ID',
			'signups'          => 'signup_id',
			'site'             => 'id',
			'sitemeta'         => 'meta_id',
			'term_taxonomy'    => 'term_taxonomy_id',
			'termmeta'         => 'meta_id',
			'terms'            => 'term_id',
			'usermeta'         => 'umeta_id',
			'users'            => 'ID',
		);

		$known = array();
		foreach ( $core_auto_increment_columns as $property => $column_name ) {
			$table_name = $this->get_wordpress_known_table_name( $property );
			if ( null !== $table_name ) {
				$known[ $table_name ] = $column_name;
			}
		}

		return $known;
	}

	/**
	 * Read learned custom-table AUTO_INCREMENT metadata from disk.
	 *
	 * @return array<string,string> AUTO_INCREMENT columns keyed by table name.
	 */
	private function read_duckdb_auto_increment_file_cache_columns(): array {
		$path = $this->duckdb_auto_increment_file_cache_path();
		if ( false === $path || ! is_readable( $path ) ) {
			return array();
		}

		$encoded = file_get_contents( $path );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return array();
		}

		$payload = json_decode( $encoded, true );
		if (
			! is_array( $payload )
			|| 1 !== (int) ( $payload['version'] ?? 0 )
			|| (string) $this->dbname !== (string) ( $payload['database'] ?? '' )
			|| ! isset( $payload['columns'] )
			|| ! is_array( $payload['columns'] )
		) {
			return array();
		}

		$columns = array();
		foreach ( $payload['columns'] as $table_name => $column_name ) {
			if ( ! is_string( $table_name ) || '' === trim( $table_name ) ) {
				continue;
			}
			if ( ! is_string( $column_name ) || '' === trim( $column_name ) ) {
				continue;
			}

			$columns[ trim( $table_name ) ] = trim( $column_name );
		}

		return $columns;
	}

	/**
	 * Persist the driver's learned AUTO_INCREMENT metadata for future requests.
	 *
	 * @return void
	 */
	private function write_duckdb_auto_increment_file_cache(): void {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver || ! method_exists( $this->dbh, 'get_known_auto_increment_columns' ) ) {
			return;
		}

		$path = $this->duckdb_auto_increment_file_cache_path();
		if ( false === $path ) {
			return;
		}

		$columns = $this->dbh->get_known_auto_increment_columns();
		if ( ! is_array( $columns ) ) {
			return;
		}

		ksort( $columns );
		$payload = array(
			'version'  => 1,
			'database' => (string) $this->dbname,
			'columns'  => $columns,
		);
		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
			return;
		}

		$tmp = tempnam( $dir, '.ht.duckdb-auto-increment-' );
		if ( ! is_string( $tmp ) ) {
			return;
		}
		if ( false === file_put_contents( $tmp, $encoded, LOCK_EX ) ) {
			@unlink( $tmp );
			return;
		}
		@chmod( $tmp, 0600 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
		}
	}

	/**
	 * Read learned persistent table and column metadata from disk.
	 *
	 * @return array<string,mixed> Schema metadata cache payload.
	 */
	private function read_duckdb_schema_metadata_file_cache(): array {
		$path = $this->duckdb_schema_metadata_file_cache_path();
		if ( false === $path || ! is_readable( $path ) ) {
			return array();
		}

		$encoded = file_get_contents( $path );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return array();
		}

		$payload = json_decode( $encoded, true );
		if (
			! is_array( $payload )
			|| 1 !== (int) ( $payload['version'] ?? 0 )
			|| (string) $this->dbname !== (string) ( $payload['database'] ?? '' )
			|| ! isset( $payload['schema'] )
			|| ! is_array( $payload['schema'] )
		) {
			return array();
		}

		return $payload['schema'];
	}

	/**
	 * Persist learned persistent table and column metadata for future requests.
	 *
	 * @return void
	 */
	private function write_duckdb_schema_metadata_file_cache(): void {
		if ( ! $this->dbh instanceof WP_DuckDB_Driver || ! method_exists( $this->dbh, 'get_schema_metadata_cache' ) ) {
			return;
		}

		$path = $this->duckdb_schema_metadata_file_cache_path();
		if ( false === $path ) {
			return;
		}

		try {
			$schema = $this->dbh->get_schema_metadata_cache();
		} catch ( Throwable $e ) {
			return;
		}
		if ( ! is_array( $schema ) ) {
			return;
		}

		$payload = array(
			'version'  => 1,
			'database' => (string) $this->dbname,
			'schema'   => $schema,
		);
		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
			return;
		}

		$tmp = tempnam( $dir, '.ht.duckdb-schema-' );
		if ( ! is_string( $tmp ) ) {
			return;
		}
		if ( false === file_put_contents( $tmp, $encoded, LOCK_EX ) ) {
			@unlink( $tmp );
			return;
		}
		@chmod( $tmp, 0600 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
		}
	}

	/**
	 * Get the learned AUTO_INCREMENT metadata cache path.
	 *
	 * @return string|false Cache path, or false when no writable database dir is known.
	 */
	private function duckdb_auto_increment_file_cache_path() {
		if ( defined( 'DB_DIR' ) ) {
			$dir = DB_DIR;
		} elseif ( defined( 'FQDBDIR' ) ) {
			$dir = FQDBDIR;
		} else {
			return false;
		}

		$dir = rtrim( (string) $dir, "/\\" );
		if ( '' === $dir ) {
			return false;
		}

		$key = sha1( (string) $this->dbname );
		return $dir . '/.ht.duckdb-auto-increment-' . $key . '.json';
	}

	/**
	 * Get the learned schema metadata cache path.
	 *
	 * @return string|false Cache path, or false when no writable database dir is known.
	 */
	private function duckdb_schema_metadata_file_cache_path() {
		if ( defined( 'DB_DIR' ) ) {
			$dir = DB_DIR;
		} elseif ( defined( 'FQDBDIR' ) ) {
			$dir = FQDBDIR;
		} else {
			return false;
		}

		$dir = rtrim( (string) $dir, "/\\" );
		if ( '' === $dir ) {
			return false;
		}

		$key = sha1( (string) $this->dbname );
		return $dir . '/.ht.duckdb-schema-' . $key . '.json';
	}

	/**
	 * Get one exact WordPress table name for a table property.
	 *
	 * @param string $property wpdb table property, such as posts or options.
	 * @return string|null Table name, or null when not knowable yet.
	 */
	private function get_wordpress_known_table_name( string $property ): ?string {
		if ( isset( $this->$property ) && is_string( $this->$property ) && '' !== $this->$property ) {
			return $this->$property;
		}

		if ( isset( $this->prefix ) && is_string( $this->prefix ) && '' !== $this->prefix ) {
			return $this->prefix . $property;
		}

		return null;
	}

	/**
	 * Close the database connection.
	 *
	 * @return bool
	 */
	public function close() {
		if ( ! $this->dbh ) {
			$this->ready         = false;
			$this->has_connected = false;
			return false;
		}

		$driver     = $this->dbh instanceof WP_DuckDB_Driver ? $this->dbh : null;
		$connection = $this->dbh instanceof WP_DuckDB_Connection ? $this->dbh : null;
		if ( $driver instanceof WP_DuckDB_Driver ) {
			$connection = $driver->get_connection();
		}

		try {
			$this->flush_storage_backend();
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}

		if ( $driver instanceof WP_DuckDB_Driver ) {
			$driver->close();
		} elseif ( $connection instanceof WP_DuckDB_Connection ) {
			$connection->close();
		}
		if ( $this->storage_backend instanceof WP_DuckDB_Storage_Backend ) {
			$this->storage_backend->close();
		}
		$this->clear_cached_driver( $driver );
		$this->clear_cached_connection( $connection );

		$this->ready         = false;
		$this->has_connected = false;
		$this->dbh           = null;

		return true;
	}

	/**
	 * Flush configured DuckDB external storage, when enabled.
	 *
	 * @return void
	 */
	public function flush_storage_backend() {
		if (
			$this->dbh instanceof WP_DuckDB_Driver
			&& method_exists( $this->dbh, 'commit_request_transaction' )
		) {
			$this->dbh->commit_request_transaction();
		}

		if ( $this->storage_backend instanceof WP_DuckDB_Storage_Backend ) {
			$this->storage_backend->flush();
		}
	}

	/**
	 * Select the database.
	 *
	 * @param string        $db  Database name.
	 * @param resource|null $dbh Optional database handle.
	 */
	public function select( $db, $dbh = null ) {
		$this->ready = true;
	}

	/**
	 * Escape data.
	 *
	 * @param string $data Data to escape.
	 * @return string
	 */
	public function _real_escape( $data ) {
		if ( ! is_scalar( $data ) ) {
			return '';
		}
		return $this->add_placeholder_escape( addslashes( $data ) );
	}

	/**
	 * Prints SQL/DB error.
	 *
	 * This overrides wpdb::print_error() while avoiding its mysqli error
	 * fallback for non-mysqli database handles.
	 *
	 * @global array $EZSQL_ERROR Stores error information of query and error string.
	 *
	 * @param string $str The error to display.
	 * @return void|false Void if the showing of errors is enabled, false if disabled.
	 */
	public function print_error( $str = '' ) {
		global $EZSQL_ERROR;

		if ( ! $str ) {
			$str = $this->last_error;
		}

		$EZSQL_ERROR[] = array(
			'query'     => $this->last_query,
			'error_str' => $str,
		);

		if ( $this->suppress_errors ) {
			return false;
		}

		$caller = $this->get_caller();
		if ( $caller ) {
			// Not translated, as this will only appear in the error log.
			$error_str = sprintf( 'WordPress database error %1$s for query %2$s made by %3$s', $str, $this->last_query, $caller );
		} else {
			$error_str = sprintf( 'WordPress database error %1$s for query %2$s', $str, $this->last_query );
		}

		error_log( $error_str );

		if ( ! $this->show_errors ) {
			return false;
		}

		wp_load_translations_early();

		if ( is_multisite() ) {
			$msg = sprintf(
				"%s [%s]\n%s\n",
				__( 'WordPress database error:' ),
				$str,
				$this->last_query
			);

			if ( defined( 'ERRORLOGFILE' ) ) {
				error_log( $msg, 3, ERRORLOGFILE );
			}
			if ( defined( 'DIEONDBERROR' ) ) {
				wp_die( $msg );
			}
		} else {
			$str   = htmlspecialchars( $str, ENT_QUOTES );
			$query = htmlspecialchars( $this->last_query, ENT_QUOTES );

			printf(
				'<div id="error"><p class="wpdberror"><strong>%s</strong> [%s]<br /><code>%s</code></p></div>',
				__( 'WordPress database error:' ),
				$str,
				$query
			);
		}
	}

	/**
	 * Wrap errors in a WordPress error page without consulting mysqli state.
	 *
	 * The parent wpdb implementation calls mysqli_connect_errno() when the
	 * database handle is not a mysqli instance. DuckDB does not use mysqli, and
	 * the extension is not required for this backend.
	 *
	 * @param string $message    Error message.
	 * @param string $error_code Optional error code.
	 * @return void|false Void when showing errors, false when suppressed.
	 */
	public function bail( $message, $error_code = '500' ) {
		if ( $this->show_errors ) {
			if ( $this->last_error ) {
				$message = '<p><code>' . $this->last_error . "</code></p>\n" . $message;
			}

			wp_die( $message );
		}

		return false;
	}

	/**
	 * Flush cached query state.
	 */
	public function flush() {
		$this->last_result    = array();
		$this->col_info       = null;
		$this->last_query     = null;
		$this->rows_affected  = 0;
		$this->num_rows       = 0;
		$this->last_error     = '';
		$this->result         = null;
		$this->last_statement = null;
	}

	/**
	 * Connect to DuckDB.
	 *
	 * @param bool $allow_bail Whether to bail on error.
	 * @return bool|null
	 */
	public function db_connect( $allow_bail = true ) {
		$this->is_mysql   = true;
		$this->last_error = '';

		$this->discard_closed_handle();

		if ( ! $this->dbh ) {
			if ( isset( $GLOBALS['@duckdb_driver'] ) && $GLOBALS['@duckdb_driver'] instanceof WP_DuckDB_Driver ) {
				if ( $GLOBALS['@duckdb_driver']->is_closed() ) {
					$this->clear_cached_driver( $GLOBALS['@duckdb_driver'] );
				} else {
					$this->dbh = $GLOBALS['@duckdb_driver'];
				}
			}
			if ( ! $this->dbh && isset( $GLOBALS['@duckdb'] ) && $GLOBALS['@duckdb'] instanceof WP_DuckDB_Connection ) {
				if ( $GLOBALS['@duckdb']->is_closed() ) {
					$this->clear_cached_connection( $GLOBALS['@duckdb'] );
				} else {
					$this->dbh = $GLOBALS['@duckdb'];
				}
			}
		}

		if ( null === $this->dbname || '' === $this->dbname ) {
			$this->bail(
				'The database name was not set. The DuckDB driver requires a database name to be set.',
				'db_connect_fail'
			);
			return false;
		}

		try {
			if ( ! $this->dbh ) {
				if ( ! ( $this->storage_backend instanceof WP_DuckDB_Storage_Backend ) ) {
					$this->storage_backend = WP_DuckDB_Storage_Backend::from_constants();
				}
				$database_path         = $this->storage_backend->get_database_path();
				if ( null !== $database_path && ':memory:' !== $database_path ) {
					$this->ensure_database_directory( $database_path );
				}
				$this->dbh                           = $this->storage_backend->create_driver(
					$this->dbname,
					array(
						'active_sql_modes'              => $this->get_wordpress_compatible_sql_modes(),
						'known_auto_increment_columns'  => $this->get_duckdb_known_auto_increment_columns(),
						'schema_metadata_cache'         => $this->read_duckdb_schema_metadata_file_cache(),
						'request_transaction'           => $this->duckdb_request_transaction_enabled(),
					)
				);
				$this->duckdb_sql_mode_bootstrapped = true;
				$this->register_storage_backend_shutdown_flush();
			} elseif ( $this->dbh instanceof WP_DuckDB_Connection ) {
				$this->dbh = new WP_DuckDB_Driver(
					array(
						'connection'                     => $this->dbh,
						'database'                       => $this->dbname,
						'active_sql_modes'               => $this->get_wordpress_compatible_sql_modes(),
						'known_auto_increment_columns'   => $this->get_duckdb_known_auto_increment_columns(),
						'schema_metadata_cache'          => $this->read_duckdb_schema_metadata_file_cache(),
						'request_transaction'            => $this->duckdb_request_transaction_enabled(),
					)
				);
				$this->duckdb_sql_mode_bootstrapped = true;
			}
			$this->update_duckdb_known_auto_increment_columns();
			$GLOBALS['@duckdb_driver'] = $this->dbh;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
		}

		if ( $this->last_error ) {
			error_log( '[duckdb-db-connect] ' . $this->last_error );
			return false;
		}

		$this->ready    = true;
		try {
			if ( ! $this->duckdb_sql_mode_bootstrapped ) {
				$this->set_sql_mode();
			}
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			$this->ready      = false;
			error_log( '[duckdb-db-connect] ' . $this->last_error );
			return false;
		}
		return true;
	}

	/**
	 * Register a shutdown flush for external DuckDB storage.
	 *
	 * @return void
	 */
	private function register_storage_backend_shutdown_flush() {
		if ( ! $this->storage_backend instanceof WP_DuckDB_Storage_Backend || ! $this->storage_backend->is_external() ) {
			return;
		}

		if ( function_exists( 'add_action' ) ) {
			if ( ! $this->storage_backend_shutdown_registered ) {
				add_action( 'shutdown', array( $this, 'flush_storage_backend' ), PHP_INT_MAX );
				$this->storage_backend_shutdown_registered = true;
			}
			return;
		}

		if ( $this->storage_backend_php_shutdown_registered ) {
			return;
		}

		$storage_backend = $this->storage_backend;
		register_shutdown_function(
			static function () use ( $storage_backend ) {
				try {
					$storage_backend->flush();
				} catch ( Throwable $e ) {
					error_log( '[duckdb-storage-backend-flush] ' . $e->getMessage() );
				}
			}
		);

		$this->storage_backend_php_shutdown_registered = true;
	}

	/**
	 * Drop a closed local handle before attempting to reconnect.
	 *
	 * @return void
	 */
	private function discard_closed_handle() {
		if ( $this->dbh instanceof WP_DuckDB_Driver && $this->dbh->is_closed() ) {
			$connection = $this->dbh->get_connection();
			$this->clear_cached_driver( $this->dbh );
			$this->clear_cached_connection( $connection );
			$this->dbh = null;
		}

		if ( $this->dbh instanceof WP_DuckDB_Connection && $this->dbh->is_closed() ) {
			$this->clear_cached_connection( $this->dbh );
			$this->dbh = null;
		}
	}

	/**
	 * Clear the cached global DuckDB driver when it matches a closed handle.
	 *
	 * @param WP_DuckDB_Driver|null $driver Driver to clear.
	 * @return void
	 */
	private function clear_cached_driver( ?WP_DuckDB_Driver $driver ) {
		if ( null !== $driver && isset( $GLOBALS['@duckdb_driver'] ) && $GLOBALS['@duckdb_driver'] === $driver ) {
			unset( $GLOBALS['@duckdb_driver'] );
		}
	}

	/**
	 * Clear the cached global DuckDB connection when it matches a closed handle.
	 *
	 * @param WP_DuckDB_Connection|null $connection Connection to clear.
	 * @return void
	 */
	private function clear_cached_connection( ?WP_DuckDB_Connection $connection ) {
		if ( null !== $connection && isset( $GLOBALS['@duckdb'] ) && $GLOBALS['@duckdb'] === $connection ) {
			unset( $GLOBALS['@duckdb'] );
		}
	}

	/**
	 * Check the connection.
	 *
	 * @param bool $allow_bail Whether to bail on reconnect failure.
	 * @return bool
	 */
	public function check_connection( $allow_bail = true ) {
		$this->discard_closed_handle();

		if ( $this->dbh instanceof WP_DuckDB_Driver && ! $this->dbh->is_closed() ) {
			$this->ready         = true;
			$this->has_connected = true;
			return true;
		}

		$this->ready         = false;
		$this->has_connected = false;
		if ( ! ( $this->dbh instanceof WP_DuckDB_Connection ) ) {
			$this->dbh = null;
		}

		return (bool) $this->db_connect( $allow_bail );
	}

	/**
	 * Prepares a SQL query for safe execution.
	 *
	 * See "wpdb::prepare()". This override only fixes a WPDB test issue.
	 *
	 * @param string      $query Query statement with `sprintf()`-like placeholders.
	 * @param array|mixed $args  The array of variables or the first variable to substitute.
	 * @param mixed       ...$args Further variables to substitute when using individual arguments.
	 * @return string|void Sanitized query string, if there is a query to prepare.
	 */
	public function prepare( $query, ...$args ) {
		$this->maybe_log_prepare_object_diagnostics( $query, $args );

		/*
		 * Sync "$allow_unsafe_unquoted_parameters" with the WPDB parent property.
		 * This is only needed because some WPDB tests access the private property
		 * externally via PHP reflection.
		 */
		$wpdb_allow_unsafe_unquoted_parameters = $this->__get( 'allow_unsafe_unquoted_parameters' );
		if ( $wpdb_allow_unsafe_unquoted_parameters !== $this->allow_unsafe_unquoted_parameters ) {
			$property = new ReflectionProperty( 'wpdb', 'allow_unsafe_unquoted_parameters' );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( true );
			}
			$property->setValue( $this, $this->allow_unsafe_unquoted_parameters );
			if ( PHP_VERSION_ID < 80100 ) {
				$property->setAccessible( false );
			}
		}

		return parent::prepare( $query, ...$args );
	}

	/**
	 * Log object prepare arguments when explicitly requested for CI diagnostics.
	 *
	 * @param string      $query Query statement with `sprintf()`-like placeholders.
	 * @param array|mixed $args  Prepared query arguments.
	 */
	private function maybe_log_prepare_object_diagnostics( $query, array $args ) {
		if ( ! $this->prepare_object_diagnostics_enabled() ) {
			return;
		}

		foreach ( $this->get_prepare_object_arguments( $args ) as $object_arg ) {
			$payload = array(
				'event'          => 'prepare_object_argument',
				'pid'            => function_exists( 'getmypid' ) ? getmypid() : null,
				'query_hash'     => is_string( $query ) ? sha1( $query ) : null,
				'query_shape'    => $this->prepare_diagnostic_query_shape( $query ),
				'argument_style' => $object_arg['argument_style'],
				'argument_key'   => $object_arg['argument_key'],
				'object_class'   => get_class( $object_arg['value'] ),
				'wpdb_state'     => array(
					'last_error'    => $this->prepare_diagnostic_value( $this->last_error ),
					'last_query'    => $this->prepare_diagnostic_value( $this->last_query ),
					'insert_id'     => $this->insert_id,
					'rows_affected' => $this->rows_affected,
					'num_queries'   => $this->num_queries,
				),
				'backtrace'      => $this->prepare_object_diagnostic_backtrace(),
			);

			$wp_error = $this->prepare_diagnostic_wp_error_payload( $object_arg['value'] );
			if ( null !== $wp_error ) {
				$payload['wp_error'] = $wp_error;
			}

			error_log(
				'WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTIC ' .
				$this->json_encode_diagnostic_payload( $payload )
			);
		}
	}

	/**
	 * Determine whether prepare object diagnostics are enabled.
	 *
	 * @return bool Whether diagnostics should be emitted.
	 */
	private function prepare_object_diagnostics_enabled() {
		if ( defined( 'WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTICS' ) ) {
			return $this->prepare_diagnostic_truthy(
				constant( 'WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTICS' )
			);
		}

		$value = getenv( 'WP_SQLITE_DUCKDB_PREPARE_OBJECT_DIAGNOSTICS' );
		return false !== $value && $this->prepare_diagnostic_truthy( $value );
	}

	/**
	 * Return object arguments from a wpdb::prepare() argument list.
	 *
	 * @param array $args Prepared query arguments.
	 * @return array<int,array{argument_style:string,argument_key:int|string,value:object}>
	 */
	private function get_prepare_object_arguments( array $args ) {
		$object_args = array();

		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			foreach ( $args[0] as $key => $value ) {
				if ( is_object( $value ) ) {
					$object_args[] = array(
						'argument_style' => 'array',
						'argument_key'   => $key,
						'value'          => $value,
					);
				}
			}

			return $object_args;
		}

		foreach ( $args as $key => $value ) {
			if ( is_object( $value ) ) {
				$object_args[] = array(
					'argument_style' => 'variadic',
					'argument_key'   => $key,
					'value'          => $value,
				);
			}
		}

		return $object_args;
	}

	/**
	 * Build a compact query shape safe enough for CI logs.
	 *
	 * @param mixed $query Query value passed to wpdb::prepare().
	 * @return string Query shape.
	 */
	private function prepare_diagnostic_query_shape( $query ) {
		if ( ! is_string( $query ) ) {
			return gettype( $query );
		}

		$shape = preg_replace( "/'(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\"/s", '?', $query );
		$shape = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $shape );
		$shape = preg_replace( '/\s+/', ' ', trim( $shape ) );

		return $this->prepare_diagnostic_truncate( $shape );
	}

	/**
	 * Return WP_Error details when the object is a WP_Error instance.
	 *
	 * @param object $value Prepare argument object.
	 * @return array<string,mixed>|null WP_Error details, or null.
	 */
	private function prepare_diagnostic_wp_error_payload( $value ) {
		if ( ! class_exists( 'WP_Error', false ) || ! ( $value instanceof WP_Error ) ) {
			return null;
		}

		$code = method_exists( $value, 'get_error_code' ) ? $value->get_error_code() : null;
		$data = method_exists( $value, 'get_error_data' )
			? $value->get_error_data( is_string( $code ) ? $code : '' )
			: null;

		return array(
			'code'    => $this->prepare_diagnostic_value( $code ),
			'message' => method_exists( $value, 'get_error_message' )
				? $this->prepare_diagnostic_value( $value->get_error_message( $code ) )
				: null,
			'data'    => $this->prepare_diagnostic_value( $data ),
		);
	}

	/**
	 * Build a compact backtrace without argument values.
	 *
	 * @return array<int,array<string,mixed>> Backtrace payload.
	 */
	private function prepare_object_diagnostic_backtrace() {
		$frames  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
		$payload = array();

		foreach ( $frames as $frame ) {
			$payload[] = array(
				'function' => isset( $frame['function'] ) ? $frame['function'] : null,
				'class'    => isset( $frame['class'] ) ? $frame['class'] : null,
				'file'     => isset( $frame['file'] ) ? $frame['file'] : null,
				'line'     => isset( $frame['line'] ) ? $frame['line'] : null,
			);
		}

		return $payload;
	}

	/**
	 * Normalize diagnostic values so JSON encoding cannot expose giant payloads.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed Normalized value.
	 */
	private function prepare_diagnostic_value( $value ) {
		if ( is_string( $value ) ) {
			return $this->prepare_diagnostic_truncate( $value );
		}

		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return array(
				'type'  => 'object',
				'class' => get_class( $value ),
			);
		}

		if ( is_array( $value ) ) {
			$normalized = array();
			$count      = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= 10 ) {
					$normalized['__truncated__'] = count( $value ) - $count;
					break;
				}
				$normalized[ is_int( $key ) ? $key : (string) $key ] = $this->prepare_diagnostic_value( $item );
				++$count;
			}
			return $normalized;
		}

		return gettype( $value );
	}

	/**
	 * Truncate a diagnostic string.
	 *
	 * @param string $value String value.
	 * @param int    $limit Maximum length.
	 * @return string Truncated value.
	 */
	private function prepare_diagnostic_truncate( $value, $limit = 500 ) {
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, $limit ) . '...';
	}

	/**
	 * Determine whether an env/constant value is truthy.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool Whether the value is truthy.
	 */
	private function prepare_diagnostic_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 0 !== $value;
		}

		if ( ! is_string( $value ) ) {
			return ! empty( $value );
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * JSON-encode a diagnostic payload.
	 *
	 * @param array $payload Diagnostic payload.
	 * @return string Encoded payload.
	 */
	private function json_encode_diagnostic_payload( array $payload ) {
		$json = json_encode( $payload );

		if ( false === $json ) {
			return '{"event":"prepare_object_argument","json_error":"encode_failed"}';
		}

		return $json;
	}

	/**
	 * Perform a query.
	 *
	 * @param string $query Database query.
	 * @return int|bool
	 */
	public function query( $query ) {
		if ( ! $this->ready ) {
			if ( property_exists( $this, 'check_current_query' ) ) {
				$this->check_current_query = true;
			}
			return false;
		}

		$this->register_storage_backend_shutdown_flush();

		$query = apply_filters( 'query', $query );
		if ( ! $query ) {
			$this->insert_id = 0;
			return false;
		}

		$this->flush();
		$this->func_call = "\$db->query(\"$query\")";

		if (
			property_exists( $this, 'check_current_query' )
			&& method_exists( $this, 'check_ascii' )
			&& method_exists( $this, 'strip_invalid_text_from_query' )
			&& $this->check_current_query
			&& ! $this->check_ascii( $query )
		) {
			$stripped_query = $this->strip_invalid_text_from_query( $query );
			$this->flush();
			if ( $stripped_query !== $query ) {
				$this->insert_id  = 0;
				$this->last_query = $query;

				wp_load_translations_early();

				$this->last_error = __( 'WordPress database error: Could not perform query because it contains invalid data.' );

				return false;
			}
		}

		if ( property_exists( $this, 'check_current_query' ) ) {
			$this->check_current_query = true;
		}
		$this->last_query = $query;

		$statement_type                 = $this->get_statement_keyword( $query );
		$mutates_options_table          = $this->duckdb_query_may_mutate_options_table( $query, $statement_type );
		$preserved_options_write_lookup = $mutates_options_table ? $this->duckdb_options_write_alloptions_preservation_metadata( $query, $statement_type ) : null;
		$invalidates_options_cache      = $mutates_options_table && null === $preserved_options_write_lookup;
		$creates_temporary_shadow       = $this->duckdb_query_creates_temporary_options_shadow_table( $query, $statement_type );
		if ( $creates_temporary_shadow ) {
			$this->duckdb_alloptions_file_cache_suspended = true;
		}

		if (
			'select' === $statement_type
				&& (
					$this->load_duckdb_alloptions_file_cache_result( $query )
					|| $this->load_duckdb_option_names_file_cache_result( $query )
					|| $this->load_duckdb_single_option_file_cache_result( $query )
				)
			) {
			return $this->num_rows;
		}

		$this->_do_query( $query );

		if ( $this->last_error ) {
			if ( $this->insert_id && preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				$this->insert_id = 0;
			}

			$this->print_error();
			return false;
		}

		if ( in_array( $statement_type, array( 'create', 'alter', 'truncate', 'drop' ), true ) ) {
			$this->clear_all_duckdb_table_charset_cache();
			if ( in_array( $statement_type, array( 'create', 'alter', 'drop' ), true ) ) {
				$this->write_duckdb_auto_increment_file_cache();
				$this->write_duckdb_schema_metadata_file_cache();
			}
			if ( $invalidates_options_cache ) {
				$this->invalidate_duckdb_alloptions_file_cache();
			}
			return true;
		}

		if ( in_array( $statement_type, array( 'insert', 'delete', 'update', 'replace' ), true ) ) {
			$this->rows_affected = $this->last_statement ? $this->last_statement->rowCount() : 0;
			if ( in_array( $statement_type, array( 'insert', 'replace' ), true ) && method_exists( $this->dbh, 'get_insert_id' ) ) {
				$this->insert_id = (int) $this->dbh->get_insert_id();
				if ( 0 === $this->rows_affected && $this->insert_id > 0 ) {
					$this->rows_affected = 1;
				}
			}
			if ( defined( 'WP_DUCKDB_E2E_DIAGNOSTICS' ) && WP_DUCKDB_E2E_DIAGNOSTICS && $this->is_persisted_preferences_usermeta_insert( $query ) ) {
				$this->log_persisted_preferences_insert_diagnostic(
					$query,
					$this->persisted_preferences_usermeta_insert_table( $query )
				);
			}
				if ( $invalidates_options_cache ) {
					$this->invalidate_duckdb_alloptions_file_cache();
				} elseif ( null !== $preserved_options_write_lookup ) {
					$this->write_duckdb_single_option_file_cache_lookup(
						$preserved_options_write_lookup['option_name'],
						array_key_exists( 'option_value', $preserved_options_write_lookup )
							? $preserved_options_write_lookup['option_value']
							: null
					);
				}
				return $this->rows_affected;
			}

		$this->last_result = is_array( $this->result ) ? $this->result : array();
		$this->num_rows    = count( $this->last_result );
		if ( 'select' === $statement_type && $this->is_duckdb_alloptions_select_query( $query ) ) {
			$this->write_duckdb_alloptions_file_cache( $this->last_result );
		} elseif ( 'select' === $statement_type && null !== $this->duckdb_option_names_select_names( $query ) ) {
			$this->write_duckdb_option_names_file_cache( $query, $this->last_result );
		} elseif ( 'select' === $statement_type && $this->is_duckdb_single_option_value_select_query( $query ) ) {
			$this->write_duckdb_single_option_file_cache( $query, $this->last_result );
		}
		return $this->num_rows;
	}

	/**
	 * Load the canonical WordPress alloptions query from the file cache.
	 *
	 * @param string $query SQL query.
	 * @return bool Whether the cache supplied the result.
	 */
	private function load_duckdb_alloptions_file_cache_result( $query ) {
		if (
			! $this->duckdb_alloptions_file_cache_enabled()
			|| $this->duckdb_alloptions_file_cache_suspended
			|| ! $this->is_duckdb_alloptions_select_query( $query )
		) {
			return false;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) || empty( $payload['alloptions_complete'] ) ) {
			return false;
		}

		$rows = array();
		foreach ( $payload['rows'] as $row ) {
			if ( ! is_array( $row ) || ! array_key_exists( 'option_name', $row ) || ! array_key_exists( 'option_value', $row ) ) {
				return false;
			}
			$rows[] = array(
				'option_name'  => (string) $row['option_name'],
				'option_value' => (string) $row['option_value'],
			);
		}

		$this->set_duckdb_alloptions_result_rows( $rows );
		return true;
	}

	/**
	 * Load an exact single-option lookup from the file cache.
	 *
	 * @param string $query SQL query.
	 * @return bool Whether the cache supplied the result.
	 */
	private function load_duckdb_single_option_file_cache_result( $query ) {
		if (
			! $this->duckdb_alloptions_file_cache_enabled()
			|| $this->duckdb_alloptions_file_cache_suspended
			|| ! $this->is_duckdb_single_option_value_select_query( $query )
		) {
			return false;
		}

		$option_name = $this->duckdb_single_option_value_select_name( $query );
		if ( null === $option_name ) {
			return false;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$lookup = $this->duckdb_options_file_cache_lookup_option( $payload, $option_name );
		if ( null === $lookup ) {
			return false;
		}

		$rows   = empty( $lookup['exists'] )
			? array()
			: array(
				array(
					'option_value' => (string) ( $lookup['option_value'] ?? '' ),
				),
			);

		$this->set_duckdb_single_option_result_rows( $rows );
		return true;
	}

	/**
	 * Load exact option-name lookups from the file cache.
	 *
	 * @param string $query SQL query.
	 * @return bool Whether the cache supplied the result.
	 */
	private function load_duckdb_option_names_file_cache_result( $query ) {
		if (
			! $this->duckdb_alloptions_file_cache_enabled()
			|| $this->duckdb_alloptions_file_cache_suspended
		) {
			return false;
		}

		$option_names = $this->duckdb_option_names_select_names( $query );
		if ( null === $option_names ) {
			return false;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) ) {
			return false;
		}

		$rows = array();
		foreach ( $option_names as $option_name ) {
			$lookup = $this->duckdb_options_file_cache_lookup_option( $payload, $option_name );
			if ( null === $lookup ) {
				return false;
			}
			if ( empty( $lookup['exists'] ) ) {
				continue;
			}
			$rows[] = array(
				'option_name'  => $option_name,
				'option_value' => (string) ( $lookup['option_value'] ?? '' ),
			);
		}

		$this->set_duckdb_alloptions_result_rows( $rows );
		return true;
	}

	/**
	 * Store the canonical WordPress alloptions query result in the file cache.
	 *
	 * @param array<int,object> $rows Result rows.
	 * @return void
	 */
	private function write_duckdb_alloptions_file_cache( array $rows ): void {
		if ( ! $this->duckdb_alloptions_file_cache_enabled() || $this->duckdb_alloptions_file_cache_suspended ) {
			return;
		}

		$path = $this->duckdb_alloptions_file_cache_path();
		if ( false === $path ) {
			return;
		}

		$cache_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->option_name ) || ! property_exists( $row, 'option_value' ) ) {
				return;
			}
			$cache_rows[] = array(
				'option_name'  => (string) $row->option_name,
				'option_value' => (string) $row->option_value,
			);
		}

		$payload                         = $this->base_duckdb_options_file_cache_payload();
		$existing                        = $this->read_duckdb_options_file_cache_payload();
		$payload['lookups']              = is_array( $existing ) && isset( $existing['lookups'] ) && is_array( $existing['lookups'] ) ? $existing['lookups'] : array();
		$payload['alloptions_complete']  = true;
		$payload['rows']                 = $cache_rows;

		$this->write_duckdb_options_file_cache_payload( $payload );
	}

	/**
	 * Store an exact single-option lookup in the file cache.
	 *
	 * @param string            $query SQL query.
	 * @param array<int,object> $rows  Result rows.
	 * @return void
	 */
	private function write_duckdb_single_option_file_cache( $query, array $rows ): void {
		if ( ! $this->duckdb_alloptions_file_cache_enabled() || $this->duckdb_alloptions_file_cache_suspended ) {
			return;
		}

		$option_name = $this->duckdb_single_option_value_select_name( $query );
		if ( null === $option_name ) {
			return;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) ) {
			$payload = $this->base_duckdb_options_file_cache_payload();
		}
		if ( ! isset( $payload['lookups'] ) || ! is_array( $payload['lookups'] ) ) {
			$payload['lookups'] = array();
		}

		$payload['lookups'][ $option_name ] = array(
			'exists'       => count( $rows ) > 0,
			'option_value' => count( $rows ) > 0 && isset( $rows[0]->option_value ) ? (string) $rows[0]->option_value : '',
		);

		$this->write_duckdb_options_file_cache_payload( $payload );
	}

	/**
	 * Store exact multi-option lookup results in the file cache.
	 *
	 * @param string            $query SQL query.
	 * @param array<int,object> $rows  Result rows.
	 * @return void
	 */
	private function write_duckdb_option_names_file_cache( $query, array $rows ): void {
		if ( ! $this->duckdb_alloptions_file_cache_enabled() || $this->duckdb_alloptions_file_cache_suspended ) {
			return;
		}

		$option_names = $this->duckdb_option_names_select_names( $query );
		if ( null === $option_names ) {
			return;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) ) {
			$payload = $this->base_duckdb_options_file_cache_payload();
		}
		if ( ! isset( $payload['lookups'] ) || ! is_array( $payload['lookups'] ) ) {
			$payload['lookups'] = array();
		}

		$returned = array();
		foreach ( $rows as $row ) {
			if ( ! is_object( $row ) || ! isset( $row->option_name ) || ! property_exists( $row, 'option_value' ) ) {
				return;
			}
			$returned[ strtolower( (string) $row->option_name ) ] = (string) $row->option_value;
		}

		foreach ( $option_names as $option_name ) {
			$lookup_key = strtolower( $option_name );
			if ( array_key_exists( $lookup_key, $returned ) ) {
				$payload['lookups'][ $option_name ] = array(
					'exists'       => true,
					'option_value' => $returned[ $lookup_key ],
				);
			} else {
				$payload['lookups'][ $option_name ] = array(
					'exists'       => false,
					'option_value' => '',
				);
			}
		}

		$this->write_duckdb_options_file_cache_payload( $payload );
	}

	/**
	 * Store or clear a single-option lookup while preserving the alloptions rows.
	 *
	 * @param string      $option_name  Option name.
	 * @param string|null $option_value Option value, or null to clear the lookup.
	 * @return void
	 */
	private function write_duckdb_single_option_file_cache_lookup( string $option_name, ?string $option_value ): void {
		if ( ! $this->duckdb_alloptions_file_cache_enabled() || $this->duckdb_alloptions_file_cache_suspended ) {
			return;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) ) {
			return;
		}
		if ( ! isset( $payload['lookups'] ) || ! is_array( $payload['lookups'] ) ) {
			$payload['lookups'] = array();
		}

		if ( null === $option_value ) {
			unset( $payload['lookups'][ $option_name ] );
		} else {
			$payload['lookups'][ $option_name ] = array(
				'exists'       => true,
				'option_value' => $option_value,
			);
		}

		$this->write_duckdb_options_file_cache_payload( $payload );
	}

	/**
	 * Read the shared options file cache payload.
	 *
	 * @return array<string,mixed>|false Cache payload, or false.
	 */
	private function read_duckdb_options_file_cache_payload() {
		$path = $this->duckdb_alloptions_file_cache_path();
		if ( false === $path || ! is_readable( $path ) ) {
			return false;
		}

		$encoded = file_get_contents( $path );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return false;
		}

		$payload = json_decode( $encoded, true );
		if (
			! is_array( $payload )
			|| 1 !== (int) ( $payload['version'] ?? 0 )
			|| (string) $this->dbname !== (string) ( $payload['database'] ?? '' )
			|| 0 !== strcasecmp( $this->duckdb_options_table_name(), (string) ( $payload['table'] ?? '' ) )
			|| ! isset( $payload['rows'] )
			|| ! is_array( $payload['rows'] )
		) {
			return false;
		}

		return $payload;
	}

	/**
	 * Look up an option value in the shared options file cache.
	 *
	 * @param array<string,mixed> $payload     Cache payload.
	 * @param string              $option_name Option name.
	 * @return array{exists:bool,option_value:string}|null Lookup result, or null when unknown.
	 */
	private function duckdb_options_file_cache_lookup_option( array $payload, string $option_name ): ?array {
		if ( isset( $payload['rows'] ) && is_array( $payload['rows'] ) ) {
			foreach ( $payload['rows'] as $row ) {
				if ( is_array( $row ) && isset( $row['option_name'] ) && 0 === strcasecmp( (string) $row['option_name'], $option_name ) ) {
					return array(
						'exists'       => true,
						'option_value' => (string) ( $row['option_value'] ?? '' ),
					);
				}
			}
		}

		if ( isset( $payload['lookups'] ) && is_array( $payload['lookups'] ) ) {
			foreach ( $payload['lookups'] as $cached_name => $lookup ) {
				if ( 0 !== strcasecmp( (string) $cached_name, $option_name ) || ! is_array( $lookup ) ) {
					continue;
				}
				return array(
					'exists'       => ! empty( $lookup['exists'] ),
					'option_value' => (string) ( $lookup['option_value'] ?? '' ),
				);
			}
		}

		return null;
	}

	/**
	 * Build an empty shared options file cache payload.
	 *
	 * @return array<string,mixed> Cache payload.
	 */
	private function base_duckdb_options_file_cache_payload(): array {
		return array(
			'version'             => 1,
			'database'            => (string) $this->dbname,
			'table'               => $this->duckdb_options_table_name(),
			'alloptions_complete' => false,
			'rows'                => array(),
			'lookups'             => array(),
		);
	}

	/**
	 * Atomically write the shared options file cache payload.
	 *
	 * @param array<string,mixed> $payload Cache payload.
	 * @return void
	 */
	private function write_duckdb_options_file_cache_payload( array $payload ): void {
		$path = $this->duckdb_alloptions_file_cache_path();
		if ( false === $path ) {
			return;
		}

		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			return;
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0700, true ) ) {
			return;
		}

		$tmp = tempnam( $dir, '.ht.duckdb-alloptions-' );
		if ( ! is_string( $tmp ) ) {
			return;
		}
		if ( false === file_put_contents( $tmp, $encoded, LOCK_EX ) ) {
			@unlink( $tmp );
			return;
		}
		@chmod( $tmp, 0600 );
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
		}
	}

	/**
	 * Populate wpdb result state from cached alloptions rows.
	 *
	 * @param array<int,array{option_name:string,option_value:string}> $rows Cached rows.
	 * @return void
	 */
	private function set_duckdb_alloptions_result_rows( array $rows ): void {
		$numeric_rows = array();
		$object_rows  = array();
		foreach ( $rows as $row ) {
			$numeric_rows[] = array( $row['option_name'], $row['option_value'] );
			$object_rows[]  = (object) array(
				'option_name'  => $row['option_name'],
				'option_value' => $row['option_value'],
			);
		}

		$this->last_statement = new WP_DuckDB_Result_Statement(
			array( 'option_name', 'option_value' ),
			$numeric_rows,
			0,
			$this->duckdb_alloptions_result_column_metadata()
		);
		$this->result         = $this->normalize_result_rows( $object_rows );
		$this->last_result    = $this->result;
		$this->num_rows       = count( $this->last_result );
		++$this->num_queries;
	}

	/**
	 * Populate wpdb result state from cached single-option rows.
	 *
	 * @param array<int,array{option_value:string}> $rows Cached rows.
	 * @return void
	 */
	private function set_duckdb_single_option_result_rows( array $rows ): void {
		$numeric_rows = array();
		$object_rows  = array();
		foreach ( $rows as $row ) {
			$numeric_rows[] = array( $row['option_value'] );
			$object_rows[]  = (object) array(
				'option_value' => $row['option_value'],
			);
		}

		$this->last_statement = new WP_DuckDB_Result_Statement(
			array( 'option_value' ),
			$numeric_rows,
			0,
			$this->duckdb_single_option_result_column_metadata()
		);
		$this->result         = $this->normalize_result_rows( $object_rows );
		$this->last_result    = $this->result;
		$this->num_rows       = count( $this->last_result );
		++$this->num_queries;
	}

	/**
	 * Invalidate the alloptions file cache.
	 *
	 * @return void
	 */
	private function invalidate_duckdb_alloptions_file_cache(): void {
		$path = $this->duckdb_alloptions_file_cache_path();
		if ( is_string( $path ) && is_file( $path ) ) {
			@unlink( $path );
		}
	}

	/**
	 * Whether the alloptions file cache is enabled.
	 *
	 * @return bool
	 */
	private function duckdb_alloptions_file_cache_enabled(): bool {
		if ( defined( 'WP_DUCKDB_ALLOPTIONS_FILE_CACHE' ) ) {
			$value = WP_DUCKDB_ALLOPTIONS_FILE_CACHE;
			if ( is_bool( $value ) ) {
				return $value;
			}

			return is_scalar( $value ) && in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		$value = getenv( 'WP_DUCKDB_ALLOPTIONS_FILE_CACHE' );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Whether request-scoped DuckDB write transactions are enabled.
	 *
	 * @return bool
	 */
	private function duckdb_request_transaction_enabled(): bool {
		if ( defined( 'WP_DUCKDB_REQUEST_TRANSACTION' ) ) {
			return $this->prepare_diagnostic_truthy( WP_DUCKDB_REQUEST_TRANSACTION );
		}

		$value = getenv( 'WP_DUCKDB_REQUEST_TRANSACTION' );
		return false !== $value && $this->prepare_diagnostic_truthy( $value );
	}

	/**
	 * Get the alloptions file cache path.
	 *
	 * @return string|false Cache path, or false when no writable database dir is known.
	 */
	private function duckdb_alloptions_file_cache_path() {
		if ( defined( 'DB_DIR' ) ) {
			$dir = DB_DIR;
		} elseif ( defined( 'FQDBDIR' ) ) {
			$dir = FQDBDIR;
		} else {
			return false;
		}

		$dir = rtrim( (string) $dir, "/\\" );
		if ( '' === $dir ) {
			return false;
		}

		$key = sha1( (string) $this->dbname . '|' . strtolower( $this->duckdb_options_table_name() ) );
		return $dir . '/.ht.duckdb-alloptions-' . $key . '.json';
	}

	/**
	 * Get the configured options table name.
	 *
	 * @return string Options table name.
	 */
	private function duckdb_options_table_name(): string {
		if ( isset( $this->options ) && is_string( $this->options ) && '' !== $this->options ) {
			return $this->options;
		}

		$prefix = isset( $this->prefix ) && is_string( $this->prefix ) ? $this->prefix : 'wp_';
		return $prefix . 'options';
	}

	/**
	 * Check whether a query is the canonical WordPress alloptions SELECT.
	 *
	 * @param string $query SQL query.
	 * @return bool Whether the query can be served by the alloptions file cache.
	 */
	private function is_duckdb_alloptions_select_query( $query ): bool {
		$identifier_pattern = '`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_]*';
		$literal_pattern    = '\'(?:\\\\.|\'\'|[^\'\\\\])*\'';
		if (
			! preg_match(
				'/^\s*SELECT\s+(?:`option_name`|option_name)\s*,\s*(?:`option_value`|option_value)\s+FROM\s+(?<table>' . $identifier_pattern . ')\s+WHERE\s+(?:`autoload`|autoload)\s+IN\s*\(\s*(?<autoloads>' . $literal_pattern . '(?:\s*,\s*' . $literal_pattern . ')*)\s*\)\s*;?\s*$/i',
				(string) $query,
				$matches
			)
		) {
			return false;
		}

		if ( 0 !== strcasecmp( $this->duckdb_options_table_name(), $this->duckdb_mysql_identifier_value( $matches['table'] ) ) ) {
			return false;
		}

		preg_match_all( '/' . $literal_pattern . '/', $matches['autoloads'], $literal_matches );
		$autoloads = array_map(
			array( $this, 'duckdb_mysql_single_quoted_literal_value' ),
			$literal_matches[0]
		);
		sort( $autoloads );
		$expected = array( 'auto', 'auto-on', 'on', 'yes' );
		sort( $expected );

		return $expected === $autoloads;
	}

	/**
	 * Check whether a query is WordPress' exact single-option value lookup.
	 *
	 * @param string $query SQL query.
	 * @return bool Whether the query can be served by the options lookup cache.
	 */
	private function is_duckdb_single_option_value_select_query( $query ): bool {
		return null !== $this->duckdb_single_option_value_select_name( $query );
	}

	/**
	 * Extract the option name from WordPress' exact single-option value lookup.
	 *
	 * @param string $query SQL query.
	 * @return string|null Option name, or null when unsupported.
	 */
	private function duckdb_single_option_value_select_name( $query ): ?string {
		$identifier_pattern = '`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_]*';
		$literal_pattern    = '\'(?:\\\\.|\'\'|[^\'\\\\])*\'';
		if (
			! preg_match(
				'/^\s*SELECT\s+(?:`option_value`|option_value)\s+FROM\s+(?<table>' . $identifier_pattern . ')\s+WHERE\s+(?:`option_name`|option_name)\s*=\s*(?<option_name>' . $literal_pattern . ')\s+LIMIT\s+(?:[0-9]+|\'[0-9]+\')\s*;?\s*$/i',
				(string) $query,
				$matches
			)
		) {
			return null;
		}

		if ( 0 !== strcasecmp( $this->duckdb_options_table_name(), $this->duckdb_mysql_identifier_value( $matches['table'] ) ) ) {
			return null;
		}

		return $this->duckdb_mysql_single_quoted_literal_value( $matches['option_name'] );
	}

	/**
	 * Extract option names from WordPress' exact multi-option lookup.
	 *
	 * @param string $query SQL query.
	 * @return string[]|null Option names, or null when unsupported.
	 */
	private function duckdb_option_names_select_names( $query ): ?array {
		$identifier_pattern = '`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_]*';
		$literal_pattern    = '\'(?:\\\\.|\'\'|[^\'\\\\])*\'';
		if (
			! preg_match(
				'/^\s*SELECT\s+(?:`option_name`|option_name)\s*,\s*(?:`option_value`|option_value)\s+FROM\s+(?<table>' . $identifier_pattern . ')\s+WHERE\s+(?:`option_name`|option_name)\s+IN\s*\(\s*(?<options>' . $literal_pattern . '(?:\s*,\s*' . $literal_pattern . ')*)\s*\)\s*;?\s*$/i',
				(string) $query,
				$matches
			)
		) {
			return null;
		}

		if ( 0 !== strcasecmp( $this->duckdb_options_table_name(), $this->duckdb_mysql_identifier_value( $matches['table'] ) ) ) {
			return null;
		}

		preg_match_all( '/' . $literal_pattern . '/', $matches['options'], $literal_matches );
		return array_map(
			array( $this, 'duckdb_mysql_single_quoted_literal_value' ),
			$literal_matches[0]
		);
	}

	/**
	 * Check whether an options write cannot affect the cached alloptions result.
	 *
	 * @param string $query          SQL query.
	 * @param string $statement_type Statement keyword.
	 * @return bool Whether the alloptions cache can be preserved.
	 */
	private function duckdb_options_write_preserves_alloptions_file_cache( $query, string $statement_type ): bool {
		return null !== $this->duckdb_options_write_alloptions_preservation_metadata( $query, $statement_type );
	}

	/**
	 * Return safe metadata for an options write that cannot affect alloptions rows.
	 *
	 * @param string $query          SQL query.
	 * @param string $statement_type Statement keyword.
	 * @return array{option_name:string,autoload:string,option_value?:string}|null Literal option write metadata.
	 */
	private function duckdb_options_write_alloptions_preservation_metadata( $query, string $statement_type ): ?array {
		if ( ! in_array( $statement_type, array( 'insert', 'replace', 'update' ), true ) ) {
			return null;
		}

		$payload = $this->read_duckdb_options_file_cache_payload();
		if ( ! is_array( $payload ) || empty( $payload['alloptions_complete'] ) ) {
			return null;
		}

		$write = $this->duckdb_literal_options_write( (string) $query, $statement_type );
		if ( null === $write || ! $this->duckdb_is_non_autoload_value( $write['autoload'] ) ) {
			return null;
		}

		return $this->duckdb_alloptions_cache_contains_option( $payload, $write['option_name'] ) ? null : $write;
	}

	/**
	 * Parse a literal wp_options write.
	 *
	 * @param string $query          SQL query.
	 * @param string $statement_type Statement keyword.
	 * @return array{option_name:string,autoload:string,option_value?:string}|null Literal option write metadata.
	 */
	private function duckdb_literal_options_write( string $query, string $statement_type ): ?array {
		if ( in_array( $statement_type, array( 'insert', 'replace' ), true ) ) {
			return $this->duckdb_literal_options_insert_write( $query );
		}

		if ( 'update' === $statement_type ) {
			return $this->duckdb_literal_options_update_write( $query );
		}

		return null;
	}

	/**
	 * Parse a literal INSERT/REPLACE into wp_options.
	 *
	 * @param string $query SQL query.
	 * @return array{option_name:string,autoload:string,option_value?:string}|null Literal option write metadata.
	 */
	private function duckdb_literal_options_insert_write( string $query ): ?array {
		$identifier_pattern = '`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_]*';
		if (
			! preg_match(
				'/^\s*(?:INSERT|REPLACE)\s+INTO\s+(?<table>' . $identifier_pattern . ')\s*\((?<columns>[^)]*)\)\s+VALUES\s*\(/is',
				$query,
				$matches,
				PREG_OFFSET_CAPTURE
			)
		) {
			return null;
		}

		if ( 0 !== strcasecmp( $this->duckdb_options_table_name(), $this->duckdb_mysql_identifier_value( $matches['table'][0] ) ) ) {
			return null;
		}

		$values_open_offset = $matches[0][1] + strlen( $matches[0][0] ) - 1;
		$values_body        = $this->duckdb_parenthesized_sql_body_at( $query, $values_open_offset );
		if ( null === $values_body ) {
			return null;
		}

		$tail = trim( substr( $query, $values_body['close_offset'] + 1 ) );
		if ( '' !== $tail && ! preg_match( '/^ON\s+DUPLICATE\s+KEY\s+UPDATE\b.*;?\s*$/is', $tail ) && ! preg_match( '/^;+\s*$/', $tail ) ) {
			return null;
		}

		$columns = $this->duckdb_split_top_level_comma_list( $matches['columns'][0] );
		$values  = $this->duckdb_split_top_level_comma_list( $values_body['body'] );
		if ( count( $columns ) !== count( $values ) ) {
			return null;
		}

		$option_name = null;
		$option_value = null;
		$autoload    = null;
		foreach ( $columns as $index => $column ) {
			$column_name = $this->duckdb_mysql_identifier_value( trim( $column ) );
			$value       = trim( $values[ $index ] );
			if ( 0 === strcasecmp( 'option_name', $column_name ) ) {
				$option_name = $this->duckdb_literal_string_value_or_null( $value );
			} elseif ( 0 === strcasecmp( 'option_value', $column_name ) ) {
				$option_value = $this->duckdb_literal_string_value_or_null( $value );
			} elseif ( 0 === strcasecmp( 'autoload', $column_name ) ) {
				$autoload = $this->duckdb_literal_string_value_or_null( $value );
			}
		}

		if ( null === $option_name || null === $autoload ) {
			return null;
		}

		return array(
			'option_name'  => $option_name,
			'autoload'     => $autoload,
			'option_value' => null === $option_value ? '' : $option_value,
		);
	}

	/**
	 * Parse a literal UPDATE to wp_options.
	 *
	 * @param string $query SQL query.
	 * @return array{option_name:string,autoload:string,option_value?:string}|null Literal option write metadata.
	 */
	private function duckdb_literal_options_update_write( string $query ): ?array {
		$identifier_pattern = '`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_]*';
		$literal_pattern    = '\'(?:\\\\.|\'\'|[^\'\\\\])*\'';
		if (
			! preg_match(
				'/^\s*UPDATE\s+(?<table>' . $identifier_pattern . ')\s+SET\s+(?<assignments>.*?)\s+WHERE\s+(?:`option_name`|option_name)\s*=\s*(?<option_name>' . $literal_pattern . ')\s*;?\s*$/is',
				$query,
				$matches
			)
		) {
			return null;
		}

		if ( 0 !== strcasecmp( $this->duckdb_options_table_name(), $this->duckdb_mysql_identifier_value( $matches['table'] ) ) ) {
			return null;
		}

		$autoload = null;
		$option_value = null;
		foreach ( $this->duckdb_split_top_level_comma_list( $matches['assignments'] ) as $assignment ) {
			$parts = explode( '=', $assignment, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$column_name = $this->duckdb_mysql_identifier_value( trim( $parts[0] ) );
			if ( 0 === strcasecmp( 'autoload', $column_name ) ) {
				$autoload = $this->duckdb_literal_string_value_or_null( trim( $parts[1] ) );
			} elseif ( 0 === strcasecmp( 'option_value', $column_name ) ) {
				$option_value = $this->duckdb_literal_string_value_or_null( trim( $parts[1] ) );
			}
		}

		if ( null === $autoload ) {
			return null;
		}

		return array(
			'option_name'  => $this->duckdb_mysql_single_quoted_literal_value( $matches['option_name'] ),
			'autoload'     => $autoload,
			'option_value' => null === $option_value ? '' : $option_value,
		);
	}

	/**
	 * Extract a parenthesized SQL body at an offset, respecting string literals.
	 *
	 * @param string $sql         SQL string.
	 * @param int    $open_offset Offset of the opening parenthesis.
	 * @return array{body:string,close_offset:int}|null Extracted body, or null.
	 */
	private function duckdb_parenthesized_sql_body_at( string $sql, int $open_offset ): ?array {
		if ( ! isset( $sql[ $open_offset ] ) || '(' !== $sql[ $open_offset ] ) {
			return null;
		}

		$depth    = 0;
		$in_quote = false;
		$length   = strlen( $sql );
		for ( $i = $open_offset; $i < $length; ++$i ) {
			$char = $sql[ $i ];
			if ( $in_quote ) {
				if ( '\\' === $char ) {
					++$i;
					continue;
				}
				if ( "'" === $char ) {
					if ( isset( $sql[ $i + 1 ] ) && "'" === $sql[ $i + 1 ] ) {
						++$i;
						continue;
					}
					$in_quote = false;
				}
				continue;
			}

			if ( "'" === $char ) {
				$in_quote = true;
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
				continue;
			}
			if ( ')' !== $char ) {
				continue;
			}

			--$depth;
			if ( 0 === $depth ) {
				return array(
					'body'         => substr( $sql, $open_offset + 1, $i - $open_offset - 1 ),
					'close_offset' => $i,
				);
			}
		}

		return null;
	}

	/**
	 * Split a comma list while respecting string literals and parenthesis depth.
	 *
	 * @param string $list SQL list body.
	 * @return string[] List items.
	 */
	private function duckdb_split_top_level_comma_list( string $list ): array {
		$items    = array();
		$start    = 0;
		$depth    = 0;
		$in_quote = false;
		$length   = strlen( $list );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $list[ $i ];
			if ( $in_quote ) {
				if ( '\\' === $char ) {
					++$i;
					continue;
				}
				if ( "'" === $char ) {
					if ( isset( $list[ $i + 1 ] ) && "'" === $list[ $i + 1 ] ) {
						++$i;
						continue;
					}
					$in_quote = false;
				}
				continue;
			}

			if ( "'" === $char ) {
				$in_quote = true;
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
				continue;
			}
			if ( ')' === $char && $depth > 0 ) {
				--$depth;
				continue;
			}
			if ( ',' === $char && 0 === $depth ) {
				$items[] = trim( substr( $list, $start, $i - $start ) );
				$start   = $i + 1;
			}
		}

		$items[] = trim( substr( $list, $start ) );
		return $items;
	}

	/**
	 * Parse a literal SQL string value.
	 *
	 * @param string $sql SQL expression.
	 * @return string|null Literal value, or null.
	 */
	private function duckdb_literal_string_value_or_null( string $sql ): ?string {
		$sql = trim( $sql );
		if ( ! preg_match( '/^\'(?:\\\\.|\'\'|[^\'\\\\])*\'$/s', $sql ) ) {
			return null;
		}

		return $this->duckdb_mysql_single_quoted_literal_value( $sql );
	}

	/**
	 * Check whether an autoload value is outside the alloptions result.
	 *
	 * @param string $value Autoload value.
	 * @return bool Whether this value is non-autoloaded.
	 */
	private function duckdb_is_non_autoload_value( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'no', 'off', 'false', '0', 'auto-off' ), true );
	}

	/**
	 * Check whether the alloptions file cache contains an option name.
	 *
	 * @param array<string,mixed> $payload     Options cache payload.
	 * @param string              $option_name Option name.
	 * @return bool Whether the option is cached as autoloaded.
	 */
	private function duckdb_alloptions_cache_contains_option( array $payload, string $option_name ): bool {
		if ( ! isset( $payload['rows'] ) || ! is_array( $payload['rows'] ) ) {
			return true;
		}

		foreach ( $payload['rows'] as $row ) {
			if ( is_array( $row ) && isset( $row['option_name'] ) && 0 === strcasecmp( (string) $row['option_name'], $option_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a query may mutate the options table.
	 *
	 * @param string $query          SQL query.
	 * @param string $statement_type Statement keyword.
	 * @return bool Whether the options cache should be invalidated.
	 */
	private function duckdb_query_may_mutate_options_table( $query, string $statement_type ): bool {
		if ( ! in_array( $statement_type, array( 'alter', 'create', 'delete', 'drop', 'insert', 'replace', 'truncate', 'update' ), true ) ) {
			return false;
		}

		return $this->duckdb_query_mentions_options_table( (string) $query );
	}

	/**
	 * Check whether a query creates a temporary options table shadow.
	 *
	 * @param string $query          SQL query.
	 * @param string $statement_type Statement keyword.
	 * @return bool Whether alloptions cache reads should be suspended.
	 */
	private function duckdb_query_creates_temporary_options_shadow_table( $query, string $statement_type ): bool {
		if ( 'create' !== $statement_type ) {
			return false;
		}

		$identifier_pattern = '`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_]*';
		if (
			! preg_match(
				'/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?TEMP(?:ORARY)?\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?<table>' . $identifier_pattern . ')\b/i',
				(string) $query,
				$matches
			)
		) {
			return false;
		}

		return 0 === strcasecmp( $this->duckdb_options_table_name(), $this->duckdb_mysql_identifier_value( $matches['table'] ) );
	}

	/**
	 * Check whether a SQL string mentions the options table identifier.
	 *
	 * @param string $query SQL query.
	 * @return bool Whether the configured options table appears in the query.
	 */
	private function duckdb_query_mentions_options_table( string $query ): bool {
		$table = preg_quote( $this->duckdb_options_table_name(), '/' );
		return (bool) preg_match( '/(?:`' . $table . '`|\b' . $table . '\b)/i', $query );
	}

	/**
	 * Unquote a MySQL identifier.
	 *
	 * @param string $identifier Identifier.
	 * @return string Identifier value.
	 */
	private function duckdb_mysql_identifier_value( string $identifier ): string {
		$identifier = trim( $identifier );
		if ( strlen( $identifier ) >= 2 && '`' === $identifier[0] && '`' === substr( $identifier, -1 ) ) {
			return str_replace( '``', '`', substr( $identifier, 1, -1 ) );
		}

		return $identifier;
	}

	/**
	 * Unquote a MySQL single-quoted string literal.
	 *
	 * @param string $literal Literal.
	 * @return string Literal value.
	 */
	private function duckdb_mysql_single_quoted_literal_value( string $literal ): string {
		$value = substr( trim( $literal ), 1, -1 );
		$value = str_replace( "''", "'", $value );
		return stripcslashes( $value );
	}

	/**
	 * Build column metadata for the cached alloptions result.
	 *
	 * @return array<int,array<string,mixed>> Column metadata.
	 */
	private function duckdb_alloptions_result_column_metadata(): array {
		$table = $this->duckdb_options_table_name();

		return array(
			array(
				'table'           => $table,
				'name'            => 'option_name',
				'mysqli:orgname'  => 'option_name',
				'mysqli:orgtable' => $table,
				'mysqli:db'       => $this->dbname,
			),
			array(
				'table'           => $table,
				'name'            => 'option_value',
				'mysqli:orgname'  => 'option_value',
				'mysqli:orgtable' => $table,
				'mysqli:db'       => $this->dbname,
			),
		);
	}

	/**
	 * Build column metadata for the cached single-option result.
	 *
	 * @return array<int,array<string,mixed>> Column metadata.
	 */
	private function duckdb_single_option_result_column_metadata(): array {
		$table = $this->duckdb_options_table_name();

		return array(
			array(
				'table'           => $table,
				'name'            => 'option_value',
				'mysqli:orgname'  => 'option_value',
				'mysqli:orgtable' => $table,
				'mysqli:db'       => $this->dbname,
			),
		);
	}

	/**
	 * Check whether a query inserts the persisted preferences user meta row.
	 *
	 * @param string $query Query to inspect.
	 * @return bool
	 */
	private function is_persisted_preferences_usermeta_insert( $query ) {
		return false !== $this->persisted_preferences_usermeta_insert_table( $query )
			&& false !== strpos( $query, 'persisted_preferences' );
	}

	/**
	 * Extract the usermeta table name from a persisted preferences insert.
	 *
	 * @param string $query Query to inspect.
	 * @return string|false Usermeta table name, or false when the query does not match.
	 */
	private function persisted_preferences_usermeta_insert_table( $query ) {
		if ( ! preg_match( '/^\s*insert\s+into\s+`?([^`\s(]+)`?\s/i', $query, $matches ) ) {
			return false;
		}

		$table = $matches[1];
		if ( isset( $this->usermeta ) && 0 === strcasecmp( $table, $this->usermeta ) ) {
			return $table;
		}

		if ( 'usermeta' === substr( strtolower( $table ), -8 ) ) {
			return $table;
		}

		return false;
	}

	/**
	 * Log immediate DuckDB state after the persisted preferences insert.
	 *
	 * @param string       $query      Insert query.
	 * @param string|false $table_name Usermeta table name.
	 */
	private function log_persisted_preferences_insert_diagnostic( $query, $table_name ) {
		$diagnostics = array(
			'query'                  => $query,
			'usermeta_table'         => $table_name ? (string) $table_name : null,
			'last_error'             => (string) $this->last_error,
			'statement_row_count'    => $this->last_statement ? (int) $this->last_statement->rowCount() : null,
			'rows_affected'          => (int) $this->rows_affected,
			'insert_id'              => (int) $this->insert_id,
			'driver_class'           => is_object( $this->dbh ) ? get_class( $this->dbh ) : gettype( $this->dbh ),
			'driver_insert_id'       => null,
			'duckdb_queries_tail'    => array(),
			'wp_usermeta_columns'    => array(),
			'wp_usermeta_row_counts' => array(),
			'wp_usermeta_latest'     => array(),
			'duckdb_column_metadata' => array(),
			'duckdb_metadata_error'  => null,
		);

		try {
			if ( is_object( $this->dbh ) && method_exists( $this->dbh, 'get_insert_id' ) ) {
				$diagnostics['driver_insert_id'] = (int) $this->dbh->get_insert_id();
			}
			if ( is_object( $this->dbh ) && method_exists( $this->dbh, 'get_last_duckdb_queries' ) ) {
				$diagnostics['duckdb_queries_tail'] = array_slice( $this->dbh->get_last_duckdb_queries(), -5 );
			}
			if ( is_object( $this->dbh ) && method_exists( $this->dbh, 'get_connection' ) ) {
				$connection = $this->dbh->get_connection();
				$table_name = $table_name ? (string) $table_name : 'wp_usermeta';
				$table      = $connection->quote_identifier( $table_name );

				$diagnostics['wp_usermeta_columns'] = $connection
					->query( 'SELECT cid, name, type, "notnull", dflt_value, pk FROM pragma_table_info(' . $connection->quote( $table_name ) . ') ORDER BY cid' )
					->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

				try {
					$diagnostics['duckdb_column_metadata'] = $connection
						->query(
							'SELECT column_name, ordinal_position, column_type, is_nullable, column_default, column_key, extra FROM '
							. $connection->quote_identifier( WP_DuckDB_Driver::COLUMN_METADATA_TABLE )
							. ' WHERE table_name = '
							. $connection->quote( $table_name )
							. ' ORDER BY ordinal_position'
						)
						->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
				} catch ( Throwable $e ) {
					$diagnostics['duckdb_metadata_error'] = $e->getMessage();
				}

				$diagnostics['wp_usermeta_row_counts'] = $connection
					->query( 'SELECT COUNT(*) AS total_rows, MAX(umeta_id) AS max_umeta_id FROM ' . $table )
					->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO

				$diagnostics['wp_usermeta_latest'] = $connection
					->query( 'SELECT umeta_id, user_id, meta_key, LENGTH(meta_value) AS meta_value_length FROM ' . $table . " WHERE meta_key LIKE '%persisted_preferences' ORDER BY umeta_id DESC LIMIT 3" )
					->fetchAll( PDO::FETCH_ASSOC ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			}
		} catch ( Throwable $e ) {
			$diagnostics['probe_error'] = $e->getMessage();
		}

		$encoded = function_exists( 'wp_json_encode' )
			? wp_json_encode( $diagnostics, JSON_UNESCAPED_SLASHES )
			: json_encode( $diagnostics );

		error_log( '[duckdb-persisted-preferences-insert] ' . $encoded );
	}

	/**
	 * Internal query runner.
	 *
	 * @param string $query Query to run.
	 */
	private function _do_query( $query ) {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->timer_start();
		}

		try {
			$this->last_statement = $this->get_connection_collation_statement( $query );
			if ( ! $this->last_statement ) {
				$this->last_statement = $this->dbh->query( $query );
			}

			if ( $this->last_statement->columnCount() > 0 ) {
				$this->result = $this->normalize_result_rows( $this->last_statement->fetchAll( PDO::FETCH_OBJ ) ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO
			} else {
				$this->result = null;
			}
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();
			$this->rollback_failed_active_duckdb_transaction( $e );
		}

		++$this->num_queries;

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->log_query(
				$query,
				$this->timer_stop(),
				$this->get_caller(),
				$this->time_start,
				array()
			);
		}
	}

	/**
	 * Roll back active DuckDB transactions after native engine failures.
	 *
	 * Unsupported or preflight driver errors should leave caller-managed
	 * transactions open. Native DuckDB query/prepare errors can leave the
	 * transaction aborted, so clean up only that narrow failure shape.
	 *
	 * @param Throwable $error Query failure.
	 */
	private function rollback_failed_active_duckdb_transaction( Throwable $error ) {
		if ( ! $this->should_rollback_active_duckdb_transaction_on_error( $error ) ) {
			return;
		}

		$connection = $this->get_duckdb_connection();
		if ( ! $connection ) {
			return;
		}

		try {
			if ( $connection->inTransaction() ) {
				$connection->rollback();
			} elseif ( $this->is_current_duckdb_transaction_aborted_error( $error ) ) {
				$connection->rollbackNativeTransaction();
			}
		} catch ( Throwable $rollback_error ) {
			if ( '' === $this->last_error ) {
				$this->last_error = $rollback_error->getMessage();
			}
		}
	}

	/**
	 * Get the underlying DuckDB connection, when one is still available.
	 *
	 * @return WP_DuckDB_Connection|null Connection, or null when unavailable.
	 */
	private function get_duckdb_connection() {
		if ( $this->dbh instanceof WP_DuckDB_Connection ) {
			return $this->dbh;
		}

		if ( ! $this->dbh instanceof WP_DuckDB_Driver || ! method_exists( $this->dbh, 'get_connection' ) ) {
			return null;
		}

		try {
			$connection = $this->dbh->get_connection();
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! $connection instanceof WP_DuckDB_Connection ) {
			return null;
		}

		return $connection;
	}

	/**
	 * Check whether a query error means the active DuckDB transaction is unsafe.
	 *
	 * @param Throwable $error Query failure.
	 * @return bool Whether to roll back the active transaction.
	 */
	private function should_rollback_active_duckdb_transaction_on_error( Throwable $error ) {
		if ( $this->is_current_duckdb_transaction_aborted_error( $error ) ) {
			return true;
		}

		for ( $current = $error; null !== $current; $current = $current->getPrevious() ) {
			$message = $current->getMessage();
			if (
				$current instanceof WP_DuckDB_Driver_Exception
				&& (
					0 === strpos( $message, 'DuckDB query failed:' )
					|| 0 === strpos( $message, 'Failed to prepare DuckDB query:' )
					|| false !== strpos( $message, ': DuckDB query failed:' )
					|| false !== strpos( $message, ': Failed to prepare DuckDB query:' )
				)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an error reports a DuckDB-aborted transaction.
	 *
	 * @param Throwable $error Query failure.
	 * @return bool Whether the native transaction is already aborted.
	 */
	private function is_current_duckdb_transaction_aborted_error( Throwable $error ) {
		for ( $current = $error; null !== $current; $current = $current->getPrevious() ) {
			if ( false !== strpos( $current->getMessage(), 'Current transaction is aborted' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize fetched DuckDB rows to MySQL/PDO-style scalar values.
	 *
	 * @param array<int,object> $rows Result rows.
	 * @return array<int,object> Normalized result rows.
	 */
	private function normalize_result_rows( array $rows ) {
		foreach ( $rows as $row ) {
			foreach ( get_object_vars( $row ) as $name => $value ) {
				if ( null === $value ) {
					continue;
				}
				if ( is_bool( $value ) ) {
					$row->$name = $value ? '1' : '0';
				} elseif ( is_scalar( $value ) ) {
					$row->$name = (string) $value;
				}
			}
		}

		return $rows;
	}

	/**
	 * Load column metadata.
	 */
	protected function load_col_info() {
		if ( $this->col_info ) {
			return;
		}

		$this->col_info = array();
		if ( ! $this->last_statement ) {
			return;
		}

		for ( $i = 0; $i < $this->last_statement->columnCount(); ++$i ) {
			$meta = $this->last_statement->getColumnMeta( $i );
			if ( ! is_array( $meta ) ) {
				continue;
			}

			$name             = isset( $meta['name'] ) ? $meta['name'] : '';
			$this->col_info[] = (object) array(
				'name'       => $name,
				'orgname'    => isset( $meta['mysqli:orgname'] ) ? $meta['mysqli:orgname'] : $name,
				'table'      => isset( $meta['table'] ) ? $meta['table'] : '',
				'orgtable'   => isset( $meta['mysqli:orgtable'] ) ? $meta['mysqli:orgtable'] : ( isset( $meta['table'] ) ? $meta['table'] : '' ),
				'def'        => '',
				'db'         => isset( $meta['mysqli:db'] ) ? $meta['mysqli:db'] : $this->dbname,
				'catalog'    => 'def',
				'max_length' => 0,
				'length'     => isset( $meta['len'] ) ? $meta['len'] : 0,
				'charsetnr'  => isset( $meta['mysqli:charsetnr'] ) ? $meta['mysqli:charsetnr'] : 224,
				'flags'      => isset( $meta['mysqli:flags'] ) ? $meta['mysqli:flags'] : 0,
				'type'       => isset( $meta['mysqli:type'] ) ? $meta['mysqli:type'] : 253,
				'decimals'   => isset( $meta['precision'] ) ? $meta['precision'] : 0,
			);
		}
	}

	/**
	 * Report supported database capabilities.
	 *
	 * @param string $db_cap Capability name.
	 * @return bool
	 */
	public function has_cap( $db_cap ) {
		switch ( strtolower( $db_cap ) ) {
			case 'collation':
			case 'group_concat':
			case 'identifier_placeholders':
			case 'set_charset':
			case 'subqueries':
			case 'utf8mb4':
			case 'utf8mb4_520':
				return true;
		}

		return false;
	}

	/**
	 * Return a MySQL-compatible version string.
	 *
	 * @return string
	 */
	public function db_version() {
		return '8.0.11';
	}

	/**
	 * Return DuckDB server information.
	 *
	 * @return string
	 */
	public function db_server_info() {
		try {
			$stmt = $this->dbh->get_connection()->query( 'SELECT version() AS version' );
			return 'DuckDB ' . $stmt->fetchColumn();
		} catch ( Throwable $e ) {
			return 'DuckDB';
		}
	}

	/**
	 * Strip invalid text without falling back to mysqli for the connection charset.
	 *
	 * @param array $data Values to strip.
	 * @return array|WP_Error Stripped values, or error.
	 */
	protected function strip_invalid_text( $data ) {
		if ( '' !== $this->charset ) {
			return parent::strip_invalid_text( $data );
		}

		$this->charset = 'utf8mb4';

		try {
			return parent::strip_invalid_text( $data );
		} finally {
			$this->charset = '';
		}
	}

	/**
	 * Get the WordPress-incompatible SQL modes for filtering.
	 *
	 * @return array
	 */
	private function get_incompatible_sql_modes() {
		$incompatible_modes = property_exists( $this, 'incompatible_modes' )
			? $this->incompatible_modes
			: $this->get_default_incompatible_sql_modes();

		return (array) apply_filters( 'incompatible_sql_modes', $incompatible_modes );
	}

	/**
	 * Get the WordPress wpdb default incompatible SQL modes.
	 *
	 * @return array
	 */
	private function get_default_incompatible_sql_modes() {
		return array(
			'NO_ZERO_DATE',
			'ONLY_FULL_GROUP_BY',
			'STRICT_TRANS_TABLES',
			'STRICT_ALL_TABLES',
			'TRADITIONAL',
			'ANSI',
		);
	}

	/**
	 * Return a wpdb-shaped result for the connection collation variable.
	 *
	 * The DuckDB driver currently accepts SET NAMES as a bootstrap no-op and
	 * returns an empty SHOW VARIABLES result. WordPress core asks for this
	 * variable directly after set_charset().
	 *
	 * @param string $query SQL query.
	 * @return WP_DuckDB_Result_Statement|null Result statement if handled.
	 */
	private function get_connection_collation_statement( $query ) {
		if (
			! preg_match(
				"/^\\s*SHOW\\s+(?:GLOBAL\\s+|LOCAL\\s+|SESSION\\s+)?VARIABLES\\s+WHERE\\s+Variable_name\\s*=\\s*(['\"])collation_connection\\1\\s*$/i",
				$query
			)
		) {
			return null;
		}

		return new WP_DuckDB_Result_Statement(
			array( 'Variable_name', 'Value' ),
			array(
				array(
					'collation_connection',
					$this->collate ? $this->collate : $this->get_default_collation_for_charset( $this->charset ),
				),
			)
		);
	}

	/**
	 * Get the default collation name for a charset.
	 *
	 * @param string $charset Character set.
	 * @return string Collation name.
	 */
	private function get_default_collation_for_charset( $charset ) {
		switch ( $charset ) {
			case 'utf8':
				return 'utf8_general_ci';
			case 'utf8mb3':
				return 'utf8mb3_general_ci';
			case 'utf8mb4':
				return 'utf8mb4_unicode_ci';
		}

		return $charset . '_general_ci';
	}

	/**
	 * Ensure database directory exists and is protected.
	 *
	 * @param string $database_path Database file path.
	 */
	private function ensure_database_directory( $database_path ) {
		$dir   = dirname( $database_path );
		$umask = umask( 0 );

		if ( ! is_dir( $dir ) ) {
			if ( ! @mkdir( $dir, 0700, true ) ) {
				wp_die( sprintf( 'Failed to create database directory: %s', $dir ), 'Error!' );
			}
		}
		if ( ! is_writable( $dir ) ) {
			wp_die( sprintf( 'Database directory is not writable: %s', $dir ), 'Error!' );
		}

		$path = $dir . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! is_file( $path ) ) {
			$result = file_put_contents( $path, 'DENY FROM ALL', LOCK_EX );
			if ( false === $result ) {
				wp_die( sprintf( 'Failed to create file: %s', $path ), 'Error!' );
			}
			chmod( $path, 0600 );
		}

		$path = $dir . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! is_file( $path ) ) {
			$result = file_put_contents( $path, '<?php // Silence is gold. ?>', LOCK_EX );
			if ( false === $result ) {
				wp_die( sprintf( 'Failed to create file: %s', $path ), 'Error!' );
			}
			chmod( $path, 0600 );
		}

		umask( $umask );
	}
}
