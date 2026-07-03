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

	private function create_in_memory_duckdb_driver(): WP_DuckDB_Driver {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);

		return $storage->create_driver( 'wp' );
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

	public function test_native_backend_exposes_mysql_metadata_shapes_for_wordpress_tables(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
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
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			)"
		);

		$this->assertSame(
			array(
				array(
					'Field'   => 'option_id',
					'Type'    => 'bigint(20) unsigned',
					'Null'    => 'NO',
					'Key'     => 'PRI',
					'Default' => null,
					'Extra'   => 'auto_increment',
				),
				array(
					'Field'   => 'option_name',
					'Type'    => 'varchar(191)',
					'Null'    => 'NO',
					'Key'     => 'UNI',
					'Default' => '',
					'Extra'   => '',
				),
				array(
					'Field'   => 'option_value',
					'Type'    => 'longtext',
					'Null'    => 'NO',
					'Key'     => '',
					'Default' => null,
					'Extra'   => '',
				),
				array(
					'Field'   => 'autoload',
					'Type'    => 'varchar(20)',
					'Null'    => 'NO',
					'Key'     => 'MUL',
					'Default' => 'yes',
					'Extra'   => '',
				),
			),
			$driver->query( 'SHOW COLUMNS FROM `wptests_options`' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$create_table = $driver->query( 'SHOW CREATE TABLE `wptests_options`' )->fetch( PDO::FETCH_ASSOC );
		$this->assertSame( 'wptests_options', $create_table['Table'] );
		$this->assertStringContainsString( 'CREATE TABLE `wptests_options`', $create_table['Create Table'] );
		$this->assertStringContainsString( '`option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT', $create_table['Create Table'] );
		$this->assertStringContainsString( 'PRIMARY KEY (`option_id`)', $create_table['Create Table'] );
		$this->assertStringContainsString( 'UNIQUE KEY `option_name` (`option_name`)', $create_table['Create Table'] );
		$this->assertStringContainsString( 'KEY `autoload` (`autoload`)', $create_table['Create Table'] );

		$this->assertSame(
			array(
				array(
					'COLUMN_NAME' => 'option_id',
					'COLUMN_TYPE' => 'bigint(20) unsigned',
					'COLUMN_KEY'  => 'PRI',
					'EXTRA'       => 'auto_increment',
				),
				array(
					'COLUMN_NAME' => 'option_name',
					'COLUMN_TYPE' => 'varchar(191)',
					'COLUMN_KEY'  => 'UNI',
					'EXTRA'       => '',
				),
				array(
					'COLUMN_NAME' => 'option_value',
					'COLUMN_TYPE' => 'longtext',
					'COLUMN_KEY'  => '',
					'EXTRA'       => '',
				),
				array(
					'COLUMN_NAME' => 'autoload',
					'COLUMN_TYPE' => 'varchar(20)',
					'COLUMN_KEY'  => 'MUL',
					'EXTRA'       => '',
				),
			),
			$driver->query(
				"SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = 'wp'
					AND TABLE_NAME = 'wptests_options'
				ORDER BY ORDINAL_POSITION"
			)->fetchAll( PDO::FETCH_ASSOC )
			);
	}

	public function test_json_valid_runtime_function_uses_mysql_semantics(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query( 'CREATE TABLE wptests_json_valid_runtime (id INTEGER PRIMARY KEY, payload TEXT)' );
		$driver->query(
			"INSERT INTO wptests_json_valid_runtime (id, payload)
			VALUES (1, '{\"ok\":true}'), (2, 'not json'), (3, NULL), (4, 'null')"
		);

		$this->assertSame(
			array(
				array(
					'id'            => 1,
					'payload_valid' => 1,
				),
				array(
					'id'            => 2,
					'payload_valid' => 0,
				),
				array(
					'id'            => 3,
					'payload_valid' => null,
				),
				array(
					'id'            => 4,
					'payload_valid' => 1,
				),
			),
			$driver->query(
				'SELECT id, JSON_VALID(payload) AS payload_valid
				FROM wptests_json_valid_runtime
				ORDER BY id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'object_valid'       => 1,
					'array_valid'        => 1,
					'invalid_json'       => 0,
					'null_json'          => null,
					'null_literal_valid' => 1,
					'number_valid'       => 1,
				),
			),
			$driver->query(
				'SELECT JSON_VALID(\'{"ok":true}\') AS object_valid,
					JSON_VALID(\'[1,2]\') AS array_valid,
					JSON_VALID(\'not json\') AS invalid_json,
					JSON_VALID(NULL) AS null_json,
					JSON_VALID(\'null\') AS null_literal_valid,
					JSON_VALID(123) AS number_valid'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_json_valid_runtime_function_rejects_invalid_arity(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		foreach (
			array(
				'SELECT JSON_VALID() AS invalid_json',
				"SELECT JSON_VALID('{\"ok\":true}', '{\"fallback\":true}') AS invalid_json",
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected invalid JSON_VALID() arity to fail closed.' );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'Unsupported JSON_VALID() call', $e->getMessage(), $query );
			}
		}
	}

	public function test_regexp_runtime_predicates_use_mysql_semantics(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			"CREATE TABLE wptests_regexp_runtime (
				id bigint(20) NOT NULL,
				option_name varchar(191) DEFAULT NULL,
				PRIMARY KEY (id)
			)"
		);
		$driver->query(
			"INSERT INTO wptests_regexp_runtime (id, option_name)
			VALUES (1, 'rss_123'), (2, 'RSS_123'), (3, 'transient'), (4, NULL)"
		);

		foreach (
			array(
				'REGEXP'            => array(
					'operator' => 'REGEXP',
					'id'       => 1,
				),
				'RLIKE'             => array(
					'operator' => 'RLIKE',
					'id'       => 1,
				),
				'REGEXP BINARY'     => array(
					'operator' => 'REGEXP BINARY',
					'id'       => 2,
				),
				'RLIKE BINARY'      => array(
					'operator' => 'RLIKE BINARY',
					'id'       => 2,
				),
				'NOT REGEXP'        => array(
					'operator' => 'NOT REGEXP',
					'id'       => 3,
				),
				'NOT RLIKE'         => array(
					'operator' => 'NOT RLIKE',
					'id'       => 3,
				),
				'NOT REGEXP BINARY' => array(
					'operator' => 'NOT REGEXP BINARY',
					'id'       => 1,
				),
				'NOT RLIKE BINARY'  => array(
					'operator' => 'NOT RLIKE BINARY',
					'id'       => 1,
				),
			) as $case
		) {
			$this->assertSame(
				array(
					array(
						'id'          => $case['id'],
						'option_name' => 1 === $case['id'] ? 'rss_123' : ( 2 === $case['id'] ? 'RSS_123' : 'transient' ),
					),
				),
				$driver->query(
					"SELECT id, option_name
					FROM wptests_regexp_runtime
					WHERE option_name {$case['operator']} '^RSS_.+$'
					ORDER BY id
					LIMIT 1"
				)->fetchAll( PDO::FETCH_ASSOC ),
				$case['operator']
			);
		}

		$this->assertSame(
			array(
				array(
					'id'                  => 1,
					'insensitive_match'   => 1,
					'binary_match'        => 0,
					'insensitive_not'     => 0,
					'binary_not'          => 1,
					'null_pattern_match'  => null,
				),
				array(
					'id'                  => 2,
					'insensitive_match'   => 1,
					'binary_match'        => 1,
					'insensitive_not'     => 0,
					'binary_not'          => 0,
					'null_pattern_match'  => null,
				),
				array(
					'id'                  => 3,
					'insensitive_match'   => 0,
					'binary_match'        => 0,
					'insensitive_not'     => 1,
					'binary_not'          => 1,
					'null_pattern_match'  => null,
				),
				array(
					'id'                  => 4,
					'insensitive_match'   => null,
					'binary_match'        => null,
					'insensitive_not'     => null,
					'binary_not'          => null,
					'null_pattern_match'  => null,
				),
			),
			$driver->query(
				"SELECT id,
					option_name REGEXP '^RSS_.+$' AS insensitive_match,
					option_name REGEXP BINARY '^RSS_.+$' AS binary_match,
					option_name NOT REGEXP '^RSS_.+$' AS insensitive_not,
					option_name NOT REGEXP BINARY '^RSS_.+$' AS binary_not,
					option_name REGEXP NULL AS null_pattern_match
				FROM wptests_regexp_runtime
				ORDER BY id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_group_concat_runtime_function_uses_mysql_separator_order_and_limit_semantics(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_group_concat (
				id INTEGER PRIMARY KEY,
				value TEXT NOT NULL,
				prefix TEXT NOT NULL,
				suffix TEXT NULL
			)'
		);
		$driver->query(
			"INSERT INTO wptests_group_concat (id, value, prefix, suffix) VALUES
				(2, 'two', 'b', NULL),
				(1, 'one', 'a', '1'),
				(3, 'three', 'c', '3'),
				(4, 'one', 'd', '4')"
		);

		$this->assertSame(
			array(
				array(
					'ordered_with_separator' => 'one|two|three|one',
					'multi_expression'       => '1:one|2:two|3:three|4:one',
					'null_skipping_composite' => 'a1,c3,d4',
				),
			),
			$driver->query(
				"SELECT
					GROUP_CONCAT(value ORDER BY id SEPARATOR '|') AS ordered_with_separator,
					GROUP_CONCAT(id, ':', value ORDER BY id SEPARATOR '|') AS multi_expression,
					GROUP_CONCAT(prefix, suffix ORDER BY id SEPARATOR ',') AS null_skipping_composite
				FROM wptests_group_concat"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$distinct = $driver->query(
			"SELECT GROUP_CONCAT(DISTINCT value SEPARATOR '|') AS combined
			FROM wptests_group_concat"
		)->fetchAll( PDO::FETCH_ASSOC );
		$parts    = explode( '|', $distinct[0]['combined'] );
		sort( $parts );
		$this->assertSame( array( 'one', 'three', 'two' ), $parts );

		$driver->query( 'CREATE TABLE wptests_group_concat_long (id INTEGER PRIMARY KEY, value TEXT NOT NULL)' );
		$driver->query(
			"INSERT INTO wptests_group_concat_long (id, value) VALUES
				(1, '" . str_repeat( 'a', 600 ) . "'),
				(2, '" . str_repeat( 'b', 600 ) . "')"
		);

		$default = $driver->query(
			"SELECT GROUP_CONCAT(value ORDER BY id SEPARATOR '') AS combined
			FROM wptests_group_concat_long"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( str_repeat( 'a', 600 ) . str_repeat( 'b', 424 ), $default[0]['combined'] );
		$this->assertSame( 1024, strlen( $default[0]['combined'] ) );

		$this->assertSame( 0, $driver->query( 'SET SESSION group_concat_max_len = 5' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@group_concat_max_len' => 5,
				),
			),
			$driver->query( 'SELECT @@group_concat_max_len' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'combined' => 'one|t',
				),
			),
			$driver->query(
				"SELECT GROUP_CONCAT(value ORDER BY id SEPARATOR '|') AS combined
				FROM wptests_group_concat"
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET SESSION group_concat_max_len = DEFAULT' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@group_concat_max_len' => 1024,
				),
			),
			$driver->query( 'SELECT @@group_concat_max_len' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_group_concat_runtime_function_rejects_malformed_forms(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		foreach (
			array(
				'SELECT GROUP_CONCAT() AS combined',
				'SELECT GROUP_CONCAT(DISTINCT id, value) AS combined FROM missing_table',
				"SELECT GROUP_CONCAT(value SEPARATOR) AS combined FROM missing_table",
				"SELECT GROUP_CONCAT(value SEPARATOR ',' SEPARATOR '|') AS combined FROM missing_table",
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected malformed GROUP_CONCAT() form to fail closed.' );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertTrue(
					str_contains( $e->getMessage(), 'Unsupported GROUP_CONCAT() call' )
					|| str_contains( $e->getMessage(), 'DuckDB driver could not parse MySQL statement' ),
					$query . ': ' . $e->getMessage()
				);
			}
		}
	}

	public function test_show_variables_returns_mysql_shaped_emulated_session_state(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$variables = array();
		foreach ( $driver->query( 'SHOW VARIABLES' )->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$variables[ $row['Variable_name'] ] = $row['Value'];
		}

		$this->assertGreaterThan( 20, count( $variables ) );
		$this->assertSame( 'utf8mb4', $variables['character_set_client'] );
		$this->assertSame( 'utf8mb4', $variables['character_set_connection'] );
		$this->assertSame( 'utf8mb4', $variables['character_set_results'] );
		$this->assertSame( 'utf8mb4_unicode_ci', $variables['collation_connection'] );
		$this->assertSame( 'InnoDB', $variables['default_storage_engine'] );
		$this->assertSame( '1', $variables['autocommit'] );
		$this->assertSame( '1', $variables['foreign_key_checks'] );
		$this->assertSame( '1024', $variables['group_concat_max_len'] );
		$this->assertSame( '67108864', $variables['max_allowed_packet'] );
		$this->assertSame( 'SYSTEM', $variables['time_zone'] );
		$this->assertSame( '8.0.38', $variables['version'] );
		$this->assertSame( 'MySQL Community Server - GPL', $variables['version_comment'] );
		$this->assertSame(
			'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES',
			$variables['sql_mode']
		);

		$this->assertSame(
			array(
				array(
					'charset_connection' => 'utf8mb4',
					'max_allowed_packet' => 67108864,
					'engine'             => 'InnoDB',
				),
			),
			$driver->query(
				'SELECT @@character_set_connection AS charset_connection,
					@@max_allowed_packet AS max_allowed_packet,
					@@default_storage_engine AS engine'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_show_variables_like_where_and_session_updates_are_emulated(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame(
			array(
				array(
					'Variable_name' => 'group_concat_max_len',
					'Value'         => '1024',
				),
			),
			$driver->query( "SHOW VARIABLES LIKE 'group_concat_max_len'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$sql_rows = $driver->query( "SHOW VARIABLES LIKE 'sql_%'" )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				'sql_auto_is_null',
				'sql_big_selects',
				'sql_buffer_result',
				'sql_mode',
				'sql_log_bin',
				'sql_notes',
				'sql_quote_show_create',
				'sql_safe_updates',
				'sql_warnings',
			),
			array_column( $sql_rows, 'Variable_name' )
		);

		$this->assertSame(
			array(
				'character_set_client',
				'character_set_connection',
				'character_set_database',
				'character_set_results',
				'character_set_server',
			),
			array_column(
				$driver->query( "SHOW VARIABLES WHERE Value = 'utf8mb4'" )->fetchAll( PDO::FETCH_ASSOC ),
				'Variable_name'
			)
		);

		$this->assertSame( 0, $driver->query( 'SET SESSION group_concat_max_len = 5' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'group_concat_max_len',
					'Value'         => '5',
				),
			),
			$driver->query( "SHOW SESSION VARIABLES WHERE Variable_name = 'group_concat_max_len'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" )->rowCount() );
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'sql_mode',
					'Value'         => 'STRICT_ALL_TABLES',
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'sql_mode'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Variable_name' => 'group_concat_max_len',
					'Value'         => '1024',
				),
			),
			$driver->query( "SHOW GLOBAL VARIABLES WHERE Variable_name = 'group_concat_max_len'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_set_names_updates_duckdb_show_variables_session_state(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame( 0, $driver->query( "SET NAMES 'utf8' COLLATE 'utf8_general_ci'" )->rowCount() );

		$this->assertSame(
			array(
				array(
					'Variable_name' => 'character_set_client',
					'Value'         => 'utf8',
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'character_set_client'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'collation_connection',
					'Value'         => 'utf8_general_ci',
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'collation_connection'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'charset_client'        => 'utf8',
					'charset_connection'    => 'utf8',
					'charset_database'      => 'utf8',
					'charset_results'       => 'utf8',
					'charset_server'        => 'utf8',
					'collation_connection'  => 'utf8_general_ci',
					'collation_database'    => 'utf8_general_ci',
					'collation_server'      => 'utf8_general_ci',
				),
			),
			$driver->query(
				'SELECT @@character_set_client AS charset_client,
					@@character_set_connection AS charset_connection,
					@@character_set_database AS charset_database,
					@@character_set_results AS charset_results,
					@@character_set_server AS charset_server,
					@@collation_connection AS collation_connection,
					@@collation_database AS collation_database,
					@@collation_server AS collation_server'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET NAMES DEFAULT' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'charset_client'       => 'utf8mb4',
					'collation_connection' => 'utf8mb4_unicode_ci',
				),
			),
			$driver->query(
				'SELECT @@character_set_client AS charset_client,
					@@collation_connection AS collation_connection'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_set_charset_aliases_update_duckdb_show_variables_session_state(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame( 0, $driver->query( 'SET CHARSET utf8' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'charset_client'       => 'utf8',
					'collation_connection' => 'utf8_general_ci',
				),
			),
			$driver->query(
				'SELECT @@character_set_client AS charset_client,
					@@collation_connection AS collation_connection'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET CHARACTER SET utf8mb4' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'charset_client'       => 'utf8mb4',
					'collation_connection' => 'utf8mb4_unicode_ci',
				),
			),
			$driver->query(
				'SELECT @@character_set_client AS charset_client,
					@@collation_connection AS collation_connection'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET CHAR SET utf8mb3' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'character_set_client',
					'Value'         => 'utf8',
				),
			),
			$driver->query( "SHOW VARIABLES LIKE 'character_set_client'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_conditional_comment_charset_set_wrappers_update_duckdb_session_state(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame( 0, $driver->query( '/*!50503 SET NAMES utf8 */;' )->rowCount() );
		$this->assertSame( 0, $driver->query( '/*!40101 SET @saved_cs_client = @@character_set_client */; ' )->rowCount() );
		$this->assertSame( 0, $driver->query( '/*!50503 SET character_set_client = latin1 */;' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'charset_client' => 'latin1',
				),
			),
			$driver->query( 'SELECT @@character_set_client AS charset_client' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( '/*!40101 SET character_set_client = @saved_cs_client */;' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'charset_client' => 'utf8',
				),
			),
			$driver->query( 'SELECT @@character_set_client AS charset_client' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wp_cli_dump_system_variable_probes_are_emulated_for_duckdb(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame(
			array(
				array(
					'@@GLOBAL.gtid_purged'                     => '',
					'@@GLOBAL.log_bin'                         => 0,
					'@@GLOBAL.log_bin_trust_function_creators' => 0,
					'@@SESSION.max_allowed_packet'             => 67108864,
					'@@lower_case_table_names'                 => 0,
					'@@hostname'                               => 'localhost',
					'@@protocol_version'                       => 10,
				),
			),
			$driver->query(
				'SELECT @@GLOBAL.gtid_purged,
					@@GLOBAL.log_bin,
					@@GLOBAL.log_bin_trust_function_creators,
					@@SESSION.max_allowed_packet,
					@@lower_case_table_names,
					@@hostname,
					@@protocol_version'
			)->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Variable_name' => 'log_bin',
					'Value'         => '0',
				),
			),
			$driver->query( "SHOW VARIABLES LIKE 'log_bin'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_wp_cli_import_system_variable_toggles_are_emulated_for_duckdb(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$this->assertSame( 0, $driver->query( 'SET @old_sql_log_bin = @@sql_log_bin' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'SET @@sql_log_bin = 0' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@sql_log_bin' => 0,
				),
			),
			$driver->query( 'SELECT @@sql_log_bin' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET @@sql_log_bin = DEFAULT' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@sql_log_bin' => 1,
				),
			),
			$driver->query( 'SELECT @@sql_log_bin' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET @old_wait_timeout = @@wait_timeout' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'SET wait_timeout = 100' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@wait_timeout' => 100,
				),
			),
			$driver->query( 'SELECT @@wait_timeout' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET wait_timeout = @old_wait_timeout' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@wait_timeout' => 28800,
				),
			),
			$driver->query( 'SELECT @@wait_timeout' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET sql_quote_show_create = OFF, pseudo_replica_mode = ON' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@sql_quote_show_create' => 0,
					'@@pseudo_replica_mode'   => 1,
				),
			),
			$driver->query( 'SELECT @@sql_quote_show_create, @@pseudo_replica_mode' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame(
			array(
				array(
					'Variable_name' => 'wait_timeout',
					'Value'         => '28800',
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'wait_timeout'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_sql_warnings_save_restore_flow_is_emulated_for_duckdb(): void {
		$driver = $this->create_in_memory_duckdb_driver();

		$this->assertSame( 0, $driver->query( 'SET @old_sql_warnings = @@sql_warnings' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'SET sql_warnings = ON' )->rowCount() );

		$this->assertSame(
			array(
				array(
					'saved_warnings' => 0,
					'warning_state'  => 1,
				),
			),
			$driver->query( 'SELECT @old_sql_warnings AS saved_warnings, @@sql_warnings warning_state' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'sql_warnings',
					'Value'         => '1',
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'sql_warnings'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( 'SET @@sql_warnings = @old_sql_warnings' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'warning_state' => 0,
				),
			),
			$driver->query( 'SELECT @@sql_warnings warning_state' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_keyword_session_system_variables_can_be_set_and_selected_for_duckdb(): void {
		$driver = $this->create_in_memory_duckdb_driver();

		$cases = array(
			'SET default_collation_for_utf8mb4 = utf8mb4_0900_ai_ci' => array( '@@default_collation_for_utf8mb4', 'utf8mb4_0900_ai_ci' ),
			'SET resultset_metadata = FULL'                          => array( '@@resultset_metadata', 'FULL' ),
			'SET session_track_gtids = OWN_GTID'                     => array( '@@session_track_gtids', 'OWN_GTID' ),
			'SET session_track_transaction_info = STATE'             => array( '@@session_track_transaction_info', 'STATE' ),
			'SET transaction_isolation = SERIALIZABLE'               => array( '@@transaction_isolation', 'SERIALIZABLE' ),
			'SET use_secondary_engine = FORCED'                      => array( '@@use_secondary_engine', 'FORCED' ),
		);

		foreach ( $cases as $query => $expected ) {
			$this->assertSame( 0, $driver->query( $query )->rowCount(), $query );
			$this->assertSame(
				array(
					array(
						$expected[0] => $expected[1],
					),
				),
				$driver->query( 'SELECT ' . $expected[0] )->fetchAll( PDO::FETCH_ASSOC ),
				$query
			);
		}
	}

	public function test_on_off_session_system_variables_can_be_set_and_selected_for_duckdb(): void {
		$driver = $this->create_in_memory_duckdb_driver();

		$cases = array(
			'SET autocommit = ON'                                  => array( '@@autocommit', 1 ),
			'SET big_tables = OFF'                                 => array( '@@big_tables', 0 ),
			'SET end_markers_in_json = ON'                         => array( '@@end_markers_in_json', 1 ),
			'SET explicit_defaults_for_timestamp = OFF'            => array( '@@explicit_defaults_for_timestamp', 0 ),
			'SET keep_files_on_create = ON'                        => array( '@@keep_files_on_create', 1 ),
			'SET old_alter_table = OFF'                            => array( '@@old_alter_table', 0 ),
			'SET print_identified_with_as_hex = ON'                => array( '@@print_identified_with_as_hex', 1 ),
			'SET require_row_format = OFF'                         => array( '@@require_row_format', 0 ),
			'SET select_into_disk_sync = ON'                       => array( '@@select_into_disk_sync', 1 ),
			'SET session_track_schema = ON'                        => array( '@@session_track_schema', 1 ),
			'SET session_track_state_change = OFF'                 => array( '@@session_track_state_change', 0 ),
			'SET show_create_table_skip_secondary_engine = ON'     => array( '@@show_create_table_skip_secondary_engine', 1 ),
			'SET show_create_table_verbosity = OFF'                => array( '@@show_create_table_verbosity', 0 ),
			'SET sql_auto_is_null = ON'                            => array( '@@sql_auto_is_null', 1 ),
			'SET sql_big_selects = OFF'                            => array( '@@sql_big_selects', 0 ),
			'SET sql_buffer_result = ON'                           => array( '@@sql_buffer_result', 1 ),
			'SET sql_safe_updates = OFF'                           => array( '@@sql_safe_updates', 0 ),
			'SET sql_warnings = ON'                                => array( '@@sql_warnings', 1 ),
			'SET transaction_read_only = OFF'                      => array( '@@transaction_read_only', 0 ),
		);

		foreach ( $cases as $query => $expected ) {
			$this->assertSame( 0, $driver->query( $query )->rowCount(), $query );
			$this->assertSame(
				array(
					array(
						$expected[0] => $expected[1],
					),
				),
				$driver->query( 'SELECT ' . $expected[0] )->fetchAll( PDO::FETCH_ASSOC ),
				$query
			);
		}

		$this->assertSame( 0, $driver->query( "SET autocommit = 'on', big_tables = 'off'" )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@autocommit' => 1,
					'@@big_tables' => 0,
				),
			),
			$driver->query( 'SELECT @@autocommit, @@big_tables' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_global_system_variables_use_separate_duckdb_emulated_state(): void {
		$driver           = $this->create_in_memory_duckdb_driver();
		$default_sql_mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES';

		$this->assertSame( 0, $driver->query( 'SET GLOBAL foreign_key_checks = 0' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'global_fk'  => 0,
					'session_fk' => 1,
				),
			),
			$driver->query( 'SELECT @@GLOBAL.foreign_key_checks AS global_fk, @@SESSION.foreign_key_checks AS session_fk' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'foreign_key_checks',
					'Value'         => '0',
				),
			),
			$driver->query( "SHOW GLOBAL VARIABLES WHERE Variable_name = 'foreign_key_checks'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'foreign_key_checks',
					'Value'         => '1',
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'foreign_key_checks'" )->fetchAll( PDO::FETCH_ASSOC )
		);

		$this->assertSame( 0, $driver->query( "SET GLOBAL sql_mode = 'ANSI_QUOTES'" )->rowCount() );
		$this->assertSame(
			array(
				array(
					'global_mode'  => 'ANSI_QUOTES',
					'session_mode' => $default_sql_mode,
				),
			),
			$driver->query( 'SELECT @@GLOBAL.sql_mode AS global_mode, @@SESSION.sql_mode AS session_mode' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'sql_mode',
					'Value'         => 'ANSI_QUOTES',
				),
			),
			$driver->query( "SHOW GLOBAL VARIABLES WHERE Variable_name = 'sql_mode'" )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'Variable_name' => 'sql_mode',
					'Value'         => $default_sql_mode,
				),
			),
			$driver->query( "SHOW VARIABLES WHERE Variable_name = 'sql_mode'" )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_comma_separated_set_assignments_are_atomic_for_duckdb(): void {
		$driver = $this->create_in_memory_duckdb_driver();

		$this->assertSame( 0, $driver->query( 'SET autocommit = ON, big_tables = OFF' )->rowCount() );
		$this->assertSame(
			array(
				array(
					'@@autocommit' => 1,
					'@@big_tables' => 0,
				),
			),
			$driver->query( 'SELECT @@autocommit, @@big_tables' )->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'SET autocommit = OFF, unsupported_setting = 1' );
			$this->fail( 'Expected unsupported SET statement to throw.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Unsupported SET session variable', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'@@autocommit' => 1,
					'@@big_tables' => 0,
				),
			),
			$driver->query( 'SELECT @@autocommit, @@big_tables' )->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'SET GLOBAL foreign_key_checks = 0, group_concat_max_len = 1000000' );
			$this->fail( 'Expected unsupported SET GLOBAL group_concat_max_len statement to throw.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertStringContainsString( 'Unsupported SET statement type', $e->getMessage() );
		}

		$this->assertSame(
			array(
				array(
					'global_fk' => 1,
				),
			),
			$driver->query( 'SELECT @@GLOBAL.foreign_key_checks AS global_fk' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_show_warnings_and_errors_return_empty_mysql_shaped_diagnostics_for_duckdb(): void {
		$driver = $this->create_in_memory_duckdb_driver();

		$warnings = $driver->query( 'SHOW WARNINGS' );
		$this->assertSame( array(), $warnings->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array( 'Level', 'Code', 'Message' ),
			array(
				$warnings->getColumnMeta( 0 )['name'],
				$warnings->getColumnMeta( 1 )['name'],
				$warnings->getColumnMeta( 2 )['name'],
			)
		);

		$this->assertSame(
			array(
				array(
					'FOUND_ROWS()' => 0,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS()' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$errors = $driver->query( 'SHOW ERRORS LIMIT 0, 10' );
		$this->assertSame( array(), $errors->fetchAll( PDO::FETCH_ASSOC ) );
		$this->assertSame(
			array( 'Level', 'Code', 'Message' ),
			array(
				$errors->getColumnMeta( 0 )['name'],
				$errors->getColumnMeta( 1 )['name'],
				$errors->getColumnMeta( 2 )['name'],
			)
		);

		$limited_warnings = $driver->query( 'SHOW WARNINGS LIMIT 1 OFFSET 0' );
		$this->assertSame( array(), $limited_warnings->fetchAll( PDO::FETCH_ASSOC ) );

		$this->assertSame(
			array(
				array(
					'@@session.warning_count' => 0,
				),
			),
			$driver->query( 'SHOW COUNT(*) WARNINGS' )->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					0,
				),
			),
			$driver->query( 'SHOW COUNT(*) ERRORS' )->fetchAll( PDO::FETCH_NUM )
		);
	}

	public function test_unsupported_show_warnings_and_errors_clauses_fail_closed_for_duckdb(): void {
		$driver = $this->create_in_memory_duckdb_driver();

		$queries = array(
			"SHOW WARNINGS WHERE Level = 'Warning'" => 'Unsupported SHOW WARNINGS statement in DuckDB driver.',
			'SHOW WARNINGS LIMIT bad'               => 'Unsupported SHOW WARNINGS statement in DuckDB driver.',
			'SHOW WARNINGS LIMIT 1, bad'            => 'Unsupported SHOW WARNINGS statement in DuckDB driver.',
			'SHOW COUNT(*) WARNINGS LIMIT 1'        => 'Unsupported SHOW WARNINGS statement in DuckDB driver.',
			"SHOW ERRORS LIKE 'error%'"             => 'Unsupported SHOW ERRORS statement in DuckDB driver.',
			'SHOW ERRORS LIMIT bad'                 => 'Unsupported SHOW ERRORS statement in DuckDB driver.',
			'SHOW COUNT(*) ERRORS LIMIT 1'          => 'Unsupported SHOW ERRORS statement in DuckDB driver.',
		);

		foreach ( $queries as $query => $message ) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected unsupported SHOW diagnostics statement to throw.' );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertTrue(
					$message === $e->getMessage()
					|| 'DuckDB driver could not parse MySQL statement.' === $e->getMessage(),
					$query . ': ' . $e->getMessage()
				);
			}
		}
	}

	public function test_insert_select_on_duplicate_key_update_uses_unique_metadata_conflict_target(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_plugin_lookup (
				source varchar(64) NOT NULL,
				external_id varchar(64) NOT NULL,
				attempts int(11) NOT NULL DEFAULT 0,
				payload longtext NOT NULL,
				UNIQUE KEY source_external_id (source, external_id)
			)'
		);

		$insert = "INSERT INTO `wptests_plugin_lookup` (`source`, `external_id`, `attempts`, `payload`)
			SELECT 'feed', 'abc', 1, 'first' FROM DUAL
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + VALUES(`attempts`),
			                        `payload` = VALUES(`payload`)";

		$this->assertSame( 1, $driver->query( $insert )->rowCount() );

		$update = "INSERT INTO `wptests_plugin_lookup` (`source`, `external_id`, `attempts`, `payload`)
			SELECT 'feed', 'abc', 3, 'second' FROM DUAL
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + VALUES(`attempts`),
			                        `payload` = VALUES(`payload`)";

		$this->assertSame( 1, $driver->query( $update )->rowCount() );
		$this->assertSame(
			array(
				array(
					'attempts' => 4,
					'payload'  => 'second',
				),
			),
			$driver->query(
				"SELECT attempts, payload
				FROM wptests_plugin_lookup
				WHERE source = 'feed' AND external_id = 'abc'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_supports_qualified_assignment_targets(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_insert_set (
				id bigint(20) NOT NULL,
				value longtext NOT NULL,
				attempts int(11) NOT NULL,
				PRIMARY KEY (id)
			)'
		);

		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO wptests_insert_set
				SET wptests_insert_set.id = 1,
				    wp.wptests_insert_set.value = 'qualified',
				    attempts = 4"
			)->rowCount()
		);
		$this->assertSame(
			array(
				array(
					'id'       => 1,
					'value'    => 'qualified',
					'attempts' => 4,
				),
			),
			$driver->query(
				'SELECT id, value, attempts
				FROM wptests_insert_set'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_on_duplicate_key_update_uses_unique_metadata_conflict_target(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_insert_set_upsert (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT "yes",
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			)'
		);

		$insert = "INSERT INTO `wptests_insert_set_upsert`
			SET `slug` = 'siteurl',
			    `value` = 'http://example.org',
			    `autoload` = 'yes'
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`),
			                        `autoload` = VALUES(`autoload`)";

		$this->assertSame( 1, $driver->query( $insert )->rowCount() );

		$update = "INSERT INTO `wptests_insert_set_upsert`
			SET `slug` = 'siteurl',
			    `value` = 'http://example.net',
			    `autoload` = 'no'
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`),
			                        `autoload` = VALUES(`autoload`)";

		$this->assertSame( 1, $driver->query( $update )->rowCount() );
		$this->assertSame(
			array(
				array(
					'slug'     => 'siteurl',
					'value'    => 'http://example.net',
					'autoload' => 'no',
				),
			),
			$driver->query(
				"SELECT slug, value, autoload
				FROM wptests_insert_set_upsert
				ORDER BY id"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_on_duplicate_key_update_supports_qualified_insert_assignment_targets(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_qualified_insert_set_upsert (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT "yes",
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_qualified_insert_set_upsert (slug, value, autoload)
			VALUES ('siteurl', 'old', 'no')"
		);

		$upsert = "INSERT INTO `wptests_qualified_insert_set_upsert`
			SET `wptests_qualified_insert_set_upsert`.`slug` = 'siteurl',
			    `wp`.`wptests_qualified_insert_set_upsert`.`value` = 'from-set',
			    `autoload` = 'off'
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)";

		$this->assertSame( 1, $driver->query( $upsert )->rowCount() );
		$this->assertSame(
			array(
				array(
					'value'    => 'from-set',
					'autoload' => 'no',
				),
			),
			$driver->query(
				"SELECT value, autoload
				FROM wptests_qualified_insert_set_upsert
				WHERE slug = 'siteurl'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_on_duplicate_key_update_supports_values_row_alias_expressions(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_alias_insert_set_upsert (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT "yes",
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			)'
		);
		$driver->query(
			"INSERT INTO wptests_alias_insert_set_upsert (slug, value, autoload)
			VALUES ('siteurl', 'old', 'no')"
		);

		$upsert = "INSERT INTO `wptests_alias_insert_set_upsert`
			SET `slug` = 'siteurl',
			    `value` = 'from-set-alias',
			    `autoload` = 'off'
			AS incoming(slug_alias, value_alias, autoload_alias)
			ON DUPLICATE KEY UPDATE `value` = incoming.`value_alias`,
			                        `autoload` = autoload_alias";

		$this->assertSame( 1, $driver->query( $upsert )->rowCount() );
		$this->assertSame(
			array(
				array(
					'value'    => 'from-set-alias',
					'autoload' => 'off',
				),
			),
			$driver->query(
				"SELECT value, autoload
				FROM wptests_alias_insert_set_upsert
				WHERE slug = 'siteurl'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_on_duplicate_key_update_supports_integer_values_row_alias_expressions(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_alias_counter_upsert (
				id bigint(20) NOT NULL,
				attempts int(11) NOT NULL,
				PRIMARY KEY (id)
			)'
		);
		$driver->query( 'INSERT INTO wptests_alias_counter_upsert (id, attempts) VALUES (1, 4)' );

		$upsert = 'INSERT INTO `wptests_alias_counter_upsert`
			SET `id` = 1,
			    `attempts` = 3
			AS incoming(row_id, incoming_attempts)
			ON DUPLICATE KEY UPDATE `attempts` = `attempts` + incoming_attempts';

		$this->assertSame( 1, $driver->query( $upsert )->rowCount() );
		$this->assertSame(
			array(
				array(
					'attempts' => 7,
				),
			),
			$driver->query(
				'SELECT attempts
				FROM wptests_alias_counter_upsert
				WHERE id = 1'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_insert_set_on_duplicate_key_update_rejects_malformed_values_row_aliases(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_bad_alias_insert_set_upsert (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				slug varchar(191) NOT NULL,
				value longtext NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY slug (slug)
			)'
		);

		foreach (
			array(
				"INSERT INTO `wptests_bad_alias_insert_set_upsert` SET `slug` = 'siteurl', `value` = 'bad' AS incoming(slug_alias) ON DUPLICATE KEY UPDATE `value` = slug_alias",
				"INSERT INTO `wptests_bad_alias_insert_set_upsert` SET `slug` = 'siteurl', `value` = 'bad' AS incoming ON DUPLICATE KEY UPDATE `value` = incoming.missing",
				"INSERT INTO `wptests_bad_alias_insert_set_upsert` SET `slug` = 'siteurl', `value` = 'bad' AS incoming ON DUPLICATE KEY UPDATE `value` = incoming",
			) as $query
		) {
			try {
				$driver->query( $query );
				$this->fail( 'Expected malformed INSERT ... SET row alias to fail closed.' );
			} catch ( WP_DuckDB_Driver_Exception $e ) {
				$this->assertStringContainsString( 'Unsupported INSERT', $e->getMessage(), $query );
			}
		}
	}

	public function test_woocommerce_reserved_stock_insert_select_on_duplicate_key_update(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_wc_reserved_stock (
				`order_id` bigint(20) unsigned NOT NULL,
				`product_id` bigint(20) unsigned NOT NULL,
				`stock_quantity` double NOT NULL DEFAULT 0,
				`timestamp` datetime NOT NULL DEFAULT "0000-00-00 00:00:00",
				`expires` datetime NOT NULL DEFAULT "0000-00-00 00:00:00",
				PRIMARY KEY (`order_id`, `product_id`)
			)'
		);

		$insert = 'INSERT INTO wptests_wc_reserved_stock (order_id, product_id, stock_quantity, timestamp, expires)
			SELECT 5001, 89, 2, NOW(), ( NOW() + INTERVAL 10 MINUTE ) FROM DUAL
			WHERE ( SELECT 12 FOR UPDATE ) - ( SELECT IFNULL(SUM(stock_quantity), 0) FROM wptests_wc_reserved_stock WHERE product_id = 89 FOR UPDATE ) >= 2
			ON DUPLICATE KEY UPDATE expires = VALUES(expires), stock_quantity = VALUES(stock_quantity)';

		$this->assertSame( 1, $driver->query( $insert )->rowCount() );

		$update = 'INSERT INTO wptests_wc_reserved_stock (order_id, product_id, stock_quantity, timestamp, expires)
			SELECT 5001, 89, 4, NOW(), ( NOW() + INTERVAL 10 MINUTE ) FROM DUAL
			WHERE ( SELECT 12 FOR UPDATE ) - ( SELECT IFNULL(SUM(stock_quantity), 0) FROM wptests_wc_reserved_stock WHERE product_id = 89 FOR UPDATE ) >= 4
			ON DUPLICATE KEY UPDATE expires = VALUES(expires), stock_quantity = VALUES(stock_quantity)';

		$this->assertSame( 1, $driver->query( $update )->rowCount() );
		$rows = $driver->query(
			'SELECT stock_quantity, expires
			FROM wptests_wc_reserved_stock
			WHERE order_id = 5001 AND product_id = 89'
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 1, $rows );
		$this->assertSame( 4, (int) $rows[0]['stock_quantity'] );
		$this->assertGreaterThan( 0, strtotime( $rows[0]['expires'] ) );
	}

	public function test_woocommerce_customer_lookup_replace_updates_existing_customer_id(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_wc_customer_lookup (
				customer_id INTEGER PRIMARY KEY,
				user_id INTEGER NOT NULL,
				email TEXT NOT NULL
			)'
		);
		$driver->query( "INSERT INTO wptests_wc_customer_lookup (customer_id, user_id, email) VALUES (1, 1, 'old@example.com')" );

		$replace = "REPLACE INTO `wptests_wc_customer_lookup` (`user_id`, `email`, `customer_id`) VALUES (2, 'new@example.com', '1')";

		$this->assertSame( 2, $driver->query( $replace )->rowCount() );
		$this->assertSame(
			array(
				array(
					'user_id' => 2,
					'email'   => 'new@example.com',
				),
			),
			$driver->query(
				'SELECT user_id, email
				FROM wptests_wc_customer_lookup
				WHERE customer_id = 1'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_woocommerce_product_lookup_replace_coerces_empty_decimal_strings(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );
		$driver->query( "SET sql_mode = ''" );

		$driver->query(
			'CREATE TABLE wptests_wc_product_meta_lookup_prices (
				`product_id` bigint(20) unsigned NOT NULL,
				`min_price` decimal(19,4) DEFAULT NULL,
				`max_price` decimal(19,4) DEFAULT NULL,
				`average_rating` decimal(3,2) NOT NULL DEFAULT 0,
				PRIMARY KEY (`product_id`)
			)'
		);

		$replace = "REPLACE INTO `wptests_wc_product_meta_lookup_prices` (`product_id`, `min_price`, `max_price`, `average_rating`) VALUES (109, '', '', '4.50')";

		$this->assertSame( 1, $driver->query( $replace )->rowCount() );

		$rows = $driver->query(
			'SELECT min_price, max_price, average_rating
			FROM wptests_wc_product_meta_lookup_prices
			WHERE product_id = 109'
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0.0, (float) $rows[0]['min_price'] );
		$this->assertSame( 0.0, (float) $rows[0]['max_price'] );
		$this->assertSame( 4.5, (float) $rows[0]['average_rating'] );
	}

	public function test_woocommerce_session_upsert_supports_double_quoted_table_identifier(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_woocommerce_sessions (
				session_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_key char(32) NOT NULL,
				session_value longtext NOT NULL,
				session_expiry bigint(20) unsigned NOT NULL,
				PRIMARY KEY (session_id),
				UNIQUE KEY session_key (session_key)
			)'
		);

		$upsert = "INSERT INTO \"wptests_woocommerce_sessions\" (`session_key`, `session_value`, `session_expiry`)
			VALUES ('session-key', 'first', 1781870576)
			ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)";

		$this->assertSame( 1, $driver->query( $upsert )->rowCount() );

		$duplicate_upsert = "INSERT INTO \"wptests_woocommerce_sessions\" (`session_key`, `session_value`, `session_expiry`)
			VALUES ('session-key', 'second', 1781870577)
			ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)";

		$this->assertSame( 1, $driver->query( $duplicate_upsert )->rowCount() );

		$rows = $driver->query(
			"SELECT session_value, session_expiry
			FROM \"wptests_woocommerce_sessions\"
			WHERE session_key = 'session-key'"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'second', $rows[0]['session_value'] );
		$this->assertSame( 1781870577, (int) $rows[0]['session_expiry'] );
	}

	public function test_woocommerce_orphan_cleanup_delete_left_join_removes_only_orphans(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query(
			'CREATE TABLE wptests_posts (
				"ID" INTEGER PRIMARY KEY
			)'
		);
		$driver->query(
			'CREATE TABLE wptests_postmeta (
				meta_id INTEGER PRIMARY KEY,
				post_id INTEGER NOT NULL
			)'
		);
		$driver->query( 'INSERT INTO wptests_posts (`ID`) VALUES (1)' );
		$driver->query( 'INSERT INTO wptests_postmeta (meta_id, post_id) VALUES (1, 1)' );
		$driver->query( 'INSERT INTO wptests_postmeta (meta_id, post_id) VALUES (2, 999)' );

		$delete = 'DELETE meta FROM wptests_postmeta meta LEFT JOIN wptests_posts posts ON posts.ID = meta.post_id WHERE posts.ID IS NULL;';

		$this->assertSame( 1, $driver->query( $delete )->rowCount() );
		$this->assertSame(
			array(
				array(
					'meta_id' => 1,
					'post_id' => 1,
				),
			),
			$driver->query( 'SELECT meta_id, post_id FROM wptests_postmeta ORDER BY meta_id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_woocommerce_found_rows_query_supports_alias_projection(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query( 'CREATE TABLE wptests_posts (`ID` INTEGER PRIMARY KEY, post_type TEXT NOT NULL, post_status TEXT NOT NULL, post_date TEXT NOT NULL)' );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (1, 'shop_order', 'wc-completed', '2024-01-01 00:00:00')" );
		$driver->query( "INSERT INTO wptests_posts (\"ID\", post_type, post_status, post_date) VALUES (2, 'shop_order', 'wc-completed', '2024-01-02 00:00:00')" );

		$rows = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS wptests_posts.ID
			FROM wptests_posts
			WHERE wptests_posts.post_type = 'shop_order'
			ORDER BY wptests_posts.ID ASC
			LIMIT 0, 1"
		)->fetchAll( PDO::FETCH_ASSOC );

		$this->assertCount( 1, $rows );
		$found_rows = $driver->query( 'SELECT FOUND_ROWS() AS found_rows' );

		$this->assertSame( 2, (int) $found_rows->fetch( PDO::FETCH_ASSOC )['found_rows'] );
		$this->assertSame( 'found_rows', $found_rows->getColumnMeta( 0 )['name'] );
	}

	public function test_savepoint_statements_restore_dml_rows(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query( 'CREATE TABLE wptests_savepoint_state (id INT)' );
		$driver->query( 'BEGIN' );
		$driver->query( 'INSERT INTO wptests_savepoint_state (id) VALUES (1)' );
		$driver->query( 'SAVEPOINT sp1' );
		$driver->query( 'INSERT INTO wptests_savepoint_state (id) VALUES (2)' );
		$driver->query( 'SAVEPOINT sp2' );
		$driver->query( 'INSERT INTO wptests_savepoint_state (id) VALUES (3)' );

		$this->assertSame( 0, $driver->query( 'ROLLBACK TO SAVEPOINT sp1' )->rowCount() );
		$this->assertSame(
			array( array( 'id' => 1 ) ),
			$driver->query( 'SELECT id FROM wptests_savepoint_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);

		try {
			$driver->query( 'RELEASE SAVEPOINT sp2' );
			$this->fail( 'Expected savepoints newer than the rollback target to be released.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertSame( 'SAVEPOINT does not exist: sp2.', $e->getMessage() );
		}

		$this->assertSame( 0, $driver->query( 'RELEASE SAVEPOINT sp1' )->rowCount() );
		$this->assertSame( 0, $driver->query( 'ROLLBACK WORK' )->rowCount() );
		$this->assertSame(
			array(),
			$driver->query( 'SELECT id FROM wptests_savepoint_state ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_rollback_work_to_savepoint_restores_rows_and_preserves_transaction(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query( 'CREATE TABLE wptests_savepoint_work (id INT)' );
		$driver->query( 'START TRANSACTION' );
		$driver->query( 'SAVEPOINT before_second_insert' );
		$driver->query( 'INSERT INTO wptests_savepoint_work (id) VALUES (1)' );
		$driver->query( 'ROLLBACK WORK TO before_second_insert' );
		$driver->query( 'INSERT INTO wptests_savepoint_work (id) VALUES (2)' );
		$driver->query( 'COMMIT' );

		$this->assertSame(
			array( array( 'id' => 2 ) ),
			$driver->query( 'SELECT id FROM wptests_savepoint_work ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	public function test_savepoint_rollback_rejects_ddl_changes_without_aborting_transaction(): void {
		$this->requireDuckDBRuntime();

		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'       => 'duckdb',
				'database_path' => ':memory:',
			)
		);
		$driver  = $storage->create_driver( 'wp' );

		$driver->query( 'CREATE TABLE wptests_savepoint_ddl_base (id INT)' );
		$driver->query( 'BEGIN' );
		$driver->query( 'SAVEPOINT before_ddl' );
		$driver->query( 'CREATE TABLE wptests_savepoint_ddl_new (id INT)' );

		try {
			$driver->query( 'ROLLBACK TO SAVEPOINT before_ddl' );
			$this->fail( 'Expected DDL after a DuckDB emulated savepoint to be rejected.' );
		} catch ( WP_DuckDB_Driver_Exception $e ) {
			$this->assertSame(
				'Unsupported SAVEPOINT rollback in DuckDB driver. Tables created after the savepoint cannot be rolled back.',
				$e->getMessage()
			);
		}

		$driver->query( 'ROLLBACK' );
		$this->assertSame(
			array(),
			$driver->query( "SHOW TABLES LIKE 'wptests_savepoint_ddl_new'" )->fetchAll( PDO::FETCH_ASSOC )
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
