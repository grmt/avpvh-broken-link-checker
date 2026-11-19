<?php
defined( 'ABSPATH' ) || exit;
/**
 * Integrations
 *
 * @package Avpvh_Broken_Link_Checker
 */

if ( ! class_exists( 'Avpvh_Brkn_Integrations' ) ) {

	/**
	 * Integrations
	 */
	class Avpvh_Brkn_Integrations {

		/**
		 * Blc Integrations constructor.
		 */
		public function __construct() {
			if ( class_exists( '\Elementor\Plugin' ) ) {
				require_once AVPVH_BRKN_DIRECTORY . '/integrations/elementor.php';
			}
			if ( class_exists( '\SiteOrigin_Panels' ) ) {
				require_once AVPVH_BRKN_DIRECTORY . '/integrations/siteorigin.php';
				Avpvh_Brkn_Site_Origin::instance();
			}
		}
	}
	new Avpvh_Brkn_Integrations();
}
