<?php
/**
 * Checks links to this site inside WordPress when the HTTP check can't see them.
 *
 * The checker requests every link as an anonymous visitor. A link to this site that only
 * members may open (a private post, a members-only AJAX endpoint, a file behind single sign-on)
 * then looks broken even though it works for the people who see it. When such a link fails
 * with an access-like status, this class looks it up in WordPress instead: the post, the file
 * on disk or the AJAX action. It only overrides the HTTP result when it positively finds the
 * target (or, for a file under wp-content, positively finds it missing).
 *
 * @package AV-PvH Broken Link Checker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local lookups for links to this site.
 */
class Avpvh_Brkn_Local_Links {

	/**
	 * Final HTTP codes that may mean "you may not see this" rather than "this does not exist".
	 * WordPress answers 400 for an AJAX action without a nopriv handler and 404 for a private post.
	 */
	const ACCESS_CODES = array( 400, 401, 403, 404 );

	/**
	 * File types the lookup never reports on: code, logs, dumps, backups and configuration
	 * are not something a post links to, and their presence is nobody's business.
	 */
	const SKIPPED_EXTENSIONS = array( 'php', 'phtml', 'phar', 'log', 'sql', 'gz', 'bak', 'old', 'ini', 'env', 'htaccess', 'htpasswd' );

	/**
	 * Re-check a broken result for a link to this site locally.
	 *
	 * @param string               $url    The checked URL.
	 * @param array<string, mixed> $result The HTTP check result.
	 * @return array<string, mixed>
	 */
	public static function apply( string $url, array $result ): array {
		if ( empty( $result['broken'] ) || ! empty( $result['timeout'] ) || ! self::is_local( $url ) ) {
			return $result;
		}

		$code = $result['final_http_code'] ?? $result['http_code'] ?? 0;
		$code = is_numeric( $code ) ? (int) $code : 0;
		if ( ! in_array( $code, self::ACCESS_CODES, true ) ) {
			return $result;
		}

		$verdict = self::resolve( $url );
		if ( null === $verdict ) {
			return $result;
		}

		$log = is_string( $result['log'] ?? null ) ? $result['log'] : '';
		if ( ! $verdict['ok'] ) {
			$result['log'] = $verdict['note'] . "\n\n" . $log;
			return $result;
		}

		$result['broken']      = false;
		$result['warning']     = false;
		$result['status_code'] = AVPVH_BRKN_LINK_STATUS_OK;
		$result['status_text'] = __( 'Verified locally', 'avpvh-broken-link-checker' );
		$result['log']         = sprintf(
			/* translators: 1: HTTP status code, 2: what the local check found. */
			__( 'HTTP %1$d for an anonymous visitor, but the target exists on this site: %2$s', 'avpvh-broken-link-checker' ),
			$code,
			$verdict['note']
		) . "\n\n" . $log;
		$result['result_hash'] = implode(
			'|',
			array(
				is_scalar( $result['http_code'] ?? null ) ? (string) $result['http_code'] : '0',
				'0',
				'0',
				'local',
				Avpvh_Brkn_Link::remove_query_string( $url ),
			)
		);

		return $result;
	}

	/**
	 * Whether a URL points at this site (home or site URL, with or without "www.").
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_local( string $url ): bool {
		$host = self::bare_host( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		foreach ( array( home_url(), site_url() ) as $own ) {
			if ( self::bare_host( (string) wp_parse_url( $own, PHP_URL_HOST ) ) === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Look a link to this site up in WordPress.
	 *
	 * @param string $url URL to this site.
	 * @return array{ok: bool, note: string}|null Null when the lookup can't tell.
	 */
	public static function resolve( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$query_args = array();
		if ( isset( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query_args );
		}
		$parts['query_args'] = $query_args;

		/**
		 * Let the owner of a URL say whether its target exists.
		 *
		 * Return true or false, or array( 'ok' => bool, 'note' => string ), to decide; return
		 * null to leave it to the built-in lookups. A plugin with a members-only AJAX endpoint
		 * uses this to check the item its query string names.
		 *
		 * @param bool|array|null $status Null.
		 * @param string          $url    The URL.
		 * @param array           $parts  wp_parse_url() of the URL, plus 'query_args'.
		 */
		$status = apply_filters( 'avpvh_brkn_local_link_status', null, $url, $parts );
		if ( is_bool( $status ) ) {
			return array(
				'ok'   => $status,
				'note' => $status ? __( 'confirmed by the plugin that serves it.', 'avpvh-broken-link-checker' ) : __( 'The plugin that serves this URL reports that its target does not exist.', 'avpvh-broken-link-checker' ),
			);
		}
		if ( is_array( $status ) && isset( $status['ok'] ) ) {
			return array(
				'ok'   => (bool) $status['ok'],
				'note' => isset( $status['note'] ) && is_string( $status['note'] ) ? $status['note'] : '',
			);
		}

		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '/';

		$ajax = self::check_ajax( $path, $query_args );
		if ( null !== $ajax ) {
			return $ajax;
		}

		$file = self::check_file( $path );
		if ( null !== $file ) {
			return $file;
		}

		return self::check_post( $url );
	}

	/**
	 * An admin-ajax.php link: is its action registered?
	 *
	 * @param string               $path       Decoded URL path.
	 * @param array<string, mixed> $query_args Query arguments.
	 * @return array{ok: bool, note: string}|null
	 */
	private static function check_ajax( string $path, array $query_args ): ?array {
		$ajax_path = (string) wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
		if ( '' === $ajax_path || untrailingslashit( $path ) !== $ajax_path ) {
			return null;
		}

		$action = isset( $query_args['action'] ) && is_string( $query_args['action'] ) ? $query_args['action'] : '';
		if ( '' === $action ) {
			return null;
		}

		// Plugins may register AJAX handlers only on admin requests, so "not registered" proves nothing.
		if ( has_action( 'wp_ajax_' . $action ) || has_action( 'wp_ajax_nopriv_' . $action ) ) {
			return array(
				'ok'   => true,
				/* translators: %s: AJAX action name. */
				'note' => sprintf( __( 'AJAX action "%s" is registered (for logged-in users).', 'avpvh-broken-link-checker' ), esc_html( $action ) ),
			);
		}

		return null;
	}

	/**
	 * A file in the uploads directory (also private uploads behind single sign-on).
	 *
	 * Only uploads: that is where the files posts link to live. Code, logs and backups
	 * elsewhere in wp-content, and this plugin's own log directory, are never looked up.
	 *
	 * @param string $path Decoded URL path.
	 * @return array{ok: bool, note: string}|null
	 */
	private static function check_file( string $path ): ?array {
		$roots = array();

		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) ) {
			$roots[] = array( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), $uploads['basedir'] );
		}

		foreach ( $roots as list( $url_path, $dir ) ) {
			$url_path = untrailingslashit( $url_path );
			if ( '' === $url_path || ! str_starts_with( $path, $url_path . '/' ) ) {
				continue;
			}

			$relative = substr( $path, strlen( $url_path ) + 1 );
			if ( '' === $relative || str_contains( $relative, '..' ) || str_contains( $relative, "\0" ) ) {
				return null;
			}

			$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
			$basename  = strtolower( wp_basename( $relative ) );
			if ( in_array( $extension, self::SKIPPED_EXTENSIONS, true ) || str_starts_with( $basename, '.' )
				|| str_starts_with( strtolower( $relative ), 'avpvh-broken-link-checker/' ) ) {
				return null;
			}

			$file = trailingslashit( $dir ) . $relative;
			$real = realpath( $file );
			$base = realpath( $dir );
			if ( false !== $real && false !== $base && str_starts_with( $real, trailingslashit( $base ) ) && is_file( $real ) ) {
				return array(
					'ok'   => true,
					/* translators: %s: file path relative to wp-content. */
					'note' => sprintf( __( 'the file %s exists.', 'avpvh-broken-link-checker' ), esc_html( $relative ) ),
				);
			}

			return array(
				'ok'   => false,
				/* translators: %s: file path relative to wp-content. */
				'note' => sprintf( __( 'Local check: the file %s does not exist.', 'avpvh-broken-link-checker' ), esc_html( $relative ) ),
			);
		}

		return null;
	}

	/**
	 * A post, page or other post type, including private and members-only ones.
	 *
	 * @param string $url URL.
	 * @return array{ok: bool, note: string}|null
	 */
	private static function check_post( string $url ): ?array {
		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}

		return array(
			'ok'   => true,
			'note' => sprintf(
				/* translators: 1: post type, 2: post ID, 3: post status. */
				__( '%1$s %2$d exists (status: %3$s).', 'avpvh-broken-link-checker' ),
				esc_html( $post->post_type ),
				$post_id,
				esc_html( $post->post_status )
			),
		);
	}

	/**
	 * Lower-case host without a leading "www.".
	 *
	 * @param string $host Host name.
	 * @return string
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
