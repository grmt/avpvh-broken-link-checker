<?php
/**
 * Patterns for the exclusion list.
 *
 * @package Avpvh_Link_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns exclusion list entries into regular expressions.
 *
 * An entry is one of:
 * - a regular expression between slashes, optionally with i, m, s, x or u flags: /\.pdf$/i;
 * - a wildcard pattern with *: https://*.example.com/private/*;
 * - plain text, matched anywhere in the URL (what the list always accepted).
 *
 * Wildcard and plain entries ignore case.
 */
final class Avpvh_Lnkc_Url_Pattern {

	/**
	 * The regular expression for one entry, or null for an empty or invalid entry.
	 *
	 * @param string $pattern Exclusion list entry.
	 * @return string|null
	 */
	public static function to_regex( string $pattern ): ?string {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return null;
		}

		if ( preg_match( '#^/(.+)/([imsxu]*)$#s', $pattern, $m ) ) {
			$regex = '~' . str_replace( '~', '\~', $m[1] ) . '~' . $m[2];

			// An entry that doesn't compile is skipped rather than breaking every check.
			return self::is_valid( $regex ) ? $regex : null;
		}

		$parts = array_map(
			static fn( string $part ): string => preg_quote( $part, '~' ),
			explode( '*', $pattern )
		);

		return '~' . implode( '.*', $parts ) . '~i';
	}

	/**
	 * The regular expressions for a list of entries; empty and invalid entries are left out.
	 *
	 * @param array<mixed> $patterns Exclusion list entries.
	 * @return list<string>
	 */
	public static function compile( array $patterns ): array {
		$regexes = array();
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) ) {
				continue;
			}
			$regex = self::to_regex( $pattern );
			if ( null !== $regex ) {
				$regexes[] = $regex;
			}
		}

		return $regexes;
	}

	/**
	 * Whether a URL matches any of the compiled expressions.
	 *
	 * @param list<string> $regexes From compile().
	 * @param string       $url     URL to test.
	 * @return bool
	 */
	public static function matches_any( array $regexes, string $url ): bool {
		foreach ( $regexes as $regex ) {
			if ( 1 === preg_match( $regex, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an entry is a regular expression that doesn't compile.
	 *
	 * @param string $pattern Exclusion list entry.
	 * @return bool
	 */
	public static function is_invalid_regex( string $pattern ): bool {
		$pattern = trim( $pattern );

		return 1 === preg_match( '#^/(.+)/([imsxu]*)$#s', $pattern ) && null === self::to_regex( $pattern );
	}

	/**
	 * Whether a regular expression compiles.
	 *
	 * @param string $regex Complete expression with delimiters.
	 * @return bool
	 */
	private static function is_valid( string $regex ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match() warns about the very pattern being tested.
		return false !== @preg_match( $regex, '' );
	}
}
