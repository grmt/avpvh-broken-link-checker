<?php
/**
 * Find and replace in link URLs.
 *
 * @package Avpvh_Broken_Link_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * One search-and-replace over URLs: plain text or a regular expression, with
 * or without regard to case.
 */
final class Avpvh_Brkn_Url_Replacer {

	/**
	 * The search as a regular expression, or null for a case-sensitive plain search.
	 *
	 * @var string|null
	 */
	private ?string $regex;

	/**
	 * Whether the replacement is literal text (a plain search) rather than a preg_replace() template.
	 *
	 * @var bool
	 */
	private bool $literal;

	/**
	 * Set up the replacement.
	 *
	 * @param string $search         Text or regular expression (without delimiters) to look for.
	 * @param string $replace        Replacement; with a regular expression, $1 and \1 refer to groups.
	 * @param bool   $use_regex      Whether $search is a regular expression.
	 * @param bool   $case_sensitive Whether case matters.
	 *
	 * @throws InvalidArgumentException When the search is empty or the expression doesn't compile.
	 */
	public function __construct(
		private string $search,
		private string $replace,
		bool $use_regex = false,
		bool $case_sensitive = false
	) {
		if ( '' === $search ) {
			throw new InvalidArgumentException( esc_html__( 'Enter the text to look for.', 'avpvh-broken-link-checker' ) );
		}

		$this->literal = ! $use_regex;

		if ( $use_regex ) {
			$this->regex = '`' . self::escape_delimiter( $search ) . '`' . ( $case_sensitive ? '' : 'i' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match() warns about the very pattern being tested.
			if ( false === @preg_match( $this->regex, '' ) ) {
				throw new InvalidArgumentException( esc_html__( 'The regular expression is not valid.', 'avpvh-broken-link-checker' ) );
			}
		} elseif ( ! $case_sensitive ) {
			$this->regex = '`' . preg_quote( $search, '`' ) . '`i';
		} else {
			$this->regex = null;
		}
	}

	/**
	 * The URL with the replacement applied; unchanged when nothing matches.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function apply( string $url ): string {
		if ( null === $this->regex ) {
			return str_replace( $this->search, $this->replace, $url );
		}

		$result = Avpvh_Brkn_Url_Pattern::with_limit(
			fn() => $this->literal
				? preg_replace_callback( $this->regex, fn(): string => $this->replace, $url )
				: preg_replace( $this->regex, $this->replace, $url )
		);

		return is_string( $result ) ? $result : $url;
	}

	/**
	 * Escape every backtick (the delimiter) that isn't escaped already.
	 *
	 * @param string $pattern Regular expression without delimiters.
	 * @return string
	 */
	private static function escape_delimiter( string $pattern ): string {
		return (string) preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)`/', '$1\\\\`', $pattern );
	}
}
