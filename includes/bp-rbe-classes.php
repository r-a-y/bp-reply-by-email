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
 * Handles checking an IMAP inbox and posting items to BuddyPress.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
class BP_Reply_By_Email_IMAP {

	protected $connection = false;

	/**
	 * Creates a singleton instance of the BP_Reply_By_Email_IMAP class
	 *
	 * @return BP_Reply_By_Email_IMAP object
	 * @static
	 */
	public static function &init() {
		static $instance = false;

		if ( !$instance ) {
			$instance = new BP_Reply_By_Email_IMAP;
		}

		return $instance;
	}

	/**
	 * The main function we use to parse an IMAP inbox.
	 */
	function run() {
		global $bp, $wpdb;

		// If safe mode isn't on, then let's set the execution time to unlimited
		if ( !ini_get( 'safe_mode' ) )
			set_time_limit(0);

		// Try to connect
		$connect = $this->connect();

		if ( ! $connect ) {
			return false;
		}

		// Total duration we should keep the IMAP stream alive for in seconds
		$duration = bp_rbe_get_execution_time();

		// Mark the current timestamp, mark the future time when we should close the IMAP connection;
		// Do our parsing until $future > $now; re-mark the timestamp at end of loop... rinse and repeat!
		for ( $now = time(), $future = time() + $duration; $future > $now; $now = time() ) :

			// Get number of messages
			$msg_num = imap_num_msg( $this->connection );

			// If there are messages in the inbox, let's start parsing!
			if( $msg_num != 0 ) :

				// According to this:
				// http://www.php.net/manual/pl/function.imap-headerinfo.php#95012
				// This speeds up rendering the email headers... could be wrong
				imap_headers( $this->connection );

				bp_rbe_log( '- Checking inbox -' );

				// Loop through each email message
				for ( $i = 1; $i <= $msg_num; ++$i ) :

					$headers = $this->header_parser( $this->connection, $i );

					if ( !$headers ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, false, 'no_headers' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ' of ' . $msg_num . ': email headers successfully parsed' );

					$user_id = email_exists( $this->address_parser( $headers, 'From' ) );

					if ( !$user_id ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_user_id' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ': user id successfully parsed - user id is - ' . $user_id );

					// Grab address tag from "To" email address
					$qs = $this->get_address_tag( $this->address_parser( $headers, 'To' ) );

					if ( !$qs ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_address_tag' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ': address tag successfully parsed' );

					// Parse our encoded querystring into variables
					// Check if we're posting a new item or not
					if ( $this->is_new_item( $qs ) )
						$params = $this->querystring_parser( $qs, $user_id );
					else
						$params = $this->querystring_parser( $qs );

					if ( !$params ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_params' );
						continue;
					}

					bp_rbe_log( 'Message #' . $i . ': params = ' . print_r( $params, true ) );

					// Parse email body
					$body = $this->body_parser( $this->connection, $i );

					// If there's no email body and this is a reply, stop!
					if ( !$body && !$this->is_new_item( $qs ) ) {
						do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'no_reply_body' );
						continue;
					}

					// Extract each param into its own variable
					extract( $params );

					// Activity reply
					if ( !empty( $a ) ) :
						bp_rbe_log( 'Message #' . $i . ': this is an activity reply, checking if parent activities still exist' );

						// Check to see if the root activity ID and the parent activity ID exist before posting
						$activity_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bp->activity->table_name} WHERE id IN ( {$a}, {$p} );" ) );

						// If $a = $p, this means that we're replying to a top-level activity update
						// So check if activity count is 1
						if ( $a == $p && $activity_count != 1 ) {
							do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'root_activity_deleted' );
							continue;
						}
						// If we're here, this means we're replying to an activity comment
						// If count != 2, this means either the super admin or activity author has deleted one of the update(s)
						elseif ( $a != $p && $activity_count != 2 ) {
							do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'root_or_parent_activity_deleted' );
							continue;
						}

						/* Let's start posting! */
						// Add our filter to override the activity action in bp_activity_new_comment()
						bp_rbe_activity_comment_action_filter( $user_id );

						bp_activity_new_comment(
							 array(
								'content'	=> $body,
								'user_id'	=> $user_id,
								'activity_id'	=> $a, // ID of the root activity item
								'parent_id'	=> $p  // ID of the parent comment
							)
						);

						bp_rbe_log( 'Message #' . $i . ': activity comment successfully posted!' );

						// remove the filter after posting
						remove_filter( 'bp_activity_comment_action', 'bp_rbe_activity_comment_action' );
						unset( $activity_count );
						unset( $a );
						unset( $p );

					// Forum reply
					elseif ( !empty( $t ) ) :

						if ( bp_is_active( $bp->groups->id ) && bp_is_active( $bp->forums->id ) ) :
							bp_rbe_log( 'Message #' . $i . ': this is a forum reply' );

							$can_post = apply_filters( 'bp_rbe_group_can_post', user_can( $user_id, 'edit_users' ), $user_id );

							// If user is a member of the group and not banned, then let's post the forum reply!
							if ( $can_post || ( groups_is_user_member( $user_id, $g ) && !groups_is_user_banned( $user_id, $g ) ) ) {
								$forum_post_id = bp_rbe_groups_new_group_forum_post( array( 
									'post_text' => $body, 
									'topic_id'  => $t, 
									'user_id'   => $user_id,
									'group_id'  => $g
								) );

								if ( !$forum_post_id ) {
									do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'forum_reply_fail' );
									continue;
								}

								bp_rbe_log( 'Message #' . $i . ': forum reply successfully posted!' );

								// could potentially add attachments
								do_action( 'bp_rbe_email_new_forum_post', $this->connection, $i, $forum_post_id, $g, $user_id );
							}

							unset( $t );
						endif;

					// Private message reply
					elseif ( !empty( $m ) ) :
						if ( bp_is_active( $bp->messages->id ) ) :
							bp_rbe_log( 'Message #' . $i . ': this is a private message reply' );

							messages_new_message (
								array(
									'thread_id' => $m,
									'sender_id' => $user_id,
									'content'   => $body
								)
							);

							bp_rbe_log( 'Message #' . $i . ': PM reply successfully posted!' );

							unset( $m );
						endif;

					// New forum topic
					elseif ( !empty( $g ) ) :

						if ( bp_is_active( $bp->groups->id ) && bp_is_active( $bp->forums->id ) ) :
							bp_rbe_log( 'Message #' . $i . ': this is a new forum topic' );

							$body    = $this->body_parser( $this->connection, $i, false );
							$subject = $this->address_parser( $headers, 'Subject' );

							if ( empty( $body ) || empty( $subject ) ) {
								do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'new_forum_topic_empty' );
								continue;
							}

							$can_post = apply_filters( 'bp_rbe_group_can_post', user_can( $user_id, 'edit_users' ), $user_id );

							// If user is a member of the group and not banned, then let's post the forum topic!
							if ( $can_post || ( groups_is_user_member( $user_id, $g ) && !groups_is_user_banned( $user_id, $g ) ) ) {
								$topic = bp_rbe_groups_new_group_forum_topic( array(
									'topic_title' => $subject,
									'topic_text'  => $body,
									'user_id'     => $user_id,
									'group_id'    => $g
								) );

								if ( !$topic ) {
									do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'new_topic_fail' );
									continue;
								}

								bp_rbe_log( 'Message #' . $i . ': forum topic successfully posted!' );

								// could potentially add attachments
								do_action_ref_array( 'bp_rbe_email_new_forum_topic', array( $this->connection, $i, &$topic, $g, $user_id ) );
							}

							unset( $g );
							unset( $subject );
						endif;
					endif;

					// Do something at the end of the loop; useful for 3rd-party plugins
					do_action( 'bp_rbe_imap_loop', $this->connection, $i, $params, $body, $user_id );

					// Unset some variables to clear some memory
					unset( $headers );
					unset( $params );
					unset( $qs );
					unset( $body );
				endfor;

			endif;

			// do something after the loop
			do_action( 'bp_rbe_imap_after_loop', $this->connection );

			// stop the loop if necessary
			if ( $this->should_stop() ) {
				if ( $this->close() ) {
					bp_rbe_log( '--- Manual termination of connection confirmed! Kaching! ---' );
				}
				else {
					bp_rbe_log( '--- Error - invalid connection during manual termination ---' );
				}
				return;
			}

			// Give IMAP server a break
			sleep( 10 );

			// If the IMAP connection is down, reconnect
			if( ! imap_ping( $this->connection ) ) {
				bp_rbe_log( '-- IMAP connection is down, reconnecting... --' );
				$this->connect();
			}

			// Unset some variables to clear some memory
			unset( $msg_num );
		endfor;

		if ( $this->close() ) {
			bp_rbe_log( '--- Closing current connection automatically ---' );

			// clear our scheduled hook to prevent wryly cron irregularity
			wp_clear_scheduled_hook( 'bp_rbe_schedule' );

			// since we clear our scheduled hook, it would take two page hits to run our task instead of one
			// let's create a marker so we can spawn cron manually on the first page hit
			// @see bp_rbe_cron()
			bp_update_option( 'bp_rbe_spawn_cron', 1 );
		}
		else {
			bp_rbe_log( '--- Invalid connection during close time ---' );
		}
	}

	/**
	 * Connects to the IMAP inbox.
	 */
	function connect() {
		global $bp_rbe;

		// Imap connection is already established!
		if ( is_resource( $this->connection ) ) {
			bp_rbe_log( '--- Already connected! ---' );
			return true;
		}

		// This needs some testing...
		$ssl = bp_rbe_is_imap_ssl() ? '/ssl' : '';

		// Need to readjust this before public release
		// In the meantime, let's add a filter!
		$hostname = '{' . $bp_rbe->settings['servername'] . ':' . $bp_rbe->settings['port'] . '/imap' . $ssl . '}INBOX';
		$hostname = apply_filters( 'bp_rbe_hostname', $hostname );

		bp_rbe_log( '--- Attempting to start new connection... ---' );

		// Let's open the IMAP stream!
		$this->connection = @imap_open( $hostname, $bp_rbe->settings['username'], $bp_rbe->settings['password'] );

		if ( $this->connection === false ) {
			bp_rbe_log( 'Cannot connect: ' . imap_last_error() );
			return false;
		}

		// add an entry in the DB to say that we're connected so we can access this info on other pages
		bp_update_option( 'bp_rbe_is_connected', true );

		bp_rbe_log( '--- Connection successful! ---' );

		return true;
	}

	/**
	 * Closes the IMAP connection.
	 */
	function close() {
		// Do something before closing
		do_action( 'bp_rbe_imap_before_close', $this->connection );

		if ( $this->is_connected()  ) {
			@imap_close( $this->connection );
			bp_update_option( 'bp_rbe_is_connected', 0 );
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
	 * Returns true when the main IMAP loop should finally stop in our version of a poor man's daemon.
	 *
	 * Info taken from Christopher Nadeau's post - {@link http://devlog.info/2010/03/07/creating-daemons-in-php/#lphp-4}.
	 *
	 * @see bp_rbe_stop_imap()
	 * @uses clearstatcache() Clear stat cache. Needed when using file_exists() in a script like this.
	 * @uses file_exists() Checks to see if our special txt file is created.
	 * @uses unlink() Deletes this txt file so we can do another check later.
	 * @return bool
	 */
	function should_stop() {
		clearstatcache();

		if ( file_exists( bp_core_avatar_upload_path() . '/bp-rbe-stop.txt' ) ) {
			unlink( bp_core_avatar_upload_path() . '/bp-rbe-stop.txt' ); // delete the file for next time
			return true;
		}

		return false;
	}

	/**
	 * Grabs and parses an email message's header and returns an array with each header item.
	 *
	 * @uses imap_fetchheader() Grabs full, raw unmodified email header
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @return mixed Array of email headers. False if no headers or if the email is junk.
	 */
	function header_parser( $imap, $i ) {
		// Grab full, raw email header
		$header = imap_fetchheader( $imap, $i );

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

		// Test to see if our email is an auto-reply message
		// If so, return false
		if ( !empty( $headers['X-Autoreply'] ) && $headers['X-Autoreply'] == 'yes' ) {
			bp_rbe_log( 'Message #' . $i . ': error - this is an autoreply message, so stop now!' );
			return false;
		}

		// Test to see if our email is an out of office automated reply or mailing list email
		// If so, return false
		// See http://en.wikipedia.org/wiki/Email#Header_fields
		if ( !empty( $headers['Precedence'] ) ) :
			switch ( $headers['Precedence'] ) {
				case 'bulk' :
				case 'junk' :
				case 'list' :
					bp_rbe_log( 'Message #' . $i . ': error - this is some type of bulk / junk / mailing list email, so stop now!' );
					return false;
				break;
			}
		endif;

		// Want to do more checks? Here's the filter!
		return apply_filters( 'bp_rbe_parse_email_headers', $headers, $header );
	}

	/**
	 * Parses the body of an email message.
	 *
	 * Tries to fetch the plain-text version when available first. Otherwise, will fallback to the HTML version.
	 *
	 * @uses imap_fetchstructure() Get the structure of an email
	 * @uses imap_fetchbody() Using the third parameter will return a portion of the email depending on the email structure.
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @param bool $reply If we're parsing a reply or not. Default set to true.
	 * @return mixed Either the email body on success or false on failure
	 */
	function body_parser( $imap, $i, $reply = true ) {
		// get the email structure
		$structure = imap_fetchstructure( $imap, $i );

		// this is a multipart email
		if ( ! empty( $structure->parts ) ) {
			// check each sub-part of a multipart email
			for ( $j = 0, $k = count( $structure->parts ); $j < $k; ++$j ) {
				// get the plain-text message only
				if ( strtolower( $structure->parts[$j]->subtype ) == 'plain' ) {
					$body = imap_fetchbody( $imap, $i, $j+1 );
					continue;
				}
			}
		}

		// either a plain-text email or a HTML email
		else {
			$body = imap_body( $imap, $i );
		}

		// decode emails with the following encoding
		switch ( $structure->encoding ) {
			// quoted-printable
			case 4 :
				$body = quoted_printable_decode( $body );
				break;

			// base64
			case 3 :
				$body = base64_decode( $body );
				break;
		}

		// do something special for HTML emails
		if ( strtolower( $structure->subtype ) == 'html' ) {
			$body = apply_filters( 'bp_rbe_parse_html_email', $body, $structure );
		}

		// Check to see if we're parsing a reply
		if ( $reply ) {

			// Find our pointer
			$pointer = strpos( $body, __( '--- Reply ABOVE THIS LINE to add a comment ---', 'bp-rbe' ) );

			// If our pointer isn't found, return false
			if ( $pointer === false )
				return false;

			// Return email body up to our pointer only
			$body = apply_filters( 'bp_rbe_parse_email_body_reply', trim( substr( $body, 0, $pointer ) ), $body, $structure );
		}

		if ( empty( $body ) ) {
			bp_rbe_log( 'Message #' . $i . ': empty body' );
			return false;
		}

		bp_rbe_log( 'Message #' . $i . ': body contents - ' . $body );
		return apply_filters( 'bp_rbe_parse_email_body', trim( $body ), $structure );
	}

	/**
	 * Parses an email header to return just the email address.
	 *
	 * eg. r-a-y <test@gmail.com> -> test@gmail.com
	 *
	 * @param array $headers The array of email headers
	 * @param string $key The key we want to check against the array.
	 * @return mixed Either the email address on success or false on failure
	 */
	function address_parser( $headers, $key ) {
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

		bp_rbe_log( $key . ' parser - ' . $headers[$key] );

		return $headers[$key];
	}

	/**
	 * Returns the address tag from an email address.
	 *
	 * eg. test+tag@gmail.com> -> tag
	 * In BP Reply By Email IMAP, this is an encoded querystring.
	 *
	 * @param string $address The email address containing the address tag
	 * @return mixed Either the address tag on success or false on failure
	 */
	function get_address_tag( $address ) {
		global $bp_rbe;

		// $address might already be false, so let's return false right away
		if ( !$address )
			return false;

		$at  = strpos( $address, '@' );
		$tag = strpos( $address, $bp_rbe->settings['tag'] );

		if ( $at === false || $tag === false )
			return false;

		return substr( $address, ++$tag, $at - $tag );
	}

	/**
	 * Decodes the encoded querystring from {@link BP_Reply_By_Email_IMAP::get_address_tag()}.
	 * Then, extracts the params into an array.
	 *
	 * @uses bp_rbe_decode() To decode the encoded querystring
	 * @uses wp_parse_str() WP's version of parse_str() to parse the querystring
	 * @param string $qs The encoded address tag we want to decode
	 * @return mixed Either an array of params on success or false on failure
	 */
	function querystring_parser( $qs, $user_id = false ) {

		// New posted items will pass $user_id along with $qs for decoding
		// This is done as an additional security measure because the "From" header
		// can be spoofed and is similar to how Basecamp handles posting new items
		if ( $user_id ) {
			// check to see if $user_id is numeric, if not, return false
			if ( !is_numeric( $user_id ) )
				return false;

			// new items will always have "-new" appended to the querystring
			$new = strrpos( $qs, '-new' );

			if ( $new !== false ) {
				// get rid of "-new" from the querystring
				$qs = substr( $qs, 0, $new );

				// pass $user_id to bp_rbe_decode()
				$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( $qs, $user_id ), $qs, $user_id );
			}
			else
				return false;
		}

		// Replied items will use the regular $qs for decoding
		else {
			$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( $qs ), $qs, $user_id );
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
		$defaults = apply_filters( 'bp_rbe_allowed_params', $defaults );

		// Parse querystring into an array
		wp_parse_str( $qs, $params );

		// Only allow parameters set from $defaults through
		$params = array_intersect_key( $params, $defaults );

		// If no params, return false
		if ( empty( $params ) )
			return false;

		return $params;
	}

	/**
	 * Check to see if we're parsing a new item (like a new forum topic).
	 *
	 * New items will always have "-new" appended to the address tag. This is what we're checking for.
	 * eg. djlkjkdjfkd-new = true
	 *     jkljd8fujkdjkdf = false
	 *
	 * @param string $tag The address tag we're checking for.
	 * @return bool
	 */
	function is_new_item( $qs ) {
		$new = '-new';

		if ( substr( $qs, -strlen( $new ) ) == $new )
			return true;

		return false;
	}
}

?>