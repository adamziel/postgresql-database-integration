<?php
/**
 * Tests for MySQL CREATE TABLE to PostgreSQL DDL translation.
 *
 * @package wp-postgresql-integration
 */

class WP_PostgreSQL_Create_Table_Translator_Tests extends WP_PostgreSQL_Test_Case {
	public function test_extracts_mysql_create_table_statements_from_schema(): void {
		$translator = new WP_PostgreSQL_Create_Table_Translator();
		$schema     = "
			CREATE TABLE wp_options (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				PRIMARY KEY (option_id)
			) DEFAULT CHARACTER SET utf8mb4;
			INSERT INTO wp_options VALUES (1, 'siteurl');
			CREATE TABLE wp_posts (
				ID bigint(20) unsigned NOT NULL auto_increment,
				post_title text NOT NULL,
				PRIMARY KEY (ID)
			) DEFAULT CHARACTER SET utf8mb4;
		";

		$statements = $translator->extract_create_table_statements( $schema );

		$this->assertCount( 2, $statements );
		$this->assertStringStartsWith( 'CREATE TABLE wp_options', trim( $statements[0] ) );
		$this->assertStringStartsWith( 'CREATE TABLE wp_posts', trim( $statements[1] ) );
	}

	public function test_translates_wordpress_table_to_executable_postgresql_ddl(): void {
		$translator = new WP_PostgreSQL_Create_Table_Translator();
		$statements = $translator->translate_schema(
			"CREATE TABLE wp_options (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL default 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
		);

		$this->assertNotEmpty( $statements );
		$this->assertStringStartsWith( 'CREATE TABLE "wp_options"', $statements[0] );
		$this->assertStringContainsString( '"option_name"', implode( "\n", $statements ) );
		$this->assertStringContainsString( 'CREATE INDEX', implode( "\n", $statements ) );

		$pdo = $this->create_isolated_pdo();
		foreach ( $statements as $statement ) {
			$pdo->exec( $statement );
		}

		$count = $pdo->query( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'wp_options'" )->fetchColumn();
		$this->assertSame( '1', (string) $count );
	}

	public function test_extracts_schema_metadata_for_indexes_and_collations(): void {
		$translator = new WP_PostgreSQL_Create_Table_Translator();
		$metadata   = $translator->extract_schema_metadata(
			"CREATE TABLE wp_terms (
				term_id bigint(20) unsigned NOT NULL auto_increment,
				name varchar(200) NOT NULL default '',
				slug varchar(200) NOT NULL default '',
				term_group bigint(10) NOT NULL default 0,
				PRIMARY KEY (term_id),
				KEY slug (slug(191)),
				KEY name (name(191))
			) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
			true
		);

		$this->assertCount( 1, $metadata );
		$this->assertSame( 'wp_terms', $metadata[0]['table_name'] );
		$this->assertArrayHasKey( 'columns', $metadata[0] );
		$this->assertArrayHasKey( 'indexes', $metadata[0] );
		$this->assertSame( 'varchar(200)', $metadata[0]['columns'][1]['type'] );
		$this->assertSame( 'slug', $metadata[0]['indexes'][1]['name'] );
	}

	public function test_rejects_create_table_as_select(): void {
		$translator = new WP_PostgreSQL_Create_Table_Translator();

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'CREATE TABLE ... AS SELECT is not supported' );

		$translator->translate_schema( 'CREATE TABLE wp_copy AS SELECT 1 AS id' );
	}
}
