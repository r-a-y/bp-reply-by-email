<?php
/**
 * BP Groupblog Comment Reply By Email Extension.
 *
 * Allows group members to reply to BP Groupblog comments via email.
 *
 * Comments will be posted directly into the blog's comments and not as a nested
 * activity comment.
 *
 * Requires the BP Group Email Subscription plugin
 * {@link http://wordpress.org/extend/plugins/buddypress-group-email-subscription/}
 * for the emails to be sent out.
 *
 * Your Group Email setting must be set to "All Mail" to reply.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Adds RBE support to the popular BP Groupblog plugin.
 *
 * Allows group members to reply to BP Groupblog comments via email.
 *
 * Extends the abstract {@link BP_Reply_By_Email_Extension} class, which
 * helps do a lot of the dirty work!
 *
 * @package BP_Reply_By_Email
 * @subpackage Extensions
 * @since 1.0-RC6
 */
class BP_Groupblog_Comment_RBE_Extension extends BP_Reply_By_Email_Extension {

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
			'id'            => 'bp-groupblog',          // your plugin name
			'activity_type' => 'new_groupblog_comment', // activity 'type' you want to match
			'item_id_param' => 'bpgbg',           // parameter name for your activity 'item_id'; in our case 'item_id' is the group ID
			                                      // hence the shortname 'bpdg'
			'secondary_item_id_param' => 'bpgbc', // parameter name for your activity 'secondary_item_id'; in our case
			                                      // 'secondary_item_id' is the comment ID we want to reply to, hence
			                                      // the shortname 'bpdc'

			// Custom parameter.
			'post_id_param' => 'bpgbp',
		) );

		// New GES support.
		add_filter( 'ass_send_email_args',        array( $this, 'ges_support' ), 9999, 2 );
		add_action( 'bp_ges_after_bp_send_email', array( $this, 'ges_remove_listener' ) );
		add_filter( 'bp_rbe_allowed_params',      array( $this, 'register_custom_params' ) );

		add_filter( 'bp_activity_generate_action_string', array( $this, 'action_string' ), 20, 2 );
	}

	/**
	 * Add support for the BP Group Email Subscription plugin.
	 *
	 * @since 1.0-RC7 Switched hook to use 'ass_send_email_args'.
	 *
	 * @param array  $retval     GES email args.
	 * @param string $email_type GES email type.
	 */
	public function ges_support( $retval, $email_type ) {
		if ( 'bp-ges-single' !== $email_type ) {
			return $retval;
		}

		/*
		 * Temporarily save activity object so we can reference it in the
		 * ges_extend_listener() method.
		 */
		$this->temp_activity = $retval['activity'];

		// Extend RBE's listener to add RBE support.
		add_action( 'bp_rbe_extend_listener', array( $this, 'ges_extend_listener' ) );

		return $retval;
	}

	/**
	 * Register support for GES with RBE.
	 *
	 * @param BP_Reply_By_Email $rbe
	 */
	public function ges_extend_listener( $rbe ) {
		// If activity type does not match our groupblog types, stop now!
		if ( $this->temp_activity->type !== $this->activity_type && $this->temp_activity->type !== 'new_groupblog_post' ) {
			return;
		}

		if ( ! isset( $rbe->listener ) ) {
			$rbe->listener = new stdClass;
		}

		$rbe->listener->component = $this->id;
		$rbe->listener->item_id   = $this->temp_activity->item_id;

		// Support 'new_groupblog_post' activity type as well.
		if ( 'new_groupblog_post' === $this->temp_activity->type ) {
			$rbe->listener->secondary_item_id = 0;
			$rbe->listener->post_id           = $this->temp_activity->secondary_item_id;

		// 'new_groupblog_comment'
		} else {
			$rbe->listener->secondary_item_id = $this->temp_activity->secondary_item_id;

			$switch = bp_is_root_blog() ? true : false;

			// Due to GES async, need to grab groupblog comment and set site ID.
			if ( $switch ) {
				$rbe->listener->blog_id = get_groupblog_blog_id( $rbe->listener->item_id );

				switch_to_blog( $rbe->listener->blog_id );
			}

			$comment = get_comment( $this->temp_activity->secondary_item_id );

			if ( $switch ) {
				restore_current_blog();
			}

			$rbe->listener->post_id = $comment->comment_post_ID;
		}
	}

	/**
	 * Remove RBE listener for GES.
	 *
	 * When used in a loop like an IMAP continuous inbox check, we have to remove
	 * the RBE listener for GES since the GES RBE listener is more of a tacked-on
	 * approach than regular RBE items and can conflict with the generation of the
	 * 'Reply-To' email header for other RBE components.
	 */
	public function ges_remove_listener() {
		if ( isset( $this->temp_activity ) ) {
			unset( $this->temp_activity );
			remove_action( 'bp_rbe_extend_listener', array( $this, 'ges_extend_listener' ) );
		}
	}

	/**
	 * Not using the activity listener, so plug it and bail.
	 *
	 * We're using Group Email Subscription instead. See ges_support() method.
	 *
	 * @param obj $listener Registers your component with RBE's activity listener
	 * @param obj $item The activity object generated by BP during save.
	 */
	public function extend_activity_listener( $listener, $item ) {}

	/**
	 * Registers our custom 'post_id_param' with RBE.
	 *
	 * @param array $params Whitelisted parameters used by RBE for the querystring
	 * @return array $params
	 */
	public function register_custom_params( $params ) {
		$params[$this->post_id_param] = false;

		return $params;
	}

	/**
	 * Sets up the querystring used in the 'Reply-To' email address.
	 *
	 * Overrides our parent method to support our second activity type,
	 * 'new_groupblog_post'.
	 *
	 * @param  string $querystring Querystring used to form the "Reply-To" email address.
	 * @param  object $listener    The listener object registered in the ges_extend_listener() method.
	 * @return string
	 */
	public function extend_querystring( $querystring, $listener ) {
		if ( $listener->component !== $this->id ) {
			return $querystring;
		}

		$querystring = "{$this->item_id_param}={$listener->item_id}";

		if ( ! empty( $listener->secondary_item_id ) ) {
			$querystring .= "&{$this->secondary_item_id_param}={$listener->secondary_item_id}";
		}

		if ( ! empty( $listener->post_id ) ) {
			$querystring .= "&{$this->post_id_param}={$listener->post_id}";
		}

		// Add groupblog site ID if necessary.
		if ( ! empty( $listener->blog_id ) ) {
			$querystring .= "&b={$listener->blog_id}";
		}

		return $querystring;
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

		$comment_id = ! empty( $params[$this->secondary_item_id_param] ) ? $params[$this->secondary_item_id_param] : 0;
		$post_id    = ! empty( $params[$this->post_id_param] ) ? $params[$this->post_id_param] : false;

		$i = $data['i'];

		// Bail if not on a BP Groupblog item.
		if ( empty( $comment_id ) && empty( $post_id ) ) {
			return $retval;
		}

		// Log what's happening.
		if ( empty( $comment_id ) ) {
			bp_rbe_log( 'Message #' . $i . ': this is a BP Groupblog post reply' );
		} else {
			bp_rbe_log( 'Message #' . $i . ': this is a BP Groupblog comment reply' );
		}

		// Check parent comment if available.
		if ( ! empty( $comment_id ) ) {
			$comment = get_comment( $comment_id );
			$post_id = $comment->comment_post_ID;

			// parent comment doesn't exist or was deleted
			if ( empty( $comment ) ) {
				return new WP_Error( 'bp_groupblog_parent_comment_deleted', '', $data );
			}

			// parent comment status checks
			switch ( $comment->comment_approved ) {
				case 'spam' :
					return new WP_Error( 'bp_groupblog_parent_comment_spam', '', $data );

					break;

				case 'trash' :
					return new WP_Error( 'bp_groupblog_parent_comment_deleted', '', $data );

					break;

				case '0' :
					return new WP_Error( 'bp_groupblog_parent_comment_unapproved', '', $data );

					break;
			}
		}

		// set temporary variable
		$bp->rbe = $bp->rbe->temp = new stdClass;

		$user_id = $data['user_id'];

		// get group ID
		// $bp->rbe->temp->group_id gets passed to BP_Reply_By_Email::set_group_id()
		$group_id = $bp->rbe->temp->group_id = $params[$this->item_id_param];

		if( ! groups_is_user_member( $user_id, $group_id ) ) {
			return new WP_Error( 'bp_groupblog_user_not_member', '', $data );
		}

		if ( groups_is_user_banned( $user_id, $group_id ) ) {
			return new WP_Error( 'bp_groupblog_user_banned', '', $data );
		}

		/* okay! we should be good to post now! */

		// get the userdata
		$userdata = get_userdata( $user_id );

		// Check to see if the blog has set comment moderation on.
		$should_moderate = 1 == get_option( 'comment_moderation' );

		// we're using wp_insert_comment() instead of wp_new_comment()
		// why? because wp_insert_comment() bypasses all the WP comment hooks, which is good for us!
		$new_comment_id = wp_insert_comment( array(
			'user_id'              => $user_id,
			'comment_post_ID'      => $post_id,
			'comment_content'      => $data['content'],
			'comment_parent'       => $comment_id, // set as $comment_id? or "0" for not threaded?
			'comment_author'       => $userdata->user_nicename, // user_login?
			'comment_author_url'   => '', // use BP member domain?
			'comment_author_email' => $userdata->user_email, // use RBE email address
			'comment_author_IP'    => '', // how to get via RBE?
			'comment_agent'        => '', // set agent as RBE? 'BP_Reply_By_Email/1.0
		        'comment_type'         => '', // not used
		        'comment_approved'     => true === $should_moderate ? '0' : 1
		) );

		// comment successfully posted!
		if ( ! empty( $new_comment_id ) ) {
			if ( $should_moderate ) {
				bp_rbe_log( 'Message #' . $i . ': BP Groupblog comment reply was posted, but is pending moderation' );

				// Notify the site admin if necessary.
				if ( function_exists( 'wp_new_comment_notify_moderator' ) && ! has_action( 'wp_insert_comment', 'wp_new_comment_notify_moderator' ) ) {
					wp_new_comment_notify_moderator( $new_comment_id );
				}

				return array( 'bp_groupblog_unapproved_comment_id' => $new_comment_id );

			} else {
				// more internal logging
				bp_rbe_log( 'Message #' . $i . ': BP Groupblog comment reply successfully posted!' );

				/* now let's record the activity item for this comment */

				// Add hooks.
				add_action( 'bp_activity_before_save', array( $this, 'comment_activity_action' ) );
				add_filter( 'bp_disable_blogforum_comments', '__return_true' );
				add_filter( 'ass_send_email_args', array( $this, 'ges_email_args' ), 10, 2 );

				// second arg = is_approved.
				$activity_id = bp_activity_post_type_comment( $new_comment_id, true, bp_activity_get_post_type_tracking_args( 'post' ) );

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

				// Remove hooks.
				remove_filter( 'ass_send_email_args', array( $this, 'ges_email_args' ), 10, 2 );
				remove_filter( 'bp_disable_blogforum_comments', '__return_true' );
				remove_action( 'bp_activity_before_save', array( $this, 'comment_activity_action' ) );

				return array( 'bp_groupblog_comment_id' => $new_comment_id );
			}

		} else {
			return new WP_Error( 'bp_groupblog_new_comment_fail', '', $data );
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
			case 'bp_groupblog_parent_comment_deleted' :
				$log = __( "error - BP groupblog parent comment was deleted before this could be posted.", 'bp-rbe' );

				break;

			case 'bp_groupblog_parent_comment_spam' :
				$log = __( "error - BP groupblog parent comment was marked as spam before this could be posted.", 'bp-rbe' );

				break;

			case 'bp_groupblog_parent_comment_unapproved' :
				$log = __( "error - BP groupblog parent comment was unapproved before this could be posted.", 'bp-rbe' );

				break;

			case 'bp_groupblog_user_not_member' :
				$log = __( 'error - user is not a member of the group', 'bp-rbe' );

				break;

			case 'bp_groupblog_user_banned' :
				$log = __( 'notice - user is banned from group. BP groupblog comment not posted.', 'bp-rbe' );

				break;

			case 'bp_groupblog_new_comment_fail' :
				$log = __( 'error - BP groupblog comment failed to post', 'bp-rbe' );

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
			case 'bp_groupblog_parent_comment_deleted' :
				$message = sprintf( __( 'Hi there,

Your comment to the groupblog comment:

"%s"

Could not be posted because the parent comment you were replying to no longer exists.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_groupblog_parent_comment_spam' :
				$message = sprintf( __( 'Hi there,

Your comment to the groupblog comment:

"%s"

Could not be posted because the parent comment you were replying to was marked as spam.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_groupblog_parent_comment_unapproved' :
				$message = sprintf( __( 'Hi there,

Your comment to the groupblog comment:

"%s"

Could not be posted because the parent comment you were replying was unapproved.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_groupblog_user_not_member' :
				$message = sprintf( __( 'Hi there,

Your comment to the groupblog comment:

"%s"

Could not be posted because you are no longer a member of this group.  To comment on this groupblog comment, please rejoin the group.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

			case 'bp_groupblog_new_comment_fail' :
				$message = sprintf( __( 'Hi there,

Your comment to the groupblog comment:

"%s"

Could not be posted due to an error.

We apologize for any inconvenience this may have caused.', 'bp-rbe' ), BP_Reply_By_Email_Parser::get_body( $data['content'], $data['is_html'], true, $i ) );

				break;

		}

		return $message;
	}

	/** CUSTOM METHODS ************************************************/

	/**
	 * Override activity action to add a 'via email' string.
	 *
	 * @param BP_Activity_Activity $activity Activity object.
	 */
	public function comment_activity_action( $activity ) {
		$activity->action .= ' ' . __( 'via email', 'bp-rbe' );
	}

	/**
	 * Override activity action format to add a 'via email' string.
	 *
	 * @param  string               $retval   Current action.
	 * @param  BP_Activity_Activity $activity Activity object.
	 * @return string
	 */
	public function action_string( $retval, $activity ) {
		if ( 'new_groupblog_comment' !== $activity->type ) {
			return $retval;
		}

		$meta = bp_activity_get_meta( $activity->id, 'bp_rbe' );
		if ( empty( $meta ) ) {
			return $retval;
		}

		return $retval . ' ' . __( 'via email', 'bp-rbe' );
	}

	/**
	 * Override GES email args to add a 'via email' string.
	 *
	 * @param  array  $retval Current GES email args.
	 * @param  string $type   Current GES email type.
	 * @return array
	 */
	public function ges_email_args( $retval, $type ) {
		$retval['tokens']['ges.action'] .= ' ' . __( 'via email', 'bp-rbe' );
		return $retval;
	}
}
