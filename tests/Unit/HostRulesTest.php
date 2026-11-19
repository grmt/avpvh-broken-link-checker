<?php
/**
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

use PHPUnit\Framework\TestCase;

#[PHPUnit\Framework\Attributes\CoversClass( Avpvh_Brkn_Host_Rules::class )]
final class HostRulesTest extends TestCase {
	protected function setUp(): void {
		wp_stub_reset();
	}

	public function test_rules(): void {
		$this->assertSame( 'uitspraken.rechtspraak.nl', Avpvh_Brkn_Host_Rules::normalize_host( 'HTTPS://Uitspraken.Rechtspraak.nl:443/details?id=1' ), 'normalize host from URL' );
		$this->assertSame( 'rechtspraak.nl', Avpvh_Brkn_Host_Rules::normalize_host( '*.rechtspraak.nl.' ), 'normalize "*." prefix and dots' );
		$this->assertSame( '', Avpvh_Brkn_Host_Rules::normalize_host( 'not a host' ), 'reject invalid host' );
		$this->assertSame( array( 403, 429 ), Avpvh_Brkn_Host_Rules::parse_codes( '429; 403, 403 99 600 abc' ), 'parse codes' );

		$rules = new Avpvh_Brkn_Host_Rules(
			array(
				array( 'host' => 'rechtspraak.nl', 'user_agent' => "Custom\r\nInjected: 1", 'min_interval' => '5', 'ok_codes' => '403' ),
				array( 'host' => 'deeplink.rechtspraak.nl', 'min_interval' => 999999, 'ok_codes' => array( 403, '429', 'x' ) ),
				array( 'host' => 'skip.example', 'skip' => '1' ),
				array( 'host' => 'RECHTSPRAAK.NL', 'user_agent' => 'duplicate' ),
				array( 'host' => '' ),
				'not an array',
			)
		);
		$this->assertSame( 3, count( $rules->rules() ), 'invalid and duplicate rows dropped' );
		$this->assertSame( 'deeplink.rechtspraak.nl', $rules->rules()[0]['host'], 'most specific rule first' );
		$this->assertSame( 'rechtspraak.nl', $rules->find( 'https://uitspraken.rechtspraak.nl/x' )['host'] ?? null, 'subdomain uses parent rule' );
		$this->assertSame( 'deeplink.rechtspraak.nl', $rules->find( 'https://deeplink.rechtspraak.nl/x' )['host'] ?? null, 'nearest rule wins' );
		$this->assertSame( null, $rules->find( 'https://notrechtspraak.nl/' ), 'no match for look-alike host' );
		$this->assertSame( 'Custom Injected: 1', $rules->user_agent( 'https://www.rechtspraak.nl/' ), 'user agent has no line breaks' );
		$this->assertSame( Avpvh_Brkn_Host_Rules::BROWSER_USER_AGENT, $rules->user_agent( 'https://other.nl/' ), 'default user agent is a browser' );
		$this->assertSame( Avpvh_Brkn_Host_Rules::MAX_INTERVAL, $rules->min_interval( 'https://deeplink.rechtspraak.nl/' ), 'interval capped' );
		$this->assertSame( array( 403, 429 ), $rules->rules()[0]['ok_codes'], 'ok codes from a list' );
		$this->assertSame( true, $rules->is_skipped( 'http://www.skip.example/a' ), 'skip' );
		$this->assertSame( false, $rules->is_skipped( 'http://example.org/' ), 'no skip without rule' );
		$this->assertSame( 'MyBot/1.0', ( new Avpvh_Brkn_Host_Rules( array(), 'MyBot/1.0' ) )->user_agent( 'https://a.nl/' ), 'custom default user agent' );

		$broken = array( 'broken' => true, 'warning' => false, 'timeout' => false, 'http_code' => 301, 'final_http_code' => 403, 'final_url' => 'https://uitspraken.rechtspraak.nl/details?id=1', 'log' => 'headers' );
		$ok     = $rules->apply_ok_codes( 'https://uitspraken.rechtspraak.nl/details?id=1', $broken );
		$this->assertSame( array( false, 'ok' ), array( $ok['broken'], $ok['status_code'] ), 'accepted code makes the link work' );
		$this->assertSame( true, str_starts_with( $ok['log'], 'HTTP 403 counts as working' ), 'accepted code is explained in the log' );
		$this->assertSame( true, $rules->apply_ok_codes( 'https://www.rechtspraak.nl/', array( 'broken' => true, 'http_code' => 404 ) )['broken'], 'other codes stay broken' );
		$this->assertSame( true, $rules->apply_ok_codes( 'https://www.rechtspraak.nl/', array( 'broken' => true, 'timeout' => true, 'http_code' => 403 ) )['broken'], 'timeouts stay broken' );
		$this->assertSame( true, $rules->apply_ok_codes( 'https://a.nl/', array( 'broken' => true, 'http_code' => 403 ) )['broken'], 'hosts without rule stay broken' );

		$fast = new Avpvh_Brkn_Host_Rules( array( array( 'host' => 'slow.example', 'min_interval' => 1 ) ) );
		$this->assertSame( 0.0, $fast->wait_for_host( 'https://slow.example/a' ), 'first request does not wait' );
		$waited = $fast->wait_for_host( 'https://www.slow.example/b' );
		$this->assertSame( true, $waited > 0.5 && $waited <= 1.0, 'second request to the same rule waits' );
		$this->assertSame( 0.0, $fast->wait_for_host( 'https://fast.example/' ), 'hosts without interval never wait' );
	}
}
