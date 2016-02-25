<?php
/**
 * BP Reply By Email Connect Class
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sets up an IMAP connection.
 *
 * Instantiate the class with the static init() method, which will return the
 * the IMAP resource on success.
 *
 * Note: You will need to manually close the connection yourself with
 * {@link imap_close()}.
 *
 * @since 1.0-RC3
 */
class BP_Reply_By_Email_Connect {
	/**
	 * Static initializer.
	 *
	 * Returns IMAP resource on success using the connect() method.
	 *
	 * For parameters, see constructor.
	 *
	 * @return resource|bool The IMAP resource on success. Boolean false on failure.
	 */
	public static function init( $args = array(), $connection = false ) {
		$instance = new self( $args, $connection );

		return $instance->connect();
	}

	/**
	 * Constructor.
	 *
	 * @param array $args {
	 *     An array of arguments.
	 *
	 *     @type string $host The server name. (eg. imap.gmail.com)
	 *     @type int $port The port number used by the server name.
	 *     @type string $username The username used to login to the server.
	 *     @type string $password The password used to login to the server.
	 *     @type bool $validatecert Whether to validate certificates from TLS/
	 *                              SSL server. Defaults to true.
	 *     @type string $mailbox The mailbox to open. Defaults to 'INBOX'.
	 *     @type int $retries How many times to try reconnection on failure.
	 *                        Defaults to 1.
	 *     @type bool $reconnect Are we attempting a reconnection? Defaults to false.
	 * }
	 * @param resource $connection The IMAP resource if attempting a reconnection.
	 * @return resource|bool The IMAP resource on success. Boolean false on failure.
	 */
	public function __construct( $args = array(), $connection = false ) {
		$this->args = wp_parse_args( $args, array (
	 		'host'         => bp_rbe_get_setting( 'servername' ),
	 		'port'         => bp_rbe_get_setting( 'port' ),
	 		'username'     => bp_rbe_get_setting( 'username' ),
	 		'password'     => bp_rbe_get_setting( 'password' ) ? bp_rbe_decode( array( 'string' => bp_rbe_get_setting( 'password' ), 'key' => wp_salt() ) ) : false,
	 		'validatecert' => true, // @todo change this to an admin setting later
	 		'mailbox'      => 'INBOX',
	 		'retries'      => 1,
	 		'reconnect'    => false
		) );

		$this->connection = $connection;
	}

	/**
	 * Connect to an IMAP inbox.
	 *
	 * @return resource|bool The IMAP resource on success. Boolean false on failure.
	 */
	protected function connect() {
		// stop if IMAP module does not exist
		if ( ! function_exists( 'imap_open' ) ) {
			return false;
		}

		$mailbox = $this->get_mailbox();

		//
		if ( $this->is_reconnection() ) {
			// if PHP is 5.2+, use extra parameter to only try connecting once
			if ( ! empty( $this->args['retries'] ) && version_compare( PHP_VERSION, '5.2.0') >= 0 ) {
				$resource = imap_reopen( $this->connection, $mailbox, 0, (int) $this->args['retries'] );

			// PHP is older, so use the default retry value of 3
			} else {
				$resource = imap_reopen( $this->connection, $mailbox );
			}

		} else {
			// if PHP is 5.2+, use extra parameter to only try connecting once
			if ( ! empty( $this->args['retries'] ) && version_compare( PHP_VERSION, '5.2.0') >= 0 ) {
				$resource = imap_open( $mailbox, $this->args['username'], $this->args['password'], 0, (int) $this->args['retries'] );

			// PHP is older, so use the default retry value of 3
			} else {
				$resource = imap_open( $mailbox, $this->args['username'], $this->args['password'] );
			}

		}

		return $resource;
	}

	/**
	 * Get the mailbox we want to connect to.
	 *
	 * This, basically, returns the first parameter of {@link imap_open()}, which
	 * is also the second parameter of {@link imap_reopen()}.
	 *
	 * @return string
	 */
	protected function get_mailbox() {
		$ssl = self::is_ssl( $this->args['port'] ) ? '/ssl' : '';

		$validate_cert = (bool) $this->args['validatecert'] === true ? '' : '/novalidate-cert';

		// Need to readjust this before public release
		// In the meantime, let's add a filter!
		$mailbox = '{' . $this->args['host'] . ':' . $this->args['port'] . '/imap' . $ssl . $validate_cert . '}' . $this->args['mailbox'];

		return apply_filters( 'bp_rbe_mailbox', $mailbox );
	}

	/**
	 * Should we enable SSL for the IMAP connection?
	 *
	 * Check to see if both the OpenSSL and IMAP modules are loaded.  Next, see if
	 * the port is explictly 993.
	 *
	 * @param int $port The port number used for the IMAP connection.
	 * @return bool
	 */
	public static function is_ssl( $port = 0 ) {
		$modules = get_loaded_extensions();

		if ( ! in_array( 'openssl', $modules ) ) {
			return false;
		}

		if ( ! in_array( 'imap', $modules ) ) {
			return false;
		}

		if ( empty( $port ) ) {
			return false;
		}

		// port 993 is the standard port for SSL connections
		// so we do a hardcoded check for it for now
		if ( $port === 993 ) {
			$retval = true;
		} else {
			$retval = false;
		}

		// if your SSL port is not the standard 993, override this with the
		// 'bp_rbe_is_imap_ssl' filter
		return apply_filters( 'bp_rbe_is_imap_ssl', $retval, $port );
	}

	/**
	 * Whether we should attempt a reconnection.
	 *
	 * @return bool
	 */
	protected function is_reconnection() {
		return ( ! empty( $this->args['reconnect'] ) && is_resource( $this->connection ) );
	}
}