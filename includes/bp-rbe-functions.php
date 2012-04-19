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
 * To check if we're connected a DB entry is updated in {@link BP_Reply_By_Email_IMAP::connect()}
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
	$key = apply_filters( 'bp_rbe_key_before_encode', sha1( $key ), $string, $param );

	$strLen = strlen( $string );
	$keyLen = strlen( $key );

	// prevent PHP warnings
	$hash = '';
	$j = NULL;

	for ( $i = 0; $i < $strLen; ++$i ) {

		$ordStr = ord( substr( $string, $i, 1) );

		if ( $j == $keyLen )
			$j = 0;

		$ordKey = ord( substr( $key, $j, 1 ) );

		++$j;

		$hash .= strrev( base_convert( dechex( $ordStr + $ordKey ), 16, 36 ) );

	}

	return $hash;
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
	$key = apply_filters( 'bp_rbe_key_before_decode', sha1( $key ), $string, $param );

	$strLen = strlen( $string );
	$keyLen = strlen( $key );

	// prevent PHP warnings
	$hash = '';
	$j = NULL;

	for ( $i = 0; $i < $strLen; $i += 2 ) {

		$ordStr = hexdec( base_convert( strrev( substr( $string, $i, 2) ), 36, 16) );

		if ( $j == $keyLen )
			$j = 0;

		$ordKey = ord( substr( $key, $j, 1 ) );

		++$j;

		$hash .= chr( $ordStr - $ordKey );

	}

	return $hash;
}

/**
 * Gets loaded PHP modules and their respective settings.
 *
 * Lightly modified from {@link http://www.php.net/manual/en/function.phpinfo.php#84259}.
 *
 * @uses ob_start() Output buffer {@link phpinfo()} so we can grab info into an array
 * @uses phpinfo() Use "INFO_MODULES" parameter to grab only module info
 * @return array Array with PHP module information
 * @since 1.0-beta
 */
function bp_rbe_get_phpinfo(){

	ob_start();
	phpinfo( INFO_MODULES );

	$phpinfo = array();

	if( preg_match_all( '#(?:<h2>(?:<a name=".*?">)?(.*?)(?:</a>)?</h2>)|(?:<tr(?: class=".*?")?><t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>)?)?</tr>)#s', ob_get_clean(), $matches, PREG_SET_ORDER ) ) {

		foreach( $matches as $match ) {
			if( strlen( $match[1] ) )
				$phpinfo[$match[1]] = array();

			elseif( isset( $match[3] ) )
				$phpinfo[end(array_keys($phpinfo))][$match[2]] = isset( $match[4] ) ? array( strip_tags($match[3]), strip_tags( $match[4]) ) : strip_tags( $match[3] );

			else
				$phpinfo[end(array_keys($phpinfo))][] = $match[2];
		}
	}

	return $phpinfo;
}

/**
 * Gets IMAP and OpenSSL information from server's PHP configuration.
 *
 * Is there a better way to do this other than output buffering {@link phpinfo()}?
 *
 * @uses bp_rbe_get_phpinfo() Get entire PHP info
 * @return mixed Array with IMAP information on success, false on failure
 * @since 1.0-beta
 */
function bp_rbe_get_imap_info() {
	$phpinfo = bp_rbe_get_phpinfo();

	if ( !empty( $phpinfo['imap'] ) ) {
		$array['imap'] = $phpinfo['imap'];

		// OpenSSL info might be handy as well, let's add it if available
		if ( !empty( $phpinfo['openssl'] ) )
			$array['openssl'] = $phpinfo['openssl'];

		return $array;
	}

	return false;
}

/**
 * Is IMAP SSL support enabled?
 *
 * Takes $openssl_check as a parameter. Default is true.
 * If set to false, this doesn't check to see if OpenSSL is enabled.
 * Might be handy for some PHP compiled builds.
 *
 * @uses bp_rbe_get_imap_info() Gets IMAP-compiled PHP info
 * @return bool
 * @since 1.0-beta
 */
function bp_rbe_is_imap_ssl( $openssl_check = true ) {
	$imap = bp_rbe_get_imap_info();

	if ( $imap['imap']['SSL Support'] == 'enabled' ) {
		if ( $openssl_check ) {
			if ( $imap['openssl']['OpenSSL support'] == 'enabled' ) {
				$retval = true;
			}
			else {
				$retval = false;
			}
		}
		else {
			$retval = true;
		}
	}
	else {
		$retval = false;
	}

	return apply_filters( 'bp_rbe_is_imap_ssl', $retval );
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
		return substr( $content, 0, strrpos( $content, PHP_EOL . $char ) );

	return $content;
}

/**
 * After successfully posting an email message to BuddyPress,
 * we mark the email for deletion for user privacy issues.
 *
 * In GMail, we have to move the message to the Trash folder,
 * since deleting an email simply moves it to "All Mail".
 *
 * @param resource $imap The current IMAP connection
 * @param int $i The current message number
 * @since 1.0-beta
 */
function bp_rbe_parsed_to_trash( $imap, $i ) {
	global $bp_rbe;

	if ( $bp_rbe->settings['gmail'] )
		imap_mail_move( $imap, $i, '[Gmail]/Trash' );
	else
		imap_delete( $imap, $i );
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
	global $bp;

	if ( bp_is_group_forum() && empty( $bp->action_variables ) && $bp->loggedin_user->id ) :
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
		<h5><?php printf( __( 'Send an email to <strong><a href="%s">%s</strong></a> and a new forum topic will be posted in %s.', 'bp-rbe' ), "mailto: {$bp->groups->current_group->name} <" . bp_rbe_groups_get_encoded_email_address(). ">", bp_rbe_groups_get_encoded_email_address(), $bp->groups->current_group->name ); ?></h5>

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
		'interval' => bp_rbe_get_execution_time() + 15,	// interval in seconds; add 15 seconds leeway
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
	if ( !wp_next_scheduled( 'bp_rbe_schedule' ) )
		wp_schedule_event( time(), 'bp_rbe_custom', 'bp_rbe_schedule' );

	// if we need to spawn cron, do it here
	if ( bp_get_option( 'bp_rbe_spawn_cron' ) ) {
		// this emulates a visitor hitting our site, which will trigger wp_cron() and our scheduled hook above
		wp_remote_post( bp_core_get_root_domain(), array( 'timeout' => 0.01, 'blocking' => false, 'sslverify' => apply_filters( 'https_local_ssl_verify', true ) ) );

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

	$imap = BP_Reply_By_Email_IMAP::init();

	// check to see if the imap resource is still connected
	// if so, stop parsing the IMAP inbox
	// this method doesn't work... multiple instances can still take place
	// even though I tried setting $imap->connection to static
	/*
	if ( $imap->is_connected() ) {
		bp_rbe_stop_imap();

		// give the IMAP loop a chance to terminate
		// needs additional testing
		sleep( 10 );
	}
	*/

	// check to see if we're connected via our reliable DB marker
	if ( bp_rbe_is_connected() ) {
		bp_rbe_log( '--- Cronjob wants to connect - however according to our DB indicator, we already have an active IMAP connection! ---' );
		return;
	}

	// run our inbox check
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
 * @param string $post_text The body of the post
 * @param int $topic_id The topic ID we're replying to
 * @param int $user_id The user ID replying to the forum topic
 * @param int $group_id The group ID where this topic resides
 * @param mixed $page False by default. Or an integer of the page where the next forum post resides
 * @return mixed If forum reply is successful, returns the forum post ID; false on failure
 * @since 1.0-beta
 */
function bp_rbe_groups_new_group_forum_post( $post_text, $topic_id, $user_id, $group_id, $page = false ) {
	global $bp;

	if ( empty( $post_text ) )
		return false;

	$post_text = apply_filters( 'group_forum_post_text_before_save', $post_text );
	$topic_id  = apply_filters( 'group_forum_post_topic_id_before_save', $topic_id );

	if ( $post_id = bp_forums_insert_post( array( 'post_text' => $post_text, 'topic_id' => $topic_id, 'poster_id' => $user_id ) ) ) {

		$topic = bp_forums_get_topic_details( $topic_id );
		$group = new BP_Groups_Group( $group_id );

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
		bp_activity_add( array(
			'user_id'           => $user_id,
			'action'            => apply_filters( 'groups_activity_new_forum_post_action', $activity_action, $post_id, $post_text, &$topic ),
			'content'           => apply_filters( 'groups_activity_new_forum_post_content', $activity_content, $post_id, $post_text, &$topic ),
			'primary_link'      => apply_filters( 'groups_activity_new_forum_post_primary_link', "{$primary_link}#post-{$post_id}" ),
			'component'         => $bp->groups->id,
			'type'              => 'new_forum_post',
			'item_id'           => $group_id,
			'secondary_item_id' => $topic_id,
			'hide_sitewide'     => ( $group->status == 'public' ) ? false : true
		) );

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
 * @param string $topic_title The topic title
 * @param string $topic_text The topic body
 * @param int $topic_tags The topic tags
 * @param int $forum_id The forum ID
 * @param int $user_id The user ID replying to the forum topic
 * @param int $group_id The group ID where this topic resides
 * @return mixed If forum topic is successful, returns the forum topic ID; false on failure
 * @since 1.0-beta
 */
function bp_rbe_groups_new_group_forum_topic( $topic_title, $topic_text, $topic_tags, $forum_id, $user_id, $group_id ) {
	global $bp;

	if ( empty( $topic_title ) || empty( $topic_text ) )
		return false;

	if ( empty( $forum_id ) )
		$forum_id = groups_get_groupmeta( $group_id, 'forum_id' );

	$topic_title = apply_filters( 'group_forum_topic_title_before_save', $topic_title );
	$topic_text  = apply_filters( 'group_forum_topic_text_before_save', $topic_text );
	$topic_tags  = apply_filters( 'group_forum_topic_tags_before_save', $topic_tags );
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

		$topic = bp_forums_get_topic_details( $topic_id );
		$group = new BP_Groups_Group( $group_id );

		$activity_action  = sprintf( __( '%s started the forum topic %s in the group %s via email:', 'bp-rbe' ), bp_core_get_userlink( $user_id ), '<a href="' . bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug .'/">' . esc_attr( $topic->topic_title ) . '</a>', '<a href="' . bp_get_group_permalink( $group ) . '">' . esc_attr( $group->name ) . '</a>' );
		$activity_content = bp_create_excerpt( $topic_text );

		/* Record this in activity streams */
		bp_activity_add( array(
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
		global $bp;

		$user_id  = !$user_id ? $bp->loggedin_user->id : $user_id;
		$group_id = !$group_id ? $bp->groups->current_group->id : $group_id;

		if ( !$user_id || !$group_id )
			return false;

		$gstring     = 'g=' . $group_id;

		$querystring = apply_filters( 'bp_rbe_encode_group_querystring', bp_rbe_encode( $gstring, $user_id ), $user_id, $group_id );

		return bp_rbe_inject_qs_in_email( $querystring . '-new' );
	}

/** PHP4 ****************************************************************/

if ( !function_exists( 'array_combine' ) ) :
/**
 * A PHP4-compatible version of array_combine().
 *
 * @link http://www.php.net/manual/en/function.array-combine.php#78244
 */
function array_combine( $arr1, $arr2 ) {
	$out = array();

	foreach( $arr1 as $key1 => $value1 )
		$out[$value1] = $arr2[$key1];

	return $out;
}
endif;

if ( !function_exists( 'array_intersect_key' ) ) :
/**
 * A PHP4-compatible version of array_intersect_key().
 *
 * @author Rod Byrnes
 * @link http://www.php.net/manual/en/function.array-intersect-key.php#74956
 */
function array_intersect_key( $isec, $arr2 ) {
	$argc = func_num_args();

	if ($argc > 2) {
		for ( $i = 1; !empty( $isec ) && $i < $argc; ++$i )	{
			$arr = func_get_arg( $i );

			foreach ( $isec as $k => $v ) {
				if ( !isset( $arr[$k] ) )
					unset( $isec[$k] );
			}
		}

		return $isec;
	}
	else {
		$res = array();

		foreach ( array_keys( $isec ) as $key ) {
			if ( isset( $keys[$key] ) ) {
				$res[$key] = $isec[$key];
			}
		}

		return $res;
	}
}
endif;

?>