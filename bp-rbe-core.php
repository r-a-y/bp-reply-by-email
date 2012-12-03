<?php
/**
 * BP Reply By Email Core
 *
 * @package BP_Reply_By_Email
 * @subpackage Core
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Core class for BP Reply By Email.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
class BP_Reply_By_Email {

	/**
	 * Initializes the class when called upon.
	 */
	public function init() {

		/** Settings *****************************************************/

		$this->settings = bp_get_option( 'bp-rbe' );

		/** Includes *****************************************************/

		$this->includes();

		/** Constants ****************************************************/

		$this->constants();

		/** Localization *************************************************/

		// we place this here instead of in hooks() because we want to
		// localize even before our requirements are fulfilled
		add_action( 'plugins_loaded',        array( &$this, 'localization' ) );

		/** Requirements check *******************************************/

		// If requirements are not fulfilled, then throw an admin notice and stop now!
		if ( ! bp_rbe_is_required_completed( $this->settings ) ) {
			add_action( 'admin_notices', array( &$this, 'admin_notice' ) );
			return;
		}

		/** Hooks ********************************************************/

		// requirements are fulfilled! load the hooks!
		$this->hooks();
	}

	/**
	 * Include required files.
	 *
	 * @access private
	 */
	private function includes() {
		require( BP_RBE_DIR . '/includes/bp-rbe-classes.php' );
		require( BP_RBE_DIR . '/includes/bp-rbe-functions.php' );
		require( BP_RBE_DIR . '/includes/bp-rbe-hooks.php' );
	}

	/**
	 * Setup our constants.
	 *
	 * @access private
	 */
	private function constants() {
		// this is true during dev period, will revert to false on release
		// or maybe not due to the failsafe's reliance on the debug log?
		if ( ! defined( 'BP_RBE_DEBUG' ) )
			define( 'BP_RBE_DEBUG', true );

		if ( ! defined( 'BP_RBE_DEBUG_LOG_PATH' ) )
			define( 'BP_RBE_DEBUG_LOG_PATH', WP_CONTENT_DIR . '/bp-rbe-debug.log' );

		// the number of lines to grab from the end of the RBE debug log
		// @see bp_rbe_failsafe()
		if ( ! defined( 'BP_RBE_TAIL_LINES' ) )
			define( 'BP_RBE_TAIL_LINES', 3 );
	}

	/**
	 * Setup RBE's hooks.
	 *
	 * @access private
	 */
	private function hooks() {
		// Preferably, BP would add the fourth parameter to wp_mail() to each component's usage
		// and allow us to filter that param. Until then, we do some elegant workarounds ;)

		// Setup our "listener" object for the following BP components
		add_action( 'bp_activity_after_save',                   array( &$this, 'activity_listener' ),    9 );
		add_action( 'messages_message_after_save',              array( &$this, 'message_listener' ) );
		add_action( 'bb_new_post',                              array( &$this, 'group_forum_listener' ), 9 );
		add_action( 'bbp_pre_notify_subscribers',               array( &$this, 'bbp_listener' ),         10, 2 );

		// These hooks are helpers for $this->group_forum_listener()
		add_filter( 'bp_rbe_groups_new_group_forum_post_args',  array( &$this, 'get_temporary_variables' ) );
		add_filter( 'bp_rbe_groups_new_group_forum_topic_args', array( &$this, 'get_temporary_variables' ) );
		add_filter( 'bp_get_current_group_id',                  array( &$this, 'set_group_id' ) );

		// Filter wp_mail(); use our listener object for component checks
		add_filter( 'wp_mail',                                  array( &$this, 'wp_mail_filter' ) );
	}

	/**
	 * Custom textdomain loader.
	 *
	 * Checks WP_LANG_DIR for the .mo file first, then the plugin's language folder.
	 * Allows for a custom language file other than those packaged with the plugin.
	 *
	 * @uses load_textdomain() Loads a .mo file into WP
	 * @since 1.0-beta
	 */
	public function localization() {
		$mofile		= sprintf( 'bp-rbe-%s.mo', get_locale() );
		$mofile_global	= WP_LANG_DIR . '/' . $mofile;
		$mofile_local	= BP_RBE_DIR . '/languages/' . $mofile;

		if ( is_readable( $mofile_global ) )
			return load_textdomain( 'bp-rbe', $mofile_global );
		elseif ( is_readable( $mofile_local ) )
			return load_textdomain( 'bp-rbe', $mofile_local );
		else
			return false;
	}

	/**
	 * Add an admin notice nag
	 *
	 * @since 1.0-beta
	 */
	public function admin_notice() {
	?>
		<div id="message" class="error"><p><?php _e( 'BuddyPress Reply By Email cannot initialize.  Please navigate to "BuddyPress > Reply By Email" to fill in the required fields and address the webhost warnings.', 'bp-rbe' ) ?></p></div>
	<?php
	}

	/**
	 * Adds "Reply-To" to email headers in {@link wp_mail()}.
	 * Also manipulates message content for Basecamp-like behaviour.
	 *
	 * @global object $bp
	 * @param array $args Arguments provided via {@link wp_mail()} filter.
	 * @return array
	 * @since 1.0-beta
	 */
	public function wp_mail_filter( $args ) {
		global $bp;

		// if our 'listener' object hasn't initialized, stop now!
		// @todo make this easier to extend in 3rd-party plugins
		if ( empty( $this->listener ) )
			return $args;

		$listener = $this->listener;

		// Make sure our 'listener' object has an 'item_id'
		// If so, start manipulating the email headers!
		if ( ! empty( $listener->item_id ) ) :

			// Make sure we don't get rid of any headers that might be declared
			if ( empty( $args['headers'] ) )
				$args['headers'] = '';

			// Setup our querystring which we'll add to the Reply-To header
			$querystring = '';

			switch ( $listener->component ) {
				case $bp->activity->id :
					$querystring = "a={$listener->item_id}&p={$listener->secondary_item_id}";
				break;

				// BP Group Email Subscripton (GES) plugin compatibility
				// GES will send out group forum emails, so let's setup our param.
				case $bp->forums->id :
					$querystring = "t={$listener->item_id}&g={$listener->secondary_item_id}";
				break;

				case $bp->messages->id :
					$querystring = "m={$listener->item_id}";
				break;

				// 3rd party plugins can hook into this
				default :
					$querystring = apply_filters( 'bp_rbe_extend_querystring', $querystring, $listener );
				break;
			}
			
			// last chance to disable the querystring with this filter!
			$querystring = apply_filters( 'bp_rbe_querystring', $querystring, $listener, $args );

			// Add our special querystring to the Reply-To header!
			if ( ! empty( $querystring ) ) {

				// Encode the qs
				// Don't like this? there's a filter for that!
				$querystring = apply_filters( 'bp_rbe_encode_querystring', bp_rbe_encode( array( 'string' => $querystring ) ), $querystring );

				// Inject the querystring into the email address
				$args['headers'] .= 'Reply-To: ' . bp_rbe_inject_qs_in_email( $querystring ) . PHP_EOL;

				// Inspired by Basecamp!
				$reply_line = __( '--- Reply ABOVE THIS LINE to add a comment ---', 'bp-rbe' );
				$args['message'] = "{$reply_line}\n\n" . $args['message'];
			}

			// Filter the headers; 3rd-party components could potentially hook into this
			$args['headers'] = apply_filters( 'bp_rbe_wp_mail_headers', $args['headers'], $listener->component, $listener->item_id, !empty( $listener->secondary_item_id ) ? $listener->secondary_item_id : false );

		endif;

		return $args;
	}

	/**
	 * Saves pertinent activity variables to our "listener" object, which is used in {@link BP_Reply_By_Email::wp_mail_filter()}
	 * Since the activity component is used in other BP components, we can also do checks for forums and blogs as well.
	 *
	 * @global object $bp
	 * @param object $item The activity object created during {@link BP_Activity_Activity::save()}
	 * @since 1.0-beta
	 */
	public function activity_listener( $item ) {
		global $bp;

		// use this hook to block any unwanted activity items from being RBE'd!
		// see https://github.com/r-a-y/bp-reply-by-email/wiki/Developer-Guide
		if ( apply_filters( 'bp_rbe_block_activity_item', false, $item ) )
			return;

		$this->listener = new stdClass;

		// activity component
		$this->listener->component = $bp->activity->id;

		// the user id
		$this->listener->user_id   = $item->user_id;

		switch ( $item->type ) {
			case 'activity_update' :
				$this->listener->item_id = $this->listener->secondary_item_id = $item->id;

				break;

			case 'activity_comment' :
				$this->listener->item_id           = $item->item_id; // id of root activity update
				$this->listener->secondary_item_id = $item->id;      // id of direct parent comment / update

				break;

			/* future support for blog comments - maybe so, maybe no?
			case 'new_blog_comment' :
				$this->listener->component         = $bp->blogs->id;
				$this->listener->item_id           = $item->item_id;           // post id
				$this->listener->secondary_item_id = $item->secondary_item_id; // blog id

				break;
			*/

			// devs: use the following hook to extend RBE's activity listener capability
			// see https://github.com/r-a-y/bp-reply-by-email/wiki/Developer-Guide
			default :
				do_action( 'bp_rbe_extend_activity_listener', $this->listener, $item );

				break;
		}

	}

	/**
	 * BP Group Email Subscription (GES) plugin compatibility.
	 *
	 * GES hooks into the 'bb_new_post' action to send emails in groups, so let's not reinvent the wheel.
	 * Here, we test to see if a forum topic / post is being made and we'll let GES handle the rest!
	 *
	 * @global object $bp
	 * @param int $post_id The forum post ID created by bbPress
	 * @since 1.0-beta
	 */
	public function group_forum_listener( $post_id ) {
		global $bp;

		// requires latest version of GES
		if ( ! function_exists( 'ass_group_notification_forum_posts' ) )
			return;

		$this->listener = new stdClass;

		$this->listener->component = $bp->forums->id;

		// get the topic ID if it's locally cached
		if ( ! empty( $bp->rbe->temp->topic_id ) ) {
			$topic_id = $bp->rbe->temp->topic_id;
		}
		// query for the topic ID
		else {
			$post     = bb_get_post( $post_id );
			$topic_id = $post->topic_id;
		}

		// topic id
		$this->listener->item_id   = $topic_id;

		// group id; we filter bp_get_current_group_id() when we post from our IMAP inbox check via WP-cron
		// @see BP_Reply_By_Email::get_temporary_variables()
		// @see BP_Reply_By_Email::set_group_id()
		$this->listener->secondary_item_id = bp_get_current_group_id();
	}

	/**
	 * Saves pertinent message variables to our "listener" object, which is used in {@link BP_Reply_By_Email::wp_mail_filter()}
	 *
	 * @global object $bp
	 * @param object $item The message object created during {@link BP_Messages_Message::send()}
	 * @since 1.0-beta
	 */
	public function message_listener( $item ) {
		global $bp;

		$this->listener = new stdClass;

		$this->listener->component = $bp->messages->id;
		$this->listener->item_id   = $item->thread_id;
		$this->listener->user_id   = $item->sender_id;
	}

	/**
	 * bbPress 2 plugin compatibility.
	 *
	 * bbPress has built-in email subscriptions, so let's not reinvent the wheel!
	 *
	 * Here, we hook into the 'bbp_pre_notify_subscriptions' hook so we can
	 * setup our listener.
	 *
	 * This listener is only for bbPress forums that are not attached to
	 * BuddyPress groups.
	 *
	 * For BuddyPress groups, see the BBP_RBE_Extension::extend_activity_listener() method instead.
	 *
	 * @param int $reply_id The forum reply ID created by bbPress
	 * @param int $topic_id The forum topic ID created by bbPress
	 */
	public function bbp_listener( $reply_id, $topic_id ) {
		$this->listener = new stdClass;

		$this->listener->component = 'bbpress';
		$this->listener->item_id   = $topic_id;
	}

	/**
	 * When posting group forum topics or posts via IMAP, we need to grab some temporary variables to
	 * pass to other methods - {@link BP_Reply_By_Email::group_forum_listener()} and {@link BP_Reply_By_Email::set_group_id()}.
	 *
	 * @global object $bp
	 * @param array $retval Array of arguments
	 * @return array Array of arguments
	 * @since 1.0-beta
	 */
	public function get_temporary_variables( $retval ) {
		global $bp;

		$bp->rbe = $bp->rbe->temp = new stdClass;

		// we need to temporarily hold the group ID so we can pass it
		// to $this->group_forum_listener() via $this->set_group_id()
		$bp->rbe->temp->group_id = $retval['group_id'];

		// if we're using the 'bp_rbe_groups_new_group_forum_post_args' filter,
		// the topic ID is passed as well, so let's save it to prevent querying for it later on!
		if ( ! empty( $retval['topic_id'] ) ) {
			$bp->rbe->temp->topic_id = $retval['topic_id'];
		}

		return $retval;
	}

	/**
	 * Overrides the current group ID with our locally-cached one from
	 * {@link BP_Reply_By_Email::get_temporary_variables()} if available.
	 *
	 * @global object $bp
	 * @param int $retval The group ID
	 * @return int $retval The group ID
	 * @since 1.0-beta
	 */
	public function set_group_id( $retval ) {
		global $bp;

		if ( ! empty( $bp->rbe->temp ) ) {
			return $bp->rbe->temp->group_id;
		}

		return $retval;
	}
}

?>