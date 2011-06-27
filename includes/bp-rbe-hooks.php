<?php
/**
 * BP Reply By Email Hooks
 *
 * @package BP_Reply_By_Email
 * @subpackage Hooks
 */

// only run the following hooks if requirements are fulfilled
if ( bp_rbe_is_required_completed() ) :

	// cron
	add_filter( 'cron_schedules',			'bp_rbe_custom_cron_schedule' );
	///*
	add_action( 'init',				'bp_rbe_cron' );
	add_action( 'admin_init',			'bp_rbe_cron' );
	add_action( 'bp_rbe_schedule',			'bp_rbe_check_imap_inbox' );
	//*/

	// email body parsing
	add_filter( 'bp_rbe_parse_email_body_reply',	'bp_rbe_remove_eol_char' );

	// email inbox parsing
	/**
	 * In Gmail, imap_delete() moves the email to the "All Mail" folder; it doesn't mark the email for deletion.
	 * For emails that do not match our BP criteria, we use imap_delete() so you can always view failed attempts in Gmail.
	 * However, you might want to remove this action and do your own thing. (eg. move the email to another folder).
	 */
	add_action( 'bp_rbe_imap_no_match',		'imap_delete',			10, 2 );
	add_action( 'bp_rbe_imap_loop',			'bp_rbe_parsed_to_trash',	10, 2 );
	
	/**
	 * Outright delete the emails that are marked for deletion once we're done.
	 * You might want to remove the following actions and do your own thing. (eg. move the email to another folder).
	 *
	 * Note: In Gmail, imap_expunge() doesn't apply to emails using imap_delete(). Emails stay intact in the "All Mail" folder.
	 */
	add_action( 'bp_rbe_imap_after_loop',		'imap_expunge' );
	add_action( 'bp_rbe_imap_before_close',		'imap_expunge' );

	// new topic info screen
	add_action( 'wp_head',				'bp_rbe_new_topic_info_css',	99 );
	add_action( 'bp_before_group_forum_post_new',	'bp_rbe_new_topic_info' );

endif;

// activity comment permalink
add_filter( 'bp_activity_permalink', 		'bp_rbe_activity_comment_view_link',	10, 2 );
?>