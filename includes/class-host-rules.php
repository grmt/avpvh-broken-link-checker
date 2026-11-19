<?php
/**
 * Per-host request rules.
 *
 * @package Avpvh_Broken_Link_Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * How the checker treats the links of one host and its subdomains.
 *
 * Some sites refuse requests that don't look like a browser (rechtspraak.nl
 * answers 403 to an honest link checker), rate-limit, or answer a code that
 * doesn't mean the page is gone. A rule per host sets the user agent, the
 * minimum time between two requests, the HTTP codes that count as working,
 * or skips the host altogether.
 *
 * @phpstan-type Avpvh_Brkn_Host_Rule array{host: string, user_agent: string, min_interval: int, ok_codes: list<int>, skip: bool}
 */
final class Avpvh_Brkn_Host_Rules {

	/**
	 * The user agent sent when no rule or setting names another: a current
	 * desktop browser, as many sites refuse anything else.
	 */
	public const BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

	/**
	 * Longest wait for a host's minimum interval, in seconds. The worker has
	 * a time budget; a longer interval is honoured over several runs instead.
	 */
	public const MAX_WAIT = 60;

	/**
	 * Highest minimum interval a rule may set, in seconds.
	 */
	public const MAX_INTERVAL = 3600;

	/**
	 * Rules, most specific (longest host) first.
	 *
	 * @var list<Avpvh_Brkn_Host_Rule>
	 */
	private array $rules;

	/**
	 * User agent for hosts without their own.
	 *
	 * @var string
	 */
	private string $default_user_agent;

	/**
	 * Instance built from the plugin settings.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Build from raw rules (as stored in the settings).
	 *
	 * @param mixed  $rules              Stored rules; anything invalid is dropped.
	 * @param string $default_user_agent User agent for hosts without one; empty for the browser one.
	 */
	public function __construct( mixed $rules, string $default_user_agent = '' ) {
		$this->rules              = self::normalize_rules( $rules );
		$this->default_user_agent = '' === trim( $default_user_agent ) ? self::BROWSER_USER_AGENT : self::clean_header_value( $default_user_agent );
		usort(
			$this->rules,
			static fn( array $a, array $b ): int => strlen( $b['host'] ) <=> strlen( $a['host'] )
		);
	}

	/**
	 * The rules from the plugin settings.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			$conf             = avpvh_brkn_get_configuration();
			$user_agent       = $conf->get( 'default_user_agent', '' );
			self::$instance = new self(
				$conf->get( 'host_rules', array() ),
				is_string( $user_agent ) ? $user_agent : ''
			);
		}

		return self::$instance;
	}

	/**
	 * Forget the cached instance, after the settings changed.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Validate stored or submitted rules. Rows without a valid host are dropped,
	 * as is a second row for the same host.
	 *
	 * @param mixed $raw List of rule arrays.
	 * @return list<Avpvh_Brkn_Host_Rule>
	 */
	public static function normalize_rules( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rules = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$host = self::normalize_host( is_string( $row['host'] ?? null ) ? $row['host'] : '' );
			if ( '' === $host || isset( $rules[ $host ] ) ) {
				continue;
			}

			$codes = $row['ok_codes'] ?? array();
			if ( is_string( $codes ) ) {
				$codes = self::parse_codes( $codes );
			} elseif ( is_array( $codes ) ) {
				$codes = self::parse_codes( implode( ',', array_filter( $codes, 'is_scalar' ) ) );
			} else {
				$codes = array();
			}

			$interval = is_numeric( $row['min_interval'] ?? null ) ? (int) $row['min_interval'] : 0;

			$rules[ $host ] = array(
				'host'         => $host,
				'user_agent'   => is_string( $row['user_agent'] ?? null ) ? self::clean_header_value( $row['user_agent'] ) : '',
				'min_interval' => max( 0, min( self::MAX_INTERVAL, $interval ) ),
				'ok_codes'     => $codes,
				'skip'         => ! empty( $row['skip'] ),
			);
		}

		return array_values( $rules );
	}

	/**
	 * A host as rules store it: lower case, without scheme, port, path, "*." or
	 * surrounding dots, internationalised names in punycode. Empty when invalid.
	 *
	 * @param string $host Host, "*.host" or a URL.
	 * @return string
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );
		if ( str_contains( $host, '://' ) ) {
			$parsed = wp_parse_url( $host, PHP_URL_HOST );
			$host   = is_string( $parsed ) ? $parsed : '';
		}
		$host = (string) preg_replace( '~[/:?#].*$~s', '', $host );
		$host = trim( (string) preg_replace( '~^\*\.~', '', $host ), '.' );

		if ( preg_match( '/[^\x00-\x7F]/', $host ) ) {
			$host = strtolower( Avpvh_Brkn_Utility::idn_to_ascii( $host, 'UTF-8' ) );
		}

		return 1 === preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host ) ? $host : '';
	}

	/**
	 * HTTP status codes from text such as "403, 429".
	 *
	 * @param string $text Codes separated by commas or spaces.
	 * @return list<int>
	 */
	public static function parse_codes( string $text ): array {
		$codes = array();
		foreach ( preg_split( '/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $code ) {
			if ( ctype_digit( $code ) && (int) $code >= 100 && (int) $code <= 599 ) {
				$codes[] = (int) $code;
			}
		}
		$codes = array_values( array_unique( $codes ) );
		sort( $codes );

		return $codes;
	}

	/**
	 * All rules, most specific first.
	 *
	 * @return list<Avpvh_Brkn_Host_Rule>
	 */
	public function rules(): array {
		return $this->rules;
	}

	/**
	 * The rule for a URL's host: the rule for the host itself or for the nearest
	 * parent domain.
	 *
	 * @param string $url URL or host name.
	 * @return Avpvh_Brkn_Host_Rule|null
	 */
	public function find( string $url ): ?array {
		$host = self::host_of( $url );
		if ( '' === $host ) {
			return null;
		}

		foreach ( $this->rules as $rule ) {
			if ( $host === $rule['host'] || str_ends_with( $host, '.' . $rule['host'] ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * The user agent to send for a URL.
	 *
	 * @param string $url URL to request.
	 * @return string
	 */
	public function user_agent( string $url ): string {
		$rule = $this->find( $url );

		return null !== $rule && '' !== $rule['user_agent'] ? $rule['user_agent'] : $this->default_user_agent;
	}

	/**
	 * Whether the URL's host is not to be checked at all.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_skipped( string $url ): bool {
		$rule = $this->find( $url );

		return null !== $rule && $rule['skip'];
	}

	/**
	 * The minimum time between two requests to the URL's host, in seconds; 0 for no rule.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	public function min_interval( string $url ): int {
		$rule = $this->find( $url );

		return null === $rule ? 0 : $rule['min_interval'];
	}

	/**
	 * Wait until the URL's host may be requested again, then note the request.
	 *
	 * The time of the last request is kept in a transient, so the interval also
	 * holds between two runs of the checker.
	 *
	 * @param string $url URL about to be requested.
	 * @return float Seconds waited.
	 */
	public function wait_for_host( string $url ): float {
		$interval = $this->min_interval( $url );
		if ( $interval <= 0 ) {
			return 0.0;
		}

		$rule = $this->find( $url );
		$key  = 'avpvh_brkn_host_' . md5( null === $rule ? self::host_of( $url ) : $rule['host'] );
		$last = get_transient( $key );
		$wait = is_numeric( $last ) ? (float) $last + $interval - microtime( true ) : 0.0;
		$wait = max( 0.0, min( (float) self::MAX_WAIT, $wait ) );

		if ( $wait > 0 ) {
			usleep( (int) round( $wait * 1000000 ) );
		}
		set_transient( $key, microtime( true ), $interval + 60 );

		return $wait;
	}

	/**
	 * Treat a broken result as working when its HTTP code is one the host's rule accepts.
	 *
	 * @param string               $url    Checked URL.
	 * @param array<string, mixed> $result Checker result.
	 * @return array<string, mixed>
	 */
	public function apply_ok_codes( string $url, array $result ): array {
		$rule = $this->find( $url );
		if ( null === $rule || empty( $rule['ok_codes'] ) || empty( $result['broken'] ) || ! empty( $result['timeout'] ) ) {
			return $result;
		}

		$code = $result['final_http_code'] ?? $result['http_code'] ?? 0;
		$code = is_numeric( $code ) ? (int) $code : 0;
		if ( ! in_array( $code, $rule['ok_codes'], true ) ) {
			return $result;
		}

		$note = sprintf(
			/* translators: 1: HTTP status code, 2: host name. */
			__( 'HTTP %1$d counts as working: host rule for %2$s.', 'avpvh-broken-link-checker' ),
			$code,
			$rule['host']
		);

		$result['broken']      = false;
		$result['warning']     = false;
		$result['status_code'] = AVPVH_BRKN_LINK_STATUS_OK;
		$result['status_text'] = __( 'Accepted by host rule', 'avpvh-broken-link-checker' );
		$result['log']         = $note . "\n\n" . ( is_string( $result['log'] ?? null ) ? $result['log'] : '' );
		$final_url             = is_string( $result['final_url'] ?? null ) ? $result['final_url'] : $url;
		$result['result_hash'] = implode(
			'|',
			array(
				is_scalar( $result['http_code'] ?? null ) ? (string) $result['http_code'] : '0',
				'0',
				'0',
				class_exists( 'Avpvh_Brkn_Link' ) ? Avpvh_Brkn_Link::remove_query_string( $final_url ) : $final_url,
			)
		);

		return $result;
	}

	/**
	 * The lower-case host of a URL, or the string itself when it is a host name.
	 *
	 * @param string $url URL or host name.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		if ( ! str_contains( $url, '/' ) ) {
			return trim( strtolower( $url ), '.' );
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? trim( strtolower( $host ), '.' ) : '';
	}

	/**
	 * A header value without line breaks or control characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function clean_header_value( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}
}
