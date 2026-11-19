<?php
defined( 'ABSPATH' ) || exit;

/**
 * @author W-Shadow
 * @copyright 2010
 */


if ( ! class_exists( 'Avpvh_Lnkc_Utility' ) ) {
	class Avpvh_Lnkc_Utility {
		/**
		 * Checks if PHP is running in safe mode
		 * Avpvh_Lnkc_Utility::is_safe_mode()
		 *
		 * @return bool
		 */
		static function is_safe_mode() {
			// safe_mode was removed in PHP 5.4.
			return false;
		}

		public static function is_host_wp_engine() {
			return ( function_exists( 'is_wpe' ) && is_wpe() ) || ( defined( 'IS_WPE' ) && IS_WPE );
		}

		public static function is_host_flywheel() {
			$host_name = 'flywheel';

			return ! empty( $_SERVER['SERVER_SOFTWARE'] ) &&
			       substr( strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ), 0, strlen( $host_name ) ) === strtolower( $host_name );
		}

		/**
		* Avpvh_Lnkc_Utility::is_open_basedir()
		* Checks if open_basedir is enabled
		*
		* @return bool
		*/
		static function is_open_basedir() {
			$open_basedir = ini_get( 'open_basedir' );
			return $open_basedir && ( strtolower( $open_basedir ) != 'none' );
		}

		/**
		* Truncate a string on a specified boundary character.
		*
		* @param string $text The text to truncate.
		* @param integer $max_characters Return no more than $max_characters
		* @param string $break Break on this character. Defaults to space.
		* @param string $pad Pad the truncated string with this string. Defaults to an HTML ellipsis.
		* @return string
		*/
		static function truncate( $text, $max_characters = 0, $break = ' ', $pad = '&hellip;' ) {
			if ( strlen( $text ) <= $max_characters ) {
				return $text;
			}

			$text      = substr( $text, 0, $max_characters );
			$break_pos = strrpos( $text, $break );
			if ( false !== $break_pos ) {
				$text = substr( $text, 0, $break_pos );
			}

			return $text . $pad;
		}

		/**
		* extract_tags()
		* Extract specific HTML tags and their attributes from a string.
		*
		* You can either specify one tag, an array of tag names, or a regular expression that matches the tag name(s).
		* If multiple tags are specified you must also set the $selfclosing parameter and it must be the same for
		* all specified tags (so you can't extract both normal and self-closing tags in one go).
		*
		* The function returns a numerically indexed array of extracted tags. Each entry is an associative array
		* with these keys :
		*   tag_name    - the name of the extracted tag, e.g. "a" or "img".
		*   offset      - the numberic offset of the first character of the tag within the HTML source.
		*   contents    - the inner HTML of the tag. This is always empty for self-closing tags.
		*   attributes  - a name -> value array of the tag's attributes, or an empty array if the tag has none.
		*   full_tag    - the entire matched tag, e.g. '<a href="http://example.com">example.com</a>'. This key
		*                 will only be present if you set $return_the_entire_tag to true.
		*
		* @param string $html The HTML code to search for tags.
		* @param string|array $tag The tag(s) to extract.
		* @param bool $selfclosing  Whether the tag is self-closing or not. Setting it to null will force the script to try and make an educated guess.
		* @param bool $return_the_entire_tag Return the entire matched tag in 'full_tag' key of the results array.
		* @param string $charset The character set of the HTML code. Defaults to ISO-8859-1.
		*
		* @return array An array of extracted tags, or an empty array if no matching tags were found.
		*/
		static function extract_tags( $html, $tag, $selfclosing = null, $return_the_entire_tag = false, $charset = 'ISO-8859-1' ) {

			if ( is_array( $tag ) ) {
				$tag = implode( '|', $tag );
			}

			//If the user didn't specify if $tag is a self-closing tag we try to auto-detect it
			//by checking against a list of known self-closing tags.
			$selfclosing_tags = array( 'area', 'base', 'basefont', 'br', 'hr', 'input', 'img', 'link', 'meta', 'col', 'param' );
			if ( is_null( $selfclosing ) ) {
				$selfclosing = in_array( $tag, $selfclosing_tags );
			}

			//The regexp is different for normal and self-closing tags because I can't figure out
			//how to make a sufficiently robust unified one.
			if ( $selfclosing ) {
				$tag_pattern =
					'@<(?P<tag>' . $tag . ')			# <tag
					(?P<attributes>\s[^>]+)?			# attributes, if any
					\s*/?>								# /> or just >, being lenient here
					@xsi';
			} else {
				$tag_pattern =
					'@<(?P<tag>' . $tag . ')			# <tag
					(?P<attributes>\s[^>]+)?	 		# attributes, if any
					\s*>						 		# >
					(?P<contents>.*?)			 		# tag contents
					</(?P=tag)>					 		# the closing </tag>
					@xsi';
			}

			$attribute_pattern =
				'@
				(?P<name>\w+)											# attribute name
				\s*=\s*
				(
					(?P<quote>[\"\'])(?P<value_quoted>.*?)(?P=quote)	# a quoted value
					|							# or
					(?P<value_unquoted>[^\s"\']+?)(?:\s+|$)				# an unquoted value (terminated by whitespace or EOF)
				)
				@xsi';

			//Find all tags
			if ( ! preg_match_all( $tag_pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				//Return an empty array if we didn't find anything
				return array();
			}

			$tags = array();
			foreach ( $matches as $match ) {

				// Parse tag attributes, if any.
				$attributes = array();
				if ( ! empty( $match['attributes'][0] ) ) {

					if ( preg_match_all( $attribute_pattern, $match['attributes'][0], $attribute_data, PREG_SET_ORDER ) ) {
						//Turn the attribute data into a name->value array
						foreach ( $attribute_data as $attr ) {
							if ( ! empty( $attr['value_quoted'] ) ) {
								$value = $attr['value_quoted'];
							} elseif ( ! empty( $attr['value_unquoted'] ) ) {
								$value = $attr['value_unquoted'];
							} else {
								$value = '';
							}

							// Passing the value through html_entity_decode is handy when you want
							// to extract link URLs or something like that. You might want to remove
							// or modify this call if it doesn't fit your situation.
							$value = html_entity_decode( $value, ENT_QUOTES, $charset );

							$attributes[ $attr['name'] ] = $value;
						}
					}
				}

				$tag = array(
					'tag_name'   => $match['tag'][0],
					'offset'     => $match[0][1],
					'contents'   => ! empty( $match['contents'] ) ? $match['contents'][0] : '', // Empty for self-closing tags.
					'attributes' => $attributes,
				);
				if ( $return_the_entire_tag ) {
					$tag['full_tag'] = $match[0][0];
				}

				$tags[] = $tag;
			}

			return $tags;
		}

		/**
		* Get the value of a cookie.
		*
		* @param string $cookie_name The name of the cookie to return.
		* @param string $default_value Optional. If the cookie is not set, this value will be returned instead. Defaults to an empty string.
		* @return mixed Either the value of the requested cookie, or $default_value.
		*/
		static function get_cookie( $cookie_name, $default_value = '' ) {
			if ( isset( $_COOKIE[ $cookie_name ] ) ) {
				return sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
			} else {
				return $default_value;
			}
		}

		/**
		* Format a time delta using a fuzzy format, e.g. '2 minutes ago', '2 days', etc.
		*
		* @param int $delta Time period in seconds.
		* @param string $type Optional. The output template to use.
		* @return string
		*/
		static function fuzzy_delta( $delta, $template = 'default' ) {

			$templates = array(
				'seconds' => array(
					'default' => /* translators: %d: number of time units. */ _n_noop( '%d second', '%d seconds', 'avpvh-link-checker' ),
					'ago'     => /* translators: %d: number of time units. */ _n_noop( '%d second ago', '%d seconds ago', 'avpvh-link-checker' ),
				),
				'minutes' => array(
					'default' => /* translators: %d: number of time units. */ _n_noop( '%d minute', '%d minutes', 'avpvh-link-checker' ),
					'ago'     => /* translators: %d: number of time units. */ _n_noop( '%d minute ago', '%d minutes ago', 'avpvh-link-checker' ),
				),
				'hours'   => array(
					'default' => /* translators: %d: number of time units. */ _n_noop( '%d hour', '%d hours', 'avpvh-link-checker' ),
					'ago'     => /* translators: %d: number of time units. */ _n_noop( '%d hour ago', '%d hours ago', 'avpvh-link-checker' ),
				),
				'days'    => array(
					'default' => /* translators: %d: number of time units. */ _n_noop( '%d day', '%d days', 'avpvh-link-checker' ),
					'ago'     => /* translators: %d: number of time units. */ _n_noop( '%d day ago', '%d days ago', 'avpvh-link-checker' ),
				),
				'months'  => array(
					'default' => /* translators: %d: number of time units. */ _n_noop( '%d month', '%d months', 'avpvh-link-checker' ),
					'ago'     => /* translators: %d: number of time units. */ _n_noop( '%d month ago', '%d months ago', 'avpvh-link-checker' ),
				),
			);

			if ( $delta < 1 ) {
				$delta = 1;
			}

			if ( $delta < MINUTE_IN_SECONDS ) {
				$units = 'seconds';
			} elseif ( $delta < HOUR_IN_SECONDS ) {
				$delta = intval( $delta / MINUTE_IN_SECONDS );
				$units = 'minutes';
			} elseif ( $delta < DAY_IN_SECONDS ) {
				$delta = intval( $delta / HOUR_IN_SECONDS );
				$units = 'hours';
			} elseif ( $delta < MONTH_IN_SECONDS ) {
				$delta = intval( $delta / DAY_IN_SECONDS );
				$units = 'days';
			} else {
				$delta = intval( $delta / MONTH_IN_SECONDS );
				$units = 'months';
			}

			return sprintf(
				_n(
					$templates[ $units ][ $template ][0], //phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle
					$templates[ $units ][ $template ][1], //phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralPlural
					$delta,
					'avpvh-link-checker'
				),
				$delta
			);
		}

		/**
		* Optimize the plugin's tables
		*
		* @return void
		*/
		static function optimize_database() {
			global $wpdb; /** @var wpdb $wpdb */

			$wpdb->query( "OPTIMIZE TABLE {$wpdb->prefix}avpvh_lnkc_links, {$wpdb->prefix}avpvh_lnkc_instances, {$wpdb->prefix}avpvh_lnkc_synch" );
		}

		/**
		* Get the server's load averages.
		*
		* Returns an array with three samples - the 1 minute avg, the 5 minute avg, and the 15 minute avg.
		*
		* @param integer $cache How long the load averages may be cached, in seconds. Set to 0 to get maximally up-to-date data.
		* @return array|null Array, or NULL if retrieving load data is impossible (e.g. when running on a Windows box).
		*/
		static function get_server_load( $cache = 5 ) {
			static $cached_load = null;
			static $cached_when = 0;

			if ( ! empty( $cache ) && ( ( time() - $cached_when ) <= $cache ) ) {
				return $cached_load;
			}

			$load = null;

			if ( function_exists( 'sys_getloadavg' ) ) {
				$load = sys_getloadavg();
			} else {
				$loadavg_file = '/proc/loadavg';
				if ( @is_readable( $loadavg_file ) ) {
					$load = explode( ' ', file_get_contents( $loadavg_file ) );
					$load = array_map( 'floatval', $load );
				}
			}

			$cached_load = $load;
			$cached_when = time();
			return $load;
		}

		/**
		 * Convert the host of an internationalized domain name or URL to ASCII (punycode).
		 *
		 * Uses PHP's intl extension; without it the URL is returned unchanged.
		 *
		 * @param string $url     Either a domain name or a complete URL.
		 * @param string $charset Encoding of $url. Defaults to the blog's charset.
		 * @return string
		 */
		public static function idn_to_ascii( $url, $charset = '' ) {
			if ( empty( $charset ) ) {
				$charset = get_bloginfo( 'charset' );
			}
			if ( ! in_array( strtoupper( (string) $charset ), array( 'UTF-8', 'UTF8' ), true ) ) {
				$url = mb_convert_encoding( $url, 'UTF-8', $charset );
			}

			return self::map_host( $url, 'idn_to_ascii' );
		}

		/**
		 * Convert the punycode host of a domain name or URL to UTF-8.
		 *
		 * @param string $url Either a domain name or a complete URL.
		 * @return string
		 */
		public static function idn_to_utf8( $url ) {
			return self::map_host( $url, 'idn_to_utf8' );
		}

		/**
		 * Apply an intl IDN conversion to the host part of a domain name or URL.
		 *
		 * @param string $url      Domain name or URL.
		 * @param string $function 'idn_to_ascii' or 'idn_to_utf8'.
		 * @return string
		 */
		private static function map_host( $url, $function ) {
			if ( ! function_exists( $function ) || ! preg_match( '~^(\w+:/*(?:[^/@]*@)?)?([^/:?#]+)(.*)$~s', (string) $url, $matches ) ) {
				return $url;
			}
			$host = $matches[2];
			// Only hosts with non-ASCII characters (to ASCII) or punycode labels (to UTF-8) change.
			if ( 'idn_to_ascii' === $function ? ! preg_match( '/[^\x00-\x7F]/', $host ) : false === stripos( $host, 'xn--' ) ) {
				return $url;
			}
			$flags     = 'idn_to_ascii' === $function ? IDNA_NONTRANSITIONAL_TO_ASCII : IDNA_NONTRANSITIONAL_TO_UNICODE;
			$converted = $function( $host, $flags, INTL_IDNA_VARIANT_UTS46 );

			return false === $converted ? $url : $matches[1] . $converted . $matches[3];
		}

		/**
		* Generate a numeric hash from a string. The result will be constrained to the specified interval.
		*
		* @static
		* @param string $input
		* @param int $min
		* @param int $max
		* @return float
		*/
		public static function constrained_hash( $input, $min = 0, $max = 1 ) {
			$bytes_to_use   = 3;
			$md5_char_count = 32;
			$hash           = substr( md5( $input ), $md5_char_count - $bytes_to_use * 2 );
			$hash           = intval( hexdec( $hash ) );
			return  $min + ( ( $max - $min ) * ( $hash / ( pow( 2, $bytes_to_use * 8 ) - 1 ) ) );
		}

	}//class

}//class_exists
