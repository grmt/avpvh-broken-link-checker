<?php
/**
 * Patterns for the exclusion list.
 *
 * @package Avpvh_Broken_Link_Checker
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
final class Avpvh_Brkn_Url_Pattern {

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
			// Escape a "~" unless it is escaped already (preceded by an odd number of backslashes).
			$regex = '~' . preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\\\\~', $m[1] ) . '~' . $m[2];

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
		return self::with_limit(
			static function () use ( $regexes, $url ): bool {
				foreach ( $regexes as $regex ) {
					if ( 1 === preg_match( $regex, $url ) ) {
						return true;
					}
				}

				return false;
			}
		);
	}

	/**
	 * Backtracking budget for user-supplied expressions: plenty for any sensible pattern on
	 * a URL, and an expression that needs more simply doesn't match.
	 */
	const BACKTRACK_LIMIT = 100000;

	/**
	 * Run a callback with the PCRE backtracking limit lowered to BACKTRACK_LIMIT.
	 *
	 * @template T
	 * @param callable(): T $callback Callback.
	 * @return T
	 */
	public static function with_limit( callable $callback ): mixed {
		$previous = ini_get( 'pcre.backtrack_limit' );
		if ( false === $previous || (int) $previous > self::BACKTRACK_LIMIT ) {
			ini_set( 'pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		try {
			return $callback();
		} finally {
			if ( false !== $previous ) {
				ini_set( 'pcre.backtrack_limit', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			}
		}
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
