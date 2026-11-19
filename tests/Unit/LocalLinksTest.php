<?php
/**
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass( Avpvh_Brkn_Local_Links::class )]
final class LocalLinksTest extends TestCase {

	private const HOME = 'https://www.example.nl';

	protected function setUp(): void {
		wp_stub_reset();
		$uploads = WP_CONTENT_DIR . '/uploads/private/2026';
		if ( ! is_dir( $uploads ) ) {
			mkdir( $uploads, 0777, true );
		}
		file_put_contents( $uploads . '/foto.jpg', 'x' );
		file_put_contents( dirname( WP_CONTENT_DIR ) . '/outside.txt', 'x' );
	}

	/**
	 * A broken HTTP result as the checker returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function broken( int $code, array $extra = array() ): array {
		return $extra + array(
			'broken'          => true,
			'warning'         => false,
			'timeout'         => false,
			'http_code'       => $code,
			'final_http_code' => $code,
			'log'             => 'headers',
		);
	}

	public static function local_hosts(): array {
		return array(
			'home'           => array( 'https://www.example.nl/a', true ),
			'without www'    => array( 'http://example.nl/a', true ),
			'upper case'     => array( 'https://WWW.EXAMPLE.NL/', true ),
			'other host'     => array( 'https://example.org/a', false ),
			'look-alike'     => array( 'https://notexample.nl/a', false ),
			'subdomain'      => array( 'https://shop.example.nl/a', false ),
			'no host'        => array( '/relative/path', false ),
		);
	}

	#[DataProvider( 'local_hosts' )]
	public function test_is_local( string $url, bool $local ): void {
		$this->assertSame( $local, Avpvh_Brkn_Local_Links::is_local( $url ) );
	}

	public function test_site_url_counts_as_local(): void {
		$GLOBALS['wp_stub']['site'] = 'https://cms.example.org';
		$this->assertTrue( Avpvh_Brkn_Local_Links::is_local( 'https://cms.example.org/wp-admin/' ) );
	}

	public function test_existing_upload_is_verified(): void {
		$r = Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-content/uploads/private/2026/foto.jpg', self::broken( 403 ) );

		$this->assertFalse( $r['broken'] );
		$this->assertSame( 'ok', $r['status_code'] );
		$this->assertSame( 'Verified locally', $r['status_text'] );
		$this->assertStringStartsWith( 'HTTP 403 for an anonymous visitor', $r['log'] );
		$this->assertStringContainsString( 'private/2026/foto.jpg', $r['log'] );
		$this->assertStringEndsWith( 'headers', $r['log'] );
	}

	public function test_url_encoded_upload_is_found(): void {
		file_put_contents( WP_CONTENT_DIR . '/uploads/private/2026/met spatie.jpg', 'x' );
		$r = Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-content/uploads/private/2026/met%20spatie.jpg', self::broken( 404 ) );
		$this->assertFalse( $r['broken'] );
	}

	public function test_missing_upload_stays_broken_with_a_note(): void {
		$r = Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-content/uploads/weg.jpg', self::broken( 404 ) );

		$this->assertTrue( $r['broken'] );
		$this->assertStringStartsWith( 'Local check: the file weg.jpg does not exist.', $r['log'] );
	}

	public function test_path_traversal_is_not_resolved(): void {
		$r = Avpvh_Brkn_Local_Links::resolve( self::HOME . '/wp-content/uploads/../../outside.txt' );
		$this->assertNull( $r );
	}

	public function test_directory_is_not_a_file(): void {
		$r = Avpvh_Brkn_Local_Links::resolve( self::HOME . '/wp-content/uploads/private/2026' );
		$this->assertFalse( $r['ok'] );
	}

	public function test_file_elsewhere_in_wp_content(): void {
		@mkdir( WP_CONTENT_DIR . '/themes/t', 0777, true );
		file_put_contents( WP_CONTENT_DIR . '/themes/t/style.css', 'x' );
		$this->assertTrue( Avpvh_Brkn_Local_Links::resolve( self::HOME . '/wp-content/themes/t/style.css' )['ok'] );
	}

	public function test_private_post_is_verified(): void {
		$url                                         = self::HOME . '/leden/verslag/';
		$GLOBALS['wp_stub']['url_to_id'][ $url ]     = 12;
		$GLOBALS['wp_stub']['posts'][12]             = (object) array( 'post_type' => 'post', 'post_status' => 'private' );

		$r = Avpvh_Brkn_Local_Links::apply( $url, self::broken( 404 ) );

		$this->assertFalse( $r['broken'] );
		$this->assertStringContainsString( 'post 12 exists (status: private)', $r['log'] );
	}

	public function test_trashed_post_is_not_verified(): void {
		$url                                     = self::HOME . '/weg/';
		$GLOBALS['wp_stub']['url_to_id'][ $url ] = 13;
		$GLOBALS['wp_stub']['posts'][13]         = (object) array( 'post_type' => 'page', 'post_status' => 'trash' );

		$this->assertTrue( Avpvh_Brkn_Local_Links::apply( $url, self::broken( 404 ) )['broken'] );
	}

	public function test_unknown_page_stays_broken_and_untouched(): void {
		$in = self::broken( 404 );
		$this->assertSame( $in, Avpvh_Brkn_Local_Links::apply( self::HOME . '/bestaat-niet/', $in ) );
	}

	public function test_registered_ajax_action_is_verified(): void {
		add_action( 'wp_ajax_foto', 'strlen' );
		$r = Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-admin/admin-ajax.php?action=foto&id=1', self::broken( 400 ) );

		$this->assertFalse( $r['broken'] );
		$this->assertStringContainsString( 'AJAX action "foto" is registered', $r['log'] );
	}

	public function test_unregistered_ajax_action_stays_broken(): void {
		$this->assertTrue( Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-admin/admin-ajax.php?action=onbekend', self::broken( 400 ) )['broken'] );
		$this->assertNull( Avpvh_Brkn_Local_Links::resolve( self::HOME . '/wp-admin/admin-ajax.php' ) );
	}

	public function test_filter_decides_first(): void {
		add_action( 'wp_ajax_foto', 'strlen' );
		add_filter(
			'avpvh_brkn_local_link_status',
			function ( $status, $url, $parts ) {
				return ( $parts['query_args']['action'] ?? '' ) === 'foto'
					? array( 'ok' => '999' !== $parts['query_args']['id'], 'note' => 'foto ' . $parts['query_args']['id'] )
					: $status;
			},
			10,
			3
		);

		$ok = Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-admin/admin-ajax.php?action=foto&id=5', self::broken( 400 ) );
		$this->assertFalse( $ok['broken'] );
		$this->assertStringContainsString( 'foto 5', $ok['log'] );

		$missing = Avpvh_Brkn_Local_Links::apply( self::HOME . '/wp-admin/admin-ajax.php?action=foto&id=999', self::broken( 400 ) );
		$this->assertTrue( $missing['broken'], 'the filter overrules the registered action' );
		$this->assertStringStartsWith( 'foto 999', $missing['log'] );
	}

	public function test_filter_may_return_a_bool(): void {
		add_filter( 'avpvh_brkn_local_link_status', fn() => false );
		$this->assertSame( array( 'ok' => false, 'note' => 'The plugin that serves this URL reports that its target does not exist.' ), Avpvh_Brkn_Local_Links::resolve( self::HOME . '/x' ) );

		wp_stub_reset();
		add_filter( 'avpvh_brkn_local_link_status', fn() => true );
		$this->assertTrue( Avpvh_Brkn_Local_Links::resolve( self::HOME . '/x' )['ok'] );
	}

	public static function untouched_results(): array {
		return array(
			'working link'      => array( 'https://www.example.nl/wp-content/uploads/private/2026/foto.jpg', array( 'broken' => false, 'http_code' => 200 ) ),
			'timeout'           => array( 'https://www.example.nl/wp-content/uploads/private/2026/foto.jpg', array( 'broken' => true, 'timeout' => true, 'http_code' => 0 ) ),
			'server error'      => array( 'https://www.example.nl/wp-content/uploads/private/2026/foto.jpg', array( 'broken' => true, 'http_code' => 500, 'final_http_code' => 500 ) ),
			'other site'        => array( 'https://example.org/wp-content/uploads/private/2026/foto.jpg', array( 'broken' => true, 'http_code' => 403 ) ),
		);
	}

	#[DataProvider( 'untouched_results' )]
	public function test_only_refused_local_links_are_looked_up( string $url, array $result ): void {
		$this->assertSame( $result, Avpvh_Brkn_Local_Links::apply( $url, $result ) );
	}

	public function test_final_code_counts_after_a_redirect(): void {
		$r = Avpvh_Brkn_Local_Links::apply(
			self::HOME . '/wp-content/uploads/private/2026/foto.jpg',
			self::broken( 302, array( 'final_http_code' => 403 ) )
		);
		$this->assertFalse( $r['broken'] );
	}
}
