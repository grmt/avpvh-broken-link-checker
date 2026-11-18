<?php
/*
Plugin Name: Plaintext URLs
Description: Parse plaintext URLs as links
Version: 1.0
Author: Janis Elsts

ModuleCategory: parser
ModuleClassName: Avpvh_Lnkc_Plaintext_Url
ModuleContext: on-demand
ModuleLazyInit: true

ModulePriority: 800
*/

if ( ! class_exists( 'Avpvh_Lnkc_Plaintext_Url_Base' ) ) {
	require_once 'plaintext-url-parser-base.php';
}

class Avpvh_Lnkc_Plaintext_Url extends Avpvh_Lnkc_Plaintext_Url_Base {

}
