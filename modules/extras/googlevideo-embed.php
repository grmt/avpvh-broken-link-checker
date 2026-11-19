<?php
defined( 'ABSPATH' ) || exit;
/*
Plugin Name: Embedded GoogleVideo videos
Description: Parse embedded videos from GoogleVideo
Version: 1.0
Author: Janis Elsts

ModuleCategory: parser
ModuleClassName: Avpvh_Lnkc_Google_Video_Embed
ModuleContext: on-demand
ModuleLazyInit: true

ModulePriority: 110
*/

if ( ! class_exists( 'Avpvh_Lnkc_Embed_Parser_Base' ) ) {
	require 'embed-parser-base.php';
}

class Avpvh_Lnkc_Google_Video_Embed extends Avpvh_Lnkc_Embed_Parser_Base {

	function init() {
		parent::init();
		$this->short_title       = __( 'GoogleVideo Video', 'avpvh-link-checker' );
		$this->long_title        = __( 'Embedded GoogleVideo video', 'avpvh-link-checker' );
		$this->url_search_string = 'video.google.com/';
	}

	function link_url_from_src( $src ) {
		parse_str( wp_parse_url( $src, PHP_URL_QUERY ), $query );
		$url = 'http://video.google.com/videoplay?' . build_query( array( 'docid' => $query['docid'] ) );
		return $url;
	}
}

