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

	$settings = !$settings ? $bp_rbe->settings : $settings;

	// also check if the IMAP extension is enabled
	if ( !is_array( $settings ) || !function_exists( 'imap_open' ) )
		return false;

	$required_key = array( 'servername', 'port', 'tag', 'username', 'password', 'key', 'keepalive', 'connect' );

	foreach ( $required_key as $required ) :
		if ( empty( $settings[$required] ) )
			return false;
	endforeach;

	return true;
}

/**
 * Check to see if we're connected to the IMAP inbox.
 *
 * To check if we're connected, a DB entry is updated in {@link BP_Reply_By_Email_IMAP::connect()}
 * and in {@link BP_Reply_By_Email_IMAP::close()}.
 *
 * @return bool
 * @since 1.0-beta
 */
function bp_rbe_is_connected() {
	$is_connected = bp_get_option( 'bp_rbe_is_connected' );

	if ( ! empty( $is_connected ) ) {
		return true;
	}

	return false;
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
	global $bp_rbe;

	// if webhost has enabled safe mode, we cannot set the time limit, so
	// we have to accommodate their max execution time
	if ( ini_get( 'safe_mode' ) ) :

		// value is in seconds
		$time = ini_get( 'max_execution_time' );

		if ( $value == 'minutes' )
			$time = floor( ini_get( 'max_execution_time' ) / 60 );

		// apply a filter just in case someone wants to override this!
		$time = apply_filters( 'bp_rbe_safe_mode_execution_time', $time );

	else :
		// if keepalive setting exists, use it; otherwise, set default keepalive to 15 minutes
		$time = !empty( $bp_rbe->settings['keepalive'] ) ? $bp_rbe->settings['keepalive'] : 15;

		if ( $value == 'seconds' )
			$time = $time * 60;
	endif;

	return $time;
}

/**
 * Injects address tag into the IMAP email address.
 *
 * eg. test@gmail.com -> test+whatever@gmail.com
 *
 * @param string $param The parameters we want to add to an email address.
 * @since 1.0-beta
 * @todo Add subdomain addressing support in a future release
 */
function bp_rbe_inject_qs_in_email( $qs ) {
	global $bp_rbe;

	$email	= $bp_rbe->settings['email'];
	$at_pos	= strpos( $email, '@' );

	// Address tag + $qs
	$tag_qs	= $bp_rbe->settings['tag'] . $qs;

	return apply_filters( 'bp_rbe_inject_qs_in_email', substr_replace( $email, $tag_qs, $at_pos, 0 ), $tag_qs );
}

/**
 * Encodes a string using the key set from the admin area.
 * Second parameter is to prefix something to the key. Handy to set different keys.
 *
 * Modified from Abhiskek Sanghvi's encode() function {@link http://myphpscriptz.com/2010/08/basic-string-encodingdecoding-functions/}
 *
 * @param string $string The content we want to encode
 * @param string $param Optional. The string we want to prepend to the key.
 * @return string The encoded string
 * @since 1.0-beta
 */
function bp_rbe_encode( $string, $param = false ) {
	global $bp_rbe;

	$key = $bp_rbe->settings['key'];

	if ( empty( $key ) )
		return false;

	if ( $param )
		$key = $param . $key;

	// filter the key
	$key = apply_filters( 'bp_rbe_key_before_encode', $key, $string, $param );

	if ( ! class_exists( 'Crypt_AES' ) ) {
		require( BP_RBE_DIR . '/includes/phpseclib/AES.php' );
	}

	$cipher = new Crypt_AES();
	$cipher->setKey( $key );

	$encrypt = bin2hex( $cipher->encrypt( $string ) );

	return apply_filters( 'bp_rbe_encode', $encrypt, $string, $key );
}

/**
 * Decodes a string using the key set from the admin area.
 * Second parameter is to prefix something to the key. Handy to set different keys.
 *
 * Modified from Abhiskek Sanghvi's decode() function {@link http://myphpscriptz.com/2010/08/basic-string-encodingdecoding-functions/}
 *
 * @param string $string The encoded string we want to decode
 * @param string $param Optional. The string we should prepend to the key.
 * @return string The decoded string
 * @since 1.0-beta
 */
function bp_rbe_decode( $string, $param = false ) {
	global $bp_rbe;

	$key = $bp_rbe->settings['key'];

	if ( empty( $key ) )
		return false;

	if ( $param )
		$key = $param . $key;

	// filter the key
	$key = apply_filters( 'bp_rbe_key_before_decode', $key, $string, $param );

	if ( ! class_exists( 'Crypt_AES' ) ) {
		require( BP_RBE_DIR . '/includes/phpseclib/AES.php' );
	}
	$cipher = new Crypt_AES();
	$cipher->setKey( $key );

	$decrypt = $cipher->decrypt( hex2bin( $string ) );

	return apply_filters( 'bp_rbe_decode', $decrypt, $string, $key );
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
 * Is IMAP SSL support enabled?
 *
 * Check to see if both the OpenSSL and IMAP modules are loaded.
 *
 * @uses get_loaded_extensions() Gets names of all PHP modules that are compiled and loaded.
 * @return bool
 * @since 1.0-beta
 */
function bp_rbe_is_imap_ssl() {
	$modules = get_loaded_extensions();

	if ( ! in_array( 'openssl', $modules ) )
		return false;

	if ( ! in_array( 'imap', $modules ) )
		return false;

	return true;
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
		$action = apply_filters_ref_array( 'bp_get_activity_action_pre_meta', array( $activity->action, &$activity ) );

		$time_since = apply_filters_ref_array( 'bp_activity_time_since', array( '<span class="time-since">' . bp_core_time_since( $activity->date_recorded ) . '</span>', &$activity ) );

		return $action . ' <a href="' . bp_activity_get_permalink( $activity->id ) . '#acomment-' . $activity->id . '" class="view activity-time-since" title="' . __( 'View Discussion', 'buddypress' ) . '">' . $time_since . '</a>';
	}

	return $link;
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
 * Uses the html2text functions from the {@link http://openiaml.org/ IAML Modelling Platform}
 * by {@link mailto:j.m.wright@massey.ac.nz Jevon Wright}.
 *
 * Licensed under the Eclipse Public License v1.0:
 * {@link http://www.eclipse.org/legal/epl-v10.html}
 *
 * Thanks Jevon! :)
 *
 * @link https://code.google.com/p/iaml/source/browse/trunk/org.openiaml.model.runtime/src/include/html2text/html2text.php
 * @uses convert_html_to_text() Converts HTML to plain-text.
 * @param string $content The HTML content we want to convert to plain-text.
 * @return string Converted plain-text.
 */
function bp_rbe_html_to_plaintext( $content ) {
	if ( ! function_exists( 'convert_html_to_text' ) )
		require( BP_RBE_DIR . '/includes/functions.html2text.php' );

	return convert_html_to_text( $content );
}

/**
 * Removes line wrap from plain-text emails.
 *
 * At the moment, this is used when posting new forum topics via email.
 *
 * @param string $body The body we want to remove the line-wraps
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
		$body = str_replace( "\r\n", '', $body );

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
		$lines = array_reverse( preg_split( '/$\R?^/m', $content ) );

		//print_r($lines);

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

				// strip from the beginning of the sig
				$content = substr( $content, 0, strrpos( $content, $lines[$i] ) );

			}

			// if $i didn't iterate, this means the sig is on the last line only
			if ( $i == 0 ) {
				$content = substr( $content, 0, strrpos( $content, $lines[0] ) );
			}

		}
	}

	return $content;
}

/**
 * Logs no match errors during IMAP inbox checks.
 *
 * @uses bp_rbe_log() Logs error messages in a custom log
 * @param resource $imap The current IMAP connection
 * @param int $i The current message number
 * @param array $headers The email headers
 * @param sring $type The type of error
 * @since 1.0-beta
 */
function bp_rbe_imap_log_no_matches( $imap, $i, $headers, $type ) {
	$message = false;

	switch ( $type ) {
		case 'no_user_id' :
			$message = __( 'error - no user ID could be found', 'bp-rbe' );
			break;

		case 'no_params' :
			$message = __( 'error - no parameters were found', 'bp-rbe' );
			break;

		case 'no_reply_body' :
		case 'new_forum_topic_empty' :
			$message = __( 'error - body message was empty', 'bp-rbe' );
			break;

		case 'root_activity_deleted' :
			$message = __( 'error - the root activity update was deleted before this could be posted', 'bp-rbe' );
			break;

		case 'root_or_parent_activity_deleted' :
			$message = __( 'error - the root or parent activity update was deleted before this could be posted', 'bp-rbe' );
			break;

		case 'forum_reply_fail' :
			$message = __( 'error - forum reply failed to post', 'bp-rbe' );
			break;

		case 'forum_topic_fail' :
			$message = __( 'error - forum topic failed to be created', 'bp-rbe' );
			break;
	}

	if ( $message )
		bp_rbe_log( sprintf( __( 'Message #%d: %s', 'bp-rbe' ), $i, $message ) );
}

/**
 * Some basic CSS to style our group forum topic by email block.
 *
 * @since 1.0-beta
 */
function bp_rbe_new_topic_info_css() {

	if ( bp_is_group_forum() && ! bp_action_variables() && bp_loggedin_user_id() ) :
?>
	<style type="text/css">
		#rbe-toggle { display:none; }
		#rbe-message { background: #FFF9DB; border: 1px solid #FFE8C4; padding:1em; }
		#rbe-message ul { list-style-type:disc; margin:1em 1.5em; }
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
?>
	<h4><?php _e( 'Post New Topics via Email', 'bp-rbe' ) ?></h4>

	<p><?php _e( 'You can post new topics to this group from the comfort of your email inbox.', 'bp-rbe' ) ?> <a href="javascript:;" id="rbe-toggle"><?php _e( 'Find out how!', 'bp-rbe' ) ?></a></p>

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
 * Add custom cron schedule to WP
 *
 * @since 1.0-beta
 */
function bp_rbe_custom_cron_schedule( $schedules ) {

	$schedules['bp_rbe_custom'] = array(
		'interval' => bp_rbe_get_execution_time(), // interval in seconds
		'display'  => sprintf( __( 'Every %s minutes', 'bp-rbe' ), bp_rbe_get_execution_time( 'minutes' ) )
	);

	return $schedules;
}

/**
 * Schedule our task
 *
 * @since 1.0-beta
 */
function bp_rbe_cron() {
	if ( ! wp_next_scheduled( 'bp_rbe_schedule' ) )
		wp_schedule_event( time(), 'bp_rbe_custom', 'bp_rbe_schedule' );

	// if we need to spawn cron, do it here
	// @see BP_Reply_By_Email_IMAP::run()
	if ( bp_get_option( 'bp_rbe_spawn_cron' ) ) {
		// manually spawn cron
		spawn_cron();

		// remove our DB marker
		bp_delete_option( 'bp_rbe_spawn_cron' );
	}
}

/**
 * Run scheduled task action set in {@link bp_rbe_cron()}
 *
 * @uses BP_Reply_By_Email_IMAP::init()
 * @uses bp_rbe_is_connected()
 * @since 1.0-beta
 */
function bp_rbe_check_imap_inbox() {

	// check to see if we're connected via our DB marker
	if ( bp_rbe_is_connected() ) {
		bp_rbe_log( '--- Cronjob wants to connect - however according to our DB indicator, we already have an active IMAP connection! ---' );
		return;
	}

	// run our inbox check
	$imap = BP_Reply_By_Email_IMAP::init();
	$imap->run();
}

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
}

/** Modified BP functions ***********************************************/

/**
 * Modified version of {@link groups_new_group_forum_post()}.
 *
 * Duplicated because:
 * 	- groups_new_group_forum_post() hardcodes the $user_id.
 * 	- $bp->groups->current_group doesn't exist outside the BP groups component.
 * 	- {@link groups_record_activity()} restricts a bunch of parameters - use full {@link bp_activity_add()} instead.
 *
 * @param mixed $args Arguments can be passed as an associative array or as a URL argument string
 * @return mixed If forum reply is successful, returns the forum post ID; false on failure
 * @since 1.0-beta
 */
function bp_rbe_groups_new_group_forum_post( $args = '' ) {
	global $bp;

	$defaults = array(
		'post_text' => false,
		'topic_id'  => false,
		'user_id'   => false,
		'group_id'  => false,
		'page'      => false // Integer of the page where the next forum post resides
	);

	$r = apply_filters( 'bp_rbe_groups_new_group_forum_post_args', wp_parse_args( $args, $defaults ) );
	extract( $r );

	if ( empty( $post_text ) )
		return false;

	// apply BP's filters
	$post_text = apply_filters( 'group_forum_post_text_before_save',     $post_text );
	$topic_id  = apply_filters( 'group_forum_post_topic_id_before_save', $topic_id );

	if ( $post_id = bp_forums_insert_post( array( 'post_text' => $post_text, 'topic_id' => $topic_id, 'poster_id' => $user_id ) ) ) {
		global $bbdb;

		do_action( 'bbpress_init' );

		// do a direct bbPress DB call
		if ( isset( $bbdb ) ) {
			$topic = $bbdb->get_row( $bbdb->prepare( "SELECT * FROM {$bbdb->topics} WHERE topic_id = {$topic_id}" ) );
		}

		$group = groups_get_group( 'group_id=' . $group_id );

		// If no page passed, calculate the page where the new post will reside.
		// I should backport this to BP.
		if ( !$page ) {
			$pag_num = apply_filters( 'bp_rbe_topic_pag_num', 15 );
			$page    = ceil( $topic->topic_posts / $pag_num );
		}

		$activity_action  = sprintf( __( '%s posted on the forum topic %s in the group %s via email:', 'bp-rbe'), bp_core_get_userlink( $user_id ), '<a href="' . bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug .'/">' . esc_attr( $topic->topic_title ) . '</a>', '<a href="' . bp_get_group_permalink( $group ) . '">' . esc_attr( $group->name ) . '</a>' );
		$activity_content = bp_create_excerpt( $post_text );
		$primary_link     = bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug . '/?topic_page=' . $page;

		/* Record this in activity streams */
		$activity_id = bp_activity_add( array(
			'user_id'           => $user_id,
			'action'            => apply_filters( 'groups_activity_new_forum_post_action', $activity_action, $post_id, $post_text, &$topic ),
			'content'           => apply_filters( 'groups_activity_new_forum_post_content', $activity_content, $post_id, $post_text, &$topic ),
			'primary_link'      => apply_filters( 'groups_activity_new_forum_post_primary_link', "{$primary_link}#post-{$post_id}" ),
			'component'         => $bp->groups->id,
			'type'              => 'new_forum_post',
			'item_id'           => $group_id,
			'secondary_item_id' => $post_id,
			'hide_sitewide'     => ( $group->status == 'public' ) ? false : true
		) );

		// special hook for RBE activity items
		do_action( 'bp_rbe_new_activity', $activity_id, 'new_forum_post', $group_id, $post_id );

		do_action( 'groups_new_forum_topic_post', $group_id, $post_id );

		return $post_id;
	}

	return false;
}

/**
 * Modified version of {@link groups_new_group_forum_topic()}.
 *
 * Duplicated because:
 * 	- groups_new_group_forum_topic() hardcodes the $user_id.
 * 	- $bp->groups->current_group doesn't exist outside the BP groups component.
 * 	- {@link groups_record_activity()} restricts a bunch of parameters - use full {@link bp_activity_add()} instead.
 *
 * @param mixed $args Arguments can be passed as an associative array or as a URL argument string
 * @return mixed If forum topic is successful, returns the forum topic ID; false on failure
 * @since 1.0-beta
 */
function bp_rbe_groups_new_group_forum_topic( $args = '' ) {
	global $bp;

	$defaults = array(
		'topic_title' => false,
		'topic_text'  => false,
		'topic_tags'  => false,
		'forum_id'    => false,
		'user_id'     => false,
		'group_id'    => false
	);

	$r = apply_filters( 'bp_rbe_groups_new_group_forum_topic_args', wp_parse_args( $args, $defaults ) );
	extract( $r );

	if ( empty( $topic_title ) || empty( $topic_text ) )
		return false;

	if ( empty( $forum_id ) )
		$forum_id = groups_get_groupmeta( $group_id, 'forum_id' );

	// apply BP's filters
	$topic_title = apply_filters( 'group_forum_topic_title_before_save',    $topic_title );
	$topic_text  = apply_filters( 'group_forum_topic_text_before_save',     $topic_text );
	$topic_tags  = apply_filters( 'group_forum_topic_tags_before_save',     $topic_tags );
	$forum_id    = apply_filters( 'group_forum_topic_forum_id_before_save', $forum_id );

	if ( $topic_id = bp_forums_new_topic( array(
		'topic_title'            => $topic_title,
		'topic_text'             => $topic_text,
		'topic_tags'             => $topic_tags,
		'forum_id'               => $forum_id,
		'topic_poster'           => $user_id,
		'topic_last_poster'      => $user_id,
		'topic_poster_name'      => bp_core_get_user_displayname( $user_id ),
		'topic_last_poster_name' => bp_core_get_user_displayname( $user_id )
	) ) ) {
		global $bbdb;

		do_action( 'bbpress_init' );

		// do a direct bbPress DB call
		if ( isset( $bbdb ) ) {
			$topic = $bbdb->get_row( $bbdb->prepare( "SELECT * FROM {$bbdb->topics} WHERE topic_id = {$topic_id}" ) );
		}

		$group = groups_get_group( 'group_id=' . $group_id );

		$activity_action  = sprintf( __( '%s started the forum topic %s in the group %s via email:', 'bp-rbe' ), bp_core_get_userlink( $user_id ), '<a href="' . bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug .'/">' . esc_attr( $topic->topic_title ) . '</a>', '<a href="' . bp_get_group_permalink( $group ) . '">' . esc_attr( $group->name ) . '</a>' );
		$activity_content = bp_create_excerpt( $topic_text );

		/* Record this in activity streams */
		$activity_id = bp_activity_add( array(
			'user_id'            => $user_id,
			'action'             => apply_filters( 'groups_activity_new_forum_topic_action', $activity_action, $topic_text, &$topic ),
			'content'            => apply_filters( 'groups_activity_new_forum_topic_content', $activity_content, $topic_text, &$topic ),
			'primary_link'       => apply_filters( 'groups_activity_new_forum_topic_primary_link', bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug . '/' ),
			'component'          => $bp->groups->id,
			'type'               => 'new_forum_topic',
			'item_id'            => $group_id,
			'secondary_item_id'  => $topic_id,
			'hide_sitewide'      => ( $group->status == 'public' ) ? false : true
		) );

		// special hook for RBE activity items
		do_action( 'bp_rbe_new_activity', $activity_id, 'new_forum_topic', $group_id, $topic_id );

		do_action( 'groups_new_forum_topic', $group_id, &$topic );

		return $topic;
	}

	return false;
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

		$querystring = apply_filters( 'bp_rbe_encode_group_querystring', bp_rbe_encode( $gstring, $user_id ), $user_id, $group_id );

		return bp_rbe_inject_qs_in_email( $querystring . '-new' );
	}

?>