<?php
/**
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cURL checker with a fixed resolver and its protected helpers opened up.
 */
final class Testable_Curl_Http extends Avpvh_Brkn_Curl_Http {
	/** @var array<string, array[]> */
	public array $dns = array();

	public function __construct() {}

	protected function lookup_dns_records( $host ) {
		return $this->dns[ $host ] ?? array();
	}

	public function pin( string $url ): ?string {
		return $this->resolve_pin( $url );
	}
}

#[CoversClass( Avpvh_Brkn_Curl_Http::class )]
#[CoversClass( Avpvh_Brkn_Http_Checker_Base::class )]
final class HttpCheckerTest extends TestCase {

	private Testable_Curl_Http $checker;

	protected function setUp(): void {
		wp_stub_reset();
		$this->checker = new Testable_Curl_Http();
	}

	public static function blocked(): array {
		return array(
			'loopback'          => array( '127.0.0.1', true ),
			'metadata'          => array( '169.254.169.254', true ),
			'private'           => array( '192.168.1.10', true ),
			'ipv6 loopback'     => array( '::1', true ),
			'ipv4-compatible'   => array( '::7f00:1', true ),
			'6to4'              => array( '2002:7f00:1::', true ),
			'teredo'            => array( '2001:0:4136:e378::1', true ),
			'site-local'        => array( 'fec0::1', true ),
			'public ipv4'       => array( '93.184.215.14', false ),
			'public ipv6'       => array( '2606:2800:21f:cb07:6820:80da:af6b:8b2c', false ),
		);
	}

	#[DataProvider( 'blocked' )]
	public function test_blocked_ranges( string $ip, bool $blocked ): void {
		$this->assertSame( $blocked, $this->checker->is_blocked_ip( $ip ) );
	}

	public function test_cleared_host_is_pinned_to_its_addresses(): void {
		$this->checker->dns['links.example.org'] = array( array( 'ip' => '93.184.215.14' ), array( 'ipv6' => '2606:2800::1' ) );

		$this->assertTrue( $this->checker->is_safe_url( 'https://links.example.org/page' ) );
		$this->assertSame( 'links.example.org:443:93.184.215.14,[2606:2800::1]', $this->checker->pin( 'https://links.example.org/other' ) );
		$this->assertSame( 'links.example.org:8080:93.184.215.14,[2606:2800::1]', $this->checker->pin( 'http://LINKS.example.org:8080/' ) );
		$this->assertSame( 'links.example.org:80:93.184.215.14,[2606:2800::1]', $this->checker->pin( 'http://links.example.org/' ) );
	}

	public function test_refused_host_is_neither_cleared_nor_pinned(): void {
		$this->checker->dns['evil.example.org'] = array( array( 'ip' => '93.184.215.14' ), array( 'ip' => '127.0.0.1' ) );

		$this->assertFalse( $this->checker->is_safe_url( 'https://evil.example.org/' ) );
		$this->assertNull( $this->checker->pin( 'https://evil.example.org/' ) );
	}

	public function test_nothing_to_pin(): void {
		$this->assertNull( $this->checker->pin( 'https://never-checked.example.org/' ), 'not cleared' );
		$this->assertNull( $this->checker->pin( 'https://93.184.215.14/' ), 'IP literal' );
		$this->assertTrue( $this->checker->is_safe_url( 'https://www.example.nl/x' ), 'own site' );
		$this->assertNull( $this->checker->pin( 'https://www.example.nl/x' ), 'own site is not resolved, so not pinned' );
	}

	public function test_body_is_capped(): void {
		$chunk = str_repeat( 'x', 10000 );
		$this->assertSame( 10000, $this->checker->read_body( null, $chunk ) );
		$this->assertSame( 0, $this->checker->read_body( null, $chunk ), 'the chunk that passes the cap stops the transfer' );

		$body = ( fn() => $this->body )->call( $this->checker );
		$this->assertSame( Avpvh_Brkn_Curl_Http::MAX_BODY, strlen( $body ) );
		$this->assertTrue( ( fn() => $this->body_capped )->call( $this->checker ) );
	}

	public function test_headers_are_capped(): void {
		$line = str_repeat( 'h', 1000 ) . "\r\n";
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertSame( strlen( $line ), $this->checker->read_header( null, $line ), 'cURL must always hear the full length' );
		}
		$this->assertSame( Avpvh_Brkn_Curl_Http::MAX_HEADERS, strlen( $this->checker->last_headers ) );
	}
}
