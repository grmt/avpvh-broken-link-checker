<?php
defined( 'ABSPATH' ) || exit;

/*
Plugin Name: Dummy
Description:
Version: 1.0
Author: Janis Elsts

ModuleID: dummy
ModuleCategory: container
ModuleClassName: Avpvh_Brkn_Dummy_Manager
ModuleAlwaysActive: true
ModuleHidden: true
*/

/**
 * A "dummy" container class that can be used as a fallback when the real container class can't be found.
 *
 *
 * @package Broken Link Checker
 * @access public
 */
class Avpvh_Brkn_Dummy_Container extends Avpvh_Brkn_Container {

	function synch() {
		//Just mark it as synched so that it doesn't bother us anymore.
		$this->mark_as_synched();
	}

	function edit_link( $field_name, $parser, $new_url, $old_url = '', $old_raw_url = '', $new_text = null ) {
		return new WP_Error(
			'container_not_found',
			sprintf(
				__( "I don't know how to edit a '%1\$s' [%2\$d].", 'avpvh-broken-link-checker' ),
				$this->container_type,
				$this->container_id
			)
		);
	}

	function unlink( $field_name, $parser, $url, $raw_url = '' ) {
		return new WP_Error(
			'container_not_found',
			sprintf(
				__( "I don't know how to edit a '%1\$s' [%2\$d].", 'avpvh-broken-link-checker' ),
				$this->container_type,
				$this->container_id
			)
		);
	}

	function ui_get_source( $container_field, $context = 'display' ) {
		return sprintf(
			'<em>Unknown source %s[%d]:%s</em>',
			esc_html( $this->container_type ),
			(int) $this->container_id,
			esc_html( $container_field )
		);
	}
}

/**
 * A dummy manager class.
 *
 * @package Broken Link Checker
 * @access public
 */
class Avpvh_Brkn_Dummy_Manager extends Avpvh_Brkn_Container_Manager {

	var $container_class_name = 'Avpvh_Brkn_Dummy_Container';

	function resynch( $forced = false ) {
		//Do nothing.
	}
}
