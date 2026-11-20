<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: Taxonomy reference
 * Description: Parses taxonomy references stored as taxonomy slugs and checks whether the referenced taxonomy is still registered.
 * Version: 1.0
 * Author: Andrey @ WPMU DEV
 * ModuleID: taxonomy_reference
 * ModuleCategory: parser
 * ModuleClassName: Avpvh_Brkn_Taxonomy_Ref_Parser
 * ModuleContext: on-demand
 * ModuleLazyInit: true
 * ModuleAlwaysActive: true
 * ModuleHidden: true
*/

/**
 * Parser for fields whose value is a single taxonomy slug.
 *
 * The parser converts the slug into a taxonomy:// pseudo-URL so that the
 * Avpvh_Brkn_Taxonomy_Checker can verify whether the taxonomy is still registered.
 *
 * @package Broken Link Checker
 * @access public
 */
class Avpvh_Brkn_Taxonomy_Ref_Parser extends Avpvh_Brkn_Parser {

	/**
	 * Supported containers and parsers.
	 *
	 * @var string[]
	 */
	public $supported_containers = array( 'custom_taxonomy' );
	/**
	 * Supported parsers.
	 *
	 * @var string[]
	 */
	public $supported_parsers = array( 'taxonomy_reference' );

	/**
	 * Convert a taxonomy slug into a link instance.
	 *
	 * @param string $content        The taxonomy slug.
	 * @param string $base_url       Unused.
	 * @param string $default_link_text The taxonomy slug is also used as the link text.
	 * @return Avpvh_Brkn_Link_Instance[]
	 */
	public function parse( $content, $base_url = '', $default_link_text = '' ) {
		$instances = array();

		$taxonomy = trim( $content );
		if ( '' === $taxonomy ) {
			return $instances;
		}

		// Build the pseudo-URL: taxonomy://<taxonomy_slug>.
		$url = 'taxonomy://' . rawurlencode( $taxonomy );

		$instance            = new Avpvh_Brkn_Link_Instance();
		$instance->set_parser( $this );
		$instance->raw_url   = $taxonomy;
		$instance->link_text = '' !== $default_link_text ? $default_link_text : $taxonomy;

		$link_obj = new Avpvh_Brkn_Link( $url );
		$instance->set_link( $link_obj );

		$instances[] = $instance;

		return $instances;
	}

	/**
	 * "Editing" a taxonomy reference is not meaningful for this checker; return it unchanged.
	 *
	 * @param string $content     The current taxonomy slug.
	 * @param string $new_url     Ignored.
	 * @param string $old_url     Ignored.
	 * @param string $old_raw_url Ignored.
	 * @return array
	 */
	public function edit( $content, $new_url, $old_url, $old_raw_url ) {
		return array(
			'content' => $content,
			'raw_url' => $content,
		);
	}

	/**
	 * "Unlinking" a taxonomy reference is not applicable; return the content unchanged.
	 *
	 * @param string $content The current taxonomy slug.
	 * @param string $url     Ignored.
	 * @param string $raw_url Ignored.
	 * @return string
	 */
	public function unlink( $content, $url, $raw_url ) {
		return $content;
	}
}
