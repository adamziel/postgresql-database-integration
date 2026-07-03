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
