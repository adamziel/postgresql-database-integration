#!/usr/bin/env php
<?php declare(strict_types = 1);

/**
 * DuckDB production-readiness evidence harness.
 *
 * The harness writes aggregation-friendly JSONL plus a summary JSON file. It is
 * intentionally broader than PHPUnit: some scenarios are negative evidence or
 * operational probes rather than unit assertions.
 */

error_reporting( E_ALL );

$repo_dir = dirname( __DIR__ );

$autoload_candidates = array(
	getenv( 'WP_DUCKDB_AUTOLOAD' ),
	getenv( 'DUCKDB_PHP_AUTOLOAD' ),
	$repo_dir . '/vendor/autoload.php',
);

foreach ( $autoload_candidates as $autoload ) {
	if ( is_string( $autoload ) && '' !== $autoload && file_exists( $autoload ) ) {
		require_once $autoload;
		break;
	}
}

require_once $repo_dir . '/wp-includes/database/load.php';

class WP_DuckDB_Readiness_Skip extends RuntimeException {}

class WP_DuckDB_Readiness_Runner {
	private $repo_dir;
	private $run_id;
	private $output_dir;
	private $work_dir;
	private $events_file;
	private $started_at;
	private $events = array();

	public function __construct( string $repo_dir ) {
		$this->repo_dir   = $repo_dir;
		$this->started_at = microtime( true );
		$this->run_id     = gmdate( 'YmdHis' ) . '-' . substr( $this->git_sha(), 0, 12 ) . '-' . getmypid();

		$output_root = getenv( 'WP_DUCKDB_READINESS_OUTPUT_DIR' );
		if ( ! is_string( $output_root ) || '' === $output_root ) {
			$output_root = $repo_dir . '/artifacts/duckdb-production-readiness/runs/' . $this->run_id;
		}

		$work_root = getenv( 'WP_DUCKDB_READINESS_WORK_DIR' );
		if ( ! is_string( $work_root ) || '' === $work_root ) {
			$work_root = sys_get_temp_dir() . '/wp-duckdb-production-readiness-work/' . $this->run_id;
		}

		$this->output_dir  = rtrim( $output_root, '/\\' );
		$this->work_dir    = rtrim( $work_root, '/\\' );
		$this->events_file = $this->output_dir . '/events.jsonl';

		$this->mkdir( $this->output_dir );
		$this->mkdir( $this->work_dir );
	}

	public function run(): int {
		WP_DuckDB_Runtime::assert_available( true );

		$this->scenario( 'native_backup_restore', array( $this, 'scenario_native_backup_restore' ) );
		$this->scenario( 'external_json_backup_restore_with_manifest', array( $this, 'scenario_external_json_backup_restore_with_manifest' ) );
		$this->scenario( 'external_json_plugin_table_discovery', array( $this, 'scenario_external_json_plugin_table_discovery' ) );
		$this->scenario( 'external_json_manifest_missing_negative', array( $this, 'scenario_external_json_manifest_missing_negative' ) );
		$this->scenario( 'external_json_flush_failure_atomicity', array( $this, 'scenario_external_json_flush_failure_atomicity' ) );
		$this->scenario( 'external_json_concurrent_serialized_writes', array( $this, 'scenario_external_json_concurrent_serialized_writes' ) );
		$this->scenario( 'native_large_table_load', array( $this, 'scenario_native_large_table_load' ) );
		$this->scenario( 'external_json_schema_upgrade_cold_reload', array( $this, 'scenario_external_json_schema_upgrade_cold_reload' ) );
		$this->scenario( 'wordpress_smoke_native_duckdb', array( $this, 'scenario_wordpress_smoke_native_duckdb' ) );

		$summary = $this->summary();
		file_put_contents( $this->output_dir . '/summary.json', json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

		echo 'DuckDB production-readiness run: ' . $this->run_id . "\n";
		echo 'Artifacts: ' . $this->output_dir . "\n";
		echo 'Summary: ' . $this->output_dir . "/summary.json\n";
		echo 'Events: ' . $this->events_file . "\n";
		echo 'Status counts: ' . json_encode( $summary['status_counts'] ) . "\n";
		echo 'Readiness counts: ' . json_encode( $summary['readiness_counts'] ) . "\n";

		return $summary['status_counts']['fail'] > 0 ? 1 : 0;
	}

	public function scenario_native_backup_restore(): array {
		$dir      = $this->work_dir . '/native-backup-restore';
		$db       = $dir . '/site.duckdb';
		$backup   = $dir . '/backup.duckdb';
		$restored = $dir . '/restored.duckdb';
		$this->mkdir( $dir );

		$storage = new WP_DuckDB_Storage_Backend( array( 'backend' => 'duckdb', 'database_path' => $db ) );
		$driver  = $storage->create_driver( 'wp' );
		$this->create_options_table( $driver );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('backup_marker', 'native-ok', 'yes')" );
		unset( $driver, $storage );

		$this->copy_file_if_exists( $db, $backup );
		$this->copy_file_if_exists( $db . '.wal', $backup . '.wal' );
		$this->copy_file_if_exists( $backup, $restored );
		$this->copy_file_if_exists( $backup . '.wal', $restored . '.wal' );

		$restored_storage = new WP_DuckDB_Storage_Backend( array( 'backend' => 'duckdb', 'database_path' => $restored ) );
		$restored_driver  = $restored_storage->create_driver( 'wp' );
		$value            = $restored_driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'backup_marker'" )->fetchColumn();

		$this->assert_true( 'native-ok' === $value, 'Restored native DuckDB file did not contain backup marker.' );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array(
				'database_bytes' => is_file( $db ) ? filesize( $db ) : null,
				'backup_bytes'   => is_file( $backup ) ? filesize( $backup ) : null,
			),
			'observations' => array( 'Native DuckDB database file could be copied and reopened from the backup path.' ),
		);
	}

	public function scenario_external_json_backup_restore_with_manifest(): array {
		$dir          = $this->work_dir . '/external-json-backup-restore';
		$external    = $dir . '/external';
		$restore_ext = $dir . '/restored-external';
		$this->mkdir( $dir );

		$storage = $this->json_storage( $dir . '/working.duckdb', $external );
		$driver  = $storage->create_driver( 'wp' );
		$this->create_options_table( $driver );
		$this->create_plugin_table( $driver );
		$storage->flush();
		unset( $driver, $storage );

		$this->copy_dir( $external, $restore_ext );
		$restored_storage = $this->json_storage( $dir . '/restored.duckdb', $restore_ext );
		$restored_driver  = $restored_storage->create_driver( 'wp' );

		$option_count = (int) $restored_driver->query( 'SELECT COUNT(*) FROM wptests_options' )->fetchColumn();
		$plugin_count = (int) $restored_driver->query( 'SELECT COUNT(*) FROM wptests_plugin_log' )->fetchColumn();

		$this->assert_true( 3 === $option_count, 'Restored external JSON option count mismatch.' );
		$this->assert_true( 1 === $plugin_count, 'Restored external JSON plugin table count mismatch.' );
		$this->assert_true( is_file( $restore_ext . '/' . WP_DuckDB_Storage_Backend::METADATA_MANIFEST_FILE ), 'Metadata manifest was not included in restored external JSON backup.' );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array(
				'option_rows' => $option_count,
				'plugin_rows' => $plugin_count,
				'files'       => count( glob( $restore_ext . '/*' ) ?: array() ),
			),
			'observations' => array( 'External JSON table files plus the metadata manifest restored into a fresh working database.' ),
		);
	}

	public function scenario_external_json_plugin_table_discovery(): array {
		$dir      = $this->work_dir . '/plugin-table-discovery';
		$external = $dir . '/external';
		$this->mkdir( $dir );

		$storage = $this->json_storage( $dir . '/working.duckdb', $external );
		$driver  = $storage->create_driver( 'wp' );
		$this->create_plugin_table( $driver );
		$storage->flush();
		unset( $driver, $storage );
		$this->remove_database_files( $dir . '/working.duckdb' );

		$fresh_storage = $this->json_storage( $dir . '/fresh.duckdb', $external );
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );
		$message       = $fresh_driver->query( 'SELECT message FROM wptests_plugin_log WHERE id = 1' )->fetchColumn();

		$this->assert_true( 'plugin table row' === $message, 'Plugin-created table was not discovered after cold reload.' );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array( 'discovered_plugin_tables' => 1 ),
			'observations' => array( 'Local path-based external storage discovered a plugin-created table without an explicit table list.' ),
		);
	}

	public function scenario_external_json_manifest_missing_negative(): array {
		$dir          = $this->work_dir . '/manifest-missing';
		$external    = $dir . '/external';
		$no_manifest = $dir . '/external-no-manifest';
		$this->mkdir( $dir );

		$storage = $this->json_storage( $dir . '/working.duckdb', $external );
		$driver  = $storage->create_driver( 'wp' );
		$this->create_options_table( $driver );
		$storage->flush();
		unset( $driver, $storage );

		$this->copy_dir( $external, $no_manifest );
		@unlink( $no_manifest . '/' . WP_DuckDB_Storage_Backend::METADATA_MANIFEST_FILE );

		$fresh_storage = $this->json_storage( $dir . '/fresh.duckdb', $no_manifest );
		$fresh_driver  = $fresh_storage->create_driver( 'wp' );

		$upsert_error = null;
		try {
			$fresh_driver->query(
				"INSERT INTO wptests_options (option_name, option_value, autoload)
				VALUES ('blogname', 'changed-without-manifest', 'yes')
				ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
			);
		} catch ( Throwable $e ) {
			$upsert_error = get_class( $e ) . ': ' . $e->getMessage();
		}

		return array(
			'readiness'    => null === $upsert_error ? 'pass' : 'warn',
			'metrics'      => array(
				'manifest_present'       => false,
				'upsert_preserved_index' => null === $upsert_error,
			),
			'observations' => array(
				null === $upsert_error
					? 'Missing manifest did not break this simple unique-key upsert because the JSON source still had enough rows for schema inference.'
					: 'Missing metadata manifest reproduced a real operational risk: unique-key upserts are not safe without durable manifest backup.',
				$upsert_error ?: 'No upsert error was raised without the metadata manifest.',
			),
		);
	}

	public function scenario_external_json_flush_failure_atomicity(): array {
		$dir      = $this->work_dir . '/flush-failure-atomicity';
		$external = $dir . '/external';
		$this->mkdir( $dir );

		$storage = $this->json_storage( $dir . '/working.duckdb', $external );
		$driver  = $storage->create_driver( 'wp' );
		$this->create_options_table( $driver );
		$storage->flush();
		unset( $driver, $storage );

		$failing_storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $dir . '/failing.duckdb',
				'external_storage_dir' => $external,
				'write_sql_template'   => "COPY (SELECT error('forced readiness flush failure') AS forced_failure FROM {table} LIMIT 1) TO {path} (FORMAT JSON)",
			)
		);
		$failing_driver  = $failing_storage->create_driver( 'wp' );
		$failing_driver->query( "UPDATE wptests_options SET option_value = 'changed-before-failing-flush' WHERE option_name = 'blogname'" );

		$flush_error = null;
		try {
			$failing_storage->flush();
		} catch ( Throwable $e ) {
			$flush_error = get_class( $e ) . ': ' . $e->getMessage();
		}
		unset( $failing_driver, $failing_storage );

		$this->assert_true( null !== $flush_error, 'Expected forced flush failure.' );

		$verify_storage = $this->json_storage( $dir . '/verify.duckdb', $external );
		$verify_driver  = $verify_storage->create_driver( 'wp' );
		$value          = $verify_driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'blogname'" )->fetchColumn();

		$this->assert_true( 'DuckDB Readiness Site' === $value, 'Atomic flush failure changed durable external JSON data.' );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array( 'forced_flush_error' => true ),
			'observations' => array(
				'Simulated failed flush preserved the previous local external JSON file contents.',
				'This is not an OS-level kill test; it exercises the configured atomic local write path.',
				$flush_error,
			),
		);
	}

	public function scenario_external_json_concurrent_serialized_writes(): array {
		$dir        = $this->work_dir . '/concurrent-json';
		$external   = $dir . '/external';
		$db         = $dir . '/shared-working.duckdb';
		$workers    = $this->env_int( 'WP_DUCKDB_READINESS_CONCURRENCY_WORKERS', 4 );
		$iterations = $this->env_int( 'WP_DUCKDB_READINESS_CONCURRENCY_ITERATIONS', 8 );
		$this->mkdir( $dir );

		$storage = $this->json_storage( $db, $external );
		$driver  = $storage->create_driver( 'wp' );
		$this->create_options_table( $driver );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('counter', '0', 'yes')" );
		$storage->flush();
		unset( $driver, $storage );

		$processes = array();
		for ( $i = 0; $i < $workers; ++$i ) {
			$processes[] = $this->start_worker(
				array(
					'WP_DUCKDB_READINESS_WORKER'                 => 'concurrency',
					'WP_DUCKDB_READINESS_WORKER_DATABASE'        => $db,
					'WP_DUCKDB_READINESS_WORKER_EXTERNAL_DIR'    => $external,
					'WP_DUCKDB_READINESS_WORKER_ITERATIONS'      => (string) $iterations,
					'WP_DUCKDB_READINESS_WORKER_ID'              => (string) $i,
					'WP_DUCKDB_READINESS_WORKER_LOCK_TIMEOUT'    => '60',
					'WP_DUCKDB_AUTOLOAD'                         => $this->autoload_path(),
					'DUCKDB_PHP_AUTOLOAD'                        => $this->autoload_path(),
				)
			);
		}

		$worker_failures = array();
		foreach ( $processes as $process ) {
			$stdout = stream_get_contents( $process['pipes'][1] );
			$stderr = stream_get_contents( $process['pipes'][2] );
			fclose( $process['pipes'][1] );
			fclose( $process['pipes'][2] );
			$code = proc_close( $process['proc'] );
			if ( 0 !== $code ) {
				$worker_failures[] = array(
					'code'   => $code,
					'stdout' => $stdout,
					'stderr' => $stderr,
				);
			}
		}

		$this->assert_true( array() === $worker_failures, 'One or more concurrency workers failed: ' . json_encode( $worker_failures ) );

		$verify_storage = $this->json_storage( $db, $external );
		$verify_driver  = $verify_storage->create_driver( 'wp' );
		$counter        = (int) $verify_driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'counter'" )->fetchColumn();
		$expected       = $workers * $iterations;

		$this->assert_true( $expected === $counter, 'Concurrent serialized writes lost updates.' );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array(
				'workers'             => $workers,
				'iterations_per_worker' => $iterations,
				'expected_counter'    => $expected,
				'actual_counter'      => $counter,
			),
			'observations' => array( 'Separate PHP processes serialized JSON external backend writes through the DuckDB working-database lock.' ),
		);
	}

	public function scenario_native_large_table_load(): array {
		$dir       = $this->work_dir . '/large-table';
		$db        = $dir . '/large.duckdb';
		$rows      = $this->env_int( 'WP_DUCKDB_READINESS_LARGE_ROWS', 5000 );
		$chunk     = 250;
		$insert_ms = 0.0;
		$this->mkdir( $dir );

		$storage = new WP_DuckDB_Storage_Backend( array( 'backend' => 'duckdb', 'database_path' => $db ) );
		$driver  = $storage->create_driver( 'wp' );
		$driver->query(
			"CREATE TABLE wptests_large_posts (
				ID bigint(20) unsigned NOT NULL,
				post_title varchar(255) NOT NULL DEFAULT '',
				post_status varchar(20) NOT NULL DEFAULT 'publish',
				PRIMARY KEY (ID)
			)"
		);

		$insert_start = microtime( true );
		for ( $offset = 1; $offset <= $rows; $offset += $chunk ) {
			$values = array();
			$limit  = min( $rows, $offset + $chunk - 1 );
			for ( $id = $offset; $id <= $limit; ++$id ) {
				$values[] = '(' . $id . ', ' . $this->sql_string( 'Large post ' . $id ) . ", 'publish')";
			}
			$driver->query( 'INSERT INTO wptests_large_posts (ID, post_title, post_status) VALUES ' . implode( ', ', $values ) );
		}
		$insert_ms = $this->elapsed_ms( $insert_start );

		$query_start = microtime( true );
		$count       = (int) $driver->query( 'SELECT COUNT(*) FROM wptests_large_posts' )->fetchColumn();
		$last_title  = $driver->query( 'SELECT post_title FROM wptests_large_posts ORDER BY ID DESC LIMIT 1' )->fetchColumn();
		$query_ms    = $this->elapsed_ms( $query_start );

		$this->assert_true( $rows === $count, 'Large table row count mismatch.' );
		$this->assert_true( 'Large post ' . $rows === $last_title, 'Large table final row mismatch.' );

		return array(
			'readiness'    => $rows >= 5000 ? 'pass' : 'warn',
			'metrics'      => array(
				'rows'      => $rows,
				'insert_ms' => $insert_ms,
				'query_ms'  => $query_ms,
			),
			'observations' => array( 'Native DuckDB backend handled a bounded large-table insert/query workload.' ),
		);
	}

	public function scenario_external_json_schema_upgrade_cold_reload(): array {
		$dir      = $this->work_dir . '/schema-upgrade';
		$external = $dir . '/external';
		$this->mkdir( $dir );

		$storage = $this->json_storage( $dir . '/working.duckdb', $external );
		$driver  = $storage->create_driver( 'wp' );
		$driver->query(
			"CREATE TABLE wptests_plugin_data (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY (id)
			)"
		);
		$driver->query( "INSERT INTO wptests_plugin_data (name) VALUES ('before-upgrade')" );
		$storage->flush();
		unset( $driver, $storage );

		$upgrade_storage = $this->json_storage( $dir . '/upgrade.duckdb', $external );
		$upgrade_driver  = $upgrade_storage->create_driver( 'wp' );
		$upgrade_driver->query( "ALTER TABLE wptests_plugin_data ADD COLUMN extra varchar(100) NOT NULL DEFAULT 'none'" );
		$upgrade_driver->query( "INSERT INTO wptests_plugin_data (name, extra) VALUES ('after-upgrade', 'extra-value')" );
		$upgrade_storage->flush();
		unset( $upgrade_driver, $upgrade_storage );

		$final_storage = $this->json_storage( $dir . '/final.duckdb', $external );
		$final_driver  = $final_storage->create_driver( 'wp' );
		$rows          = $final_driver->query( 'SELECT id, name, extra FROM wptests_plugin_data ORDER BY id' )->fetchAll( PDO::FETCH_ASSOC );

		$this->assert_true( 2 === count( $rows ), 'Schema upgrade row count mismatch after cold reload.' );
		$this->assert_true( 'none' === $rows[0]['extra'], 'Existing row did not receive upgraded default value.' );
		$this->assert_true( 'extra-value' === $rows[1]['extra'], 'Inserted upgraded row did not persist after cold reload.' );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array( 'rows_after_upgrade' => count( $rows ) ),
			'observations' => array( 'External JSON backend preserved ALTER TABLE ADD COLUMN metadata and values across a cold reload.' ),
		);
	}

	public function scenario_wordpress_smoke_native_duckdb(): array {
		if ( '1' === getenv( 'WP_DUCKDB_READINESS_SKIP_WORDPRESS_SMOKE' ) ) {
			throw new WP_DuckDB_Readiness_Skip( 'Skipped by WP_DUCKDB_READINESS_SKIP_WORDPRESS_SMOKE=1.' );
		}
		if ( ! is_executable( $this->repo_dir . '/bin/duckdb-wordpress-plugin-smoke.sh' ) ) {
			throw new WP_DuckDB_Readiness_Skip( 'WordPress plugin smoke script is not executable.' );
		}

		$log_file = $this->output_dir . '/wordpress-smoke-native-duckdb.log';
		$work_dir = $this->work_dir . '/wordpress-smoke';
		$env      = array(
			'WORDPRESS_VERSION'             => getenv( 'WORDPRESS_VERSION' ) ?: '7.0',
			'WOOCOMMERCE_VERSION'           => getenv( 'WOOCOMMERCE_VERSION' ) ?: '10.9.1',
			'QUERY_MONITOR_VERSION'         => getenv( 'QUERY_MONITOR_VERSION' ) ?: '4.0.7',
			'WP_DUCKDB_PLUGIN_SMOKE_DIR'    => $work_dir,
			'WP_DUCKDB_PLUGIN_SMOKE_CACHE'  => getenv( 'WP_DUCKDB_PLUGIN_SMOKE_CACHE' ) ?: sys_get_temp_dir() . '/wp-duckdb-plugin-smoke-cache',
		);

		$result = $this->run_process( './bin/duckdb-wordpress-plugin-smoke.sh duckdb', $env, $log_file, 180 );
		$this->assert_true( 0 === $result['code'], 'WordPress native DuckDB smoke failed; see ' . $log_file );

		return array(
			'readiness'    => 'pass',
			'metrics'      => array(
				'exit_code'    => $result['code'],
				'duration_ms'  => $result['duration_ms'],
				'log_bytes'    => is_file( $log_file ) ? filesize( $log_file ) : null,
			),
			'artifacts'    => array( 'log' => $log_file ),
			'observations' => array( 'Existing full WordPress smoke passed against the native DuckDB backend.' ),
		);
	}

	private function scenario( string $name, callable $callback ): void {
		$started = microtime( true );
		try {
			$result    = $callback();
			$status    = $result['status'] ?? 'pass';
			$readiness = $result['readiness'] ?? ( 'pass' === $status ? 'pass' : 'fail' );
			$event     = array(
				'schema_version' => 1,
				'run_id'         => $this->run_id,
				'timestamp'      => gmdate( 'c' ),
				'scenario'       => $name,
				'status'         => $status,
				'readiness'      => $readiness,
				'duration_ms'    => $this->elapsed_ms( $started ),
				'metrics'        => $result['metrics'] ?? array(),
				'observations'   => $result['observations'] ?? array(),
				'artifacts'      => $result['artifacts'] ?? array(),
			);
		} catch ( WP_DuckDB_Readiness_Skip $e ) {
			$event = array(
				'schema_version' => 1,
				'run_id'         => $this->run_id,
				'timestamp'      => gmdate( 'c' ),
				'scenario'       => $name,
				'status'         => 'skipped',
				'readiness'      => 'unknown',
				'duration_ms'    => $this->elapsed_ms( $started ),
				'metrics'        => array(),
				'observations'   => array( $e->getMessage() ),
				'artifacts'      => array(),
			);
		} catch ( Throwable $e ) {
			$event = array(
				'schema_version' => 1,
				'run_id'         => $this->run_id,
				'timestamp'      => gmdate( 'c' ),
				'scenario'       => $name,
				'status'         => 'fail',
				'readiness'      => 'fail',
				'duration_ms'    => $this->elapsed_ms( $started ),
				'metrics'        => array(),
				'observations'   => array( $e->getMessage() ),
				'artifacts'      => array(),
				'error'          => array(
					'class'   => get_class( $e ),
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				),
			);
		}

		$this->record( $event );
	}

	private function record( array $event ): void {
		$this->events[] = $event;
		file_put_contents( $this->events_file, json_encode( $event, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND );
		printf(
			"%-48s status=%-7s readiness=%s duration_ms=%.3f\n",
			$event['scenario'],
			$event['status'],
			$event['readiness'],
			$event['duration_ms']
		);
	}

	private function summary(): array {
		$status_counts    = array( 'pass' => 0, 'fail' => 0, 'skipped' => 0 );
		$readiness_counts = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'unknown' => 0 );

		foreach ( $this->events as $event ) {
			$status_counts[ $event['status'] ]       = ( $status_counts[ $event['status'] ] ?? 0 ) + 1;
			$readiness_counts[ $event['readiness'] ] = ( $readiness_counts[ $event['readiness'] ] ?? 0 ) + 1;
		}

		return array(
			'schema_version'   => 1,
			'run_id'           => $this->run_id,
			'started_at'       => gmdate( 'c', (int) $this->started_at ),
			'completed_at'     => gmdate( 'c' ),
			'duration_ms'      => $this->elapsed_ms( $this->started_at ),
			'git_sha'          => $this->git_sha(),
			'php_version'      => PHP_VERSION,
			'host'             => gethostname(),
			'output_dir'       => $this->output_dir,
			'work_dir'         => $this->work_dir,
			'events_file'      => $this->events_file,
			'status_counts'    => $status_counts,
			'readiness_counts' => $readiness_counts,
			'events'           => $this->events,
		);
	}

	private function create_options_table( WP_DuckDB_Driver $driver ): void {
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
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('siteurl', 'https://example.test', 'yes')" );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('blogname', 'DuckDB Readiness Site', 'yes')" );
		$driver->query( "INSERT INTO wptests_options (option_name, option_value, autoload) VALUES ('admin_email', 'admin@example.test', 'yes')" );
	}

	private function create_plugin_table( WP_DuckDB_Driver $driver ): void {
		$driver->query(
			"CREATE TABLE wptests_plugin_log (
				id bigint(20) unsigned NOT NULL,
				message varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY (id)
			)"
		);
		$driver->query( "INSERT INTO wptests_plugin_log (id, message) VALUES (1, 'plugin table row')" );
	}

	private function json_storage( string $database, string $external_dir ): WP_DuckDB_Storage_Backend {
		return new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external_dir,
			)
		);
	}

	private function start_worker( array $env ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' -d ffi.enable=1 ' . escapeshellarg( __FILE__ ) . ' --worker';
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = proc_open( $cmd, $descriptors, $pipes, $this->repo_dir, $env );
		if ( ! is_resource( $proc ) ) {
			throw new RuntimeException( 'Failed to start readiness worker.' );
		}
		fclose( $pipes[0] );

		return array(
			'proc'  => $proc,
			'pipes' => $pipes,
		);
	}

	private function run_process( string $command, array $env, string $log_file, int $timeout_seconds ): array {
		$started = microtime( true );
		$full_env = array_merge(
			array(
				'PATH'                  => getenv( 'PATH' ) ?: '',
				'HOME'                  => getenv( 'HOME' ) ?: '',
				'WP_DUCKDB_AUTOLOAD'    => $this->autoload_path(),
				'DUCKDB_PHP_AUTOLOAD'   => $this->autoload_path(),
			),
			$env
		);
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = proc_open( 'bash -lc ' . escapeshellarg( $command ), $descriptors, $pipes, $this->repo_dir, $full_env );
		if ( ! is_resource( $proc ) ) {
			throw new RuntimeException( 'Failed to start process: ' . $command );
		}
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output = '';
		$timed_out = false;
		$exit_code = null;
		while ( true ) {
			$status = proc_get_status( $proc );
			$output .= $this->read_pipe( $pipes[1] );
			$output .= $this->read_pipe( $pipes[2] );
			if ( ! $status['running'] ) {
				$exit_code = $status['exitcode'];
				break;
			}
			if ( microtime( true ) - $started > $timeout_seconds ) {
				$timed_out = true;
				proc_terminate( $proc );
				usleep( 500000 );
				$status = proc_get_status( $proc );
				if ( $status['running'] ) {
					proc_terminate( $proc, 9 );
				}
				break;
			}
			usleep( 100000 );
		}
		$output .= $this->read_pipe( $pipes[1] );
		$output .= $this->read_pipe( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$close_code = proc_close( $proc );
		$code       = null !== $exit_code && $exit_code >= 0 ? $exit_code : $close_code;
		if ( $timed_out ) {
			$code = 124;
			$output .= "\nTimed out after {$timeout_seconds} seconds.\n";
		}
		file_put_contents( $log_file, $output );

		return array(
			'code'        => $code,
			'duration_ms' => $this->elapsed_ms( $started ),
		);
	}

	/**
	 * Read all currently available data from a non-blocking process pipe.
	 *
	 * @param resource $pipe Process pipe.
	 * @return string Available output.
	 */
	private function read_pipe( $pipe ): string {
		$output = '';
		while ( ! feof( $pipe ) ) {
			$chunk = fread( $pipe, 8192 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$output .= $chunk;
		}

		return $output;
	}

	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	private function env_int( string $name, int $default ): int {
		$value = getenv( $name );
		if ( ! is_string( $value ) || '' === $value || ! is_numeric( $value ) ) {
			return $default;
		}

		return max( 1, (int) $value );
	}

	private function elapsed_ms( float $started ): float {
		return round( ( microtime( true ) - $started ) * 1000, 3 );
	}

	private function sql_string( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	private function mkdir( string $dir ): void {
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( 'Failed to create directory: ' . $dir );
		}
	}

	private function copy_file_if_exists( string $source, string $target ): void {
		if ( is_file( $source ) && ! copy( $source, $target ) ) {
			throw new RuntimeException( 'Failed to copy ' . $source . ' to ' . $target );
		}
	}

	private function copy_dir( string $source, string $target ): void {
		$this->mkdir( $target );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $path ) {
			$dest = $target . '/' . $iterator->getSubPathName();
			if ( $path->isDir() ) {
				$this->mkdir( $dest );
			} elseif ( ! copy( $path->getPathname(), $dest ) ) {
				throw new RuntimeException( 'Failed to copy ' . $path->getPathname() . ' to ' . $dest );
			}
		}
	}

	private function remove_database_files( string $database ): void {
		foreach ( array( $database, $database . '.wal', $database . '.lock', $database . '.tmp' ) as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	private function git_sha(): string {
		$output = array();
		$code   = 0;
		exec( 'git -C ' . escapeshellarg( $this->repo_dir ) . ' rev-parse HEAD 2>/dev/null', $output, $code );
		return 0 === $code && isset( $output[0] ) ? $output[0] : 'unknown';
	}

	private function autoload_path(): string {
		$autoload = getenv( 'WP_DUCKDB_AUTOLOAD' );
		if ( is_string( $autoload ) && '' !== $autoload ) {
			return $autoload;
		}
		return $this->repo_dir . '/vendor/autoload.php';
	}
}

function wp_duckdb_readiness_run_worker(): int {
	$type = getenv( 'WP_DUCKDB_READINESS_WORKER' );
	if ( 'concurrency' !== $type ) {
		fwrite( STDERR, "Unknown readiness worker type.\n" );
		return 2;
	}

	$database   = getenv( 'WP_DUCKDB_READINESS_WORKER_DATABASE' );
	$external   = getenv( 'WP_DUCKDB_READINESS_WORKER_EXTERNAL_DIR' );
	$iterations = (int) ( getenv( 'WP_DUCKDB_READINESS_WORKER_ITERATIONS' ) ?: 1 );
	$lock       = (float) ( getenv( 'WP_DUCKDB_READINESS_WORKER_LOCK_TIMEOUT' ) ?: 60 );

	if ( ! is_string( $database ) || '' === $database || ! is_string( $external ) || '' === $external ) {
		fwrite( STDERR, "Missing worker database or external storage path.\n" );
		return 2;
	}

	for ( $i = 0; $i < $iterations; ++$i ) {
		$storage = new WP_DuckDB_Storage_Backend(
			array(
				'backend'              => 'json',
				'database_path'        => $database,
				'external_storage_dir' => $external,
				'lock_timeout_seconds' => $lock,
			)
		);
		$driver  = $storage->create_driver( 'wp' );
		$current = (int) $driver->query( "SELECT option_value FROM wptests_options WHERE option_name = 'counter'" )->fetchColumn();
		$next    = $current + 1;
		$driver->query( "UPDATE wptests_options SET option_value = '" . $next . "' WHERE option_name = 'counter'" );
		$storage->flush();
		unset( $driver );
		$storage->close();
		unset( $storage );
	}

	echo json_encode(
		array(
			'worker'     => getenv( 'WP_DUCKDB_READINESS_WORKER_ID' ),
			'iterations' => $iterations,
		),
		JSON_UNESCAPED_SLASHES
	) . "\n";

	return 0;
}

if ( in_array( '--worker', $argv, true ) ) {
	exit( wp_duckdb_readiness_run_worker() );
}

$runner = new WP_DuckDB_Readiness_Runner( $repo_dir );
exit( $runner->run() );
