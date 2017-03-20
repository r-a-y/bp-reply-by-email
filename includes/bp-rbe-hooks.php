<?php
/**
 * BP Reply By Email Hooks
 *
 * @package BP_Reply_By_Email
 * @subpackage Hooks
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// only run the following hooks if requirements are fulfilled
if ( bp_rbe_is_required_completed() ) :

	// imap mode hooks
	if ( ! bp_rbe_is_inbound() ) {
		/*
		 * Cron - only run if:
		 *  - We're the root blog and if WP cron is not active
		 *  - And if auto-connect is off.
		 */
		if ( bp_is_root_blog() && ! defined( 'DOING_CRON' ) && 1 !== (int) bp_rbe_get_setting( 'keepaliveauto' ) ) {
			add_action( 'init', 'bp_rbe_should_connect',     20 );
			add_action( 'init', 'bp_rbe_run_inbox_listener', 999 );
		}

		if ( bp_is_root_blog() && bp_rbe_get_setting( 'keepaliveauto' ) ) {
			add_action( 'bp_rbe_schedule', 'bp_rbe_imap_down_email_notice' );
		}

		// email inbox parsing
		/**
		 * In Gmail, imap_delete() moves the email to the "All Mail" folder; it doesn't mark the email for deletion.
		 *
		 * Note: If you're using Gmail AND you're hooking into the "bp_rbe_imap_no_match" or "bp_rbe_imap_loop" filters,
		 *       DO NOT USE imap_mail_move() or imap_mail_copy() as this will F up the message loop.
		 *
		 *       From my testing, using GMail and imap_mail_move() / imap_mail_copy() will also expunge the email.
		 *       (Will need to test non-Gmail IMAP servers.)
		 *
		 *       Expunging an email *during the message loop with multiple emails* will screw up the message numbers.
		 *       For more details, view:
		 *       https://bugs.php.net/bug.php?id=10536#988465219
		 *
		 * If you're not using Gmail, you might want to remove the following actions and do your own thing.
		 * (eg. move the email to another folder instead of marking emails for deletion).
		 */
		add_action( 'bp_rbe_imap_loop',             'imap_delete', 99, 2 );

		// outright delete the emails that are marked for deletion once we're done.
		add_action( 'bp_rbe_imap_after_loop',       'imap_expunge' );

		// failsafe
		add_action( 'bp_rbe_log_already_connected', 'bp_rbe_failsafe' );

	// inbound mode
	} else {
		add_action( 'wp_loaded',                'bp_rbe_inbound_catch_callback', 0 );
	}

	// log no matches
	add_action( 'bp_rbe_no_match',                  'bp_rbe_log_no_matches', 10, 4 );

	// html email to plain-text
	add_filter( 'bp_rbe_parse_html_email',          'bp_rbe_html_to_plaintext' );

	// email reply parsing
	add_filter( 'bp_rbe_parse_email_body_reply',    'bp_rbe_remove_eol_char', 1 );
	add_filter( 'bp_rbe_parse_email_body_reply',    'bp_rbe_remove_email_client_signature' );

	// log last activity when posting via email
	add_action( 'bp_rbe_new_activity',              'bp_rbe_log_last_activity' );
	add_action( 'bp_rbe_new_pm_reply',              'bp_rbe_log_last_activity' );

	// add meta to RBE'd activities and forum posts
	add_action( 'bp_rbe_new_activity',              'bp_rbe_activity_record_meta' );
	add_action( 'bp_forums_new_post',               'bp_rbe_group_forum_record_meta' );

	// alter forum post timestamp
	add_filter( 'bp_get_the_topic_post_time_since', 'bp_rbe_alter_forum_post_timestamp' );

	// new topic info screen
	add_action( 'wp_head',                          'bp_rbe_new_topic_info_css', 99 );
	add_action( 'bp_before_group_forum_post_new',   'bp_rbe_new_topic_info' );

endif;

// activity comment permalink
add_filter( 'bp_activity_permalink',                    'bp_rbe_activity_comment_view_link',         10, 2 );

// activity comment action formatting
add_filter( 'bp_activity_comment_action',               'bp_rbe_activity_comment_action_formatting', 10, 2 );
