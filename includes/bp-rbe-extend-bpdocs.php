<?php
/**
 * BuddyPress Docs Comment Reply By Email Extension.
 *
 * Allows group members to reply to BP Doc comments via email.
 *
 * Comments will be posted directly into the doc's comments and not as a nested
 * activity comment.
 *
 * Requires the BP Group Email Subscription plugin
 * {@link http://wordpress.org/extend/plugins/buddypress-group-email-subscription/}
 * for the emails to be sent out.
 *
 * Your Group Email setting must be set to "All Mail" to reply to BP Doc comments.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds RBE support to the popular BuddyPress Docs plugin by Boone Gorges.
 *
 * Allows group members to reply to BP Doc comments via email.
 *
 * Extends the abstract {@link BP_Reply_By_Email_Extension} class, which
 * helps do a lot of the dirty work!
 *
 * @package BP_Reply_By_Email
 * @subpackage Extensions
 * @since 1.0-RC1
 */
class BP_Docs_Comment_RBE_Extension extends BP_Reply_By_Email_Extension {

	/**
	 * Constructor.
	 */
	public function __construct() {

		// (required) must use the bootstrap() method in your constructor!
		// once you've used the bootstrap() method, you can call your params magically
		// eg.
		//    $this->id
		//    $this->activity_type
		$this->bootstrap( array(
			'id'            => 'bp-docs',        // your plugin name
			'activity_type' => 'bp_doc_comment', // activity 'type' you want to match
			'item_id_param' => 'bpdg',           // parameter name for your activity 'item_id'; in our case 'item_id' is the group ID
			                                     // hence the shortname 'bpdg'
			'secondary_item_id_param' => 'bpdc', // parameter name for your activity 'secondary_item_id'; in our case
			                                     // 'secondary_item_id' is the comment ID we want to reply to, hence
			                                     // the shortname 'bpdc'
		) );

		// custom hooks to disable RBE email manipulation for certain BP Doc conditions
		// this isn't required, but is an example of how you can add additional hooks in
		// your constructor to customize your extension
		add_filter( 'bp_rbe_block_activity_item', array( $this, 'block_user_docs' ),     10, 2 );
		add_filter( 'bp_rbe_querystring',         array( $this, 'disable_querystring' ), 10, 3 );
	}

	/**
	 * Post by email handler.
	 *
	 * Validate data and post on success.
	 *
	 * @param bool $retval True by default.
	 * @param array $data {
	 *     An array of arguments.
	 *
	 *     @type array $headers Email headers.
	 *     @type string $content The email body content.
	 *     @type string $subject The email subject line.
	 *     @type int $user_id The user ID who sent the email.
	 *     @type bool $is_html Whether the email content is HTML or not.
	 *     @type int $i The email message number.
	 * }
	 * @param array $params Parsed paramaters from the email address querystring.
	 *   See {@link BP_Reply_By_Email_Parser::get_parameters()}.
	 * @return array|object Array of the parsed item on success. WP_Error object
	 *  on failure.
	 */
	public function post( $retval, $data, $params ) {
		global $bp;

		$comment_id = ! empty( $params[$this->secondary_item_id_param] ) ? $params[$this->secondary_item_id_param] : false;

		$i = $data['i'];

		// this means that the current email is a BP Doc reply
		// let's proceed!
		if ( ! empty( $comment_id ) ) {
			// it's important to let RBE know what's happening during the process
			// for debugging purposes
			//
			// use bp_rbe_log() to log anything you want
			// in this case, we're letting RBE know that we're in the process of
			// rendering a comment reply
			bp_rbe_log( 'Message #' . $i . ': this is a BP Doc comment reply' );

			// get parent comment data
			$comment = get_comment( $comment_id );

			// parent comment doesn't exist or was deleted
			if ( empty( $comment ) ) {
				// when a condition for posting isn't met, return a WP_Error object.
				// next, log it under the internal_rbe_log()_method
				// and optionally, prep a failure message under the failure_message_to_sender() method
				//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_parent_comment_deleted' );
				return new WP_Error( 'bp_doc_parent_comment_deleted', '', $data );
			}

			// parent comment status checks
			switch ( $comment->comment_approved ) {
				case 'spam' :
					//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_parent_comment_spam' );
					return new WP_Error( 'bp_doc_parent_comment_spam', '', $data );

					break;

				case 'trash' :
					//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_parent_comment_deleted' );
					return new WP_Error( 'bp_doc_parent_comment_deleted', '', $data );

					break;

				case '0' :
					//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_parent_comment_unapproved' );
					return new WP_Error( 'bp_doc_parent_comment_unapproved', '', $data );

					break;
			}

			// get doc settings
			$doc_settings = get_post_meta( $comment->comment_post_ID, 'bp_docs_settings', true );

			// set temporary variable
			$bp->rbe = $bp->rbe->temp = new stdClass;

			// get group ID
			// $bp->rbe->temp->group_id gets passed to BP_Reply_By_Email::set_group_id()
			$group_id = $bp->rbe->temp->group_id = $params[$this->item_id_param];

			// get user ID
			$user_id = $data['user_id'];

			// check to see if the user can post comments for the group doc in question
			//
			// bp_docs_user_can( 'post_comments', $user_id, $group_id ) doesn't work the way I want it to
			// using the doc's comment settings as a guideline

			// check the comment settings for the doc
			switch( $doc_settings['post_comments'] ) {

				// this means that the comment settings for the doc recently switched to 'no-one'
				case 'no-one' :
					//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_comment_change_to_noone' );
					return new WP_Error( 'bp_doc_comment_change_to_noone', '', $data );

					break;

				// if the doc only allows group admins and mods to comment, return false for regular group members
				case 'admins-mods' :
					// get the email address of the replier
					$user_email = BP_Reply_By_Email_Parser::get_header( $data['headers'], 'From' );

					// get an array of group admin / mod email addresses
					// note: email addresses are set as key, not value
					$admin_mod_emails = $this->get_admin_mod_user_emails( $group_id );

					// if the replier's email address does not match a group admin or mod, stop now!
					if ( ! isset( $admin_mod_emails[$user_email] ) ) {
						//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_user_not_admin_mod' );
						return new WP_Error( 'bp_doc_user_not_admin_mod', '', $data );
					}

					break;

				// if the doc allows any group member to comment, check if member is still part of
				// the group and not banned
				case 'group-members' :
					if( ! groups_is_user_member( $user_id, $group_id ) ) {
						//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_user_not_member' );
						return new WP_Error( 'bp_doc_user_not_member', '', $data );
					}

					if ( groups_is_user_banned( $user_id, $group_id ) ) {
						//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_user_banned' );
						return new WP_Error( 'bp_doc_user_banned', '', $data );
					}

					break;
			}

			/* okay! we should be good to post now! */

			// get the userdata
			$userdata = get_userdata( $user_id );

			// we're using wp_insert_comment() instead of wp_new_comment()
			// why? because wp_insert_comment() bypasses all the WP comment hooks, which is good for us!
			$new_comment_id = wp_insert_comment( array(
				'user_id'              => $user_id,
				'comment_post_ID'      => $comment->comment_post_ID,
				'comment_content'      => $data['content'],
				'comment_parent'       => $comment_id, // set as $comment_id? or "0" for not threaded?
				'comment_author'       => $userdata->user_nicename, // user_login?
				'comment_author_url'   => '', // use BP member domain?
				'comment_author_email' => $userdata->user_email, // use RBE email address
				'comment_author_IP'    => '', // how to get via RBE?
				'comment_agent'        => '', // set agent as RBE? 'BP_Reply_By_Email/1.0
			        'comment_type'         => '', // not used
			) );

			// comment successfully posted!
			if ( ! empty( $new_comment_id ) ) {
				// more internal logging
				bp_rbe_log( 'Message #' . $i . ': BP Doc comment reply successfully posted!' );

				/* now let's record the activity item for this comment */

				// override BP Docs' default comment activity action
				add_filter( 'bp_docs_comment_activity_action', array( $this, 'comment_activity_action' ) );

				// now post the activity item with BP Docs' special class method
				if ( class_exists( 'BP_Docs_BP_Integration' ) ) {
					// BP Docs v1.1.x support
					$activity_id = BP_Docs_BP_Integration::post_comment_activity( $new_comment_id );
				} else {
					// BP Docs v1.2.x support
					$activity_id = BP_Docs_Component::post_comment_activity( $new_comment_id );
				}

				// special hook for RBE activity items
				// if you're adding an activity entry in this method, remember to add this hook after posting
				// your activity item in this method!
				do_action( 'bp_rbe_new_activity', array(
					'activity_id'       => $activity_id,
					'type'              => $this->activity_type,
					'user_id'           => $user_id,
					'item_id'           => $group_id,
					'secondary_item_id' => $comment_id,
					'content'           => $data['content']
				) );

				// remove the filter after posting
				remove_filter( 'bp_docs_comment_activity_action', array( $this, 'comment_activity_action' ) );

				return array( 'bp_doc_comment_id' => $new_comment_id );

			} else {
				//do_action( 'bp_rbe_imap_no_match', $connection, $i, $headers, 'bp_doc_new_comment_fail' );
				return new WP_Error( 'bp_doc_new_comment_fail', '', $data );
			}
		}
	}

	/**
	 * Log our extension's error messages during the post() method.
	 *
	 * @param mixed $log
	 * @param string $type Type of error message
	 * @param array $data {
	 *     An array of arguments.
	 *
	 *     @type array $headers Email headers.
	 *     @type string $content The email body content.
	 *     @type string $subject The email subject line.
	 *     @type int $user_id The user ID who sent the email.
	 *     @type bool $is_html Whether the email content is HTML or not.
	 *     @type int $i The email message number.
	 * }
	 * @param int $i The message number from the inbox loop
	 * @param resource $connection The current IMAP connection. Chances are you probably don't have to do anything with this!
	 * @return string|bool Could be a string or boolean false.
	 */
	public function internal_rbe_log( $log, $type, $data, $i, $connection ) {
		switch( $type ) {
			case 'bp_doc_parent_comment_deleted' :
				$log = __( "error - BP doc parent comment was deleted before this could be posted.", 'bp-rbe' );

				break;

			case 'bp_doc_parent_comment_spam' :
				$log = __( "error - BP doc parent comment was marked as spam before this could be posted.", 'bp-rbe' );

				break;

			case 'bp_doc_parent_comment_unapproved' :
				$log = __( "error - BP doc parent comment was unapproved before this could be posted.", 'bp-rbe' );

				break;

			case 'bp_doc_comment_change_to_noone' :
				$log = __( "error - BP doc's comment settings changed to 'no-one'", 'bp-rbe' );

				break;

			case 'bp_doc_user_not_admin_mod' :
				$log = __( "error - this BP doc's comment setting is set to admins and mods only", 'bp-rbe' );

				break;

			case 'bp_doc_user_not_member' :
				$log = __( 'error - user is not a member of the group', 'bp-rbe' );

				break;

			case 'bp_doc_user_banned' :
				$log = __( 'notice - user is banned from group. BP doc comment not posted.', 'bp-rbe' );

				break;

			case 'bp_doc_new_comment_fail' :
				$log = __( 'error - BP doc comment failed to post', 'bp-rbe' );

				break;
		}

		return $log;
	}

	/**
	 * Setup our extension's failure message to send back to the sender.
	 *
	 * @param mixed $message
	 * @param string $type Type of error message
	 * @param array $data {
	 *     An array of arguments.
	 *
	 *     @type array $headers Email headers.
	 *     @type string $content The email body content.
	 *     @type string $subject The email subject line.
	 *     @type int $user_id The user ID who sent the email.
	 *     @type bool $is_html Whether the email content is HTML or not.
	 *     @type int $i The email message number.
	 * }
	 * @param int $i The message number from the inbox loop
	 * @param resource $connection The current IMAP connection. Chances are you probably don't have to do anything with this!
	 * @return string|bool Could be a string or boolean false.
	 */
	public function failure_message_to_sender( $message, $type, $data, $i, $imap ) {
		switch( $type ) {
			case 'bp_doc_parent_comment_deleted' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted because the parent comment you were replying to no longer exists.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_doc_parent_comment_spam' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted because the parent comment you were replying to was marked as spam.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_doc_parent_comment_unapproved' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted because the parent comment you were replying was unapproved.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_doc_comment_change_to_noone' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted because the comment setting for this group document recently changed to "No One".
This means that no other comments can be posted for the group document in question.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_doc_user_not_admin_mod' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted because the comment setting for this group document recently changed to "Admin and moderators only".
Since you are not a group administrator or a group moderator, this means your comment could be posted.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_doc_user_not_member' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted because you are no longer a member of this group.  To comment on this group document, please rejoin the group.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_doc_new_comment_fail' :
				$message = sprintf( __( 'Hi there,

Your comment to the group document:

"%s"

Could not be posted due to an error.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

		}

		return $message;
	}

	/** CUSTOM METHODS ************************************************/

	/**
	 * Limit BP Docs comment functionality to groups only.
	 *
	 * We're limiting to groups at the moment, since the Group Email Subscription plugin
	 * does all the hard work of sending emails to all group members, etc. and that this
	 * extension is explicitly built for groups.
	 *
	 * Just being extra cautious at the moment since BP Docs v1.2 at the time of this
	 * blurb hasn't completed the users module yet.
	 *
	 * @param bool $retval Defaults to true. Return false to block the current email from RBE manipulation.
	 * @param obj $item The activity object generated after the activity is saved.
	 * @return bool
	 */
	public function block_user_docs( $retval, $item ) {
		if ( ! empty( $this->activity_type ) && $item->type == $this->activity_type ) {
			// if not the groups component, return false to disable RBE manipulation for this email
			if ( $item->component != 'groups' )
				return false;
		}

		// return the usual for regular items
		return $retval;
	}

	/**
	 * Check the BP Doc's comment settings to see if we should disable RBE's email
	 * manipulation for the current email before sending.
	 *
	 * @param string $querystring Custom querystring that gets used for RBE's "Reply-To" email address
	 * @param obj $listener RBE's listener object that was registered in the bootstrap() method.
	 * @param array $args Email args from the 'wp_mail' filter
	 * @return string
	 */
	public function disable_querystring( $querystring, $listener, $args ) {
		// if the listener component does not match our registered 'id'
		// return querystring as-is!
		if ( $listener->component != $this->id )
			return $querystring;

		/* now proceed with our own special use-case! */

		// get parent comment data
		$comment = get_comment( $listener->secondary_item_id );

		// parent comment doesn't exist or was deleted
		if ( empty( $comment ) ) {
			return false;
		}

		// parent comment status checks
		switch ( $comment->comment_approved ) {
			case 'spam' :
				return false;

				break;

			case 'trash' :
				return false;

				break;

			case '0' :
				return false;

				break;
		}

		// get the doc ID
		$doc_id  = $comment->comment_post_ID;

		// get doc settings
		$doc_settings = get_post_meta( $doc_id, 'bp_docs_settings', true );

		// check the comment settings for the doc
		switch( $doc_settings['post_comments'] ) {

			// if the doc does not allow anyone to comment, return false
			// this will disable RBE from manipulating the email notification for this user email
			case 'no-one' :
				return false;

				break;

			// if the doc only allows group admins and mods to comment, return false for regular group members
			case 'admins-mods' :
				// get an array of group admin / mod email addresses
				// note: email addresses are set as key, not value
				$admin_mod_emails = $this->get_admin_mod_user_emails( $listener->item_id );

				// if the 'To' email address does not match a group admin or mod, return false
				// this will disable RBE for this email
				if ( ! isset( $admin_mod_emails[$args['to']] ) )
					return false;

				break;
		}

		// remember to return the querystring for our good emails!
		return $querystring;
	}

	/**
	 * Override BP Doc's comment activity action to add a 'via email' string.
	 *
	 * Thanks Boone for the filter! :)
	 * Note: This isn't a required method for RBE extensions!
	 *
	 * @param string $action The activity action
	 * @return string
	 */
	public function comment_activity_action( $action ) {
		// yeah, i know it's concatenated... proof-of-concept for now! :)
		return $action . ' ' . __( 'via email', 'bp-rbe' );
	}

	/** HELPERS *******************************************************/

	/**
	 * Return an array of a group's admin and moderator's email addresses.
	 *
	 * Note: The user email addresses are set as the key, not the value.
	 * This is so you can use isset($key) for a faster alternative to in_array().
	 *
	 * @param int $group_id The group ID
	 * @return array
	 */
	public function get_admin_mod_user_emails( $group_id ) {
		global $bp, $wpdb;

		$group_admin_mods = $wpdb->get_results(
			$wpdb->prepare( "SELECT u.user_email FROM {$wpdb->users} u, {$bp->groups->table_name_members} m WHERE u.ID = m.user_id AND m.group_id = %d AND ( m.is_admin = 1 OR m.is_mod = 1 )", $group_id )
		);

		$user_emails = wp_list_pluck( $group_admin_mods, 'user_email' );

		return array_flip( $user_emails );
	}
}
