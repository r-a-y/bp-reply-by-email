<?php
/**
 * BP Reply By Email Classes
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

/**
 * Handles checking an IMAP inbox and posting items to BuddyPress.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
class BP_Reply_By_Email_IMAP {

	/**
	 * Holds the single-running RBE IMAP object.
	 *
	 * @var BP_Reply_By_Email_IMAP
	 */
	private static $instance = false;

	/**
	 * Holds the current IMAP connection.
	 */
	protected $connection = false;

	/**
	 * Is the current email message body HTML?
	 */
	public static $html = false;

	/**
	 * Creates a singleton instance of the BP_Reply_By_Email_IMAP class
	 *
	 * @return BP_Reply_By_Email_IMAP object
	 * @static
	 */
	public static function &init() {
		if ( self::$instance === false ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor. Intentionally left empty.
	 *
	 * Instantiate this class by using {@link BP_Reply_By_Email_IMAP::init()}.
	 */
	private function __construct() {}

	/**
	 * The main method we use to parse an IMAP inbox.
	 */
	public function run() {

		// $instance must be initialized before we go on!
		if ( self::$instance === false )
			return false;

		// If safe mode isn't on, then let's set the execution time to unlimited
		if ( ! ini_get( 'safe_mode' ) )
			set_time_limit(0);

		// Try to connect
		$connect = $this->connect();

		if ( ! $connect ) {
			return false;
		}

		// Total duration we should keep the IMAP stream alive for in seconds
		$duration = bp_rbe_get_execution_time();
		bp_rbe_log( '--- Keep alive for ' . $duration / 60 . ' minutes ---' );

		bp_rbe_remove_imap_lock();

		// Mark the current timestamp, mark the future time when we should close the IMAP connection;
		// Do our parsing until $future > $now; re-mark the timestamp at end of loop... rinse and repeat!
		for ( $now = time(), $future = time() + $duration; $future > $now; $now = time() ) {

			// Get number of messages
			$message_count = imap_num_msg( $this->connection );

			// If there are messages in the inbox, let's start parsing!
			if( $message_count != 0 ) {

				// According to this:
				// http://www.php.net/manual/pl/function.imap-headerinfo.php#95012
				// This speeds up rendering the email headers... could be wrong
				imap_headers( $this->connection );

				bp_rbe_log( '- Checking inbox -' );

				// Loop through each email message
				for ( $i = 1; $i <= $message_count; ++$i ) {

					// flush object cache if necessary
					//
					// we only flush the cache if the default object cache is in use
					// why? b/c the default object cache is only meant for single page loads and
					// since RBE runs in the background, we need to flush the object cache so WP
					// will do a direct DB query for the next data fetch
					if ( ! wp_using_ext_object_cache() ) {
						wp_cache_flush();
					}

					self::$html = false;

					$content = self::body_parser( $this->connection, $i );
					$headers = $this->get_mail_headers( $this->connection, $i );

					$data = array(
						'headers'    => $headers,
						'to_email'   => BP_Reply_By_Email_Parser::get_header( $headers, 'To' ),
						'from_email' => BP_Reply_By_Email_Parser::get_header( $headers, 'From' ),
						'content'    => $content,
						'is_html'    => self::$html,
						'subject'    => BP_Reply_By_Email_Parser::get_header( $headers, 'Subject' )
					);

					$parser = BP_Reply_By_Email_Parser::init( $data, $i );

					if ( is_wp_error( $parser ) ) {
						//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, $parser->get_error_code() );
						do_action( 'bp_rbe_no_match', $parser, $data, $i, $connection );
					}

					// do something during the loop
					// we mark the message for deletion here via this hook
					do_action( 'bp_rbe_imap_loop', $this->connection, $i );

					// unset some variables at the end of the loop
					unset( $content, $headers, $data, $parser );
				}

				// do something after the loop
				do_action( 'bp_rbe_imap_after_loop', $this->connection );

			}

			// stop the loop if necessary
			if ( bp_rbe_should_stop() ) {
				if ( $this->close() ) {
					bp_rbe_log( '--- Manual termination of connection confirmed! Kaching! ---' );
				} else {
					bp_rbe_log( '--- Error - invalid connection during manual termination ---' );
				}

				remove_action( 'shutdown', 'bp_rbe_spawn_inbox_check' );
				exit();
			}

			// Give IMAP server a break
			sleep( 10 );

			// If the IMAP connection is down, reconnect
			if( ! imap_ping( $this->connection ) ) {
				bp_rbe_log( '-- IMAP connection is down, attempting to reconnect... --' );

				if ( bp_rbe_is_connecting( array( 'clearcache' => true ) ) ) {
					bp_rbe_log( '--- RBE is already attempting to connect - stopping connection attempt ---' );
					continue;
				}

				// add lock marker before connecting
				bp_rbe_add_imap_lock();

				// attempt to reconnect
				$reopen = BP_Reply_By_Email_Connect::init( array( 'reconnect' => true ), $this->connection );

				if ( $reopen ) {
					bp_rbe_log( '-- Reconnection successful! --' );
				} else {
					bp_rbe_log( '-- Reconnection failed! :( --' );
					bp_rbe_log( 'Cannot connect: ' . imap_last_error() );

					// cleanup RBE after failure
					bp_rbe_cleanup();

					remove_action( 'shutdown', 'bp_rbe_spawn_inbox_check' );
					exit();
				}
			}

			// Unset some variables to clear some memory
			unset( $message_count );
		}

		if ( $this->close() ) {
			bp_rbe_log( '--- Closing current connection automatically ---' );
		} else {
			bp_rbe_log( '--- Invalid connection during close time ---' );
		}

		// autoconnect is off
		if ( 1 !== (int) bp_rbe_get_setting( 'keepaliveauto', array( 'refetch' => true ) ) ) {
			remove_action( 'shutdown', 'bp_rbe_spawn_inbox_check' );

		// sleep a bit before autoconnecting again
		} else {
			sleep( 5 );
		}

		exit();
	}

	/**
	 * Connects to the IMAP inbox.
	 *
	 * @return bool
	 */
	private function connect() {
		bp_rbe_log( '--- Attempting to start new connection... ---' );

		// if our DB marker says we're already connected, stop now!
		// this is an extra precaution
		if ( bp_rbe_is_connected() ) {
			bp_rbe_log( '--- RBE is already connected! ---' );
			return false;
		}

		// Let's open the IMAP stream!
		$this->connection = BP_Reply_By_Email_Connect::init();

		// couldn't connect :(
		if ( $this->connection === false ) {
			bp_rbe_log( 'Cannot connect: ' . imap_last_error() );
			bp_rbe_remove_imap_lock();
			bp_rbe_remove_imap_connection_marker();
			return false;
		}

		// add an entry in the DB to say that we're connected
		//bp_update_option( 'bp_rbe_is_connected', time() + bp_rbe_get_execution_time() );
		bp_rbe_add_imap_connection_marker();

		bp_rbe_log( '--- Connection successful! ---' );

		return true;
	}

	/**
	 * Closes the IMAP connection.
	 *
	 * @return bool
	 */
	private function close() {
		// Do something before closing
		do_action( 'bp_rbe_imap_before_close', $this->connection );

		if ( $this->is_connected()  ) {
			@imap_close( $this->connection );
			bp_rbe_remove_imap_connection_marker();
			return true;
		}

		return false;
	}

	/**
	 * Check to see if the IMAP connection is connected.
	 *
	 * @return bool
	 */
	private function is_connected() {
		if ( ! is_resource( $this->connection ) ) {
			bp_rbe_log( '-- There is no active IMAP connection --' );
			return false;
		}

		return true;
	}

	/**
	 * Fetch all headers for an email and parses them into an array.
	 *
	 * @uses imap_fetchheader() Grabs full, raw unmodified email header
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @return mixed Array of email headers. False if no headers.
	 */
	protected function get_mail_headers( $imap, $i ) {
		// Grab full, raw email header
		$header = imap_fetchheader( $imap, $i );

		// No header? Return false
		if ( empty( $header ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - no IMAP header' );
			return false;
		}

		// Do a regex match
		$pattern = apply_filters( 'bp_rbe_header_regex', '/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m' );
		preg_match_all( $pattern, $header, $matches );

		// Parse headers into an array with descriptive key
		$headers = array_combine( $matches[1], $matches[2] );

		// No headers? Return false
		if ( empty( $headers ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - no headers found' );
			return false;
		}

		return $headers;
	}

	/**
	 * Parses the body of an email message.
	 *
	 * Tries to fetch the plain-text version when available first. Otherwise, will
	 * fallback to the HTML version.
	 *
	 * @uses imap_fetchstructure() Get the structure of an email
	 * @uses imap_fetchbody() Using the third parameter will return a portion of the email depending on the email structure.
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @param bool $reply If we're parsing a reply or not. Default set to true.
	 * @return mixed Either the email body on success or false on failure
	 */
	public static function body_parser( $imap, $i, $reply = true ) {
		// get the email structure
		$structure = imap_fetchstructure( $imap, $i );

		// setup encoding variable
		$encoding  = $structure->encoding;

		// this is a multipart email
		if ( ! empty( $structure->parts ) ) {
			// parse the parts!
			$data = self::multipart_plain_text_parser( $structure->parts, $imap, $i );

			// we successfully parsed something from the multipart email
			if ( ! empty( $data ) ) {
				// $data when extracted includes:
				//	$body
				//	$encoding
				//	$params (if applicable)
				extract( $data );

				unset( $data );
			}

		// either a plain-text email or a HTML email
		} else {
			$body = imap_body( $imap, $i );
		}

		// decode emails with the following encoding
		switch ( $encoding ) {
			// quoted-printable
			case 4 :
				$body = quoted_printable_decode( $body );
				break;

			// base64
			case 3 :
				$body = base64_decode( $body );
				break;
		}

		// convert email to UTF-8 if not UTF-8
		if ( ! empty( $params['charset'] ) && $params['charset'] != 'utf-8' ) {
			// try to use mb_convert_encoding() first if it exists

			// there are differing opinions as to whether iconv() is better than
			// mb_convert_encoding()
			// mb_convert_encoding() appears to have less problems than iconv()
			// so this is used first
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$body = mb_convert_encoding( $body, 'utf-8', $params['charset'] );

			// fallback to iconv() if mb_convert_encoding()_doesn't exist
			} elseif ( function_exists( 'iconv' ) ) {
				$body = iconv( $params['charset'], 'utf-8//TRANSLIT', $body );
			}

		}

		// do something special for emails that only contain HTML
		if ( strtolower( $structure->subtype ) == 'html' ) {
			self::$html = true;
		}

		return $body;
	}

	/**
	 * Multipart plain-text parser.
	 *
	 * Used during {@link BP_Reply_By_Email_IMAP::body_parser()} if email is a multipart email.
	 *
	 * This method parses a multipart email to return the plain-text body as well as the encoding
	 * type and other parameters on success.
	 *
	 * @param obj $parts The multiple parts of an email. See imap_fetchstructure() for more details.
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @param bool $subpart If we're parsing a subpart or not. Defaults to false.
	 * @return array A populated array containing the body, encoding type and other parameters on success. Empty array on failure.
	 */
	public static function multipart_plain_text_parser( $parts, $imap, $i, $subpart = false ) {
		$items = $params = array();

		// check each sub-part of a multipart email
		for ( $j = 0, $k = count( $parts ); $j < $k; ++$j ) {
			// get subtype
			$subtype = strtolower( $parts[$j]->subtype );

			// get the plain-text message only
			if ( $subtype == 'plain' ) {
				// setup the part number
				// if $subpart is true, we must use a decimal number
				$partno            = ! $subpart ? $j+1 : $j+1 . '.' . ($j+1);

				$items['body']     = imap_fetchbody( $imap, $i, $partno );
				$items['encoding'] = $parts[$j]->encoding;

				// add all additional parameters if available
				if ( ! empty( $parts[$j]->parameters ) ) {
					foreach ( $parts[$j]->parameters as $x )
						$params[ strtolower( $x->attribute ) ] = $x->value;
				}

				// add all additional dparameters if available
				if ( ! empty( $parts[$j]->dparameters ) ) {
					foreach ( $parts[$j]->dparameters as $x )
						$params[ strtolower( $x->attribute ) ] = $x->value;
				}

				continue;
			}

			// if subtype is 'alternative', we must recursively use this method again
			elseif ( $subtype == 'alternative' ) {
				$items = self::multipart_plain_text_parser( $parts[$j]->parts, $imap, $i, true );

				continue;
			}
		}

		if ( ! empty( $params ) ) {
			$items['params'] = $params;
		}

		return $items;
	}

}

/**
 * Email parser class.
 *
 * @since 1.0-RC3.
 */
class BP_Reply_By_Email_Parser {
	/**
	 * Email headers of the current email.
	 *
	 * @var array
	 */
	public static $headers = array();

	/**
	 * The querystring parsed from the 'From' email address.
	 *
	 * @var string
	 */
	public static $querystring = '';

	/**
	 * The WP_User object when successfully parsed.
	 *
	 * @var object
	 */
	public static $user = false;

	/**
	 * The email body content.
	 *
	 * @var string
	 */
	public static $content = '';

	/**
	 * The email subject line.
	 *
	 * @var string
	 */
	public static $subject = '';

	/**
	 * Whether the current email uses HTML exclusively.
	 *
	 * @var bool
	 */
	public static $is_html = false;

	/**
	 * Static initializer.
	 *
	 * Returns an array of the parsed item on success. WP_Error object on failure.
	 *
	 * For parameters, see constructor.
	 *
	 * @return array|object Array of the parsed item on success.
	 *  WP_Error object on failure.
	 */
	public static function init( $args = array(), $i = 1 ) {
		$instance = new self( $args, $i );

		// Email header check ******************************************

		if ( empty( self::$headers ) ) {
			//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, false, 'no_headers' );
			return new WP_Error( 'no_headers' );
		}

		bp_rbe_log( 'Message #' . $i . ': email headers successfully parsed' );

		// Querystring check *******************************************

		if ( empty( self::$querystring ) ) {
			//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, self::$headers, 'no_address_tag' );
			return new WP_Error( 'no_address_tag', '', $args );
		}

		bp_rbe_log( 'Message #' . $i . ': address tag successfully parsed' );

		// User check **************************************************

		if ( empty( self::$user ) ) {
			//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, self::$headers, 'no_user_id' );
			return new WP_Error( 'no_user_id', '', $args );
		}

		// Spammer check ***********************************************

		$is_spammer = false;

		// Multisite spammer check
		if ( ! empty( self::$user->spam ) ) {
			$is_spammer = true;
		}

		// Single site spammer check
		if ( 1 == self::$user->user_status ) {
			$is_spammer = true;
		}

		if ( $is_spammer ) {
			//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_is_spammer' );
			return new WP_Error( 'user_is_spammer', '', $args );
		}

		// Parameters parser *******************************************

		// Check if we're posting a new item or not
		$params = self::get_parameters();

		if ( ! $params ) {
			//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_params' );
			return new WP_Error( 'no_params', '', $args );
		}

		bp_rbe_log( 'Message #' . $i . ': params = ' . print_r( $params, true ) );

		// Email body parser *******************************************

		self::$content = self::get_body( self::$content, self::$is_html, ! self::is_new_item(), $i );

		// If there's no email body and this is a reply, stop!
		if ( ! self::$content && ! self::is_new_item() ) {
			//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_reply_body' );
			return new WP_Error( 'no_reply_body', '', $args );
		}

		// log the body for replied items
		if ( ! self::is_new_item() ) {
			bp_rbe_log( 'Message #' . $i . ': body contents - ' . self::$content );
		}

		// Parsing completed! ******************************************

		$data = array(
			'headers' => self::$headers,
			'content' => self::$content,
			'subject' => self::$subject,
			'user_id' => self::$user->ID,
			'is_html' => self::$is_html,
			'i'       => $i
		);

		// plugins should use the following hook to do their posting routine
		$retval = apply_filters( 'bp_rbe_parse_completed', true, $data, $params );

		// clean up after ourselves
		self::clear_properties();

		return $retval;
	}

	/**
	 * Constructor.
	 *
	 * @param array $args {
	 *     An array of arguments.
	 *
	 *     @type array $headers Email headers.
	 *     @type string $to_email The 'To' email address.
	 *     @type string $from_email The 'From' email address.
	 *     @type string $content The email body content.
	 *     @type string $subject The email subject line.
	 *     @type bool $html Whether the email content is HTML or not.
	 * }
	 * @param int The email message number in the loop. Defaults to 1.
	 */
	public function __construct( $args = array(), $i = 1 ) {
		// headers check
		if ( ! empty( $args['headers'] ) ) {
			self::$headers = self::validate_headers( (array) $args['headers'], $i );
		}

		// get querystring
		if ( ! empty( $args['to_email'] ) ) {
			self::$querystring = self::get_querystring( $args['to_email'] );
		}

		// get userdata from email address
		if ( ! empty( $args['from_email'] ) ) {
			self::$user = get_user_by( 'email', $args['from_email'] );
		}

		// set email content
		if ( ! empty( $args['content'] ) ) {
			self::$content = $args['content'];
		}

		// set email subject
		if ( ! empty( $args['subject'] ) ) {
			self::$subject = $args['subject'];
		}

		// is the current email HTML-only?
		if ( isset( $args['is_html'] ) ) {
			self::$is_html = (bool) $args['is_html'];
		}
	}

	/**
	 * Returns the querystring from an email address.
	 *
	 * In IMAP mode:
	 *   test+THEQUERYSTRING@gmail.com> -> THEQUERYSTRING
	 *
	 * In Inbound mode:
	 *   THEQUERYSTRING@reply.mydomain.com -> THEQUERYSTRING
	 *
	 * The querystring is encoded by default.
	 *
	 * @param string $address The email address containing the address tag
	 * @return mixed Either the address tag on success or false on failure
	 */
	public static function get_querystring( $address = '' ) {
		if ( empty( $address ) ) {
			return false;
		}

		$at = strpos( $address, '@' );

		if ( $at === false ) {
			return false;
		}

		// inbound mode uses subdomain addressing
		if ( bp_rbe_is_inbound() ) {
			return substr( $address, 0, $at );

		// imap mode uses address tags
		} else {
			$tag = strpos( $address, bp_rbe_get_setting( 'tag' ) );

			if ( $tag === false ) {
				return false;
			}

			return substr( $address, ++$tag, $at - $tag );
		}
	}

	/**
	 * Checks email headers for auto-submitted / auto-replies.
	 *
	 * @param array $headers The email headers to check
	 * @param int $i The current email message number
	 * @return mixed Array of email headers. False if no headers or if the email is junk.
	 */
	public static function validate_headers( $headers = array(), $i = 0 ) {
		// No headers? Return false
		if ( empty( $headers ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - no headers found' );
			return false;
		}

		// 'X-AutoReply' header check
		if ( ! empty( $headers['X-Autoreply'] ) && $headers['X-Autoreply'] == 'yes' ) {
			bp_rbe_log( 'Message #' . $i . ': error - this is an autoreply message, so stop now!' );
			return false;
		}

		// 'Precedence' header check
		// Test to see if our email is an out of office automated reply or mailing list email
		// See http://en.wikipedia.org/wiki/Email#Header_fields
		if ( ! empty( $headers['Precedence'] ) ) {
			switch ( $headers['Precedence'] ) {
				case 'bulk' :
				case 'junk' :
				case 'list' :
					bp_rbe_log( 'Message #' . $i . ': error - this is some type of bulk / junk / mailing list email, so stop now!' );
					return false;
				break;
			}
		}

		// 'Auto-Submitted' header check
		// See https://tools.ietf.org/html/rfc3834#section-5
		if ( ! empty( $headers['Auto-Submitted'] ) ) {
			switch ( strtolower( $headers['Auto-Submitted'] ) ) {
				case 'auto-replied' :
				case 'auto-generated' :
					bp_rbe_log( 'Message #' . $i . ': error - this is an auto-reply using the "Auto-Submitted" header, so stop now!' );
					return false;
				break;
			}
		}

		// 'X-Auto-Response-Suppress' header check
		// used in MS Exchange mail servers
		// See http://msdn.microsoft.com/en-us/library/ee219609%28v=EXCHG.80%29.aspx
		if ( ! empty( $headers['X-Auto-Response-Suppress'] ) ) {
			switch ( $headers['X-Auto-Response-Suppress'] ) {
				// non-standard value, but seems to be in use
				case 'All' :

				// these are official values
				case 'OOF' :
				case 'AutoReply' :
					bp_rbe_log( 'Message #' . $i . ': error - this is auto-reply from MS Exchange, so stop now!' );
					return false;
				break;
			}
		}

		// 'X-FC-MachineGenerated' header check
		// used in FirstClass mail servers
		if ( ! empty( $headers['X-FC-MachineGenerated'] ) ) {
			bp_rbe_log( 'Message #' . $i . ': error - this is an auto-reply from FirstClass mail, so stop now!' );
			return false;
		}

		// Envelope Sender check
		// See http://qmail.3va.net/qdp/bounce.html
		// See http://wiki.dovecot.org/LDA/Sieve
		if ( ! empty( $headers['Return-Path'] ) ) {
			$return_path = strtolower( self::get_header( $headers, 'Return-Path' ) );

			if ( strpos( $return_path, 'mailer-daemon' ) === 0 ||
				strpos( $return_path, 'owner-' ) === 0 ||
				empty( $return_path )
			) {

				bp_rbe_log( 'Message #' . $i . ': error - this is a mailer-daemon message of some sort, so stop now!' );
				return false;
			}
		}

		// @todo Perhaps implement more auto-reply checks from these links:
		// https://github.com/opennorth/multi_mail/wiki/Detecting-autoresponders
		// http://www.hackvalue.nl/en/article/19/the%20art%20of%20autoresponders%20(part%202)
		// http://stackoverflow.com/questions/6317714/apache-camel-mail-to-identify-auto-generated-messages/6383675#6383675
		// http://wiki.exim.org/EximAutoReply#Router-1

		// Want to do more checks? Here's the filter!
		return apply_filters( 'bp_rbe_parse_email_headers', $headers );
	}

	/**
	 * Decodes the encoded querystring from {@link BP_Reply_By_Email_Parser::get_querystring()}.
	 * Then, extracts the params into an array.
	 *
	 * @uses bp_rbe_decode() To decode the encoded querystring
	 * @uses wp_parse_str() WP's version of parse_str() to parse the querystring
	 * @return mixed Either an array of params on success or false on failure
	 */
	protected static function get_parameters() {

		// new items will always have "-new" appended to the querystring
		// we need to strip "-new" to get the querystring
		if ( self::is_new_item() ) {
			// check to see if user ID is set, if not, return false
			if ( empty( self::$user->ID ) ) {
				return false;
			}

			$new = strrpos( self::$querystring, '-new' );

			if ( $new !== false ) {
				// get rid of "-new" from the querystring
				$qs = substr( self::$querystring, 0, $new );
			} else {
				return false;
			}

		} else {
			$qs = self::$querystring;
		}

		// only decode if querystring is a hexadecimal string
		if ( ctype_xdigit( $qs ) ) {

			// New posted items will pass $user_id along with $qs for decoding
			// This is done as an additional security measure because the "From" header
			// can be spoofed and is similar to how Basecamp handles posting new items
			if ( self::is_new_item() ) {
				// pass $user_id to bp_rbe_decode()
				$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( array( 'string' => $qs, 'param' => self::$user->ID ) ), $qs, self::$user->ID );

			// Replied items will use the regular $qs for decoding
			} else {
				$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( array( 'string' => self::$querystring ) ), $qs, false );
			}
		}

		// These are the default params we want to check for
		$defaults = array(
			'a' => false, // root activity id
			'p' => false, // direct parent activity id
			't' => false, // topic id
			'm' => false, // message thread id
			'g' => false  // group id
		);

		// Let 3rd-party plugins whitelist additional params
		$defaults = apply_filters( 'bp_rbe_allowed_params', $defaults, $qs );

		// Parse querystring into an array
		wp_parse_str( $qs, $params );

		// Only allow parameters set from $defaults through
		$params = array_intersect_key( $params, $defaults );

		// If no params, return false
		if ( empty( $params ) ) {
			return false;
		}

		return $params;
	}

	/**
	 * Returns an email header.
	 *
	 * If a header includes the user's name, it will return just the email address.
	 *  eg. r-a-y <test@gmail.com> -> test@gmail.com
	 *
	 * @param array|object $headers Email headers
	 * @param string $key The key we want to check against the array.
	 * @return mixed Either the email header on success or false on failure
	 */
	public static function get_header( $headers, $key ) {
		// make sure we typecast $headers to an array
		// mandrill sets headers as an object, while our RBE IMAP class uses an array
		$headers = (array) $headers;

		if ( empty( $headers[$key] ) ) {
			bp_rbe_log( $key . ' parser - empty key' );
			return false;
		}

		if ( $key == 'To' && strpos( $headers[$key], '@' ) === false ) {
			bp_rbe_log( $key . ' parser - missing email address' );
			return false;
		}

		// Sender is attempting to send to multiple recipients in the "To" header
		// A legit BP reply will not add multiple recipients, so let's return false
		if ( $key == 'To' && strpos( $headers['To'], ',' ) !== false ) {
			bp_rbe_log( $key . ' parser - multiple recipients - so stop!' );
			return false;
		}

		// grab email address in between triangular brackets if they exist
		// strip the rest
		$lbracket = strpos( $headers[$key], '<' );

		if ( $lbracket !== false ) {
			$rbracket = strpos( $headers[$key], '>' );

			$headers[$key] = substr( $headers[$key], ++$lbracket, $rbracket - $lbracket );
		}

		//bp_rbe_log( $key . ' parser - ' . $headers[$key] );

		return $headers[$key];
	}

	/**
	 * Parses and returns the email body content.
	 *
	 * @param string $body The email body content.
	 * @param bool $html Whether the email body is HTML or not. Defaults to false.
	 * @param bool $reply Whether the current item is a reply.  Defaults to true.
	 * @param int $i The current email message number.
	 * @return string|bool
	 */
	public static function get_body( $body = '', $html = false, $reply = true, $i = 1 ) {
		if ( $html ) {
			$body = apply_filters( 'bp_rbe_parse_html_email', $body );
		}

		// Check to see if we're parsing a reply
		if ( $reply ) {

			// Find our pointer
			$pointer = strpos( $body, __( '--- Reply ABOVE THIS LINE to add a comment ---', 'bp-rbe' ) );

			// If our pointer isn't found, return false
			if ( $pointer === false ) {
				return false;
			}

			// Return email body up to our pointer only
			$body = apply_filters( 'bp_rbe_parse_email_body_reply', trim( substr( $body, 0, $pointer ) ), $body );

		// this means we're posting something new (eg. new forum topic)
		// do something special for this case
		} else {
			$body = apply_filters( 'bp_rbe_parse_email_body_new', $body );
		}

		if ( empty( $body ) ) {
			bp_rbe_log( 'Message #' . $i . ': empty body' );
			return false;
		}

		return apply_filters( 'bp_rbe_parse_email_body', trim( $body ) );
	}

	/**
	 * Check to see if we're parsing a new item (like a new forum topic).
	 *
	 * New items will always have "-new" appended to the querystring. This is what we're checking for.
	 * eg. djlkjkdjfkd-new = true
	 *     jkljd8fujkdjkdf = false
	 *
	 * @return bool
	 */
	protected static function is_new_item() {
		$new = '-new';

		if ( substr( self::$querystring, -strlen( $new ) ) == $new ) {
			$retval = true;
		} else {
			$retval = false;
		}

		return apply_filters( 'bp_rbe_is_new_item', $retval, self::$querystring );
	}

	/**
	 * Clears static properties after reaching the end of parsing.
	 *
	 * This is to prevent any lingering properties when used in a loop.
	 */
	protected static function clear_properties() {
		self::$headers     = array();
		self::$querystring = '';
		self::$user        = false;
		self::$content     = '';
		self::$subject     = '';
		self::$is_html     = false;
	}
}

/**
 * Abstract base class for adding an inbound email provider.
 *
 * @since 1.0-RC3
 */
abstract class BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var array An array containing names and types of abstract properties that must
	 *      be implemented in child classes.
	 */
	private $_abstract_properties = array(
		'name' => array(
			'type'   => 'string',
			'static' => true,
		),
	);

	/**
	 * Constructor.
	 */
	final public function __construct() {
		$this->_abstract_properties_validate();
	}

	/**
	 * Make sure our class properties exist in extended classes.
	 *
	 * PHP doesn't accept abstract class properties, so this class method adds
	 * this capability.
	 */
	final protected function _abstract_properties_validate() {
		//check if the child class has defined the abstract properties or not
		$child = get_class( $this );

		foreach ( $this->_abstract_properties as $name => $settings ) {
			if ( isset( $settings['type'] ) && 'string' == $settings['type'] ) {
				if ( isset( $settings['static'] ) && true === $settings['static'] ) {
					$prop = new ReflectionProperty( $child, $name );

					if ( ! $prop->isStatic() ) {
						// property does not exist
						$error = $child . ' class must define $' . $name . ' property as static ' . $settings['type'];
						unset( $prop, $child );
						throw new \LogicException( $error );
					}
				} else {

					if ( property_exists( $this, $name ) && strtolower( gettype( $this->$name ) ) == $settings['type'] ) {
						continue;
					}

					// property does not exist
					$error = $child . ' class must define $' . $name . ' property as ' . $settings['type'];

					throw new \LogicException( $error );
				}
			}
		}

		unset( $error, $child );
	}

	/**
	 * Validates post callback and posts the data on success.
	 *
	 * This method must exist in extended classes.
	 */
	abstract public function webhook_parser();
}

/**
 * Add Mandrill as an inbound provider for RBE.
 *
 * @since 1.0-RC3
 *
 * @see http://help.mandrill.com/entries/22092308-What-is-the-format-of-inbound-email-webhooks-
 */
class BP_Reply_By_Email_Inbound_Provider_Mandrill extends BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var string Display name for our inbound provider
	 */
	public static $name = 'Mandrill';

	/**
	 * Webhook parser class method for Mandrill.
	 */
	public function webhook_parser() {
		if ( empty( $_SERVER['HTTP_X_MANDRILL_SIGNATURE'] ) ) {
			return false;
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && strpos( $_SERVER['HTTP_USER_AGENT'], 'Mandrill-Webhook/' ) === false ) {
			return false;
		}

		bp_rbe_log( '- Mandrill webhook received -' );

		// mandrill signature verification
		if ( defined( 'BP_RBE_MANDRILL_WEBHOOK_URL' ) && defined( 'BP_RBE_MANDRILL_WEBHOOK_KEY' ) ) {
			$signed_data  = constant( 'BP_RBE_MANDRILL_WEBHOOK_URL' );
			$signed_data .= 'mandrill_events';
			$signed_data .= stripslashes( $_POST['mandrill_events'] );
			$webhook_key  = constant( 'BP_RBE_MANDRILL_WEBHOOK_KEY' );

			$signature = base64_encode( hash_hmac( 'sha1', $signed_data, $webhook_key, true ) );

			// check if generated signature matches Mandrill's
			if ( $signature !== $_SERVER['HTTP_X_MANDRILL_SIGNATURE'] ) {
				bp_rbe_log( 'Mandrill signature verification failed.' );
				die();
			}

		}

		// get parsed content
		$response = json_decode( stripslashes( $_POST['mandrill_events'] ) );

		// log JSON errors if present
		if ( json_last_error() != JSON_ERROR_NONE ) {
			switch ( json_last_error() ) {
				case JSON_ERROR_DEPTH:
					bp_rbe_log( 'json error: - Maximum stack depth exceeded' );
					break;
				case JSON_ERROR_STATE_MISMATCH:
					bp_rbe_log( 'json error: - Underflow or the modes mismatch' );
					break;
				case JSON_ERROR_CTRL_CHAR:
					bp_rbe_log( 'json error: - Unexpected control character found' );
					break;
				case JSON_ERROR_SYNTAX:
					bp_rbe_log( 'json error: - Syntax error, malformed JSON' );
					break;
				case JSON_ERROR_UTF8:
					bp_rbe_log( 'json error: - Malformed UTF-8 characters, possibly incorrectly encoded' );
					break;
				default:
					bp_rbe_log( 'json error: - Unknown error' );
					break;
			}

			die();

		// ready to start the parsing!
		} else {

			$i = 1;
			foreach ( $response as $item ) {
				$data = array(
					'headers'    => $item->msg->headers,
					'to_email'   => $item->msg->email,
					'from_email' => $item->msg->from_email,
					'content'    => $item->msg->text,
					'subject'    => $item->msg->subject
				);

				$parser = BP_Reply_By_Email_Parser::init( $data, $i );

				if ( is_wp_error( $parser ) ) {
					do_action( 'bp_rbe_no_match', $parser, $data, $i, false );
				}

				++$i;
			}

			bp_rbe_log( '- Webhook parsing completed -' );

			die();
		}
	}
}

/**
 * Abstract base class for adding support for RBE to your plugin.
 *
 * This class relies on the activity component.  If your plugin doesn't rely
 * on the activity component (eg. PMs), don't extend this class.
 *
 * Extend this class and in your constructor call the bootstrap() method.
 * See inline docs in the bootstrap() method for more details.
 *
 * Next, override the post_by_email() method to do your custom checks and
 * posting routine.
 *
 * You should call this class anytime *after* the 'bp_include' hook of priority 10,
 * but before or equal to the 'bp_loaded' hook.
 *
 * @since 1.0-RC1
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
abstract class BP_Reply_By_Email_Extension {
	/**
	 * Holds our custom variables.
	 *
	 * These variables are stored in a protected array that is magically
	 * updated using PHP 5.2+ methods.
	 *
	 * @see BP_Reply_By_Email::bootstrap() This is where $data is defined
	 * @var array
	 */
	protected $data;

	/**
	 * Magic method for checking the existence of a certain data variable.
	 *
	 * @param string $key
	 */
	public function __isset( $key ) { return isset( $this->data[$key] ); }

	/**
	 * Magic method for getting a certain data variable.
	 *
	 * @param string $key
	 */
	public function __get( $key ) { return isset( $this->data[$key] ) ? $this->data[$key] : null; }

	/**
	 * Extensions must use this method in their constructor.
	 *
	 * @param array $data See inline doc.
	 */
	protected function bootstrap( $data = array() ) {
		if ( empty( $data ) ) {
			_doing_it_wrong( __METHOD__, 'array $data cannot be empty.' );
			return;
		}

		/*
		Paramaters for $data are as follows:

		$data = array(
			'id'                      => 'your-unique-id',   // your plugin name
			'activity_type'           => 'my_activity_type', // activity 'type' you want to match
			'item_id_param'           => '',                 // short parameter name for your 'item_id',
			'secondary_item_id_param' => '',                 // short paramater name for your 'secondary_item_id' (optional)
		);

		*/
		$this->data = $data;

		$this->setup_hooks();
	}

	/**
	 * Detect your extension and do your post routine in this method.
	 *
	 * Yup, you must declare this in your class and actually write some code! :)
	 * The actual contents of this method will differ for each extension.
	 *
	 * See {@link BP_Reply_By_Email::post()} for an example.
	 *
	 * You should return the posted ID of your extension on success and a {@link WP_Error}
	 * object on failure.
	 *
	 * @param bool $retval Defeults to boolean true.
	 * @param array $data The data from the parsing. Includes email content, user ID, subject.
	 * @param array $params Holds an array of params used by RBE. Also holds the params registered in
	 *  the bootstrap() method.
	 * @return array|object On success, return an array of the posted ID recommended. On failure, return a
	 *  WP_Error object.
	 */
	abstract public function post( $retval, $data, $params );

	/**
	 * Hooks! We do the dirty work here, so you don't have to! :)
	 */
	protected function setup_hooks() {
		add_action( 'bp_rbe_extend_activity_listener',          array( $this, 'extend_activity_listener' ),  10, 2 );
		add_filter( 'bp_rbe_extend_querystring',                array( $this, 'extend_querystring' ),        10, 2 );
		add_filter( 'bp_rbe_allowed_params',                    array( $this, 'register_params' ) );
		add_filter( 'bp_rbe_parse_completed',                   array( $this, 'post' ),                      10, 3 );

		// (recommended to extend) custom hooks to log unmet conditions during posting
		// your extension should do some error handling to let RBE know what's happening
		// and optionally, you should inform the sender that their email failed to post
		add_filter( 'bp_rbe_extend_log_no_match',               array( $this, 'internal_rbe_log' ),          10, 5 );
		add_filter( 'bp_rbe_extend_log_no_match_email_message', array( $this, 'failure_message_to_sender' ), 10, 5 );
	}

	/**
	 * RBE activity listener for your plugin.
	 *
	 * Override this in your class if your 'item_id' / 'secondary_item_id' needs to be calculated
	 * in a different manner.
	 *
	 * If your plugin doesn't rely on the activity component, you will probably want to override
	 * this method and make it an empty method.
	 *
	 * @param obj $listener Registers your component with RBE's activity listener
	 * @param obj $item The activity object generated by BP during save.
	 */
	public function extend_activity_listener( $listener, $item ) {
		if ( ! empty( $this->activity_type ) && $item->type == $this->activity_type ) {
			$listener->component = $this->id;
			$listener->item_id   = $item->item_id;

			if ( ! empty( $this->secondary_item_id_param ) )
				$listener->secondary_item_id = $item->secondary_item_id;
		}
	}

	/**
	 * Sets up the querystring used in the 'Reply-To' email address.
	 *
	 * Override this if needed.
	 *
 	 * @param string $querystring Querystring used to form the "Reply-To" email address.
 	 * @param obj $listener The listener object registered in the extend_activity_listener() method.
 	 * @param string $querystring
	 */
	public function extend_querystring( $querystring, $listener ) {
		// check to see if the listener component matches our extension's unique ID
		// if it does, proceed with setting up our custom querystring
		if ( $listener->component == $this->id ) {
			$querystring = "{$this->item_id_param}={$listener->item_id}";

			// some querystrings only use one parameter; if a second one exists,
			// add it.
			if ( ! empty( $this->secondary_item_id_param ) )
				$querystring .= "&{$this->secondary_item_id_param}={$listener->secondary_item_id}";
		}

		return $querystring;
	}

	/**
	 * This method registers your 'item_id_param' / 'secondary_item_id_param' with RBE.
	 *
	 * You shouldn't have to override this.
	 *
	 * @param array $params Whitelisted parameters used by RBE for the querystring
	 * @return array $params
	 */
	public function register_params( $params ) {
		if ( ! empty( $params[$this->item_id_param] ) ) {
			_doing_it_wrong( __METHOD__, 'Your "item_id_param" is already registered in RBE.  Please change your "item_id_param" to a more, unique identifier.' );
			return $params;
		}

		if ( ! empty( $this->secondary_item_id_param ) && ! empty( $params[$this->secondary_item_id_param] ) ) {
			_doing_it_wrong( __METHOD__, 'Your "secondary_item_id_param" is already registered in RBE.  Please change your "secondary_item_id_param" to a more, unique identifier.' );
			return $params;
		}

		$params[$this->item_id_param] = false;

		if ( ! empty( $this->secondary_item_id_param ) )
			$params[$this->secondary_item_id_param] = false;

		return $params;
	}

	/**
	 * Log your extension's error messages during the post_by_email() method.
	 *
	 * Extend away!
	 *
	 * @param mixed $log Should override to string in method.  Defaults to boolean false.
	 * @param string $type Type of error message
	 * @param array $headers The email headers
	 * @param int $i The message number from the inbox loop
	 * @param resource $connection The current IMAP connection. Chances are you probably don't have to do anything with this!
	 * @return mixed $log
	 */
	public function internal_rbe_log( $log, $type, $headers, $i, $connection ) {
		return $log;
	}

	/**
	 * Setup your extension's failure message to send back to the sender.
	 *
	 * Extend away!
	 *
	 * @param mixed $message Should override to string in method.  Defaults to boolean false.
	 * @param string $type Type of error message
	 * @param array $headers The email headers
	 * @param int $i The message number from the inbox loop
	 * @param resource $connection The current IMAP connection. Chances are you probably don't have to do anything with this!
	 * @return mixed $message
	 */
	public function failure_message_to_sender( $message, $type, $headers, $i, $connection ) {
		return $message;
	}

}