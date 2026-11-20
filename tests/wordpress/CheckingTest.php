<?php
/**
 * Checking links end to end: parse a post, check its links, read the stored result.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

class CheckingTest extends Avpvh_Brkn_Test_Case {

	private function check( string $url ): Avpvh_Brkn_Link {
		$link = $this->link( $url );
		$link->check( true );
		return new Avpvh_Brkn_Link( $link->link_id );
	}

	public function test_working_and_broken_links(): void {
		$this->responses['/ok'] = 200;
		$this->post_with( '<a href="' . self::HOST . '/ok">ok</a> <a href="' . self::HOST . '/gone">gone</a>' );

		$ok = $this->check( self::HOST . '/ok' );
		$this->assertFalse( (bool) $ok->broken );
		$this->assertSame( 200, (int) $ok->http_code );

		$gone = $this->check( self::HOST . '/gone' );
		$this->assertTrue( (bool) $gone->broken );
		$this->assertSame( 404, (int) $gone->http_code );
		$this->assertSame( 'error', $gone->analyse_status()['code'] );
	}

	public function test_head_refused_is_retried_with_get(): void {
		$this->post_with( '<a href="' . self::HOST . '/head-refused">x</a>' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				return str_ends_with( $url, '/head-refused' ) && 'HEAD' === $args['method']
					? array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 405, 'message' => '' ), 'cookies' => array(), 'filename' => null )
					: array( 'headers' => array(), 'body' => 'ok', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
			},
			5,
			3
		);

		$link = $this->check( self::HOST . '/head-refused' );
		$this->assertFalse( (bool) $link->broken );
	}

	public function test_host_rule_accepts_a_code(): void {
		$conf                          = avpvh_brkn_get_configuration();
		$conf->options['host_rules']   = array( array( 'host' => '93.184.215.14', 'ok_codes' => array( 403 ) ) );
		$conf->save_options();
		Avpvh_Brkn_Host_Rules::reset();
		$this->responses['/members'] = 403;
		$this->post_with( '<a href="' . self::HOST . '/members">x</a>' );

		$link = $this->check( self::HOST . '/members' );
		$this->assertFalse( (bool) $link->broken );
		$this->assertStringContainsString( 'counts as working', $link->log );
	}

	public function test_refused_address_is_not_requested(): void {
		$this->post_with( '<a href="http://169.254.169.254/latest/">x</a>' );

		$link = $this->check( 'http://169.254.169.254/latest/' );
		$this->assertSame( array(), $this->requests );
		$this->assertTrue( (bool) $link->warning );
	}

	public function test_private_post_on_this_site_is_verified_locally(): void {
		$private = self::factory()->post->create( array( 'post_status' => 'private', 'post_title' => 'Leden' ) );
		$url     = get_permalink( $private );
		$this->post_with( '<a href="' . esc_url( $url ) . '">leden</a>' );

		$link = $this->check( $url );
		$this->assertFalse( (bool) $link->broken, $link->log );
		$this->assertSame( 'Verified locally', $link->status_text );
		$this->assertStringContainsString( 'status: private', $link->log );
	}

	public function test_missing_page_on_this_site_stays_broken(): void {
		$url = home_url( '/bestaat-niet/' );
		$this->post_with( '<a href="' . esc_url( $url ) . '">weg</a>' );

		$this->assertTrue( (bool) $this->check( $url )->broken );
	}

	public function test_upload_is_verified_and_sensitive_files_are_not(): void {
		$uploads = wp_upload_dir();
		file_put_contents( $uploads['basedir'] . '/foto.jpg', 'x' );
		file_put_contents( $uploads['basedir'] . '/dump.sql', 'x' );
		$this->post_with( '<img src="' . $uploads['baseurl'] . '/foto.jpg"> <a href="' . $uploads['baseurl'] . '/dump.sql">x</a>' );

		$this->assertSame( 'Verified locally', $this->check( $uploads['baseurl'] . '/foto.jpg' )->status_text );
		$this->assertTrue( (bool) $this->check( $uploads['baseurl'] . '/dump.sql' )->broken );

		unlink( $uploads['basedir'] . '/foto.jpg' );
		unlink( $uploads['basedir'] . '/dump.sql' );
	}

	public function test_owner_of_a_url_decides_through_the_filter(): void {
		$url = admin_url( 'admin-ajax.php?action=test_photo&id=7' );
		$this->post_with( '<img src="' . esc_url( $url ) . '">' );
		add_filter(
			'avpvh_brkn_local_link_status',
			fn( $status, $u, $parts ) => ( $parts['query_args']['action'] ?? '' ) === 'test_photo' ? array( 'ok' => true, 'note' => 'photo 7 exists' ) : $status,
			10,
			3
		);
		$this->responses[ (string) wp_parse_url( $url, PHP_URL_PATH ) ] = 400;

		$link = $this->check( $url );
		$this->assertFalse( (bool) $link->broken );
		$this->assertStringContainsString( 'photo 7 exists', $link->log );
	}

	public function test_work_parses_and_checks_without_holding_a_transaction(): void {
		global $wpdb;
		$this->responses['/a'] = 200;
		self::factory()->post->create( array( 'post_content' => '<a href="' . self::HOST . '/a">a</a> <a href="' . self::HOST . '/b">b</a>', 'post_status' => 'publish' ) );

		$this->core()->work();

		$this->assertFalse( (bool) $this->link( self::HOST . '/a' )->broken );
		$this->assertTrue( (bool) $this->link( self::HOST . '/b' )->broken );
		$depth = ( fn() => $this->depth )->call( Avpvh_Brkn_Transaction_Manager::getInstance() );
		$this->assertSame( 0, $depth, 'no transaction left open' );
	}

	public function test_excluded_link_is_not_requested(): void {
		$conf                            = avpvh_brkn_get_configuration();
		$conf->options['exclusion_list'] = array( '*/skip-*' );
		$conf->save_options();
		$this->core()->build_exclusion_regex();
		self::factory()->post->create( array( 'post_content' => '<a href="' . self::HOST . '/skip-me">x</a>', 'post_status' => 'publish' ) );

		$this->core()->work();

		$this->assertSame( array(), $this->requests );
	}
}
