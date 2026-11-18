<?php
/**
 * Integrations
 *
 * @package Avpvh_Link_Checker
 */

if ( ! class_exists( 'Avpvh_Lnkc_Integrations' ) ) {

	/**
	 * Integrations
	 */
	class Avpvh_Lnkc_Integrations {

		/**
		 * Blc Integrations constructor.
		 */
		public function __construct() {
			if ( class_exists( '\Elementor\Plugin' ) ) {
				require_once AVPVH_LNKC_DIRECTORY . '/integrations/elementor.php';
			}
			if ( class_exists( '\SiteOrigin_Panels' ) ) {
				require_once AVPVH_LNKC_DIRECTORY . '/integrations/siteorigin.php';
				Avpvh_Lnkc_Site_Origin::instance();
			}
		}
	}
	new Avpvh_Lnkc_Integrations();
}
