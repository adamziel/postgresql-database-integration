<?php

require_once __DIR__ . '/WP_DuckDB_TestCase.php';

/**
 * @group duckdb
 * @group duckdb-external-formats
 */
class WP_DuckDB_External_Format_Backend_Tests extends WP_DuckDB_TestCase {
	/**
	 * Temporary directories created by the current test.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$autoload = getenv( 'DUCKDB_PHP_AUTOLOAD' );
		if ( is_string( $autoload ) && '' !== $autoload && file_exists( $autoload ) ) {
			require_once $autoload;
		}
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->temp_dirs ) as $temp_dir ) {
			$this->remove_temp_dir( $temp_dir );
		}
		$this->temp_dirs = array();

		parent::tearDown();
	}

	public function test_parquet_file_backed_views_can_serve_wordpress_table_reads(): void {
		$this->assert_external_format_serves_wordpress_table_reads( 'parquet' );
	}

	/**
	 * @dataProvider other_external_format_provider
	 *
	 * @param string $format External storage format.
	 */
	public function test_csv_and_json_file_backed_views_can_serve_wordpress_table_reads( string $format ): void {
		$this->assert_external_format_serves_wordpress_table_reads( $format );
	}

	public static function other_external_format_provider(): array {
		return array(
			'csv'  => array( 'csv' ),
			'json' => array( 'json' ),
		);
	}

	public static function external_format_provider(): array {
		return array(
			'parquet' => array( 'parquet' ),
			'csv'     => array( 'csv' ),
			'json'    => array( 'json' ),
		);
	}

	public function test_csv_storage_backend_hydrates_with_recorded_wordpress_metadata(): void {
		$this->requireDuckDBRuntime();

		$temp_dir = $this->create_temp_dir();
		$database = $temp_dir . '/wordpress.duckdb';
		$backend  = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'csv',
				'database_path'        => $database,
				'external_storage_dir' => $temp_dir,
			)
		);
		$driver   = $backend->create_driver( 'wp' );

		$driver->query(
			"CREATE TABLE wptests_posts (
				ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_title varchar(255) NOT NULL DEFAULT '',
				post_status varchar(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			)"
		);
		$driver->query(
			"CREATE TABLE wptests_comments (
				comment_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				comment_approved varchar(20) NOT NULL DEFAULT '1',
				PRIMARY KEY (comment_ID)
			)"
		);
		$driver->query(
			"INSERT INTO wptests_posts (post_date, post_date_gmt, post_title, post_status)
			VALUES ('2026-07-01 12:00:00', '2026-07-01 12:00:00', 'Seed post', 'publish')"
		);
		$driver->query( "INSERT INTO wptests_comments (comment_approved) VALUES ('1')" );
		$backend->flush();
		unset( $driver, $backend );

		$fresh_backend = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'csv',
				'database_path'        => $database,
				'external_storage_dir' => $temp_dir,
			)
		);
		$fresh_driver  = $fresh_backend->create_driver( 'wp' );
		$fresh_driver->query( "SET SESSION sql_mode = ''" );

		$this->assertSame(
			1,
			$fresh_driver->query(
				"INSERT INTO wptests_posts (post_date, post_date_gmt, post_title, post_status)
				VALUES ('2026-07-01 13:00:00', '0000-00-00 00:00:00', 'Auto draft', 'auto-draft')"
			)->rowCount()
		);
		$this->assertSame( 2, $fresh_driver->get_insert_id() );
		$this->assertSame(
			array(
				array(
					'ID'            => 2,
					'post_date_gmt' => '0000-00-00 00:00:00',
				),
			),
			$fresh_driver->query(
				"SELECT ID, post_date_gmt
				FROM wptests_posts
				WHERE post_title = 'Auto draft'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array( array( 'trashed' => 0 ) ),
			$fresh_driver->query(
				"SELECT COUNT(*) AS trashed
				FROM wptests_comments
				WHERE comment_approved = 'trash'"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	/**
	 * @dataProvider external_format_provider
	 *
	 * @param string $format External storage format.
	 */
	public function test_external_format_files_can_round_trip_wordpress_mutations_as_storage( string $format ): void {
		$this->requireDuckDBRuntime();

		$paths  = $this->create_external_wordpress_storage_files( $format );
		$duckdb = null;
		$driver = $this->create_mutable_driver_from_external_wordpress_storage( $format, $paths, $duckdb );

		$this->assertSame(
			1,
			$driver->query(
				"UPDATE wptests_posts
				SET post_status = 'publish', post_title = 'Published draft post'
				WHERE ID = 2"
			)->rowCount()
		);
		$this->assertSame(
			1,
			$driver->query(
				"INSERT INTO wptests_posts (ID, post_author, post_date, post_title, post_type, post_status)
				VALUES (5, 50, '2026-07-01 00:00:00', 'Created through WP driver', 'post', 'publish')"
			)->rowCount()
		);
		$this->assertSame( 1, $driver->query( 'DELETE FROM wptests_postmeta WHERE meta_id = 10' )->rowCount() );
		$this->assertSame(
			1,
			$driver->query(
				"UPDATE wptests_options
				SET option_value = 'Mutable External Format Site'
				WHERE option_name = 'blogname'"
			)->rowCount()
		);

		$this->flush_wordpress_tables_to_external_storage( $duckdb, $format, $paths );
		unset( $driver, $duckdb );

		$fresh_duckdb = null;
		$fresh_driver = $this->create_mutable_driver_from_external_wordpress_storage( $format, $paths, $fresh_duckdb );

		$this->assertSame(
			array(
				array(
					'ID'          => 1,
					'post_title'  => 'Old published post',
					'post_status' => 'publish',
				),
				array(
					'ID'          => 2,
					'post_title'  => 'Published draft post',
					'post_status' => 'publish',
				),
				array(
					'ID'          => 3,
					'post_title'  => 'New published post',
					'post_status' => 'publish',
				),
				array(
					'ID'          => 5,
					'post_title'  => 'Created through WP driver',
					'post_status' => 'publish',
				),
			),
			$fresh_driver->query(
				"SELECT ID, post_title, post_status
				FROM wptests_posts
				WHERE post_type = 'post'
				ORDER BY ID"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'meta_id'    => 11,
					'post_id'    => 3,
					'meta_key'   => '_featured',
					'meta_value' => 'yes',
				),
				array(
					'meta_id'    => 12,
					'post_id'    => 4,
					'meta_key'   => '_featured',
					'meta_value' => 'yes',
				),
				array(
					'meta_id'    => 13,
					'post_id'    => 3,
					'meta_key'   => '_score',
					'meta_value' => '10',
				),
			),
			$fresh_driver->query(
				'SELECT meta_id, post_id, meta_key, meta_value
				FROM wptests_postmeta
				ORDER BY meta_id'
			)->fetchAll( PDO::FETCH_ASSOC )
		);
		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'Mutable External Format Site',
				),
				array(
					'option_name'  => 'transient',
					'option_value' => 'skip-me',
				),
			),
			$fresh_driver->query(
				"SELECT option_name, option_value
				FROM wptests_options
				WHERE option_name IN ('blogname', 'transient')
				ORDER BY option_name"
			)->fetchAll( PDO::FETCH_ASSOC )
		);
	}

	private function assert_external_format_serves_wordpress_table_reads( string $format ): void {
		$this->requireDuckDBRuntime();

		$driver = $this->create_driver_with_external_wordpress_views( $format );

		$posts = $driver->query(
			"SELECT SQL_CALC_FOUND_ROWS ID, post_title
			FROM wptests_posts
			WHERE post_type = 'post' AND post_status = 'publish'
			ORDER BY post_date DESC, ID DESC
			LIMIT 10"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'ID'         => 3,
					'post_title' => 'New published post',
				),
				array(
					'ID'         => 1,
					'post_title' => 'Old published post',
				),
			),
			$posts
		);
		$this->assertSame(
			array(
				array(
					'found' => 2,
				),
			),
			$driver->query( 'SELECT FOUND_ROWS() AS found' )->fetchAll( PDO::FETCH_ASSOC )
		);

		$featured_posts = $driver->query(
			"SELECT p.ID, p.post_title, pm.meta_value
			FROM wptests_posts AS p
			INNER JOIN wptests_postmeta AS pm ON p.ID = pm.post_id
			WHERE p.post_type = 'post'
				AND p.post_status = 'publish'
				AND pm.meta_key = '_featured'
				AND pm.meta_value = 'yes'
			ORDER BY p.ID"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'ID'         => 3,
					'post_title' => 'New published post',
					'meta_value' => 'yes',
				),
			),
			$featured_posts
		);

		$options = $driver->query(
			"SELECT option_name, option_value
			FROM wptests_options
			WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')
			ORDER BY option_name"
		)->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'option_name'  => 'blogname',
					'option_value' => 'External Format Site',
				),
				array(
					'option_name'  => 'home',
					'option_value' => 'https://example.test',
				),
				array(
					'option_name'  => 'siteurl',
					'option_value' => 'https://example.test',
				),
			),
			$options
		);
	}

	private function create_driver_with_external_wordpress_views( string $format ): WP_DuckDB_Driver {
		$temp_dir     = $this->create_temp_dir();
		$database     = $temp_dir . '/wordpress.duckdb';
		$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
		$duckdb       = $client_class::create( $database );

		$this->create_native_wordpress_source_tables( $duckdb );
		foreach ( array( 'posts', 'postmeta', 'options' ) as $table ) {
			$path = $temp_dir . '/' . $table . '.' . $format;
			$this->copy_table_to_external_storage( $duckdb, 'source_' . $table, $format, $path );
			$duckdb->query(
				'CREATE VIEW wptests_' . $table . ' AS SELECT * FROM ' .
				$this->read_function_for_format( $format, $path )
			);
		}
		unset( $duckdb );

		return new WP_DuckDB_Driver(
			array(
				'path'     => $database,
				'database' => 'wp',
			)
		);
	}

	private function create_external_wordpress_storage_files( string $format ): array {
		$temp_dir     = $this->create_temp_dir();
		$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
		$duckdb       = $client_class::create();
		$paths        = array();

		$this->create_native_wordpress_source_tables( $duckdb );
		foreach ( array( 'posts', 'postmeta', 'options' ) as $table ) {
			$paths[ $table ] = $temp_dir . '/' . $table . '.' . $format;
			$this->copy_table_to_external_storage( $duckdb, 'source_' . $table, $format, $paths[ $table ] );
		}

		return $paths;
	}

	private function create_mutable_driver_from_external_wordpress_storage( string $format, array $paths, &$duckdb ): WP_DuckDB_Driver {
		$client_class = WP_DuckDB_Runtime::CLIENT_CLASS;
		$duckdb       = $client_class::create();

		foreach ( array( 'posts', 'postmeta', 'options' ) as $table ) {
			$duckdb->query(
				'CREATE TABLE wptests_' . $table . ' AS SELECT * FROM ' .
				$this->read_function_for_format( $format, $paths[ $table ] )
			);
		}

		$connection = new WP_DuckDB_Connection( array( 'duckdb' => $duckdb ) );
		return new WP_DuckDB_Driver(
			array(
				'connection' => $connection,
				'database'   => 'wp',
			)
		);
	}

	private function flush_wordpress_tables_to_external_storage( $duckdb, string $format, array $paths ): void {
		foreach ( array( 'posts', 'postmeta', 'options' ) as $table ) {
			$this->copy_table_to_external_storage( $duckdb, 'wptests_' . $table, $format, $paths[ $table ] );
		}
	}

	private function copy_table_to_external_storage( $duckdb, string $table, string $format, string $path ): void {
		if ( file_exists( $path ) ) {
			unlink( $path );
		}

		$duckdb->query(
			'COPY ' . $table . ' TO ' . $this->duckdb_string_literal( $path ) .
			' (' . $this->copy_options_for_format( $format ) . ')'
		);
	}

	private function create_native_wordpress_source_tables( $duckdb ): void {
		$duckdb->query(
			'CREATE TABLE source_posts (
				ID BIGINT,
				post_author BIGINT,
				post_date VARCHAR,
				post_title VARCHAR,
				post_type VARCHAR,
				post_status VARCHAR
			)'
		);
		$duckdb->query(
			"INSERT INTO source_posts VALUES
				(1, 10, '2026-01-01 00:00:00', 'Old published post', 'post', 'publish'),
				(2, 20, '2026-02-01 00:00:00', 'Draft post', 'post', 'draft'),
				(3, 30, '2026-06-01 00:00:00', 'New published post', 'post', 'publish'),
				(4, 40, '2026-05-01 00:00:00', 'Published page', 'page', 'publish')"
		);

		$duckdb->query(
			'CREATE TABLE source_postmeta (
				meta_id BIGINT,
				post_id BIGINT,
				meta_key VARCHAR,
				meta_value VARCHAR
			)'
		);
		$duckdb->query(
			"INSERT INTO source_postmeta VALUES
				(10, 1, '_featured', 'no'),
				(11, 3, '_featured', 'yes'),
				(12, 4, '_featured', 'yes'),
				(13, 3, '_score', '10')"
		);

		$duckdb->query(
			'CREATE TABLE source_options (
				option_id BIGINT,
				option_name VARCHAR,
				option_value VARCHAR,
				autoload VARCHAR
			)'
		);
		$duckdb->query(
			"INSERT INTO source_options VALUES
				(1, 'siteurl', 'https://example.test', 'yes'),
				(2, 'home', 'https://example.test', 'on'),
				(3, 'blogname', 'External Format Site', 'auto-on'),
				(4, 'transient', 'skip-me', 'no')"
		);
	}

	private function copy_options_for_format( string $format ): string {
		switch ( $format ) {
			case 'parquet':
				return 'FORMAT PARQUET';
			case 'csv':
				return "HEADER, DELIMITER ','";
			case 'json':
				return 'FORMAT JSON';
		}

		throw new InvalidArgumentException( 'Unsupported DuckDB external format: ' . $format );
	}

	private function read_function_for_format( string $format, string $path ): string {
		$path_literal = $this->duckdb_string_literal( $path );
		switch ( $format ) {
			case 'parquet':
				return 'read_parquet(' . $path_literal . ')';
			case 'csv':
				return 'read_csv_auto(' . $path_literal . ', HEADER = true)';
			case 'json':
				return 'read_json_auto(' . $path_literal . ')';
		}

		throw new InvalidArgumentException( 'Unsupported DuckDB external format: ' . $format );
	}

	private function create_temp_dir(): string {
		$temp_dir = sys_get_temp_dir() . '/wp-duckdb-external-format-' . getmypid() . '-' . uniqid( '', true );
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
