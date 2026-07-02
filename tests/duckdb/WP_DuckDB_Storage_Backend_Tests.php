<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

/**
 * @group duckdb
 * @group duckdb-storage-backend
 */
class WP_DuckDB_Storage_Backend_Tests extends WP_DuckDB_TestCase {
	/**
	 * Temporary directories created by the current test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	protected function tearDown(): void {
		foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
			$this->remove_temp_dir( $temp_dir );
		}
		$this->temp_dirs = array();

		parent::tearDown();
	}

	public static function backend_provider(): array {
		return array(
			'parquet' => array( 'parquet' ),
			'csv'     => array( 'csv' ),
			'json'    => array( 'json' ),
		);
	}

	/**
	 * @dataProvider backend_provider
	 *
	 * @param string $backend DuckDB external backend.
	 */
	public function test_configured_external_backend_round_trips_wordpress_mutations( string $backend ): void {
		$this->requireDuckDBRuntime();

		$temp_dir     = $this->create_temp_dir();
		$external_dir = $temp_dir . '/external';
		$this->create_external_wordpress_storage_files( $backend, $external_dir );

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => $backend,
				'database_path'        => $temp_dir . '/working.duckdb',
				'external_storage_dir' => $external_dir,
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'Configured External Site',
				),
			),
			$driver->query(
				"SELECT option_name, option_value
				FROM wptests_options
				WHERE option_name = 'blogname'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			1,
			$driver->query(
				"UPDATE wptests_options
				SET option_value = 'Changed Through WordPress'
				WHERE option_name = 'blogname'"
			)->rowCount()
		);
		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO wptests_posts (ID, post_title, post_status)
				VALUES (3, 'Created through configured backend', 'publish')"
			)->rowCount()
		);
		$driver->query(
			'CREATE TABLE wptests_plugin_log (
				id bigint(20),
				message varchar(255)
			)'
		);
		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO wptests_plugin_log (id, message)
				VALUES (1, 'custom table flushed')"
			)->rowCount()
		);

		$storage->flush();
		unset( $driver, $storage );

		$fresh_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => $backend,
				'database_path'        => $temp_dir . '/fresh.duckdb',
				'external_storage_dir' => $external_dir,
			)
		);
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'Changed Through WordPress',
				),
			),
			$fresh_driver->query(
				"SELECT option_name, option_value
				FROM wptests_options
				WHERE option_name = 'blogname'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'ID'          => 1,
					'post_title'  => 'First post',
					'post_status' => 'publish',
				),
				array(
					'ID'          => 2,
					'post_title'  => 'Draft post',
					'post_status' => 'draft',
				),
				array(
					'ID'          => 3,
					'post_title'  => 'Created through configured backend',
					'post_status' => 'publish',
				),
			),
			$fresh_driver->query(
				'SELECT ID, post_title, post_status
				FROM wptests_posts
				ORDER BY ID'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'message' => 'custom table flushed',
				),
			),
			$fresh_driver->query(
				'SELECT id, message
				FROM wptests_plugin_log
				ORDER BY id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_custom_backend_templates_round_trip_wordpress_mutations(): void {
		$this->requireDuckDBRuntime();

		$temp_dir     = $this->create_temp_dir();
		$external_dir = $temp_dir . '/custom-external';
		$this->create_external_wordpress_storage_files( 'pipe', $external_dir, 'psv' );

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'pipe_text',
				'database_path'        => $temp_dir . '/custom-working.duckdb',
				'external_storage_dir' => $external_dir,
				'file_extension'       => 'psv',
				'read_sql_template'    => "SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')",
				'write_sql_template'   => "COPY {table} TO {path} (HEADER, DELIMITER '|')",
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame(
			1,
			$driver->query(
				"UPDATE wptests_options
				SET option_value = 'Changed Through Custom Backend'
				WHERE option_name = 'blogname'"
			)->rowCount()
		);
		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO wptests_posts (ID, post_title, post_status)
				VALUES (3, 'Custom backend post', 'publish')"
			)->rowCount()
		);

		$storage->flush();
		unset( $driver, $storage );

		$fresh_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'pipe_text',
				'database_path'        => $temp_dir . '/custom-fresh.duckdb',
				'external_storage_dir' => $external_dir,
				'file_extension'       => 'psv',
				'read_sql_template'    => "SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')",
				'write_sql_template'   => "COPY {table} TO {path} (HEADER, DELIMITER '|')",
			)
		);
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'Changed Through Custom Backend',
				),
			),
			$fresh_driver->query(
				"SELECT option_name, option_value
				FROM wptests_options
				WHERE option_name = 'blogname'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'ID'         => 3,
					'post_title' => 'Custom backend post',
				),
			),
			$fresh_driver->query(
				"SELECT ID, post_title
				FROM wptests_posts
				WHERE ID = 3"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_non_scannable_backend_uses_working_database_tables_as_source_registry(): void {
		$this->requireDuckDBRuntime();

		$temp_dir     = $this->create_temp_dir();
		$database     = $temp_dir . '/working.duckdb';
		$store        = $temp_dir . '/attached-store.duckdb';
		$attach_store = "ATTACH '" . str_replace( "'", "''", $store ) . "' AS wp_store";

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'            => 'attached_duckdb',
				'database_path'      => $database,
				'setup_sql'          => array( $attach_store ),
				'read_sql_template'  => 'SELECT * FROM wp_store.{table}',
				'write_sql_template' => 'CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}',
			)
		);
		$driver  = $storage->create_driver( 'wp' );
		$driver->query(
			'CREATE TABLE wptests_plugin_log (
				id bigint(20),
				message varchar(255)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_plugin_log (id, message)
			VALUES (1, 'flushed through attached backend')"
		);
		$storage->flush();
		unset( $driver, $storage );

		$fresh_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'            => 'attached_duckdb',
				'database_path'      => $database,
				'setup_sql'          => array( $attach_store ),
				'read_sql_template'  => 'SELECT * FROM wp_store.{table}',
				'write_sql_template' => 'CREATE OR REPLACE TABLE wp_store.{table} AS SELECT * FROM {table}',
			)
		);
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$this->assertSame(
			array(
				array(
					'id'      => 1,
					'message' => 'flushed through attached backend',
				),
			),
			$fresh_driver->query(
				'SELECT id, message
				FROM wptests_plugin_log'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_json_backend_hydrates_empty_metadata_backed_tables(): void {
		$this->requireDuckDBRuntime();

		$temp_dir     = $this->create_temp_dir();
		$external_dir = $temp_dir . '/json-external';
		$database     = $temp_dir . '/working.duckdb';

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
		$driver  = $storage->create_driver( 'wp' );
		$driver->query(
			'CREATE TABLE wptests_commentmeta (
				meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id)
			)'
		);
		$storage->flush();
		unset( $driver, $storage );

		$this->assertFileExists( $external_dir . '/wptests_commentmeta.json' );
		$this->assertSame( 0, filesize( $external_dir . '/wptests_commentmeta.json' ) );

		$fresh_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$this->assertSame(
			array( array( 'total' => 0 ) ),
			$fresh_driver->query( 'SELECT COUNT(*) AS total FROM wptests_commentmeta' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			1,
			$fresh_driver->query(
				"INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value)
				VALUES (1, 'rating', '5')"
			)->rowCount()
		);
		$this->assertSame( 1, $fresh_driver->get_insert_id() );
	}

	public function test_json_backend_hydrates_zero_row_metadata_backed_tables_with_synthetic_json_column(): void {
		$this->requireDuckDBRuntime();

		$temp_dir     = $this->create_temp_dir();
		$external_dir = $temp_dir . '/json-external';
		$database     = $temp_dir . '/working.duckdb';

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
		$driver  = $storage->create_driver( 'wp' );
		$driver->query(
			'CREATE TABLE wptests_commentmeta (
				meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id)
			)'
		);
		$storage->flush();
		unset( $driver, $storage );

		file_put_contents( $external_dir . '/wptests_commentmeta.json', '[]' );

		$fresh_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$this->assertSame(
			array( array( 'total' => 0 ) ),
			$fresh_driver->query( 'SELECT COUNT(*) AS total FROM wptests_commentmeta' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			1,
			$fresh_driver->query(
				"INSERT INTO wptests_commentmeta (comment_id, meta_key, meta_value)
				VALUES (1, 'rating', '5')"
			)->rowCount()
		);
		$this->assertSame( 1, $fresh_driver->get_insert_id() );
	}

	public function test_json_backend_restores_secondary_unique_indexes_after_hydration(): void {
		$this->requireDuckDBRuntime();

		$temp_dir     = $this->create_temp_dir();
		$external_dir = $temp_dir . '/json-external';
		$database     = $temp_dir . '/working.duckdb';

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
		$driver  = $storage->create_driver( 'wp' );
		$driver->query(
			"CREATE TABLE wptests_options (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL DEFAULT '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			)"
		);
		$driver->query(
			"INSERT INTO wptests_options (option_name, option_value, autoload)
			VALUES ('blogname', 'DuckDB JSON Site', 'yes')"
		);
		$storage->flush();
		unset( $driver, $storage );

		$fresh_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$this->assertSame(
			1,
			$fresh_driver->query(
				"INSERT INTO wptests_options (option_name, option_value, autoload)
				VALUES ('blogname', 'Updated Site', 'no')
				ON DUPLICATE KEY UPDATE
					option_name = VALUES(option_name),
					option_value = VALUES(option_value),
					autoload = VALUES(autoload)"
			)->rowCount()
		);
		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'Updated Site',
					'autoload'     => 'no',
				),
			),
			$fresh_driver->query(
				"SELECT option_name, option_value, autoload
				FROM wptests_options
				WHERE option_name = 'blogname'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_native_backend_aliases_use_duckdb_file_backend(): void {
		foreach ( array( 'duckdb', 'duck', 'native', 'file' ) as $alias ) {
			$backend = new WP_DuckDB_Storage_Backend(
				array(
					'backend'       => $alias,
					'database_path' => ':memory:',
				)
			);

			$this->assertSame( 'duckdb', $backend->get_backend() );
			$this->assertFalse( $backend->is_external() );
		}
	}

	public function test_database_lock_times_out_instead_of_blocking_forever(): void {
		$temp_dir  = $this->create_temp_dir();
		$database  = $temp_dir . '/locked.duckdb';
		$lock_path = $database . '.lock';
		$handle    = fopen( $lock_path, 'c' );

		$this->assertNotFalse( $handle );
		$this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'duckdb',
				'database_path'        => $database,
				'lock_timeout_seconds' => 0.05,
			)
		);

		$started_at = microtime( true );
		try {
			$storage->create_driver( 'wp' );
			$this->fail( 'Expected a DuckDB lock timeout.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Timed out after', $e->getMessage() );
			$this->assertStringContainsString( $lock_path, $e->getMessage() );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}

		$this->assertLessThan( 1.0, microtime( true ) - $started_at );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_backend_can_be_configured_from_constants(): void {
		define( 'DUCKDB_BACKEND', 'pipe_text' );
		define( 'DUCKDB_WORKING_DATABASE_FILE', ':memory:' );
		define( 'DUCKDB_EXTERNAL_STORAGE_DIR', sys_get_temp_dir() . '/wp-duckdb-constant-backend' );
		define( 'DUCKDB_BACKEND_FILE_EXTENSION', 'psv' );
		define( 'DUCKDB_BACKEND_READ_SQL', "SELECT * FROM read_csv_auto({path}, HEADER = true, DELIM = '|')" );
		define( 'DUCKDB_BACKEND_WRITE_SQL', "COPY {table} TO {path} (HEADER, DELIMITER '|')" );

		$backend = WP_DuckDB_Storage_Backend::from_constants();

		$this->assertSame( 'pipe_text', $backend->get_backend() );
		$this->assertTrue( $backend->is_external() );
		$this->assertSame( DUCKDB_EXTERNAL_STORAGE_DIR, $backend->get_external_storage_dir() );
	}

	public function test_external_backends_require_storage_directory(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'external_storage_dir' );

		new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'parquet',
				'database_path' => ':memory:',
			)
		);
	}

	public function test_custom_backends_require_read_and_write_templates(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'DUCKDB_BACKEND_READ_SQL' );

		new WP_DuckDB_Storage_Backend(
			array(
				'backend' => 'arbitrary_backend',
			)
		);
	}

	private function create_external_wordpress_storage_files( string $backend, string $external_dir, ?string $extension = null ): void {
		if ( ! mkdir( $external_dir, 0777, true ) && ! is_dir( $external_dir ) ) {
			throw new RuntimeException( 'Failed to create temporary external storage directory: ' . $external_dir );
		}

		$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
		$duckdb       = $client_class::create();

		$duckdb->query(
			'CREATE TABLE wptests_options (
				option_id BIGINT,
				option_name VARCHAR,
				option_value VARCHAR,
				autoload VARCHAR
			)'
		);
		$duckdb->query(
			"INSERT INTO wptests_options VALUES
				(1, 'siteurl', 'https://example.test', 'yes'),
				(2, 'blogname', 'Configured External Site', 'yes')"
		);
		$duckdb->query(
			'CREATE TABLE wptests_posts (
				ID BIGINT,
				post_title VARCHAR,
				post_status VARCHAR
			)'
		);
		$duckdb->query(
			"INSERT INTO wptests_posts VALUES
				(1, 'First post', 'publish'),
				(2, 'Draft post', 'draft')"
		);

		$extension = null === $extension ? $backend : $extension;
		$this->copy_table_to_external_storage( $duckdb, 'wptests_options', $backend, $external_dir . '/wptests_options.' . $extension );
		$this->copy_table_to_external_storage( $duckdb, 'wptests_posts', $backend, $external_dir . '/wptests_posts.' . $extension );
	}

	private function copy_table_to_external_storage( $duckdb, string $table, string $backend, string $path ): void {
		$duckdb->query(
			'COPY "' . str_replace( '"', '""', $table ) . '" TO ' . $this->duckdb_string_literal( $path ) .
			' (' . $this->copy_options_for_backend( $backend ) . ')'
		);
	}

	private function copy_options_for_backend( string $backend ): string {
		switch ( $backend ) {
			case 'parquet':
				return 'FORMAT PARQUET';
			case 'csv':
				return "HEADER, DELIMITER ','";
			case 'json':
				return 'FORMAT JSON';
			case 'pipe':
				return "HEADER, DELIMITER '|'";
		}

		throw new InvalidArgumentException( 'Unsupported DuckDB backend: ' . $backend );
	}

	private function create_temp_dir(): string {
		$temp_dir = sys_get_temp_dir() . '/wp-duckdb-storage-backend-' . getmypid() . '-' . uniqid( '', true );
		if ( ! mkdir( $temp_dir, 0777, true ) && ! is_dir( $temp_dir ) ) {
			throw new RuntimeException( 'Failed to create temporary directory: ' . $temp_dir );
		}

		$this->temp_dirs[] = $temp_dir;
		return $temp_dir;
	}

	private function remove_temp_dir( string $temp_dir ): void {
		if ( ! is_dir( $temp_dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $temp_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $path ) {
			if ( $path->isDir() ) {
				rmdir( $path->getPathname() );
			} else {
				unlink( $path->getPathname() );
			}
		}

		rmdir( $temp_dir );
	}

	private function duckdb_string_literal( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}
}
