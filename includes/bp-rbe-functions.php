<?php
/**
 * BP Reply By Email Functions
 *
 * @package BP_Reply_By_Email
 * @subpackage Functions
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/** RBE All-purpose *****************************************************/

/**
 * Checks to see if minimum requirements are completed (admin settings, webhost requirements).
 *
 * @param mixed $settings If you already have a settings array available, pass it.  Otherwise, default is false.
 * @return bool
 * @since 1.0-beta
 */
function bp_rbe_is_required_completed( $settings = false ) {
	global $bp_rbe;

	$settings = ! $settings ? $bp_rbe->settings : $settings;

	if ( ! is_array( $settings ) ) {
		return false;
	}

	// inbound mode requirements
	if( isset( $settings['mode'] ) && $settings['mode'] == 'inbound' ) {
		$required_key = array( 'inbound-domain', 'inbound-provider' );

	// imap mode requirements
	} else {
		// check if the IMAP extension is enabled
		if ( ! function_exists( 'imap_open' ) ) {
			return false;
		}

		$required_key = array( 'servername', 'port', 'tag', 'username', 'password', 'key', 'keepalive', 'connect' );
	}

	foreach ( $required_key as $required ) {
		if ( empty( $settings[$required] ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Get an individual setting from RBE's settings array.
 *
 * @since 1.0-RC3
 *
 * @param string $setting The setting parameter.
 * @param array $args {
 *     Misc settings.
 *     @type bool $refetch Whether to refetch RBE's settings. Handy when you
 *           need to ensure the settings are updated. Defaults to false.
 * }
 * @return string|bool
 */
function bp_rbe_get_setting( $setting = '', $args = array() ) {
	if ( empty( $setting ) || ! is_string( $setting ) ) {
		return false;
	}

	$r = wp_parse_args( $args, array(
		'refetch' => false,
	) );

	global $bp_rbe;

	// refetches RBE options
	if ( true === $r['refetch'] ) {
		// flush cache if necessary
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}

		// refetch option
		$bp_rbe->settings = bp_get_option( 'bp-rbe' );
	}

	return isset( $bp_rbe->settings[$setting] ) ? $bp_rbe->settings[$setting] : false;
}

/**
 * Whether RBE is in inbound mode.
 *
 * Previous to 1.0-RC3, BP RBE only supported IMAP to check an inbox and post
 * items to BuddyPress.  Now, RBE will also support inbound email.
 *
 * Inbound email mode means BP emails are sent to an external service that
 * parses the email content and will post back the parsed content to the site.
 * RBE will then used the parsed content to post the content to BuddyPress.
 *
 * Inbound email will be the default mode going forward.
 *
 * @since 1.0-RC3
 *
 * @return bool
 */
function bp_rbe_is_inbound() {
	return (bool) ( bp_rbe_get_setting( 'mode' ) == 'inbound' );
}

if ( ! function_exists( 'bp_rbe_is_connected' ) ) :
/**
 * Check if we're connected to the IMAP inbox.
 *
 * This is updated in {@link BP_Reply_By_Email_IMAP::connect()} and in
 * {@link BP_Reply_By_Email_IMAP::close()}.
 *
 * @since 1.0-RC4
 *
 * @return bool
 */
function bp_rbe_is_connected( $args = array() ) {
	$r = wp_parse_args( $args, array(
		'clearcache' => true,
	) );

	if ( true == $r['clearcache'] ) {
		clearstatcache();
	}

	$file = bp_core_avatar_upload_path() . '/bp-rbe-connected.txt';
	if ( file_exists( $file ) ) {
		// check if we're connected
		if ( time() <= ( filemtime( $file ) + bp_rbe_get_execution_time() + 15 ) ) {
			return true;
		}
	}

	return false;
}
endif;

if ( ! function_exists( 'bp_rbe_is_connecting' ) ) :
/**
 * Check if we're in the process of connecting to the IMAP inbox.
 *
 * If we're already attempting a connection to the inbox, bail.
 *
 * Uses the filesystem.  Function is pluggable, so redeclare this function if
 * you want to use another method (eg. memcached).
 *
 * @since 1.0-RC3
 *
 * @see bp_rbe_add_imap_lock()
 * @see bp_rbe_remove_imap_lock()
 * @see bp_rbe_should_stop()
 * @see bp_rbe_stop_imap()
 * @param array
 * @return bool
 */
function bp_rbe_is_connecting( $args = array() ) {
	$r = wp_parse_args( $args, array(
		'clearcache' => false,
	) );

	if ( true == $r['clearcache'] ) {
		clearstatcache();
	}

	$lockfile = bp_core_avatar_upload_path() . '/bp-rbe-lock.txt';
	if ( file_exists( $lockfile ) ) {
		// check if we're already attempting to connect
		if ( time() <= filemtime( $lockfile ) + WP_CRON_LOCK_TIMEOUT ) {
			return true;
		}
	}

	return false;
}
endif;

/**
 * Cleanup RBE.
 *
 * Clears RBE's scheduled hook from WP, as well as any DB entries and
 * files.
 *
 * @since 1.0-RC1
 */
function bp_rbe_cleanup() {
	// remove remnants from any previous failed attempts to stop the inbox
	bp_rbe_should_stop();
	bp_rbe_remove_imap_lock();
	bp_rbe_remove_imap_connection_marker();

	// we don't use these options anymore
	bp_delete_option( 'bp_rbe_is_connected' );
	bp_delete_option( 'bp_rbe_spawn_cron' );
	bp_delete_option( 'bp_rbe_lock' );
	delete_site_transient( 'bp_rbe_is_connected' );
	delete_site_transient( 'bp_rbe_lock' );

	// Clear RBE's cron.
	wp_clear_scheduled_hook( 'bp_rbe_schedule' );
}

/**
 * Get execution time for IMAP loop.
 * This is the amount of time that RBE stays connected to the IMAP inbox.
 *
 * If safe mode is enabled, we use the max_execution_time as set in PHP.
 * Otherwise, this value is configurable from the admin page.
 *
 * @see BP_Reply_By_Email_IMAP:::run()
 * @param string $value Either 'seconds' or 'minutes'.  Default is 'seconds'.
 * @return int The execution time in either seconds or minutes
 * @since 1.0-beta
 * @todo Remove safe mode support as safe mode is being deprecated in PHP 5.3+.
 */
function bp_rbe_get_execution_time( $value = 'seconds' ) {
	// if webhost has enabled safe mode, we cannot set the time limit, so
	// we have to accommodate their max execution time
	if ( ini_get( 'safe_mode' ) ) :

		// value is in seconds
		$time = ini_get( 'max_execution_time' );

		if ( $value == 'minutes' ) {
			$time = floor( ini_get( 'max_execution_time' ) / 60 );
		}

		// apply a filter just in case someone wants to override this!
		$time = apply_filters( 'bp_rbe_safe_mode_execution_time', $time );

	else :
		// if keepalive setting exists, use it; otherwise, set default keepalive to 15 minutes
		$time = bp_rbe_get_setting( 'keepalive' ) ? bp_rbe_get_setting( 'keepalive' ) : 15;

		if ( $value == 'seconds' ) {
			$time = $time * 60;
		}
	endif;

	return $time;
}

/**
 * Injects querystring into an email address.
 *
 * For IMAP mode, the constructed email address will look like this:
 *  test@gmail.com -> test+THEQUERYSTRING@gmail.com
 *
 * For inbound mode, the constructed email address will look like this:
 *  THEQUERYSTRING@reply.yourdomain.com
 *
 * @since 1.0-beta
 *
 * @param string $qs The querystring we want to add to an email address.
 * @returns string
 */
function bp_rbe_inject_qs_in_email( $qs ) {
	// inbound mode uses subdomain addressing
	// eg. whatever@reply.yourdomain.com
	if ( bp_rbe_is_inbound() ) {
		$retval = $qs . '@' . bp_rbe_get_setting( 'inbound-domain' );

	// imap mode uses address tags
	// eg. test+whatever@gmail.com
	} else {
		$email	= bp_rbe_get_setting( 'email' );
		$at_pos	= strpos( $email, '@' );

		// Address tag + $qs
		$qs = bp_rbe_get_setting( 'tag' ) . $qs;

		$retval = substr_replace( $email, $qs, $at_pos, 0 );
	}

	return apply_filters( 'bp_rbe_inject_qs_in_email', $retval, $qs );
}

/**
 * Returns the RBE marker used to parse the email reply content.
 *
 * @since 1.0-RC4
 *
 * @return string
 */
function bp_rbe_get_marker() {
+	/**
	 * Filters the marker used in reply-to emails.
	 *
	 * @since 1.0-RC5
	 *
	 * @param string $marker RBE marker.
	 */
	$marker = apply_filters( 'bp_rbe_get_marker', __( '--- Reply ABOVE THIS LINE to add a comment ---', 'bp-rbe' ) );

	return $marker;
}

/**
 * Returns the notice used to denote if an email cannot be replied by email.
 *
 * @since 1.0-RC5
 *
 * @return string
 */
function bp_rbe_get_nonrbe_notice() {
+	/**
	 * Filters the non-RBE notice.
	 *
	 * @since 1.0-RC5
	 *
	 * @param string $notice Non-RBE notice.
	 */
	$notice = apply_filters( 'bp_rbe_get_nonrbe_notice', __( '--- Replying to this email will not send a message directly to the recipient or group ---', 'bp-rbe' ) );

	return $notice;
}

/**
 * Encodes a string.
 *
 * By default, uses AES encryption from {@link http://phpseclib.sourceforge.net/ phpseclib}.
 * Licensed under the {@link http://www.opensource.org/licenses/mit-license.html MIT License}.
 *
 * Thanks phpseclib! :)
 *
 * @param array $args Array of arguments. See inline doc of function for full details.
 * @return string The encoded string
 * @since 1.0-beta
 */
function bp_rbe_encode( $args = array() ) {
	$r = wp_parse_args( $args, array (
 		'string' => false,                       // the content we want to encode
 		'key'    => bp_rbe_get_setting( 'key' ), // the key used to aid in encryption; defaults to the key set in the admin area
 		'param'  => false,                       // the string we want to prepend to the key; handy to set different keys
 		'mode'   => 'aes',                       // mode of encryption; defaults to 'aes'
	) );

	if ( empty( $r['string'] ) || empty( $r['key'] ) ) {
		return false;
	}

	if ( $r['param'] ) {
		$r['key'] = $r['param'] . $r['key'];
	}

	$encrypt = false;

	// default mode is AES
	// you can override this with the filter below to prevent the AES library from loading
	// to modify the return value, use the 'bp_rbe_encode' filter
	$r['mode'] = apply_filters( 'bp_rbe_encode_mode', $r['mode'] );

	if ( 'aes' == $r['mode'] ) {
		if ( ! class_exists( 'Crypt_AES' ) ) {
			require( BP_RBE_DIR . '/includes/phpseclib/AES.php' );
		}

		$cipher = new Crypt_AES();
		$cipher->setKey( $r['key'] );

		// converts AES binary string to hexadecimal
		$encrypt = bin2hex( $cipher->encrypt( $r['string'] ) );
	}

	return apply_filters( 'bp_rbe_encode', $encrypt, $r['string'], $r['mode'], $r['key'], $r['param'] );
}

/**
 * Decodes an encrypted string.
 *
 * By default, uses AES decryption from {@link http://phpseclib.sourceforge.net/ phpseclib}.
 * Licensed under the {@link http://www.opensource.org/licenses/mit-license.html MIT License}.
 *
 * Thanks phpseclib! :)
 *
 * @param array $args Array of arguments. See inline doc of function for full details.
 * @return string The decoded string
 * @since 1.0-beta
 */
function bp_rbe_decode( $args = array() ) {
	$r = wp_parse_args( $args, array (
 		'string' => false,                       // the encoded string we want to dencode
 		'key'    => bp_rbe_get_setting( 'key' ), // the key used to aid in encryption; defaults to the key set in the admin area
 		'param'  => false,                       // the string we want to prepend to the key; handy to set different keys
 		'mode'   => 'aes',                       // mode of decryption; defaults to 'aes'
	) );

	if ( empty( $r['string'] ) || empty( $r['key'] ) ) {
		return false;
	}

	if ( $r['param'] ) {
		$r['key'] = $r['param'] . $r['key'];
	}

	$decrypt = false;

	// default mode is AES
	// you can override this with the filter below to prevent the AES library from loading
	// to modify the return value, use the 'bp_rbe_decode' filter
	$r['mode'] = apply_filters( 'bp_rbe_encode_mode', $r['mode'] );

	if ( 'aes' == $r['mode'] ) {
		if ( ! class_exists( 'Crypt_AES' ) ) {
			require( BP_RBE_DIR . '/includes/phpseclib/AES.php' );
		}

		$cipher = new Crypt_AES();
		$cipher->setKey( $r['key'] );

		// converts hexadecimal AES string back to binary and then decrypts string back to plain-text
		$decrypt = $cipher->decrypt( hex2bin( $r['string'] ) );
	}

	return apply_filters( 'bp_rbe_decode', $decrypt, $r['string'], $r['mode'], $r['key'], $r['param'] );
}

if ( ! function_exists( 'hex2bin' ) ) :
/**
 * hex2bin() isn't available in PHP < 5.4.
 *
 * So let's add our compatible version here.
 *
 * @uses pack()
 * @param string $text Hexadecimal representation of data.
 * @return mixed Returns the binary representation of the given data or FALSE on failure.
 */
function hex2bin( $text ) {
    return pack( 'H*', $text );
}
endif;

/**
 * Should we enable SSL for the IMAP connection??
 *
 * Check to see if both the OpenSSL and IMAP modules are loaded.  Next, see if
 * the port is explictly 993.
 *
 * @since 1.0-beta
 *
 * @param int $port The port number for the IMAP server
 * @return bool
 */
function bp_rbe_is_imap_ssl( $port = 0 ) {
	if ( empty( $port ) ) {
		$port = bp_rbe_get_setting( 'port' );
	}

	return BP_Reply_By_Email_Connect::is_ssl( (int) $port );
}

/**
 * Logs BP Reply To Email actions to a debug log.
 *
 * @uses error_log()
 * @since 1.0-beta
 */
function bp_rbe_log( $message ) {
	// if debugging is off, stop now.
	if ( ! constant( 'BP_RBE_DEBUG' ) )
		return;

	if ( empty( $message ) )
		return;

	error_log( '[' . gmdate( 'd-M-Y H:i:s' ) . '] ' . $message . "\n", 3, BP_RBE_DEBUG_LOG_PATH );
}

/** Hook-related ********************************************************/

/**
 * Inbound POST callback catcher.
 *
 * @since 1.0-RC3
 */
function bp_rbe_inbound_catch_callback() {
	global $bp_rbe;

	// make sure WP-cron / AJAX / XMLRPC isn't running.
	if ( defined( 'DOING_CRON' ) || defined( 'DOING_AJAX' ) || isset( $_GET['doing_wp_cron'] ) || defined( 'XMLRPC_REQUEST' ) ) {
		return;
	}

	// setup the webhook parser
	if ( is_callable( array( $bp_rbe->inbound_provider, 'webhook_parser' ) ) ) {
		call_user_func( array( $bp_rbe->inbound_provider, 'webhook_parser' ) );
	}
}

/**
 * Overrides an activity comment's action formatting.
 *
 * If an activity comment was posted via email, this function reformats the
 * activity action to denote this.
 *
 * Only applicable in BuddyPress 2.0+.  Lower versions uses {@link bp_rbe_activity_comment_action()}
 * instead.
 *
 * @since 1.0-RC3
 *
 * @param string $retval The current activity comment action string
 * @param BP_Activity_Activity $activity
 */
function bp_rbe_activity_comment_action_formatting( $retval, $activity ) {
	if ( bp_activity_get_meta( $activity->id, 'bp_rbe' ) ) {
		$retval = sprintf( __( '%s posted a new activity comment via email:', 'bp-rbe' ), bp_core_get_userlink( $activity->user_id ) );
	}

	return $retval;
}

/**
 * Overrides an activity comment's action string.
 *
 * BP doesn't pass the $user_id in the "bp_activity_comment_action" filter.
 * So, a little bit of hackery is done just to add the words "via email" to the comment action! :)
 *
 * @since 1.0-beta
 */
function bp_rbe_activity_comment_action_filter( $user_id ) {
	global $bp_rbe;

	// hack to pass user ID!
	$bp_rbe->filter = new stdClass;
	$bp_rbe->filter->user_id = $user_id;

	add_filter( 'bp_activity_comment_action', 'bp_rbe_activity_comment_action' );
}

	/**
	 * Callback for "bp_activity_comment_action" filter.
	 * Uses the passed user ID from {@link bp_rbe_activity_comment_action_filter()}.
	 *
	 * @since 1.0-beta
	 */
	function bp_rbe_activity_comment_action( $action ) {
		global $bp_rbe;

		// use our passed user ID from hack above
		return sprintf( __( '%s posted a new activity comment via email:', 'bp-rbe' ), bp_core_get_userlink( $bp_rbe->filter->user_id ) );
	}


/**
 * Adds anchor to an activity comment's "View" link.
 *
 * Who likes scrolling all the way down the page to find their comment!
 *
 * @since 1.0-beta
 */
function bp_rbe_activity_comment_view_link( $link, $activity ) {
	if ( $activity->type == 'activity_comment' ) {
		$action = apply_filters_ref_array( 'bp_get_activity_action_pre_meta', array(
			$activity->action,
			&$activity,
			array(
				'no_timestamp' => false,
			)
		) );

		$time_since = apply_filters_ref_array( 'bp_activity_time_since', array( '<span class="time-since">' . bp_core_time_since( $activity->date_recorded ) . '</span>', &$activity ) );

		return $action . ' <a href="' . bp_activity_get_permalink( $activity->id ) . '#acomment-' . $activity->id . '" class="view activity-time-since" title="' . __( 'View Discussion', 'buddypress' ) . '">' . $time_since . '</a>';
	}

	return $link;
}

/**
 * When posting via email, we also update the last activity entries in BuddyPress.
 *
 * This is so your BuddyPress site doesn't look dormant when your members
 * are emailing each other back and forth! :)
 *
 * @param array $args Depending on the filter that this function is hooked into, contents will vary
 * @since 1.0-RC1
 */
function bp_rbe_log_last_activity( $args ) {
	// get user id from activity entry
	if ( ! empty( $args['user_id'] ) )
		$user_id = $args['user_id'];

	// get user id from PM
	elseif ( ! empty( $args['sender_id'] ) )
		$user_id = $args['sender_id'];

	else
		$user_id = false;

	// if no user ID, return now
	if ( empty( $user_id ) )
		return;

	// update user's last activity
	bp_update_user_last_activity( $user_id );

	// now update 'last_activity' group meta entry if applicable
	if ( ! empty( $args['type'] ) ) {
		switch ( $args['type'] ) {
			case 'new_forum_topic' :
			case 'new_forum_post' :
			case 'bbp_topic_create' :
			case 'bbp_reply_create' :
				// sanity check!
				if ( ! bp_is_active( 'groups' ) )
					return;

				groups_update_last_activity( $args['item_id'] );

				break;

			// for group activity comments, we have to look up the parent activity to see
			// if the activity comment came from a group
			case 'activity_comment' :
				// we don't need to proceed if the groups component was disabled
				if ( ! bp_is_active( 'groups' ) )
					return;

				// sanity check!
				if ( ! bp_is_active( 'activity' ) )
					return;

				// grab the parent activity
				$activity = bp_activity_get_specific( 'activity_ids=' . $args['item_id'] );

				if ( ! empty( $activity['activities'][0] ) ) {
					$parent_activity = $activity['activities'][0];

					// if parent activity update is from the groups component,
					// that means the activity comment was in a group!
					// so update group 'last_activity' meta entry
					if ( $parent_activity->component == 'groups' )
						groups_update_last_activity( $parent_activity->item_id );
				}

				break;
		}
	}
}

/**
 * Removes end of line (EOL) and a given character from content.
 * Used to remove the trailing ">" character from email replies. Wish Basecamp did this!
 *
 * @param string $content The content we want to modify
 * @return string Either content without EOL + $char or the unmodified content.
 * @since 1.0-beta
 */
function bp_rbe_remove_eol_char( $content ) {
	$char = apply_filters( 'bp_rbe_eol_char', '>' );

	if ( substr( $content, -strlen( $char ) ) == $char )
		return substr( $content, 0, strrpos( $content, chr( 10 ) . $char ) );

	return $content;
}

/**
 * Converts HTML to plain-text.
 *
 * Uses the html2text() function by Jevon Wright.  Thanks Jevon! :)
 *
 * @uses html2text() by Jevon Wright. Licensed under the EPL v1.0 and LGPL v3.0.
 *       We use a fork of 0.1.1 to maintain PHP 5.2 compatibility.
 * @link https://github.com/r-a-y/html2text/tree/0.1.x
 * @link https://github.com/soundasleep/html2text/
 *
 * @param string $content The HTML content we want to convert to plain-text.
 * @return string Converted plain-text.
 */
function bp_rbe_html_to_plaintext( $content ) {
	if ( empty( $content ) ) {
		return $content;
	}

	if ( false === function_exists( 'convert_html_to_text' ) ) {
		require BP_RBE_DIR . '/includes/functions.html2text.php';
	}

	// Suppress warnings when using DOMDocument.
	// This addresses issues when failing to parse certain HTML.
	if ( function_exists( 'libxml_use_internal_errors' ) ) {
		libxml_use_internal_errors( true );
	}

	return convert_html_to_text( $content );
}

/**
 * Removes line wrap from plain-text emails.
 *
 * Plain-text emails usually wrap after a certain amount of characters
 * (GMail wraps after ~78 characters) and this will also be reflected on
 * the frontend of BuddyPress.
 *
 * This function attempts to remove the line wrap from plain-text emails
 * during email parsing so things will look pretty on the frontend.
 *
 * But, this isn't used at the moment due to bugginess!
 * If you want to try it, hook this function to the 'bp_rbe_parse_email_body' filter.
 *
 * Note: Github's RBE doesn't strip line wraps.
 *
 * @param string $body The body we want to remove line-wraps for
 * @param obj $structure The structure of the email from imap_fetchstructure()
 * @return string Converted plain-text.
 * @todo Need to check line endings on other OSs... might use PHP_EOL instead
 */
function bp_rbe_remove_line_wrap_from_plaintext( $body, $structure ) {
	// just in case, we only do this to emails that are not HTML-only
	if ( $structure->subtype != 'html' ) {
		// replace double CRLF with double LF
		$body = str_replace( "\r\n\r\n", "\n\n", $body );

		// keep line breaks for certain instances
		// hacky at best... :(
		// doesn't handle numbered list items
		// @todo craft a nice regex to do this instead and cover all instances?

		// any line ending with a colon
		$body = str_replace( ":\r\n", ':<RAY>', $body );

		// any line beginning with '-', '*', ' '
		$body = str_replace( "\r\n-", '<RAY>-', $body );
		$body = str_replace( "\r\n*", '<RAY>*', $body );
		$body = str_replace( "\r\n ", '<RAY> ', $body );

		// now remove single CRLF so line wrap is gone!
		$body = str_replace( "\r\n", ' ', $body );

		// add back the line breaks
		$body = str_replace( '<RAY>', "\n", $body );
	}

	return $body;
}

/**
 * Tries to remove the email signature of *most* common email clients from email replies.
 *
 * Keyword here is *most*! :) A work-in-progress!
 *
 * @param string $content The content we want to modify
 * @return string
 * @since 1.0-beta
 */
function bp_rbe_remove_email_client_signature( $content ) {

	// Good reference article:
	// http://stackoverflow.com/questions/1372694/strip-signatures-and-replies-from-emails#answer-2193937
	//
	// I've implemented basically everything except #2 and #6


	// helpful ascii whitespace debugger
	//var_dump( str_replace( array( "\r\n", "\r", "\n", "\t"), array( '\\r\\n', '\\r', '\\n', '\\t' ), $content ) );

	// (1) Standard email sig delimiter
	//
	// eg. "--\r\n
	//      John Doe"
	//
	if ( strpos( $content, chr( 10 ) . '--' . chr( 13 ) . chr( 10 ) ) !== false ) {
		$content = substr( $content, 0, strpos( $content, chr( 10 ) . '--' . chr( 13 ) . chr( 10 ) ) );
	}

	// (2) Common mobile email client sigs:
	// check to see if any line begins with "Sent from my "
	elseif ( strrpos( $content, chr( 10 ) . 'Sent from my ' ) !== false ) {
		$content = substr( $content, 0, strrpos( $content, chr( 10 ) . 'Sent from my ' ) );

	}

	// (3)(i) Miscellaneous email sigs: Outlook Desktop, Novell Groupwise Web Access
	//
	// These clients (and probably others) use an indeterminate amount of dashes to
	// separate the body and the signature; let's check for at least 20 occurences in a row
	// @todo Perhaps use a longer length to be extra safe?
	elseif ( strrpos( $content, chr( 10 ) . '--------------------' ) !== false ) {
		$content = substr( $content, 0, strrpos( $content, chr( 10 ) . '--------------------' ) );
	}

	// (3)(ii) Miscellaneous email sigs: Outlook Web Access
	//
	// Outlook Web Access sigs look like this:
	//
	// 	________________________________________
	//      From: ...
	//
	// Since the multiple underscores are sometimes of an indeterminant length,
	// we check for at least 20 occurences
	// @todo Perhaps use a longer length to be extra safe?
	elseif ( strrpos( $content, chr( 10 ) . '____________________' ) !== false ) {
		$content = substr( $content, 0, strrpos( $content, chr( 10 ) . '____________________' ) );
	}

	// (3)(iii) Miscellaneous email sigs: Outlook Desktop
	//
	// Some Outlook Desktop sigs look like this:
	//
	// 	-----Original Message-----
	//      From: ...
	//
	elseif ( strrpos( $content, chr( 10 ) . '-----Original Message-----' ) !== false ) {
		$content = substr( $content, 0, strrpos( $content, chr( 10 ) . '-----Original Message-----' ) );
	}

	// (3)(iv) Miscellaneous email sigs: Lotus Notes
	//
	// eg. "-----Blah <blah.com> wrote: -----"
	//
	// The reason we do two checks here is people might use five dashes to emulate a <hr> tag.
	elseif ( strrpos( $content, chr( 10 ) . '-----' ) !== false && strrpos( $content, ': -----' ) !== false ) {
		$content = substr( $content, 0, strrpos( $content, chr( 10 ) . '-----' ) );
	}

	// (4) Common email client sigs:
	// check if last character of last line ends with a colon.
	//
	// eg. 'On DATE, USER wrote:'
	//     'USER wrote:'
	//
	// This is the last check because it's slightly more intensive than the others
	else {
		// split email into an array of lines; reverse the order
		$lines = array_reverse( preg_split( '/$\R?^/m', trim( $content ) ) );

		//print_r($lines);

		// trailing '>' character
		//
		// this might occur when you're replying to a HTML email generated by WP Better Emails
		// if so, remove this line
		if ( '>' === $lines[0] ) {
			array_shift( $lines );
		}

		// last character of last line ends with a colon!
		if ( substr( rtrim( $lines[0] ), -1 ) === ':' ) {

			$i = 0;

			// now we check to see if the sig was wrapped after a certain character limit.
			//
			// 	eg. 'On DATE, USER
			//           wrote:'
			//
			// chances are this sig takes up a maximum of two lines, but just to be safe, I'm using this method!
			//
			// this is done by checking if each line from the last line is less than the last line
			while ( ! empty( $lines[$i + 1] ) ) {
				// if the nth-to-last line is less than the current line, stop now!
				if ( strlen( $lines[$i + 1] ) < strlen( $lines[$i] ) )
					break;

				// iterate!
				++$i;

				// find position of marker
				$marker = strrpos( $content, $lines[$i] );

				// if marker matches integer 0, remove the line preceding this one
				if ( $marker === 0 ) {
					$marker = strrpos( $content, $lines[$i-1] );
				}

				// get body until beginning of the sig
				$content = substr( $content, 0, $marker );

			}

			// if $i didn't iterate, this means the sig is on the last line only
			if ( $i == 0 ) {
				$content = substr( $content, 0, strrpos( $content, $lines[0] ) );
			}

		}
	}

	return trim( $content );
}

/**
 * Logs no match errors during RBE parsing.
 *
 * Also sends a failure message back to the original sender for feedback
 * purposes if enabled.
 *
 * @since 1.0-RC3
 *
 * @uses bp_rbe_log() Logs error messages in a custom log
 * @param object $parser The WP_Error object.
 * @param array $data {
 *     An array of arguments.
 *
 *     @type array $headers Email headers.
 *     @type string $to_email The 'To' email address.
 *     @type string $from_email The 'From' email address.
 *     @type string $content The email body content.
 *     @type string $subject The email subject line.
 *     @type bool $is_html Whether the email content is HTML or not.
 * }
 * @param int $i The current message number
 * @param resource|bool $imap The IMAP connection if passed. Boolean false if not.
 */
function bp_rbe_log_no_matches( $parser, $data, $i, $imap ) {
	$log = $message = false;

	$type = is_wp_error( $parser ) ? $parser->get_error_code() : false;

	// log messages based on the type
	switch ( $type ) {

		/** RBE **********************************************************/

		case 'no_address_tag' :
			$log = __( 'error - no address tag could be found', 'bp-rbe' );

			break;

		case 'no_user_id' :
			$log      = __( 'error - no user ID could be found', 'bp-rbe' );

			$sitename = wp_specialchars_decode( get_blog_option( bp_get_root_blog_id(), 'blogname' ), ENT_QUOTES );

			$message  = sprintf( __( 'Hi there,

You tried to use the email address - %s - to reply by email.  Unfortunately, we could not find this email address in our system.

This can happen in a couple of different ways:
* You have configured your email client to reply with a custom "From:" email address.
* You read email addressed to more than one account inside of a single Inbox.

Make sure that, when replying by email, your "From:" email address is the same as the address you\'ve registered at %s.

If you have any questions, please let us know.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_header( $data['headers'], 'From' ), $sitename );
			break;

		case 'user_is_spammer' :
			$log = __( 'notice - user is marked as a spammer.  reply not posted!', 'bp-rbe' );

			break;

		case 'no_params' :
			$log = __( 'error - no parameters were found', 'bp-rbe' );

			break;

		case 'no_reply_body' :
			$log = __( 'error - body message for reply was empty', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your reply could not be posted because we could not find the "%s" marker in the body of your email.

In the future, please make sure you reply *above* this line for your comment to be posted on the site.

For reference, your entire reply was:

"%s".

If you have any questions, please let us know.', 'bp-rbe' ), bp_rbe_get_marker(), $data['content'] );

			break;

		/** ACTIVITY *****************************************************/

		case 'root_activity_deleted' :
			$log     = __( 'error - root activity update was deleted before this could be posted', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your reply:

"%s"

Could not be posted because the activity entry you were replying to no longer exists.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		case 'root_or_parent_activity_deleted' :
			$log     = __( 'error - root or parent activity update was deleted before this could be posted', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your reply:

"%s"

Could not be posted because the activity entry you were replying to no longer exists.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		/** GROUP FORUMS *************************************************/

		case 'user_not_group_member' :
			$log     = __( 'error - user is not a member of the group. forum reply not posted.', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your forum reply:

"%s"

Could not be posted because you are no longer a member of this group.  To comment on the forum thread, please rejoin the group.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		case 'user_banned_from_group' :
			$log = __( 'notice - user is banned from group. forum reply not posted.', 'bp-rbe' );

			break;

		case 'new_forum_topic_empty' :
			$log     = __( 'error - body message for new forum topic was empty', 'bp-rbe' );

			$message = __( 'Hi there,

We could not post your new forum topic by email because we could not find any text in the body of the email.

In the future, please make sure to type something in your email! :)

If you have any questions, please let us know.', 'bp-rbe' );

			break;

		case 'forum_reply_exists' :
			$log     = __( 'error - forum reply already exists in topic', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your forum reply:

"%s"

Could not be posted because you have already posted the same message in the forum topic you were attempting to reply to.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		case 'forum_reply_fail' :
			$log     = __( 'error - forum topic was deleted before reply could be posted', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your forum reply:

"%s"

Could not be posted because the forum topic you were replying to no longer exists.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		case 'forum_topic_fail' :
			$log     = __( 'error - forum topic failed to be created', 'bp-rbe' );

			// this is a pretty generic message...
			$message = sprintf( __( 'Hi there,

Your forum topic titled "%s" could not be posted due to an error.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), $data['subject'] );

			break;

		/** PRIVATE MESSAGES *********************************************/

		// most likely a spammer trying to infiltrate an existing PM thread
		case 'private_message_not_in_thread' :
			$log = __( 'error - user is not a part of the existing PM conversation', 'bp-rbe' );

			break;

		case 'private_message_thread_deleted' :
			$log     = __( 'error - private message thread was deleted by all parties before this could be posted', 'bp-rbe' );

			$message = sprintf( __( 'Hi there,

Your private message reply:

"%s"

Could not be posted because the private message thread you were replying to no longer exists.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		case 'private_message_fail' :
			$log     = __( 'error - private message failed to post', 'bp-rbe' );
			$message = sprintf( __( 'Hi there,

Your reply:

"%s"

Could not be posted due to an error.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

			break;

		// 3rd-party plugins can filter the two variables below to add their own logs and email messages.
		default :
			$log     = apply_filters( 'bp_rbe_extend_log_no_match', $log, $type, $data, $i, $imap );
			$message = apply_filters( 'bp_rbe_extend_log_no_match_email_message', $message, $type, $data, $i, $imap );

			break;
	}

	// internal logging
	if ( $log ) {
		bp_rbe_log( sprintf( __( 'Message #%d: %s', 'bp-rbe' ), $i, $log ) );
	}

	// failure message to author
	// if you want to turn off failure messages, use the filter below
	if ( apply_filters( 'bp_rbe_enable_failure_message', true ) && $message ) {
		$to = BP_Reply_By_Email_Parser::get_header( $data['headers'], 'From' );

		if ( ! empty( $to ) ) {
			$sitename = wp_specialchars_decode( get_blog_option( bp_get_root_blog_id(), 'blogname' ), ENT_QUOTES );
			$subject  = sprintf( __( '[%s] Your Reply By Email message could not be posted', 'bp-rbe' ), $sitename );

			// temporarily remove RBE mail filter by wiping out email querystring
			add_filter( 'bp_rbe_querystring', '__return_false' );

			// send email
			wp_mail( $to, $subject, $message );

			// add it back
			remove_filter( 'bp_rbe_querystring', '__return_false' );
		}
	}
}

/**
 * Failsafe for RBE.
 *
 * RBE occasionally hangs during the inbox loop.  This function tries
 * to snap RBE out of it by checking the last few lines of the RBE
 * debug log.
 *
 * If all lines match our failed cronjob message, then reset RBE so
 * RBE can run fresh on the next scheduled run.
 *
 * @uses bp_rbe_tail() Grabs the last N lines from the RBE debug log
 * @uses bp_rbe_cleanup() Cleans up the DB entries that RBE uses
 * @since 1.0-RC1
 */
function bp_rbe_failsafe() {
	// get the last N lines from the RBE debug log
	$last_entries = bp_rbe_tail( constant( 'BP_RBE_DEBUG_LOG_PATH' ), constant( 'BP_RBE_TAIL_LINES' ) );

	if ( empty( $last_entries ) )
		return;

	// count the number of tines our 'cronjob wants to connect' message occurs
	$counter = 0;

	// see if each line contains our cronjob fail string
	foreach ( $last_entries as $entry ) {
		if ( strpos( $entry, '--- Cronjob wants to connect - however according to our DB indicator, we already have an active IMAP connection! ---' ) !== false )
			++$counter;
	}

	// if all lines match the cronjob fail string, reset RBE!
	if ( $counter == constant( 'BP_RBE_TAIL_LINES' ) ) {
		bp_rbe_log( '--- Uh-oh! Looks like RBE is stuck! - FORCE RBE cleanup ---' );

		// cleanup RBE!
		bp_rbe_cleanup();

		// use this hook to perhaps send an email to the admin?
		do_action( 'bp_rbe_failsafe_complete' );
	}
}

/**
 * When RBE posts a new group forum post, record the post meta in bundled bbPress
 * so we can reference it later in the topic post loop.
 *
 * @uses bb_update_postmeta() To add post meta in bundled bbPress.
 * @since 1.0-RC1
 */
function bp_rbe_group_forum_record_meta( $id ) {
	// since we post items outside of BP's screen functions, it should be safe
	// to just check if BP's current component and actions are false
	if ( ! bp_current_component() && ! bp_current_action() )
		bb_update_postmeta( $id, 'bp_rbe', 1 );
}

/**
 * When RBE posts a new activity item, record the activity meta.
 *
 * Could be used in a custom activity loop to grab activities made by RBE later.
 *
 * @uses bp_activity_update_meta() To add activity meta.
 * @since 1.0-RC1
 */
function bp_rbe_activity_record_meta( $args ) {
	bp_activity_update_meta( $args['activity_id'], 'bp_rbe', 1 );
}

/**
 * Modify the topic post timestamp to append our custom RBE string.
 *
 * Checks to see if the current topic post was posted by RBE, if so, alter the
 * timestamp string.
 *
 * @since 1.0-RC1
 */
function bp_rbe_alter_forum_post_timestamp( $timestamp ) {
	global $topic_template;

	// if the forum post was made via email, alter the post timestamp to add our custom string ;)
	// hackalicious!
	if ( ! empty( $topic_template->post->bp_rbe ) )
		// piggyback off of GES' email icon with the 'gemail_icon' class!
		return $timestamp . ' <span class="bp-forum-post-rbe gemail_icon">' . __( 'via email', 'bp-rbe' ) . '</span>';

	return $timestamp;
}

/**
 * Some basic CSS to style our group forum topic by email block.
 *
 * @since 1.0-beta
 */
function bp_rbe_new_topic_info_css() {
	$show_css = apply_filters( 'bp_rbe_new_topic_info_css', bp_is_single_item() && bp_is_groups_component() && bp_is_current_action( 'forum' ) && ! bp_action_variables() && bp_loggedin_user_id() );

	if ( $show_css ) :

		$current_group = function_exists( 'groups_get_current_group' ) ? groups_get_current_group() : false;
		if ( is_object( $current_group ) && false === $current_group->is_member ) {
			return;
		}
?>
	<style type="text/css">
		#rbe-toggle { display:none; }
		#rbe-header, #new-post #rbe-message, #rbe-header h4 {margin-bottom: 1em;}
		#rbe-message { background: #FFFFE0; border: 1px solid #E6DB55; border-radius:3px; padding:1em; }
		#rbe-message h5 {margin:5px 0 0;}
		#rbe-message ul { margin:1em 1.5em; }
		#rbe-message li {list-style-type:disc;}
	</style>
<?php
	endif;
}

/**
 * Content block to show group members how to post new forum topics via email.
 * Javascript-degradable.
 *
 * @uses bp_rbe_groups_get_encoded_email_address()
 * @since 1.0-beta
 */
function bp_rbe_new_topic_info() {
	global $bp;

	$group = groups_get_current_group();

	// if current user is not a member of the group, stop now!
	if ( empty( $group->is_member ) )
		return;
?>
	<div id="rbe-header">
		<h4><?php _e( 'Post New Topics via Email', 'bp-rbe' ) ?></h4>

		<p><?php _e( 'You can post new topics to this group from the comfort of your email inbox.', 'bp-rbe' ) ?> <a href="javascript:;" id="rbe-toggle"><?php _e( 'Find out how!', 'bp-rbe' ) ?></a></p>
	</div>

	<div id="rbe-message">
		<h5><?php printf( __( 'Send an email to <strong><a href="%s">%s</strong></a> and a new forum topic will be posted in %s.', 'bp-rbe' ), "mailto: " . bp_get_current_group_name() . " <" . bp_rbe_groups_get_encoded_email_address(). ">", bp_rbe_groups_get_encoded_email_address(), bp_get_current_group_name() ); ?></h5>

		<ul>
			<li><?php printf( __( 'Compose a new email from the same email address you registered with &ndash; %s', 'bp-rbe' ), '<strong>' . $bp->loggedin_user->userdata->user_email . '</strong>' ) ?>.</li>
			<li><?php _e( 'Put the address above in the "To:" field of the email.', 'bp-rbe' ) ?></li>
			<li><?php _e( 'The email subject will become the topic title.', 'bp-rbe' ) ?></li>
			<?php do_action( 'bp_rbe_new_topic_info_extra' ) ?>
		</ul>

		<p><?php _e( '<strong>Note:</strong> The email address above is unique to you and this group. Do not share this email address with anyone else! (Each group member will have their own unique email address.)', 'bp-rbe' ) ?></p>
	</div>

	<script type="text/javascript">
	jQuery(function() {
		jQuery('#rbe-toggle').show();
		jQuery('#rbe-message').hide();
		jQuery('#rbe-toggle').click(function() {
			jQuery('#rbe-message').toggle(300);
		});
	});

	</script>
<?php
}

/** Cron ****************************************************************/

/**
 * See if we should connect to the IMAP inbox.
 *
 * If we need to connect, we add a hook to spawn an inbox check on the
 * 'shutdown' action.
 *
 * @since 1.0-RC3
 *
 * @see bp_rbe_spawn_inbox_check()
 */
function bp_rbe_should_connect() {
	// make sure WP-cron / XMLRPC isn't running
	if ( defined( 'DOING_CRON' ) || defined( 'DOING_AJAX' ) || isset( $_GET['doing_wp_cron'] ) || defined( 'XMLRPC_REQUEST' ) ) {
		return;
	}

	// check to see if we're connected via our DB marker
	if ( ! bp_rbe_is_connected() ) {
		add_action( 'shutdown', 'bp_rbe_spawn_inbox_check' );
	}
}

/**
 * Send a request to check the IMAP inbox.
 *
 * @since 1.0-RC3
 *
 * @see bp_rbe_run_inbox_listener()
 */
function bp_rbe_spawn_inbox_check() {
	if ( bp_rbe_is_connecting( array( 'clearcache' => true ) ) ) {
		return;
	}

	wp_remote_post( home_url( '/?bp-rbe-ping' ), array(
		'blocking'  => false,
		'sslverify' => false,
		'timeout'   => 0.01,
		'body'      => array(
			'_bp_rbe_check' => 1
		)
	) );
}

/**
 * Listens to requests to check the IMAP inbox.
 *
 * If a POST request is made to check the IMAP inbox, RBE will actually
 * process this request in this function.  We also make sure that WP-cron is
 * running before pinging the IMAP inbox.
 *
 * @since 1.0-RC3
 * @since 1.0-RC5 Added $r as an argument.
 *
 * @see bp_rbe_spawn_inbox_check()
 *
 * @param array $r {
 *     An array of parameters.
 *
 *     @type bool $force If true, do not check for GET or WP cron parameters.
 * }
 */
function bp_rbe_run_inbox_listener( $r = array() ) {
	$r = wp_parse_args( $r, array(
		'force' => false
	) );

	/**
	 * Filter to bail out of running IMAP inbox checks.
	 *
	 * Handy if you're using IMAP and you want to run your own thing via real cron.
	 *
	 * @since 1.0-RC5
	 *
	 * @return bool
	 */
	if ( true !== apply_filters( 'bp_rbe_run_inbox_listener', true ) ) {
		return;
	}

	if ( false === $r['force'] ) {
		if ( false === isset( $_GET['bp-rbe-ping'] ) ) {
			return;
		}

		// make sure WP-cron isn't running
		if ( defined( 'DOING_CRON' ) || isset( $_GET['doing_wp_cron'] ) ) {
			return;
		}

	}

	if ( bp_rbe_is_inbound() || bp_rbe_is_connecting( array( 'clearcache' => true ) ) ) {
		return;
	}

	if ( bp_rbe_is_connected() ) {
		return;
	}

	bp_rbe_add_imap_lock();

	// run our inbox check
	$imap = BP_Reply_By_Email_IMAP::init();
	$imap = $imap->run();

	// Do not run the inbox check again on failure.
	if ( false === $imap ) {
		remove_action( 'shutdown', 'bp_rbe_spawn_inbox_check' );
	}

	// kill the rest of this page
	die();
}

if ( ! function_exists( 'bp_rbe_stop_imap' ) ) :
/**
 * Poor man's daemon stopper.
 *
 * Adds a text file that gets checked by {@link BP_Reply_By_Email_IMAP::should_stop()}
 * in order to stop an existing IMAP inbox loop.
 *
 * @see BP_Reply_By_Email_IMAP::run()
 * @since 1.0-beta
 */
function bp_rbe_stop_imap() {
	touch( bp_core_avatar_upload_path() . '/bp-rbe-stop.txt' );
	remove_action( 'shutdown', 'bp_rbe_spawn_inbox_check' );
}
endif;

if ( ! function_exists( 'bp_rbe_should_stop' ) ) :
/**
 * Returns true when the main IMAP loop should finally stop.
 *
 * Uses a poor man's daemon.  Info taken from Christopher Nadeau's post -
 * {@link http://devlog.info/2010/03/07/creating-daemons-in-php/#lphp-4}.
 *
 * @see bp_rbe_stop_imap()
 * @uses clearstatcache() Clear stat cache. Needed when using file_exists() in a script like this.
 * @uses file_exists() Checks to see if our special txt file is created.
 * @uses unlink() Deletes this txt file so we can do another check later.
 * @return bool
 */
function bp_rbe_should_stop() {
	clearstatcache();

	if ( file_exists( bp_core_avatar_upload_path() . '/bp-rbe-stop.txt' ) ) {
		// delete the file for next time
		unlink( bp_core_avatar_upload_path() . '/bp-rbe-stop.txt' );
		return true;
	}

	return false;
}
endif;

if ( ! function_exists( 'bp_rbe_add_imap_lock' ) ) :
/**
 * Add IMAP lock before connecting to inbox.
 *
 * The lock uses the filesystem by default.  Function is pluggable.  Handy if
 * you want to override the current system. (eg. using memcached instead.)
 *
 * Lock is checked in [@link bp_rbe_is_connecting()}.
 *
 * @since 1.0-RC3
 *
 * @uses touch() Sets or modifies time of file
 */
function bp_rbe_add_imap_lock() {
	touch( bp_core_avatar_upload_path() . '/bp-rbe-lock.txt' );
}
endif;

if ( ! function_exists( 'bp_rbe_remove_imap_lock' ) ) :
/**
 * Remove IMAP lock file.
 *
 * @since 1.0-RC3
 *
 * @see bp_rbe_add_imap_lock()
 */
function bp_rbe_remove_imap_lock() {
	clearstatcache();

	if ( file_exists( bp_core_avatar_upload_path() . '/bp-rbe-lock.txt' ) ) {
		unlink( bp_core_avatar_upload_path() . '/bp-rbe-lock.txt' );
	}
}
endif;

if ( ! function_exists( 'bp_rbe_add_imap_connection_marker' ) ) :
/**
 * Add IMAP connection marker.  Used to determine if we are connected.
 *
 * The lock uses the filesystem by default.  Function is pluggable.  Handy if
 * you want to override the current system. (eg. using memcached instead.)
 *
 * Lock is checked in [@link bp_rbe_is_connecting()}.
 *
 * @since 1.0-RC4
 *
 * @uses touch() Sets or modifies time of file
 */
function bp_rbe_add_imap_connection_marker() {
	touch( bp_core_avatar_upload_path() . '/bp-rbe-connected.txt' );
}
endif;

if ( ! function_exists( 'bp_rbe_remove_imap_connection_marker' ) ) :
/**
 * Remove IMAP connection marker file.
 *
 * @since 1.0-RC4
 *
 * @see bp_rbe_add_imap_lock()
 */
function bp_rbe_remove_imap_connection_marker() {
	clearstatcache();

	if ( file_exists( bp_core_avatar_upload_path() . '/bp-rbe-connected.txt' ) ) {
		unlink( bp_core_avatar_upload_path() . '/bp-rbe-connected.txt' );
	}
}
endif;

/**
 * Run hourly WP cron check to see if IMAP connection is connected
 *
 * If not connected, an email will be sent to the site admin by default so
 * that person can login and re-initiate the IMAP connection.  This only runs
 * in IMAP mode and if the auto-connect option is enabled.
 *
 * @since 1.0-RC5
 */
function bp_rbe_imap_down_email_notice() {
	// If inbound mode or is connecting or IMAP auto-connect is off, bail.
	if ( bp_rbe_is_inbound() || bp_rbe_is_connecting( array( 'clearcache' => true ) ) || ( 1 !== (int) bp_rbe_get_setting( 'keepaliveauto' ) ) ) {
		return;
	}

	// If IMAP connection is down, send email so someone can reconnect.
	if ( ! bp_rbe_is_connected() ) {
		$recipients   = array();
		$recipients[] = get_option( 'admin_email' );

		/**
		 * Filter of email addresses to send "IMAP connection is down" email to.
		 *
		 * @since 1.0-RC5
		 *
		 * @param array
		 */
		$recipients = apply_filters( 'bp_rbe_imap_down_recipients', $recipients );

		if ( ! empty( $recipients ) ) {
			$message = sprintf( __( 'Hi,

The IMAP connection to the email inbox - %1$s - has been disconnected.

Please manually go to:
%2$s

And click on the "Connect" button to re-establish a connection.

Otherwise, new replies by email will not be posted to the site.', 'bp-rbe' ), bp_rbe_get_setting( 'servername' ), admin_url( 'admin.php?page=bp-rbe' ) );

			wp_mail( $recipients, __( 'BP Reply By Email - IMAP connection is down', 'bp-rbe' ), $message );
		}
	}
}

/** Modified BP functions ***********************************************/

/**
 * Get a group member's info.
 *
 * Basically a copy of {@link BP_Groups_Member::populate()} without the
 * extra {@link BP_Core_User()} call.
 *
 * @param int $user_id The user ID
 * @param int $group_id The group ID
 * @param mixed Object of group member data on success. NULL on failure.
 * @since 1.0-RC1
 */
function bp_rbe_get_group_member_info( $user_id = false, $group_id = false ) {
	if ( ! $user_id || ! $group_id )
		return false;

	global $bp, $wpdb;

	$sql = $wpdb->prepare( "SELECT * FROM {$bp->groups->table_name_members} WHERE user_id = %d AND group_id = %d", $user_id, $group_id );

	return $wpdb->get_row( $sql );
}

/** Template ************************************************************/

/**
 * Template tag to output an encoded group email address for a user.
 *
 * @uses bp_rbe_groups_get_encoded_email_address()
 * @since 1.0-beta
 */
function bp_rbe_groups_encoded_email_address( $user_id = false, $group_id = false ) {
	echo bp_rbe_groups_get_encoded_email_address( $user_id, $group_id );
}

	/**
	 * Returns the encoded group email address for a user.
	 * Note: Each user gets their own, individual email address per group.
	 *
	 * Takes $user_id and $group_id as parameters.
	 * If no parameters are passed, uses logged in user and current group respectively.
	 *
	 * @since 1.0-beta
	 */
	function bp_rbe_groups_get_encoded_email_address( $user_id = false, $group_id = false ) {
		$user_id  = ! $user_id  ? bp_loggedin_user_id()     : $user_id;
		$group_id = ! $group_id ? bp_get_current_group_id() : $group_id;

		if ( ! $user_id || ! $group_id )
			return false;

		$gstring     = 'g=' . $group_id;

		$querystring = apply_filters( 'bp_rbe_encode_group_querystring', bp_rbe_encode( array( 'string' => $gstring, 'param' => $user_id ) ), $user_id, $group_id );

		return bp_rbe_inject_qs_in_email( $querystring . '-new' );
	}

/** Abstraction *********************************************************/

if ( ! function_exists( 'bp_update_user_last_activity' ) ) :
/**
 * Update a user's last activity.
 *
 * Abstraction function for BuddyPress installs less than 1.9.0.
 */
function bp_update_user_last_activity( $user_id = 0, $time = '' ) {
	// Fall back on current user
	if ( empty( $user_id ) ) {
		$user_id = bp_loggedin_user_id();
	}

	// Bail if the user id is 0, as there's nothing to update
	if ( empty( $user_id ) ) {
		return false;
	}

	// Fall back on current time
	if ( empty( $time ) ) {
		$time = bp_core_current_time();
	}

	return bp_update_user_meta( $user_id, 'last_activity', $time );
}
endif;
