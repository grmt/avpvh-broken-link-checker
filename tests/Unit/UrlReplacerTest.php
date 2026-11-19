<?php
/**
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

use PHPUnit\Framework\TestCase;

#[PHPUnit\Framework\Attributes\CoversClass( Avpvh_Brkn_Url_Replacer::class )]
final class UrlReplacerTest extends TestCase {
	public function test_replacer(): void {
		$old = 'https://uitspraken.rechtspraak.nl/#!/details?id=ECLI:NL:HR:2016:540';
		$this->assertSame( 'https://uitspraken.rechtspraak.nl/details?id=ECLI:NL:HR:2016:540', ( new Avpvh_Brkn_Url_Replacer( '/#!/', '/', false, true ) )->apply( $old ), 'plain replace' );
		$this->assertSame( 'a/$1\\x', ( new Avpvh_Brkn_Url_Replacer( 'ECLI', '$1\\x', false, false ) )->apply( 'a/ecli' ), 'case-insensitive plain replace keeps $1 literal' );
		$this->assertSame( 'https://a.nl/x', ( new Avpvh_Brkn_Url_Replacer( '^http://(.*)$', 'https://$1', true ) )->apply( 'http://a.nl/x' ), 'regex replace with group' );
		$this->assertSame( 'xcy', ( new Avpvh_Brkn_Url_Replacer( 'a`b', 'c', true ) )->apply( 'xa`by' ), 'regex with backtick' );
		$this->assertSame( 'xcy', ( new Avpvh_Brkn_Url_Replacer( 'a\\`b', 'c', true ) )->apply( 'xa`by' ), 'regex with escaped backtick' );
		$this->assertSame( 'xcy', ( new Avpvh_Brkn_Url_Replacer( 'a\\\\`b', 'c', true ) )->apply( 'xa\\`by' ), 'regex with escaped backslash before backtick' );
		$this->assertSame( 'https://a.nl/', ( new Avpvh_Brkn_Url_Replacer( 'zzz', 'y' ) )->apply( 'https://a.nl/' ), 'no match leaves URL' );
		foreach ( array( array( '', 'x', false ), array( '(', 'x', true ) ) as $bad ) {
			try {
				new Avpvh_Brkn_Url_Replacer( $bad[0], $bad[1], $bad[2] );
				$this->assertSame( 'exception', 'accepted', 'rejects ' . var_export( $bad[0], true ) );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'exception', 'exception', 'rejects ' . var_export( $bad[0], true ) );
			}
		}
	}
}
