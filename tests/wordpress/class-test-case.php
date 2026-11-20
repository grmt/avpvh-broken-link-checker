<?php
/**
 * Shared helpers for the WordPress integration tests.
 *
 * @package Avpvh_Broken_Link_Checker
 */

// phpcs:disable

/**
 * Base test case: a clean link table, an admin user, fast and offline link checks.
 *
 * Links in the tests point at a public IP literal (93.184.215.14): the checker clears it without
 * a DNS lookup, and pre_http_request answers for it, so nothing leaves the machine. Tests of the
 * cURL transport itself start a local PHP server instead (see Avpvh_Brkn_Local_Server).
 */
abstract class Avpvh_Brkn_Test_Case extends WP_UnitTestCase {

	/** Host of the links the tests check. */
	const HOST = 'http://93.184.215.14';

	/** @var array<string, int|array> Path => status code, or [code, headers, body]. */
	protected array $responses = array();

	/** @var list<array{url: string, method: string}> Requests the checker made. */
	protected array $requests = array();

	protected int $admin_id;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		foreach ( array( 'links', 'instances', 'synch' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}avpvh_brkn_{$table}" );
		}

		$conf = avpvh_brkn_get_configuration();
		$conf->options['target_resource_usage'] = 1;
		$conf->options['max_execution_time']    = 60;
		$conf->options['exclusion_list']        = array();
		$conf->options['host_rules']            = array();
		$conf->options['check_threshold']       = 72;
		$conf->save_options();
		Avpvh_Brkn_Host_Rules::reset();
		if ( isset( $GLOBALS['ws_link_checker'] ) ) {
			$GLOBALS['ws_link_checker']->build_exclusion_regex();
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'dashboard' );

		// Check through the WordPress HTTP API, answered by pre_http_request.
		add_filter( 'avpvh_brkn_use_curl', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_response' ), 10, 3 );
		$this->reset_http_checker();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_response' ), 10 );
		remove_all_filters( 'avpvh_brkn_use_curl' );
		$this->reset_http_checker();
		parent::tear_down();
	}

	/**
	 * Drop the cached HTTP checker, so the next one picks the transport (and throttle) anew.
	 */
	protected function reset_http_checker(): void {
		$manager = Avpvh_Brkn_Module_Manager::getInstance();
		( function () {
			unset( $this->instances['http'] );
		} )->call( $manager );
	}

	/**
	 * pre_http_request: answer from $this->responses, 404 for anything else.
	 */
	public function fake_response( $pre, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'method' => $args['method'] ?? 'GET' );
		if ( false !== $pre ) {
			return $pre; // A test's own filter answered already.
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$response = $this->responses[ $path ] ?? 404;
		if ( is_int( $response ) ) {
			$response = array( $response, array(), '' );
		}
		list( $code, $headers, $body ) = $response;

		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array( 'code' => $code, 'message' => get_status_header_desc( $code ) ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * A published post by the admin with the given HTML, its links parsed into the link table.
	 */
	protected function post_with( string $html, array $args = array() ): int {
		$id = self::factory()->post->create(
			$args + array(
				'post_content' => $html,
				'post_status'  => 'publish',
				'post_author'  => $this->admin_id,
			)
		);
		$this->parse();
		return $id;
	}

	/**
	 * Parse every container that is out of sync, as the first half of work() does.
	 */
	protected function parse(): void {
		while ( $containers = Avpvh_Brkn_Container_Helper::get_unsynched_containers( 50 ) ) {
			foreach ( $containers as $container ) {
				$container->synch();
			}
		}
	}

	/**
	 * The link with this URL.
	 */
	protected function link( string $url ): Avpvh_Brkn_Link {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT link_id FROM {$wpdb->prefix}avpvh_brkn_links WHERE url = %s", $url ) );
		$this->assertGreaterThan( 0, $id, "no link for $url" );
		return new Avpvh_Brkn_Link( $id );
	}

	/**
	 * The plugin's admin core.
	 */
	protected function core(): Avpvh_Brkn_Link_Checker {
		global $ws_link_checker;
		$this->assertInstanceOf( Avpvh_Brkn_Link_Checker::class, $ws_link_checker );
		return $ws_link_checker;
	}

	/**
	 * Call a protected or private method of an object.
	 */
	protected function call( object $object, string $method, ...$args ) {
		return ( fn() => $this->$method( ...$args ) )->call( $object );
	}

	/**
	 * Output of a callback.
	 */
	protected function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$output = (string) ob_get_clean();
		}
		return $output;
	}
}

/**
 * A PHP built-in server on 127.0.0.1 serving tests/wordpress/fixtures/server.php, for tests of
 * the cURL transport. The checker only talks to 127.0.0.1 because it is the site's home (the
 * tests set it); any other loopback address is refused.
 */
final class Avpvh_Brkn_Local_Server {

	/** @var resource|null */
	private static $process = null;

	public static int $port = 0;

	public static function start(): string {
		if ( null === self::$process ) {
			$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
			self::$port = (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
			fclose( $socket );

			$router        = __DIR__ . '/fixtures/server.php';
			self::$process = proc_open(
				array( PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router ),
				array( array( 'pipe', 'r' ), array( 'file', '/dev/null', 'w' ), array( 'file', '/dev/null', 'w' ) ),
				$pipes
			);
			register_shutdown_function( array( self::class, 'stop' ) );

			for ( $i = 0; $i < 50; $i++ ) {
				$probe = @fsockopen( '127.0.0.1', self::$port, $errno, $errstr, 0.1 );
				if ( $probe ) {
					fclose( $probe );
					break;
				}
				usleep( 100000 );
			}
		}

		return 'http://127.0.0.1:' . self::$port;
	}

	public static function stop(): void {
		if ( null !== self::$process ) {
			proc_terminate( self::$process );
			proc_close( self::$process );
			self::$process = null;
		}
	}
}
