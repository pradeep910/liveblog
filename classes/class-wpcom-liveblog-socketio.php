<?php

/**
 * The class responsible for adding WebSocket support
 * if the constant LIVEBLOG_USE_SOCKETIO is true and
 * requirements are met.
 *
 * PHP sends messages to a Socket.io server via a Redis
 * server using socket.io-php-emitter.
 */
class WPCOM_Liveblog_Socketio {

	/**
	 * @var SocketIO\Emitter
	 */
	private static $emitter;

	/**
	 * @var string Socket.io server URL
	 */
	private static $url;

	/**
	 * @var string Redis server host
	 */
	private static $redis_host;

	/**
	 * @var int Redis server port
	 */
	private static $redis_port;

	/**
	 * @var Predis\Client
	 */
	private static $redis_client;

	/**
	 * Load everything that is necessary to use WebSocket
	 *
	 * @return void
	 */
	public static function load() {
		// load socket.io-php-emitter
		require( dirname( __FILE__ ) . '/../vendor/autoload.php' );

		self::load_settings();

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		self::$redis_client = new Predis\Client(
			array(
				'host'   => self::$redis_host,
				'port'   => self::$redis_port,
			)
		);

		try {
			self::$redis_client->connect();
			self::$emitter = new SocketIO\Emitter( self::$redis_client );
		} catch ( Exception $exception ) {
			self::add_redis_error();
		}
	}

	/**
	 * Add message to warn the user that it was not possible to
	 * the Redis server.
	 *
	 * @return void
	 */
	private static function add_redis_error() {
		add_action( 'admin_notices', array( __CLASS__, 'show_redis_error' ) );
	}

	/**
	 * Display message to warn the user that it was not possible to
	 * the Redis server.
	 *
	 * @return void
	 */
	public static function show_redis_error() {
		$message = __( 'Liveblog was unable to connect to the Redis server. Please check your configuration.', 'liveblog' );

		WPCOM_Liveblog_Socketio_Loader::show_error_message( $message );
	}

	/**
	 * Load Socket.io settings from PHP constants or use
	 * default values if constants are not defined.
	 *
	 * @return void
	 */
	public static function load_settings() {
		if ( defined( 'LIVEBLOG_SOCKETIO_URL' ) ) {
			self::$url = LIVEBLOG_SOCKETIO_URL;
		} else {
			$parsed_url = parse_url( site_url() );
			self::$url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . ':3000';
		}

		self::$redis_host = defined( 'LIVEBLOG_REDIS_HOST' ) ? LIVEBLOG_REDIS_HOST : 'localhost';
		self::$redis_port = defined( 'LIVEBLOG_REDIS_PORT' ) ? LIVEBLOG_REDIS_PORT : 6379;
	}

	/**
	 * Enqueue the necessary CSS and JS that the WebSocket support needs to function.
	 * Nothing is enqueued if not viewing a Liveblog post.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
		if ( ! WPCOM_Liveblog_Socketio_Loader::should_use_socketio() ) {
			return;
		}

		$handle = 'liveblog-socket.io';

		wp_enqueue_script( 'socket.io', plugins_url( '../js/socket.io.min.js', __FILE__ ), array(), '1.4.4', true );
		wp_enqueue_script(
			$handle,
			plugins_url( '../js/liveblog-socket.io.js', __FILE__ ),
			array( 'jquery', 'socket.io', WPCOM_Liveblog::key ),
			WPCOM_Liveblog::version,
			true
		);

		wp_localize_script( $handle, 'liveblog_socketio_settings',
			apply_filters( 'liveblog_socketio_settings',
				array(
					'url' => self::$url,

					// i18n
					'unable_to_connect' => esc_html__( 'Unable to connect to the server to get new entries', 'liveblog' ),
				)
			)
		);
	}

	/**
	 * True if able to connect to the Redis server.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return self::$redis_client->isConnected()
		       && is_object( self::$emitter )
		       && 'SocketIO\Emitter' === get_class( self::$emitter );
	}

	/**
	 * Emits a message to all connected socket.io clients
	 * via Redis.
	 *
	 * @param string $name the name of the message
	 * @param string|array $data the content of the message
	 * @return void
	 */
	public static function emit( $name, $data ) {
		if ( self::is_connected() ) {
			self::$emitter->json->emit( $name, json_encode( $data ) );
		}

		exit;
	}
}
