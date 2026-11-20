<?php
/**
 * PHPUnit bootstrap: the few WordPress functions the plugin's own classes use, as stubs.
 *
 * The legacy engine needs a full WordPress; the classes under test (URL patterns, host rules,
 * URL replacer, local links) only need these.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'AVPVH_BRKN_DIRECTORY', dirname( __DIR__ ) );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/avpvh-brkn-tests/wp-content' );
define( 'AVPVH_BRKN_LINK_STATUS_OK', 'ok' );
define( 'AVPVH_BRKN_LINK_STATUS_WARNING', 'warning' );
define( 'AVPVH_BRKN_LINK_STATUS_ERROR', 'error' );

/**
 * State the stubs read; tests reset it with wp_stub_reset().
 */
function wp_stub_reset(): void {
	$GLOBALS['wp_stub'] = array(
		'transients' => array(),
		'filters'    => array(),
		'actions'    => array(),
		'posts'      => array(),
		'url_to_id'  => array(),
		'home'       => 'https://www.example.nl',
		'site'       => 'https://www.example.nl',
	);
}
wp_stub_reset();

function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_basename( $p, $suffix = '' ) { return basename( str_replace( '\\', '/', $p ), $suffix ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_parse_str( $input, &$result ) { parse_str( (string) $input, $result ); }
function get_transient( $k ) { return $GLOBALS['wp_stub']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['wp_stub']['transients'][ $k ] = $v; return true; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function home_url( $path = '' ) { return $GLOBALS['wp_stub']['home'] . $path; }
function site_url( $path = '' ) { return $GLOBALS['wp_stub']['site'] . $path; }
function admin_url( $path = '' ) { return $GLOBALS['wp_stub']['site'] . '/wp-admin/' . ltrim( $path, '/' ); }
function content_url() { return $GLOBALS['wp_stub']['site'] . '/wp-content'; }
function wp_upload_dir( $time = null, $create = true ) {
	return array(
		'basedir' => WP_CONTENT_DIR . '/uploads',
		'baseurl' => content_url() . '/uploads',
		'error'   => false,
	);
}
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['wp_stub']['filters'][ $hook ][] = array( $cb, $args ); }
function apply_filters( $hook, $value, ...$rest ) {
	foreach ( $GLOBALS['wp_stub']['filters'][ $hook ] ?? array() as list( $cb, $args ) ) {
		$value = $cb( ...array_slice( array_merge( array( $value ), $rest ), 0, $args ) );
	}
	return $value;
}
function add_action( $hook, $cb ) { $GLOBALS['wp_stub']['actions'][ $hook ] = $cb; }
function has_action( $hook ) { return isset( $GLOBALS['wp_stub']['actions'][ $hook ] ); }
function url_to_postid( $url ) { return $GLOBALS['wp_stub']['url_to_id'][ $url ] ?? 0; }
function get_post( $id ) { return $GLOBALS['wp_stub']['posts'][ $id ] ?? null; }
function get_option( $name, $default = false ) { return 'home' === $name ? $GLOBALS['wp_stub']['home'] : $default; }
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false; }

class Avpvh_Brkn_Utility {
	public static function idn_to_ascii( $url, $charset = '' ) {
		return function_exists( 'idn_to_ascii' ) ? (string) idn_to_ascii( $url, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 ) : $url;
	}
}

class Avpvh_Brkn_Link {
	public static function remove_query_string( $url ) {
		return preg_replace( '@\?[^#]*?(#|$)@', '$1', $url );
	}
}

require __DIR__ . '/../includes/class-url-pattern.php';
require __DIR__ . '/../includes/class-host-rules.php';
require __DIR__ . '/../includes/class-url-replacer.php';
require __DIR__ . '/../includes/class-local-links.php';
require __DIR__ . '/../includes/module-base.php';
require __DIR__ . '/../includes/checkers.php';
require __DIR__ . '/../modules/checkers/http.php';
