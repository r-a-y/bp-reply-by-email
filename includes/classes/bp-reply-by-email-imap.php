<?php
/**
 * BP Reply By Email IMAP Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

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
						'subject'    => imap_utf8( BP_Reply_By_Email_Parser::get_header( $headers, 'Subject' ) )
					);

					$parser = BP_Reply_By_Email_Parser::init( $data, $i );

					if ( is_wp_error( $parser ) ) {
						//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, $parser->get_error_code() );
						do_action( 'bp_rbe_no_match', $parser, $data, $i, $this->connection );
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

			// Do this whole thing again!
			bp_rbe_run_inbox_listener( array( 'force' => true ) );
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