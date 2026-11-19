<?php
defined( 'ABSPATH' ) || exit;
/*
Plugin Name: Embedded Vimeo videos
Description: Parse embedded videos from Vimeo
Version: 1.0
Author: Janis Elsts

ModuleCategory: parser
ModuleClassName: Avpvh_Lnkc_Vimeo_Embed
ModuleContext: on-demand
ModuleLazyInit: true
*/

if ( ! class_exists( 'Avpvh_Lnkc_Embed_Parser_Base' ) ) {
	require 'embed-parser-base.php';
}

class Avpvh_Lnkc_Vimeo_Embed extends Avpvh_Lnkc_Embed_Parser_Base {
	var $supported_formats = array( 'html' );

	function init() {
		parent::init();
		$this->url_search_string = 'vimeo.com/moogaloop.swf?';
		$this->short_title       = __( 'Vimeo Video', 'avpvh-link-checker' );
		$this->long_title        = __( 'Embedded Vimeo video', 'avpvh-link-checker' );
	}

	function link_url_from_src( $src ) {
		//Extract video ID from the SRC
		$components = wp_parse_url( $src );
		if ( empty( $components['query'] ) ) {
			return '';
		}

		parse_str( $components['query'], $query );
		if ( empty( $query['clip_id'] ) ) {
			return '';
		} else {
			$video_id = $query['clip_id'];
		}

		//Reconstruct the video permalink based on the ID
		$url = 'http://vimeo.com/' . $video_id;

		return $url;
	}
}
