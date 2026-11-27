<?php
/**
 * The admin screens and bulk actions.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

class AdminTest extends Avpvh_Brkn_Test_Case {

	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	public function test_settings_page(): void {
		$conf                            = avpvh_brkn_get_configuration();
		$conf->options['exclusion_list'] = array( 'example.com', '/(<b>/' );
		$conf->save_options();

		$html = $this->capture( fn() => $this->core()->options_page() );

		$this->assertStringContainsString( 'host_rules[', $html );
		$this->assertStringContainsString( '<code>/(&lt;b&gt;/</code>', $html, 'an invalid pattern is shown, escaped' );
		$this->assertStringNotContainsString( '<b>/', $html );
		$this->assertStringNotContainsString( 'wp-external-links', $html );
		$this->assertMatchesRegularExpression( '/type="password"[^>]*name="youtube_api_key"/s', $html );
	}

	public function test_links_page_and_its_script(): void {
		$this->post_with( '<a href="' . self::HOST . '/gone">gone</a>' );
		$this->link( self::HOST . '/gone' )->check( true );
		$_GET['filter_id'] = 'all';

		$html = $this->capture( fn() => $this->core()->links_page() );

		$this->assertStringContainsString( 'id="blc-row-', $html );
		$this->assertStringContainsString( self::HOST . '/gone', $html );
		$this->assertStringContainsString( 'rel=\'noopener noreferrer\'', $html );
		$this->assertStringContainsString( 'https://archive.org/wayback/available', $html );
		$this->assertStringNotContainsString( 'callback=?', $html );
		$this->assertStringNotContainsString( 'eval(', $html );
		$this->assertStringContainsString( 'https://web.archive.org/', $html );
	}

	public function test_warnings_notice_is_hidden_only_with_a_nonce(): void {
		$conf = avpvh_brkn_get_configuration();
		$conf->set( 'show_warnings_section_hint', true );
		$_GET = array( 'filter_id' => 'warnings', 'page' => 'avpvh-broken-link-checker', 'show_warnings_section_hint' => '0' );

		$html = $this->capture( fn() => $this->core()->show_warnings_section_notice() );
		$this->assertTrue( (bool) $conf->get( 'show_warnings_section_hint', true ), 'no nonce: unchanged' );
		$this->assertStringContainsString( '_wpnonce=', $html, 'the hide link carries a nonce' );

		$_GET['_wpnonce'] = wp_create_nonce( 'show_warnings_section_hint' );
		$html             = $this->capture( fn() => $this->core()->show_warnings_section_notice() );
		$this->assertFalse( (bool) $conf->get( 'show_warnings_section_hint', true ) );
		$this->assertSame( '', trim( $html ) );
	}

	private function bulk( string $method, array $ids, array $post = array() ): array {
		$_POST                = $post;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-action' );
		return $this->core()->$method( $ids );
	}

	public function test_bulk_edit_replaces_and_sanitises(): void {
		$post_id = $this->post_with( '<a href="' . self::HOST . '/old/page">x</a>' );
		$link    = $this->link( self::HOST . '/old/page' );

		list( $message ) = $this->bulk( 'do_bulk_edit', array( $link->link_id ), array( 'search' => '/old/', 'replace' => '/new path/' ) );

		$this->assertStringContainsString( '1 link updated', $message );
		$this->assertStringContainsString( self::HOST . '/new%20path/page', get_post( $post_id )->post_content );
	}

	public function test_bulk_edit_refuses_a_bad_protocol_without_unfiltered_html(): void {
		$post_id = $this->post_with( '<a href="' . self::HOST . '/x">x</a>' );
		$link    = $this->link( self::HOST . '/x' );
		add_filter( 'map_meta_cap', fn( $caps, $cap ) => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps, 10, 2 );

		list( $message ) = $this->bulk( 'do_bulk_edit', array( $link->link_id ), array( 'search' => '^.*$', 'replace' => 'javascript:alert(1)', 'regex' => '1' ) );

		$this->assertStringContainsString( '0 links updated', $message );
		$this->assertStringContainsString( self::HOST . '/x', get_post( $post_id )->post_content );
	}

	public function test_bulk_discard_and_dismiss_skip_links_the_user_may_not_edit(): void {
		$mine   = $this->post_with( '<a href="' . self::HOST . '/mine">x</a>' );
		$theirs = $this->post_with( '<a href="' . self::HOST . '/theirs">x</a>' );
		foreach ( array( '/mine', '/theirs' ) as $path ) {
			$this->link( self::HOST . $path )->check( true );
		}
		add_filter(
			'map_meta_cap',
			fn( $caps, $cap, $user, $args ) => 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $theirs ? array( 'do_not_allow' ) : $caps,
			10,
			4
		);
		$ids = array( $this->link( self::HOST . '/mine' )->link_id, $this->link( self::HOST . '/theirs' )->link_id );

		$this->bulk( 'do_bulk_discard', $ids );
		$this->assertFalse( (bool) $this->link( self::HOST . '/mine' )->broken );
		$this->assertTrue( (bool) $this->link( self::HOST . '/theirs' )->broken );

		$this->bulk( 'do_bulk_dismiss', $ids );
		$this->assertFalse( (bool) $this->link( self::HOST . '/theirs' )->dismissed );
	}

	public function test_url_search_params_ignore_arrays(): void {
		$_GET = array( 's_link_url' => array( 'x' ), 's_link_text' => 'tekst', 'unknown' => 'y' );
		$this->assertSame( array( 's_link_text' => 'tekst' ), Avpvh_Brkn_Link_Query::getInstance()->get_url_search_params() );
	}
}
