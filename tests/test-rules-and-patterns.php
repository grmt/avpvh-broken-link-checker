<?php
/**
 * Stand-alone tests for the URL patterns, host rules and URL replacer.
 * Run: php tests/test-rules-and-patterns.php (no WordPress needed).
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'AVPVH_BRKN_LINK_STATUS_OK', 'ok' );

$GLOBALS['transients'] = array();
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }

class Avpvh_Brkn_Utility {
	public static function idn_to_ascii( $url, $charset = '' ) {
		return function_exists( 'idn_to_ascii' ) ? (string) idn_to_ascii( $url, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 ) : $url;
	}
}

require __DIR__ . '/../includes/class-url-pattern.php';
require __DIR__ . '/../includes/class-host-rules.php';
require __DIR__ . '/../includes/class-url-replacer.php';

$failures = 0;
function check( string $name, $got, $want ): void {
	global $failures;
	if ( $got === $want ) {
		echo "ok    $name\n";
		return;
	}
	++$failures;
	echo "FAIL  $name\n      got:  " . var_export( $got, true ) . "\n      want: " . var_export( $want, true ) . "\n";
}

// --- URL patterns -----------------------------------------------------------
$r = Avpvh_Brkn_Url_Pattern::compile( array( 'Example.com/Private', 'https://*.cdn.net/*.js', '/\.pdf$/i', '/(unclosed/', '', 42 ) );
check( 'compile drops empty, invalid and non-string entries', count( $r ), 3 );
check( 'plain text matches anywhere, any case', Avpvh_Brkn_Url_Pattern::matches_any( $r, 'http://example.com/private/x' ), true );
check( 'wildcard matches', Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://static.cdn.net/app/main.js' ), true );
check( 'wildcard needs its literal parts', Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://cdn.org/main.js' ), false );
check( 'regex with flags', Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://x.nl/file.PDF' ), true );
check( 'unrelated URL', Avpvh_Brkn_Url_Pattern::matches_any( $r, 'https://x.nl/page' ), false );
check( 'regex special characters in plain text are literal', Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '?id=1' ) ), 'https://a.nl/?id=1' ), true );
check( 'tilde in a regex', Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '#/~user/#' ) ), 'https://a.nl/~user/' ), false );
check( 'tilde in a regex between slashes', Avpvh_Brkn_Url_Pattern::matches_any( Avpvh_Brkn_Url_Pattern::compile( array( '/\/~user\//' ) ), 'https://a.nl/~user/' ), true );
check( 'invalid regex is reported', Avpvh_Brkn_Url_Pattern::is_invalid_regex( '/(unclosed/' ), true );
check( 'plain text is not an invalid regex', Avpvh_Brkn_Url_Pattern::is_invalid_regex( 'example.com' ), false );

// --- Host rules --------------------------------------------------------------
check( 'normalize host from URL', Avpvh_Brkn_Host_Rules::normalize_host( 'HTTPS://Uitspraken.Rechtspraak.nl:443/details?id=1' ), 'uitspraken.rechtspraak.nl' );
check( 'normalize "*." prefix and dots', Avpvh_Brkn_Host_Rules::normalize_host( '*.rechtspraak.nl.' ), 'rechtspraak.nl' );
check( 'reject invalid host', Avpvh_Brkn_Host_Rules::normalize_host( 'not a host' ), '' );
check( 'parse codes', Avpvh_Brkn_Host_Rules::parse_codes( '429; 403, 403 99 600 abc' ), array( 403, 429 ) );

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
check( 'invalid and duplicate rows dropped', count( $rules->rules() ), 3 );
check( 'most specific rule first', $rules->rules()[0]['host'], 'deeplink.rechtspraak.nl' );
check( 'subdomain uses parent rule', $rules->find( 'https://uitspraken.rechtspraak.nl/x' )['host'] ?? null, 'rechtspraak.nl' );
check( 'nearest rule wins', $rules->find( 'https://deeplink.rechtspraak.nl/x' )['host'] ?? null, 'deeplink.rechtspraak.nl' );
check( 'no match for look-alike host', $rules->find( 'https://notrechtspraak.nl/' ), null );
check( 'user agent has no line breaks', $rules->user_agent( 'https://www.rechtspraak.nl/' ), 'Custom Injected: 1' );
check( 'default user agent is a browser', $rules->user_agent( 'https://other.nl/' ), Avpvh_Brkn_Host_Rules::BROWSER_USER_AGENT );
check( 'interval capped', $rules->min_interval( 'https://deeplink.rechtspraak.nl/' ), Avpvh_Brkn_Host_Rules::MAX_INTERVAL );
check( 'ok codes from a list', $rules->rules()[0]['ok_codes'], array( 403, 429 ) );
check( 'skip', $rules->is_skipped( 'http://www.skip.example/a' ), true );
check( 'no skip without rule', $rules->is_skipped( 'http://example.org/' ), false );
check( 'custom default user agent', ( new Avpvh_Brkn_Host_Rules( array(), 'MyBot/1.0' ) )->user_agent( 'https://a.nl/' ), 'MyBot/1.0' );

$broken = array( 'broken' => true, 'warning' => false, 'timeout' => false, 'http_code' => 301, 'final_http_code' => 403, 'final_url' => 'https://uitspraken.rechtspraak.nl/details?id=1', 'log' => 'headers' );
$ok     = $rules->apply_ok_codes( 'https://uitspraken.rechtspraak.nl/details?id=1', $broken );
check( 'accepted code makes the link work', array( $ok['broken'], $ok['status_code'] ), array( false, 'ok' ) );
check( 'accepted code is explained in the log', str_starts_with( $ok['log'], 'HTTP 403 counts as working' ), true );
check( 'other codes stay broken', $rules->apply_ok_codes( 'https://www.rechtspraak.nl/', array( 'broken' => true, 'http_code' => 404 ) )['broken'], true );
check( 'timeouts stay broken', $rules->apply_ok_codes( 'https://www.rechtspraak.nl/', array( 'broken' => true, 'timeout' => true, 'http_code' => 403 ) )['broken'], true );
check( 'hosts without rule stay broken', $rules->apply_ok_codes( 'https://a.nl/', array( 'broken' => true, 'http_code' => 403 ) )['broken'], true );

$fast = new Avpvh_Brkn_Host_Rules( array( array( 'host' => 'slow.example', 'min_interval' => 1 ) ) );
check( 'first request does not wait', $fast->wait_for_host( 'https://slow.example/a' ), 0.0 );
$waited = $fast->wait_for_host( 'https://www.slow.example/b' );
check( 'second request to the same rule waits', $waited > 0.5 && $waited <= 1.0, true );
check( 'hosts without interval never wait', $fast->wait_for_host( 'https://fast.example/' ), 0.0 );

// --- URL replacer ------------------------------------------------------------
$old = 'https://uitspraken.rechtspraak.nl/#!/details?id=ECLI:NL:HR:2016:540';
check( 'plain replace', ( new Avpvh_Brkn_Url_Replacer( '/#!/', '/', false, true ) )->apply( $old ), 'https://uitspraken.rechtspraak.nl/details?id=ECLI:NL:HR:2016:540' );
check( 'case-insensitive plain replace keeps $1 literal', ( new Avpvh_Brkn_Url_Replacer( 'ECLI', '$1\\x', false, false ) )->apply( 'a/ecli' ), 'a/$1\\x' );
check( 'regex replace with group', ( new Avpvh_Brkn_Url_Replacer( '^http://(.*)$', 'https://$1', true ) )->apply( 'http://a.nl/x' ), 'https://a.nl/x' );
check( 'regex with backtick', ( new Avpvh_Brkn_Url_Replacer( 'a`b', 'c', true ) )->apply( 'xa`by' ), 'xcy' );
check( 'regex with escaped backtick', ( new Avpvh_Brkn_Url_Replacer( 'a\\`b', 'c', true ) )->apply( 'xa`by' ), 'xcy' );
check( 'regex with escaped backslash before backtick', ( new Avpvh_Brkn_Url_Replacer( 'a\\\\`b', 'c', true ) )->apply( 'xa\\`by' ), 'xcy' );
check( 'no match leaves URL', ( new Avpvh_Brkn_Url_Replacer( 'zzz', 'y' ) )->apply( 'https://a.nl/' ), 'https://a.nl/' );
foreach ( array( array( '', 'x', false ), array( '(', 'x', true ) ) as $bad ) {
	try {
		new Avpvh_Brkn_Url_Replacer( $bad[0], $bad[1], $bad[2] );
		check( 'rejects ' . var_export( $bad[0], true ), 'accepted', 'exception' );
	} catch ( InvalidArgumentException $e ) {
		check( 'rejects ' . var_export( $bad[0], true ), 'exception', 'exception' );
	}
}

echo $failures ? "\n$failures test(s) failed\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
