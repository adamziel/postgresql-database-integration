<?php
/**
 * Savepoint recorder used by PostgreSQL connection tests.
 *
 * @package wp-postgresql-integration
 */

class WP_PostgreSQL_Connection_Statement_Savepoint_Recording_PDO {
	/**
	 * SQL statements observed by the recorder.
	 *
	 * @var string[]
	 */
	private $statements = array();

	/**
	 * Record a SQL statement.
	 *
	 * @param string $sql SQL statement.
	 */
	public function record( string $sql ): void {
		$this->statements[] = $sql;
	}

	/**
	 * Return all recorded SQL statements.
	 *
	 * @return string[]
	 */
	public function get_statements(): array {
		return $this->statements;
	}

	/**
	 * Return recorded savepoint-related SQL statements.
	 *
	 * @return string[]
	 */
	public function get_savepoint_statements(): array {
		return array_values(
			array_filter(
				$this->statements,
				static function ( string $sql ): bool {
					return 1 === preg_match( '/^\s*(SAVEPOINT|RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+SAVEPOINT)\b/i', $sql );
				}
			)
		);
	}
}
