<?php
/**
 * The cURL transport against a local PHP server: redirects, limits, refused targets.
 *
 * The server runs on 127.0.0.1. The checker refuses loopback addresses except the site's own
 * host, so these tests make the server the site's home.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

/**
 * @requires extension curl
 */
class CurlTransportTest extends Avpvh_Brkn_Test_Case {

	private string $server;

	public function set_up() {
		parent::set_up();
		remove_all_filters( 'avpvh_brkn_use_curl' );
		remove_filter( 'pre_http_request', array( $this, 'fake_response' ), 10 );
		$this->reset_http_checker();

		$this->server = Avpvh_Brkn_Local_Server::start();
		update_option( 'home', $this->server );
	}

	private function check( string $path, bool $use_get = false ): array {
		return Avpvh_Brkn_Module_Manager::getInstance()->get_module( 'http' )->check( $this->server . $path, $use_get );
	}

	public function test_working_and_missing(): void {
		$ok = $this->check( '/ok' );
		$this->assertFalse( $ok['broken'] );
		$this->assertSame( 200, $ok['http_code'] );

		$missing = $this->check( '/missing' );
		$this->assertTrue( $missing['broken'] );
		$this->assertSame( 404, $missing['http_code'] );
	}

	public function test_redirect_is_followed_hop_by_hop(): void {
		$r = $this->check( '/redirect' );
		$this->assertFalse( $r['broken'] );
		$this->assertSame( 1, $r['redirect_count'] );
		$this->assertSame( $this->server . '/ok', $r['final_url'] );
	}

	public function test_redirect_loop_is_broken(): void {
		$r = $this->check( '/redirect-loop' );
		$this->assertTrue( $r['broken'] );
		$this->assertSame( 'too_many_redirects', $r['error_code'] );
	}

	public function test_redirect_to_a_refused_address_is_not_followed(): void {
		$r = $this->check( '/redirect-blocked' );
		$this->assertSame( 'unsafe_redirect', $r['error_code'] );
		$this->assertFalse( $r['broken'] );
		$this->assertTrue( $r['warning'] );
	}

	public function test_head_refused_is_retried_with_get(): void {
		$r = $this->check( '/head-refused' );
		$this->assertFalse( $r['broken'] );
		$this->assertSame( 200, $r['http_code'] );
	}

	public function test_large_body_is_cut_off(): void {
		$before = memory_get_usage();
		$r      = $this->check( '/big', true );

		$this->assertFalse( $r['broken'] );
		$this->assertLessThan( 4 * 1048576, memory_get_usage() - $before, '20 MB response must not be held in memory' );
	}

	public function test_credentials_are_kept_out_of_the_log(): void {
		add_filter(
			'avpvh_brkn_curl_options',
			fn( $options ) => $options + array( CURLOPT_HTTPHEADER => array( 'Authorization: Basic c2VjcmV0', 'Cookie: session=secret' ) )
		);

		$r = $this->check( '/echo-headers', true );

		$this->assertTrue( $r['broken'] );
		$start   = (int) strpos( $r['log'], 'Request headers' );
		$request = substr( $r['log'], $start, (int) strpos( $r['log'], 'Response HTML' ) - $start );
		$this->assertStringContainsString( "Authorization: [removed]\r\n", $request );
		$this->assertStringContainsString( "Cookie: [removed]\r\n", $request );
		$this->assertStringNotContainsString( 'c2VjcmV0', $request );
		$this->assertStringNotContainsString( 'session=secret', $request );
	}

	public function test_refused_and_invalid_urls(): void {
		$checker = Avpvh_Brkn_Module_Manager::getInstance()->get_module( 'http' );

		foreach ( array( 'http://127.0.0.2/', 'http://10.0.0.1/', 'http://[::1]/', 'http://0x7f.0.0.1/', 'javascript:alert(1)' ) as $url ) {
			$r = $checker->check( $url );
			$this->assertSame( 'invalid_url', $r['error_code'] ?? '', $url );
		}
	}
}
