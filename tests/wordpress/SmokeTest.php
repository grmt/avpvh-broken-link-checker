<?php
/**
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

class SmokeTest extends Avpvh_Brkn_Test_Case {
	public function test_plugin_is_installed_and_core_loaded(): void {
		$conf = avpvh_brkn_get_configuration();
		$this->assertTrue( (bool) $conf->options['installation_complete'] );
		$this->core();
	}

	public function test_post_links_are_parsed(): void {
		$this->post_with( '<a href="' . self::HOST . '/a">A</a> <img src="' . self::HOST . '/b.png">' );
		$this->assertSame( self::HOST . '/a', $this->link( self::HOST . '/a' )->url );
		$this->assertSame( self::HOST . '/b.png', $this->link( self::HOST . '/b.png' )->url );
	}
}
