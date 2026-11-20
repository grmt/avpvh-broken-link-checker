<?php
/**
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

use PHPUnit\Framework\TestCase;

#[PHPUnit\Framework\Attributes\CoversClass( Avpvh_Brkn_Url_Pattern::class )]
final class UrlPatternTest extends TestCase {
	public function test_patterns(): void {
		$r = Avpvh_Brkn_Url_Pattern::compile( array( 'Example.com/Private', 'https://*.cdn.net/*.js', '/\.pdf$/i', '/(unclosed/', '', 42 ) );
		$this->assertSame( 3, count( $r ), 'compile drops empty, invalid and non-string entries' );
		$this->assertSame( true, Avpvh_Brkn_Url_Pattern::matches_any( $r, 'http://example.com/private/x' ), 'plain text matches anywhere, any case' );
		$this->assertSame( true, Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://static.cdn.net/app/main.js' ), 'wildcard matches' );
		$this->assertSame( false, Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://cdn.org/main.js' ), 'wildcard needs its literal parts' );
		$this->assertSame( true, Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://x.nl/file.PDF' ), 'regex with flags' );
		$this->assertSame( false, Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://x.nl/page' ), 'unrelated URL' );
		$this->assertSame( true, Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '?id=1' ) ), 'https://a.nl/?id=1' ), 'regex special characters in plain text are literal' );
		$this->assertSame( false, Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '#/~user/#' ) ), 'https://a.nl/~user/' ), 'tilde in a regex' );
		$this->assertSame( true, Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '/\/~user\//' ) ), 'https://a.nl/~user/' ), 'tilde in a regex between slashes' );
		$this->assertSame( true, Avpvh_Brkn_Url_Pattern::is_invalid_regex( '/(unclosed/' ), 'invalid regex is reported' );
		$this->assertSame( false, Avpvh_Brkn_Url_Pattern::is_invalid_regex( 'example.com' ), 'plain text is not an invalid regex' );
	}

	public function test_tilde_is_escaped_once(): void {
		foreach ( array( '/a~b/', '/a\\~b/' ) as $entry ) {
			$this->assertTrue( Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( $entry ) ), 'x/a~b' ), $entry );
		}
		$this->assertTrue( Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '/a\\\\~b/' ) ), 'a\\~b' ), 'escaped backslash, then a tilde' );
	}

	public function test_runaway_expression_stops_and_limit_is_restored(): void {
		$before = ini_get( 'pcre.backtrack_limit' );
		$start  = microtime( true );
		$this->assertFalse( Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '/(a+)+$/' ) ), str_repeat( 'a', 40 ) . 'b' ) );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
		$this->assertSame( $before, ini_get( 'pcre.backtrack_limit' ) );
	}
}
