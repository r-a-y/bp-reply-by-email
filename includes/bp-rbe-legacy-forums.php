<?php
/**
 * BP Reply By Email - Legacy Forums support
 *
 * @package BP_Reply_By_Email
 * @subpackage LegacyForums
 */

/**
 * Post by email routine for legacy forums.
 *
 * Validates the parsed data and posts the various BuddyPress content.
 *
 * @since 1.0-RC3
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
function bp_rbe_legacy_forums_post( $retval, $data, $params ) {
	// Forum reply
	if ( ! empty( $params['t'] ) ) {

		if ( bp_is_active( 'groups' ) && bp_is_active( 'forums' ) ) {
			bp_rbe_log( 'Message #' . $data['i'] . ': this is a forum reply' );

			// get all group member data for the user in one swoop!
			$group_member_data = bp_rbe_get_group_member_info( $data['user_id'], $params['g'] );

			// user is not a member of the group anymore
			if ( empty( $group_member_data ) ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_not_group_member' );
				return new WP_Error( 'user_not_group_member' );
			}

			// user is banned from group
			if ( (int) $group_member_data->is_banned == 1 ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_banned_from_group' );
				return new WP_Error( 'user_banned_from_group' );
			}

			// Don't allow reply flooding
			// Only available in 1.6+
			if ( function_exists( 'bp_forums_reply_exists' ) && bp_forums_reply_exists( esc_sql( $data['content'] ), $params['t'], $data['user_id'] ) ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'forum_reply_exists' );
				return new WP_Error( 'forum_reply_exists' );
			}

			/* okay, we should be good to post now! */

			$forum_post_id = bp_rbe_groups_new_group_forum_post( array(
				'post_text' => $data['content'],
				'topic_id'  => $params['t'],
				'user_id'   => $data['user_id'],
				'group_id'  => $params['g']
			) );

			if ( ! $forum_post_id ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'forum_reply_fail' );
				return new WP_Error( 'forum_reply_fail' );
			}

			bp_rbe_log( 'Message #' . $data['i'] . ': forum reply successfully posted!' );

			// could potentially add attachments
			// @todo
			do_action( 'bp_rbe_new_forum_post', false, $forum_post_id, $data['user_id'], $params['g'], $data['headers'] );

			return array( 'legacy_forums_post_id' => $forum_post_id );
		}

	// New forum topic
	} elseif ( ! empty( $params['g'] ) ) {

		if ( bp_is_active( 'groups' ) && bp_is_active( 'forums' ) ) {
			bp_rbe_log( 'Message #' . $data['i'] . ': this is a new forum topic' );

			bp_rbe_log( 'Message #' . $data['i'] . ': body contents - ' . $data['content'] );
			bp_rbe_log( 'Subject - ' . $subject );

			if ( empty( $data['content'] ) || empty( $data['subject'] ) ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'new_forum_topic_empty' );
				return new WP_Error( 'new_forum_topic_empty' );
			}

			// get all group member data for the user in one swoop!
			$group_member_data = bp_rbe_get_group_member_info( $data['user_id'], $params['g'] );

			// user is not a member of the group anymore
			if ( empty( $group_member_data ) ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_not_group_member' );
				return new WP_Error( 'user_not_group_member' );
			}

			// user is banned from group
			if ( (int) $group_member_data->is_banned == 1 ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'user_banned_from_group' );
				return new WP_Error( 'user_banned_from_group' );
			}

			/* okay, we should be good to post now! */

			$topic = bp_rbe_groups_new_group_forum_topic( array(
				'topic_title' => $data['subject'],
				'topic_text'  => $data['content'],
				'user_id'     => $data['user_id'],
				'group_id'    => $params['g']
			) );

			if ( ! $topic ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'new_topic_fail' );
				return new WP_Error( 'new_topic_fail' );
			}

			bp_rbe_log( 'Message #' . $data['i'] . ': forum topic successfully posted!' );

			// could potentially add attachments
			do_action( 'bp_rbe_new_forum_topic', false, $topic, $data['user_id'], $params['g'], $data['headers'] );

			return array( 'legacy_forums_topic_id' => $topic );
		}
	}
}
add_filter( 'bp_rbe_parse_completed', 'bp_rbe_legacy_forums_post', 10, 3 );

/**
 * Modified version of {@link groups_new_group_forum_post()}.
 *
 * Duplicated because:
 * 	- groups_new_group_forum_post() hardcodes the $user_id.
 *      - groups_new_group_forum_post() doesn't check if the corresponding topic is deleted before posting.
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

	// initialize bundled bbPress
	// @todo perhaps use $wpdb instead and do away with the 'bbpress_init' hook as it's hella intensive
	do_action( 'bbpress_init' );

	global $bbdb;

	// do a direct bbPress DB call
	if ( isset( $bbdb ) ) {
		$topic = $bbdb->get_row( $bbdb->prepare( "SELECT * FROM {$bbdb->topics} WHERE topic_id = %d", $topic_id ) );
	}

	// if the topic was deleted, stop now!
	if ( $topic->topic_status == 1 )
		return false;

	if ( $post_id = bp_forums_insert_post( array( 'post_text' => $post_text, 'topic_id' => $topic_id, 'poster_id' => $user_id ) ) ) {
		$group = groups_get_group( 'group_id=' . $group_id );

		// If no page passed, calculate the page where the new post will reside.
		// I should backport this to BP.
		if ( !$page ) {
			$pag_num = apply_filters( 'bp_rbe_topic_pag_num', 15 );
			$page    = ceil( $topic->topic_posts / $pag_num );
		}

		$activity_action  = sprintf( __( '%s replied to the forum topic %s in the group %s via email:', 'bp-rbe'), bp_core_get_userlink( $user_id ), '<a href="' . bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug .'/">' . esc_attr( $topic->topic_title ) . '</a>', '<a href="' . bp_get_group_permalink( $group ) . '">' . esc_attr( $group->name ) . '</a>' );
		$activity_content = bp_create_excerpt( $post_text );
		$primary_link     = bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug . '/?topic_page=' . $page;

		/* Record this in activity streams */
		$activity_id = bp_activity_add( array(
			'user_id'           => $user_id,
			'action'            => apply_filters( 'groups_activity_new_forum_post_action', $activity_action, $post_id, $post_text, $topic ),
			'content'           => apply_filters( 'groups_activity_new_forum_post_content', $activity_content, $post_id, $post_text, $topic ),
			'primary_link'      => apply_filters( 'groups_activity_new_forum_post_primary_link', "{$primary_link}#post-{$post_id}" ),
			'component'         => $bp->groups->id,
			'type'              => 'new_forum_post',
			'item_id'           => $group_id,
			'secondary_item_id' => $post_id,
			'hide_sitewide'     => ( $group->status == 'public' ) ? false : true
		) );

		// special hook for RBE activity items
		do_action( 'bp_rbe_new_activity', array(
			'activity_id'       => $activity_id,
			'type'              => 'new_forum_post',
			'user_id'           => $user_id,
			'item_id'           => $group_id,
			'secondary_item_id' => $post_id,
			'content'           => $activity_content
		) );

		// apply BP's group forum post hook
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

		// initialize bundled bbPress
		do_action( 'bbpress_init' );

		global $bbdb;

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
			'action'             => apply_filters( 'groups_activity_new_forum_topic_action', $activity_action, $topic_text, $topic ),
			'content'            => apply_filters( 'groups_activity_new_forum_topic_content', $activity_content, $topic_text, $topic ),
			'primary_link'       => apply_filters( 'groups_activity_new_forum_topic_primary_link', bp_get_group_permalink( $group ) . 'forum/topic/' . $topic->topic_slug . '/' ),
			'component'          => $bp->groups->id,
			'type'               => 'new_forum_topic',
			'item_id'            => $group_id,
			'secondary_item_id'  => $topic_id,
			'hide_sitewide'      => ( $group->status == 'public' ) ? false : true
		) );

		// special hook for RBE activity items
		do_action( 'bp_rbe_new_activity', array(
			'activity_id'       => $activity_id,
			'type'              => 'new_forum_topic',
			'user_id'           => $user_id,
			'item_id'           => $group_id,
			'secondary_item_id' => $topic_id,
			'content'           => $activity_content
		) );

		// apply BP's group forum topic hook
		do_action( 'groups_new_forum_topic', $group_id, $topic );

		return $topic;
	}

	return false;
}