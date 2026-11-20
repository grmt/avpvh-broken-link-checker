<?php
/**
 * The log file's name, directory protection and level.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

class LoggerTest extends Avpvh_Brkn_Test_Case {

	public function test_log_name_is_random_and_stable(): void {
		delete_option( 'avpvh_brkn_log_token' );

		$name = avpvh_brkn_get_default_log_basename();
		$this->assertMatchesRegularExpression( '/^blc-log-[a-z0-9]{20}\.txt$/', $name );
		$this->assertSame( $name, avpvh_brkn_get_default_log_basename() );

		delete_option( 'avpvh_brkn_log_token' );
		$this->assertNotSame( $name, avpvh_brkn_get_default_log_basename() );
	}

	public function test_log_directory_is_closed_for_apache_22_and_24(): void {
		$dir = avpvh_brkn_get_log_directory();
		if ( is_dir( $dir ) ) {
			foreach ( array_diff( scandir( $dir ) ?: array(), array( '.', '..' ) ) as $file ) {
				unlink( $dir . '/' . $file );
			}
			@rmdir( $dir );
		}

		$this->assertTrue( (bool) avpvh_brkn_prepare_log_directory() );

		$htaccess = (string) file_get_contents( $dir . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
		$this->assertStringContainsString( 'Deny from all', $htaccess );
		$this->assertFileExists( $dir . '/index.php' );
	}

	public function test_file_logger_skips_debug_lines(): void {
		$file   = get_temp_dir() . 'avpvh-brkn-test.log';
		@unlink( $file );
		$logger = new Avpvh_Brkn_File_Logger( $file );

		$logger->debug( 'SELECT secret' );
		$logger->info( 'checked' );

		$log = file_exists( $file ) ? (string) file_get_contents( $file ) : '';
		$this->assertStringNotContainsString( 'SELECT secret', $log );
		$this->assertStringContainsString( 'checked', $log );
		@unlink( $file );
	}
}
