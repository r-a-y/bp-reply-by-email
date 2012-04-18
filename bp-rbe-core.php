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
	function init() {

		// settings
		$this->settings = bp_get_option( 'bp-rbe' );

		/** Includes *********************************************************************/
		$files = array( 'classes', 'functions', 'hooks' );

		foreach ( $files as $file )
			require_once( BP_RBE_DIR . "/includes/bp-rbe-{$file}.php" );

		/** Localization *****************************************************************/
		add_action( 'plugins_loaded',			array( &$this, 'localization' ) );

		/** Settings check ***************************************************************/
		// If requirements are not fulfilled, then throw an admin notice and stop now!
		if ( !bp_rbe_is_required_completed( $this->settings ) ) {
			add_action( 'admin_notices',		array( &$this, 'admin_notice' ) );
			return;
		}

		/** Hooks ************************************************************************/

		// Preferably, BP would add the fourth parameter to wp_mail() to each component's usage
		// and allow us to filter that param. Until then, we do some elegant workarounds ;)

		// Setup our "listener" object for the following BP components
		// activity_listener() set at priority 9 for compatibility with Group Email Subscription plugin
		add_action( 'bp_activity_after_save',		array( &$this, 'activity_listener' ), 9 );
		add_action( 'messages_message_after_save',	array( &$this, 'message_listener' ) );

		// Filter wp_mail(); use our listener object for component checks
		add_filter( 'wp_mail',				array( &$this, 'wp_mail_filter' ) );
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
	function localization() {
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
	function admin_notice() {
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
	function wp_mail_filter( $args ) {
		global $bp;

		// Check to see if our "listener" object exists
		// If so, start manipulating the email headers!
		if ( $listener = $this->listener ) :

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
					$querystring = apply_filters( 'bp_rbe_querystring', $querystring );
				break;
			}

			// Add our special querystring to the Reply-To header!
			if ( !empty( $querystring ) ) {
			
				// Encode the qs
				// Don't like this? there's a filter for that!
				$querystring = apply_filters( 'bp_rbe_encode_querystring', bp_rbe_encode( $querystring ), $querystring );
				
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
	function activity_listener( $item ) {
		global $bp;

		// activity component
		$this->listener->component = $bp->activity->id;

		// the user id
		$this->listener->user_id   = $item->user_id;

		// activity update
		if ( $item->type == 'activity_update' ) {
			$this->listener->item_id = $this->listener->secondary_item_id = $item->id;
		}
		// activity comment
		else {
			$this->listener->item_id           = $item->item_id; // id of root activity update
			$this->listener->secondary_item_id = $item->id;      // id of direct parent comment / update
		}

		// BP Group Email Subscription (GES) plugin compatibility
		// GES already hooks into this action to send emails in groups, so let's not reinvent the wheel.
		// Here, we test to see if a forum topic / post is being made and we'll let GES handle the rest!
		if ( function_exists( 'activitysub_load_buddypress' ) && strpos( $item->type, 'new_forum_' ) !== false ) {
			$this->listener->component         = $bp->forums->id;
			$this->listener->item_id           = $item->secondary_item_id; // topic id
			$this->listener->secondary_item_id = $item->item_id;           // group id

			// If a forum post is being made, we still need to grab the topic id
			if ( $item->type == 'new_forum_post' ) {

				// Sanity check!
				if ( !bp_is_active( $bp->forums->id ) )
					return;

				$post = bp_forums_get_post( $item->secondary_item_id );
				$this->listener->item_id = $post->topic_id;
			}
		}

		/* future support for blog comments - maybe so, maybe no?
		if ( $item->type == 'new_blog_comment' ) {
			$this->listener->component         = $bp->blogs->id;
			$this->listener->item_id           = $item->item_id;           // post id
			$this->listener->secondary_item_id = $item->secondary_item_id; // blog id
		}
		*/
	}

	/**
	 * Saves pertinent message variables to our "listener" object, which is used in {@link BP_Reply_By_Email::wp_mail_filter()}
	 *
	 * @global object $bp
	 * @param object $item The message object created during {@link BP_Messages_Message::send()}
	 * @since 1.0-beta
	 */
	function message_listener( $item ) {
		global $bp;

		$this->listener->component = $bp->messages->id;
		$this->listener->item_id   = $item->thread_id;
		$this->listener->user_id   = $item->sender_id;
	}
}

?>