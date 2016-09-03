<?php
/**
 * BP Reply By Email Parser Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

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
		/**
		 * Hook to allow plugins to do something before parser begins.
		 *
		 * @since 1.0-RC4
		 */
		do_action( 'bp_rbe_before_parser' );

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
			// Strip line-breaks from subject lines.
			self::$subject = str_replace( "\r\n", '', $args['subject'] );
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
			$qs = substr( $address, 0, $at );

		// imap mode uses address tags
		} else {
			$tag = strpos( $address, bp_rbe_get_setting( 'tag' ) );

			if ( $tag === false ) {
				$qs = false;
			} else {
				$qs = substr( $address, ++$tag, $at - $tag );
			}
		}

		/**
		 * Filter the querystring from an email address.
		 *
		 * @since 1.0-RC4
		 *
		 * @param string|false $qs      Current querystring.
		 * @param string       $address Full 'to' email address.
		 */
		return apply_filters( 'bp_rbe_get_querystring', $qs, $address );
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
				/**
				 * If new item querystring isn't default, let plugins render querystring.
				 *
				 * Plugins should return a querystring matching whitelisted parameters from the
				 * 'bp_rbe_allowed_params' filter.
				 *
				 * @since 1.0-RC4
				 *
				 * @param string $qs Current string.
				 */
				$qs = (string) apply_filters( 'bp_rbe_new_item_querystring', self::$querystring );
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
			$pointer = strpos( $body, bp_rbe_get_marker() );

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