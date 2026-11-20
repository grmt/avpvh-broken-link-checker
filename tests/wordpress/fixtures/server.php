<?php
/**
 * Router for the PHP built-in server the cURL tests talk to.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

switch ( $path ) {
	case '/ok':
		echo 'fine';
		break;

	case '/missing':
		http_response_code( 404 );
		echo 'not here';
		break;

	case '/redirect':
		header( 'Location: /ok', true, 301 );
		break;

	case '/redirect-loop':
		header( 'Location: /redirect-loop', true, 302 );
		break;

	case '/redirect-blocked':
		header( 'Location: http://169.254.169.254/latest/meta-data/', true, 302 );
		break;

	case '/head-refused':
		// Like some sites: HEAD fails, GET works.
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			http_response_code( 405 );
		} else {
			echo 'fine with GET';
		}
		break;

	case '/big':
		// Ignores the Range header and sends 20 MB.
		header( 'Content-Type: text/plain' );
		for ( $i = 0; $i < 80; $i++ ) {
			echo str_repeat( 'x', 262144 );
			flush();
		}
		break;

	case '/echo-headers':
		http_response_code( 500 );
		header( 'Content-Type: text/plain' );
		foreach ( getallheaders() as $name => $value ) {
			echo $name, ': ', $value, "\n";
		}
		break;

	default:
		http_response_code( 404 );
}
