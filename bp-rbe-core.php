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
	 * @var object Inbound provider loader. Defaults to boolean false.
	 */
	public $inbound_provider = false;

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
		$this->localization();

		/** Requirements check *******************************************/

		// If requirements are not fulfilled, then throw an admin notice and stop now!
		if ( ! bp_rbe_is_required_completed( $this->settings ) ) {
			add_action( 'admin_notices', array( &$this, 'admin_notice' ) );
			return;
		}

		/** Post-requirements routine ************************************/

		// load inbound provider
		if ( bp_rbe_is_inbound() ) {
			$this->load_inbound_provider();
		}

		// load the hooks!
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

		if ( class_exists( 'BP_Forums_Component' ) ) {
			require( BP_RBE_DIR . '/includes/bp-rbe-legacy-forums.php' );
		}
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

		// BuddyPress 2.5+ has their own email implementation; these hooks support it.
		add_filter( 'bp_email_set_post_object',                 array( $this, 'set_bp_post_object' ), 999 );
		add_filter( 'bp_email_validate',                        array( $this, 'set_bp_email_headers' ), 10, 2 );
		add_filter( 'bp_email_get_property',                    array( $this, 'move_rbe_marker_in_bp_html' ), 10, 3 );
		add_filter( 'bp_email_get_property',                    array( $this, 'move_nonrbe_notice_in_bp_html' ), 10, 3 );

		// WP Better Emails support
		add_filter( 'wpbe_html_body',                           array( &$this, 'move_rbe_marker' ) );

		// Do not show non-RBE notice in certain emails
		add_filter( 'bp_rbe_show_non_rbe_notice',               array( &$this, 'disable_non_rbe_notice' ), 10, 2 );

		// Post after parsing is validated
		add_filter( 'bp_rbe_parse_completed',                   array( $this, 'post' ), 10, 3 );
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

	/** INBOUND-RELATED ***********************************************/

	/**
	 * Load inbound provider.
	 *
	 * @since 1.0-RC3
	 */
	public function load_inbound_provider() {
		$selected = bp_rbe_get_setting( 'inbound-provider' );

		$providers = self::get_inbound_providers();

		if ( isset( $providers[$selected] ) && class_exists( $providers[$selected] ) ) {
			$this->inbound_provider =  new $providers[$selected];

		// Default to SendGrid if no provider is valid.
		} else {
			$this->inbound_provider =  new $providers['sendgrid'];
		}
	}

	/**
	 * Get inbound providers.
	 *
	 * @since 1.0-RC3
	 *
	 * @return array Key/value pairs (inbound provider name => class name)
	 */
	public static function get_inbound_providers() {
		$default = array(
			'postmark'  => 'BP_Reply_By_Email_Inbound_Provider_Postmark',
			'sparkpost' => 'BP_Reply_By_Email_Inbound_Provider_Sparkpost',
			'sendgrid'  => 'BP_Reply_By_Email_Inbound_Provider_Sendgrid',
			'mandrill'  => 'BP_Reply_By_Email_Inbound_Provider_Mandrill'
		);

		// If you've added a custom inbound provider, register it with this filter
		$third_party_providers = apply_filters( 'bp_rbe_register_inbound_providers', array() );

		return $default + (array) $third_party_providers;
	}

	/** EMAIL-HOOK RELATED ********************************************/

	/**
	 * Modify BP email content to prepend our RBE marker.
	 *
	 * For BuddyPress 2.5+.
	 *
	 * @since 1.0-RC4
	 *
	 * @param  WP_Post $post BP Email post object.
	 * @return WP_Post
	 */
	public function set_bp_post_object( $post ) {
		if ( false === is_a( $post, 'WP_Post' ) ) {
			return $post;
		}

		// Plugins should hook here and add their custom listener to $this->listener
		do_action_ref_array( 'bp_rbe_extend_listener', array( &$this ) );

		// Prepend notice to non-RBE emails.
		if ( empty( $this->listener->item_id ) ) {
			$args = array();
			$args['post'] = $post;

			$post->post_content = $this->prepend_nonrbe_notice_to_content( $post->post_content, $args );
			$post->post_excerpt = $this->prepend_nonrbe_notice_to_content( $post->post_excerpt, $args );
			return $post;
		}

		// We've already added our RBE marker, so bail!
		if ( false !== strpos( $post->post_excerpt, bp_rbe_get_marker() ) ) {
			return $post;
		}

		$post->post_content = $this->prepend_rbe_marker_to_content( $post->post_content );
		$post->post_excerpt = $this->prepend_rbe_marker_to_content( $post->post_excerpt );

		return $post;
	}

	/**
	 * Set various email headers for BP 2.5 emails.
	 *
	 * This includes the 'Reply-To' and custom 'From' headers.
	 *
	 * @since 1.0-RC4
	 *
	 * @param bool|WP_Error $retval Returns true if validation is successful, else a descriptive WP_Error.
	 * @param BP_Email      $email  Current instance of the email type class.
	 */
	public function set_bp_email_headers( $retval, $email ) {
		if ( ! $email->get_to() ) {
			return $retval;
		}

		if ( empty( $this->listener->item_id ) ) {
			return $retval;
		}

		$to = $email->get_to();

		// Backpat headers to be used for checks in 'bp_rbe_querystring' filter.
		$headers = array();
		$headers['to'] = array_shift( $to )->get_address();
		$reply_to = $this->get_reply_to_address( $headers );

		// If no reply to, bail.
		if ( empty( $reply_to ) ) {
			return $retval;
		}

		/**
		 * Should we use the poster's display name as the 'From' name?
		 *
		 * @since 1.0-RC4.
		 *
		 * @param bool $retval
		 */
		$use_custom_from_header = apply_filters( 'bp_rbe_use_custom_from_header', true );

		// Set custom 'From' header.
		if ( ! empty( $this->listener->user_id ) && true === $use_custom_from_header ) {
			// Fetch current 'From' email address.
			$from = $email->get_from()->get_address();

			// Grab the host.
			$host = substr( $from, strpos( $from, '@' ) + 1 );

			// Set the custom From email address and name.
			$email->set_from( "noreply@{$host}", bp_core_get_user_displayname( $this->listener->user_id ) );
		}

		/**
		 * Set our custom 'Reply-To' email header.
		 *
		 * Have to workaround a mailbox character limit PHPMailer bug by wiping out
		 * the Reply-To header and then setting it as a custom header.
		 *
		 * @link https://github.com/PHPMailer/PHPMailer/issues/706
		 */
		$email->set_reply_to( '' );
		$email->set_headers( array(
			'Reply-To' => $reply_to
		) );

		return $retval;
	}

	/**
	 * Moves the RBE marker in the BP HTML email content to the top of the email.
	 *
	 * For BuddyPress 2.5+.
	 *
	 * @since 1.0-RC4.
	 *
	 * @param string $retval        Current email HTML content.
	 * @param string $property_name The email property being fetched.
	 * @param string $transform     Transformation return type.
	 */
	public function move_rbe_marker_in_bp_html( $retval, $property_name, $transform ) {
		if ( 'template' !== $property_name || 'add-content' !== $transform ) {
			return $retval;
		}

		return $this->move_rbe_marker( $retval );
	}

	/**
	 * Moves the non-RBE notice in the BP HTML email content to the top of the email.
	 *
	 * For BuddyPress 2.5+.
	 *
	 * @since 1.0-RC5.
	 *
	 * @param string $retval        Current email HTML content.
	 * @param string $property_name The email property being fetched.
	 * @param string $transform     Transformation return type.
	 */
	public function move_nonrbe_notice_in_bp_html( $retval, $property_name, $transform ) {
		if ( 'template' !== $property_name || 'add-content' !== $transform ) {
			return $retval;
		}

		$notice = bp_rbe_get_nonrbe_notice();

		// try to find the reply line in the email
		$pos = strpos( $retval, $notice );

		// if our non-RBE notice isn't in this email, bail.
		if ( $pos === false ) {
			return $retval;
		}

		// remove the marker temporarily
		$html = substr_replace( $retval, '', $pos, strlen( $notice ) + 13 );

		// add some CSS styling
		// 3rd party devs can filter this
		$style = apply_filters( 'bp_rbe_reply_marker_css', "color:#333; font-size:12px; font-family:arial,san-serif;" );

		// add back the marker at the top of the HTML email and centered
		$body_close_pos = strpos( $retval, '>', strpos( $retval, '<body' ) );
		return substr_replace( $html, '<center><span style="' . $style . '">' . $notice . '</span></center>', $body_close_pos + 1, 0 );
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
		// plugins should hook here and add their custom listener to $this->listener
		do_action_ref_array( 'bp_rbe_extend_listener', array( &$this ) );

		// if our 'listener' object hasn't initialized, stop now!
		if ( empty( $this->listener ) ) {
			// since this isn't a RBE email, add a line above each email noting that
			// this isn't a RBE email and that you should not reply to this
			$args['message'] = $this->prepend_nonrbe_notice_to_content( $args['message'], $args );

			return $args;
		}

		$listener = $this->listener;

		// Make sure our 'listener' object has an 'item_id'
		// If so, start manipulating the email headers!
		if ( ! empty( $listener->item_id ) ) :

			// $args['headers'] can be either a string or array
			// so standardize to an array
			if ( empty( $args['headers'] ) ) {
				$args['headers'] = array();

			} elseif ( ! is_array( $args['headers'] ) ) {
				// Explode the headers out, so this function can take both
				// string headers and an array of headers.
				$args['headers'] = explode( "\n", str_replace( "\r\n", "\n", $args['headers'] ) );
			}

			/** This filter is documented in /bp-reply-by-email/bp-rbe-core.php */
			$use_custom_from_header = apply_filters( 'bp_rbe_use_custom_from_header', true );

			// Set the "From" email header.
			if ( ! empty( $listener->user_id ) && true === $use_custom_from_header ) {
				// override existing "From" name and use the member's display name
				$from = false;
				foreach ( $args['headers'] as $key => $custom_header ) {
					if ( substr( $custom_header, 0, 4 ) === "From" ) {
						$from = true;
						$lbracket = strpos( $custom_header, '<' );
						$args['headers'][$key] = substr_replace( $custom_header, bp_core_get_user_displayname( $listener->user_id ), 6, $lbracket - 7 );
						break;
					}
				}

				// no "From" header? set it now!
				if ( false === $from ) {
					// Set "From" email; copied from wp_mail()
					$sitename = strtolower( $_SERVER['SERVER_NAME'] );
					if ( substr( $sitename, 0, 4 ) == 'www.' ) {
						$sitename = substr( $sitename, 4 );
					}
					$from_email = apply_filters( 'bp_rbe_no_reply_email', 'noreply@' . $sitename );

					// add the "From" header
					$args['headers'][] = 'From: ' . bp_core_get_user_displayname( $listener->user_id ) . ' <' . $from_email . '>';
				}

				// remove BP's aggressive "From" name filtering
				remove_filter( 'wp_mail_from_name', 'bp_core_email_from_name_filter' );
			}

			// Setup our querystring which we'll add to the Reply-To header
			$reply_to = $this->get_reply_to_address( $args );

			// Add our special querystring to the Reply-To header!
			if ( ! empty( $reply_to ) ) {
				// Inject the querystring into the email address
				$args['headers'][] = 'Reply-To: ' . $reply_to;

				// Prepend our RBE marker to the email content.
				$args['message'] = $this->prepend_rbe_marker_to_content( $args['message'] );

				// PHPMailer 'Reply-To' email header override.
				$this->temp_args = $args;
				add_action( 'phpmailer_init', array( $this, 'phpmailer_set_reply_to_header' ) );
			}

			// Filter the headers; 3rd-party components could potentially hook into this
			$args['headers'] = apply_filters( 'bp_rbe_wp_mail_headers', $args['headers'], $listener->component, $listener->item_id, !empty( $listener->secondary_item_id ) ? $listener->secondary_item_id : false );

		endif;

		return $args;
	}

	/**
	 * Set 'Reply-To' email address for PHPMailer if mailbox is > 64 characters.
	 *
	 * Have to workaround a PHPMailer mailbox character limit issue by setting the
	 * 'Reply-To' email address again after PHPMailer has done its checks.
	 *
	 * This is done for installs still using wp_mail().
	 *
	 * @since 1.0-RC4.
	 * @link  https://github.com/PHPMailer/PHPMailer/issues/706
	 *
	 * @param PHPMailer $phpmailer
	 */
	public function phpmailer_set_reply_to_header( $phpmailer ) {
		/*
		 * Do a check to see if the reply-to email address is > 64 characters.
		 *
		 * If so, set the 'Reply-To' email address again since PHPMailer silently
		 * drops our first attempt at doing this.
		 */
		$reply_to = $this->get_reply_to_address( $this->temp_args );
		if ( strpos( $reply_to, '@' ) > 64 ) {
			$phpmailer->addCustomHeader( 'Reply-To', $reply_to );
		}

		// Unset some temporary items.
		unset( $this->temp_args );
		remove_action( 'phpmailer_init', array( $this, 'phpmailer_set_reply_to_header' ) );
	}

	/**
	 * Saves pertinent activity variables to our "listener" object, which is used in {@link BP_Reply_By_Email::wp_mail_filter()}
	 * Since the activity component is used in other BP components, we can also do checks for forums and blogs as well.
	 *
	 * @param object $item The activity object created during {@link BP_Activity_Activity::save()}
	 * @since 1.0-beta
	 */
	public function activity_listener( $item ) {
		// use this hook to block any unwanted activity items from being RBE'd!
		// see https://github.com/r-a-y/bp-reply-by-email/wiki/Developer-Guide
		if ( apply_filters( 'bp_rbe_block_activity_item', false, $item ) ) {
			$this->show_non_rbe_notice = true;
			return;
		}

		$this->listener = new stdClass;

		// activity component
		$this->listener->component = 'activity';

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

		$this->listener->component = 'forums';

		// get the topic ID if it's locally cached
		if ( ! empty( $bp->rbe->temp->topic_id ) ) {
			$topic_id = $bp->rbe->temp->topic_id;
			$user_id  = $bp->rbe->temp->user_id;

		// query for the topic ID
		} else {
			$post     = bb_get_post( $post_id );
			$topic_id = $post->topic_id;
			$user_id  = $post->poster_id;
		}

		// topic id
		$this->listener->item_id = $topic_id;

		// user ID
		$this->listener->user_id = $user_id;

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
		$this->listener = new stdClass;

		$this->listener->component = 'messages';
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

		$this->listener->component   = 'bbpress';
		$this->listener->item_id     = $topic_id;
		$this->listener->reply_to_id = $reply_id;

		if ( function_exists( 'bbp_get_reply_author_id' ) ) {
			$this->listener->user_id = bbp_get_reply_author_id( $reply_id );
		}
	}

	/**
	 * Post by email routine.
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
	public function post( $retval, $data, $params ) {
		global $bp, $wpdb;

		// Activity reply
		if ( ! empty( $params['a'] ) ) {
			bp_rbe_log( 'Message #' . $data['i'] . ': this is an activity reply, checking if parent activities still exist' );

			// Check to see if the root activity ID and the parent activity ID exist before posting
			$activity_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bp->activity->table_name} WHERE id IN ( %d, %d )", $params['a'], $params['p'] ) );

			// If $a = $p, this means that we're replying to a top-level activity update
			// So check if activity count is 1
			if ( $params['a'] == $params['p'] && $activity_count != 1 ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'root_activity_deleted' );
				return new WP_Error( 'root_activity_deleted' );

			// If we're here, this means we're replying to an activity comment
			// If count != 2, this means either the super admin or activity author has deleted one of the update(s)
			} elseif ( $params['a'] != $params['p'] && $activity_count != 2 ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'root_or_parent_activity_deleted' );
				return new WP_Error( 'root_or_parent_activity_deleted' );
			}

			/* Let's start posting! */
			// Add our filter to override the activity action in bp_activity_new_comment()
			bp_rbe_activity_comment_action_filter( $data['user_id'] );

			$comment_id = bp_activity_new_comment(
				 array(
					'content'     => $data['content'],
					'user_id'     => $data['user_id'],
					'activity_id' => $params['a'], // ID of the root activity item
					'parent_id'   => $params['p']  // ID of the parent comment
				)
			);

			if ( ! $comment_id ) {
				//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'activity_comment_fail' );
				return new WP_Error( 'activity_comment_fail' );
			}

			// special hook for RBE activity items
			// might want to do something like add some activity meta
			do_action( 'bp_rbe_new_activity', array(
				'activity_id'       => $comment_id,
				'type'              => 'activity_comment',
				'user_id'           => $data['user_id'],
				'item_id'           => $params['a'],
				'secondary_item_id' => $params['p'],
				'content'           => $data['content']
			) );

			bp_rbe_log( 'Message #' . $data['i'] . ': activity comment successfully posted!' );

			// remove the filter after posting
			remove_filter( 'bp_activity_comment_action', 'bp_rbe_activity_comment_action' );

			// return array of item on success
			return array( 'activity_comment_id' => $comment_id );

		// Private message reply
		} elseif ( ! empty( $params['m'] ) ) {
			if ( bp_is_active( $bp->messages->id ) ) {
				bp_rbe_log( 'Message #' . $data['i'] . ': this is a private message reply' );

				// see if the PM thread still exists
				if ( messages_is_valid_thread( $params['m'] ) ) {

					// see if the user is in the PM conversation
					$has_access = messages_check_thread_access( $params['m'], $data['user_id'] ) || is_super_admin( $data['user_id'] );

					if ( ! $has_access ) {
						//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'private_message_not_in_thread' );
						return new WP_Error( 'private_message_not_in_thread' );
					}

					// post the PM!
					$message_id = messages_new_message (
						array(
							'thread_id' => $params['m'],
							'sender_id' => $data['user_id'],
							'content'   => $data['content']
						)
					);

					if ( ! $message_id ) {
						//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'private_message_fail' );
						return new WP_Error( 'private_message_fail' );
					}

					// special hook for RBE parsed PMs
					do_action( 'bp_rbe_new_pm_reply', array(
						'thread_id' => $params['m'],
						'sender_id' => $data['user_id'],
						'content'   => $data['content']
					) );

					bp_rbe_log( 'Message #' . $data['i'] . ': PM reply successfully posted!' );

					// return array of item on success
					return array( 'message_id' => $message_id );

				// the PM thread doesn't exist anymore
				} else {
					//do_action( 'bp_rbe_imap_no_match', $this->connection, $i, $headers, 'private_message_thread_deleted' );
					return new WP_Error( 'private_message_thread_deleted' );
				}
			}
		}
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

		$bp->rbe       = new stdClass;
		$bp->rbe->temp = new stdClass;

		// we need to temporarily hold the group ID so we can pass it
		// to $this->group_forum_listener() via $this->set_group_id()
		$bp->rbe->temp->group_id = $retval['group_id'];

		// temporarily hold the user ID
		$bp->rbe->temp->user_id  = $retval['user_id'];

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

		if ( ! empty( $bp->rbe->temp->group_id ) ) {
			return $bp->rbe->temp->group_id;
		}

		return $retval;
	}

	/**
	 * WP Better Emails Support.
	 *
	 * WP Better Emails gives admins the ability to wrap the plain-text content
	 * around a HTML template.
	 *
	 * This interferes with the positioning of RBE's marker and how RBE parses the
	 * reply. So what we do in this method is to reposition the RBE marker to the
	 * beginning of the HTML body.
	 *
	 * This allows RBE to parse replies the way it was intended.
	 *
	 * @param str $html The full HTML email content from WPBE
	 * @return str Modified HTML content
	 */
	public function move_rbe_marker( $html ) {
		// if non-RBE email, stop now!
		if ( strpos( $html, __( '--- Replying to this email will not send a message directly to the recipient or group ---', 'bp-rbe' ) ) !== false ) {
			return $html;
		}

		$reply_line = bp_rbe_get_marker();

		// try to find the reply line in the email
		$pos = strpos( $html, $reply_line );

		// if our RBE marker isn't in this email, then this isn't a RBE email!
		// so stop!
		if ( $pos === false ) {
			return $html;
		}

		// remove the marker temporarily
		$html = substr_replace( $html, '', $pos, strlen( $reply_line ) + 13 );

		// add some CSS styling
		// 3rd party devs can filter this
		$style = apply_filters( 'bp_rbe_reply_marker_css', "color:#333; font-size:12px; font-family:arial,san-serif;" );

		// add back the marker at the top of the HTML email and centered
		$body_close_pos = strpos( $html, '>', strpos( $html, '<body' ) );
		return substr_replace( $html, '<center><span style="' . $style . '">' . $reply_line . '</span></center>', $body_close_pos + 1, 0 );
	}

	/**
	 * For non-RBE emails, we now add a line above each message denoting that the
	 * email is not one you can reply to.
	 *
	 * However, there are instances where we do not want to add this line.
	 *
	 * This method disables this line when:
	 *  - An email is sent from the admin area
	 *  - An activation email is sent
	 *
	 * @since 1.0-RC2
	 *
	 * @param bool $retval Should we disable the non-RBE notice?
	 * @param array $args Email args passed by the 'wp_mail' filter
	 * @return bool
	 */
	public function disable_non_rbe_notice( $retval, $args ) {
		// if explicitly showing non-rbe notice, return true
		if ( isset( $this->show_non_rbe_notice ) ) {
			unset( $this->show_non_rbe_notice );
			return true;
		}

		// don't do this in the admin area
		if ( is_admin() ) {
			return false;
		}

		// do not add the notice to activation emails
		// check the subject line and look for the activation subject text
		if ( isset( $args['subject'] ) && (
			strpos( $args['subject'], __( 'Activate Your Account', 'buddypress' ) ) !== false ||
			strpos( $args['subject'], __( 'Activate %s', 'buddypress' ) ) !== false )
		)  {
				return false;
		}

		// BP post type version check for activation emails.
		if ( isset( $args['post'] ) && $args['post'] instanceof WP_Post ) {
			$terms = get_the_terms( $args['post'], bp_get_email_tax_type() );
			if ( ! empty( $terms[0] ) && false !== strpos( $terms[0]->slug, 'core-user-registration' ) ) {
				return false;
			}
		}

		return $retval;
	}

	/** HELPERS *************************************************************/

	/**
	 * Get the email address used for the 'Reply-To' email header.
	 *
	 * @since 1.0-RC4
	 *
	 * @param array $headers Email headers.
	 */
	protected function get_reply_to_address( $headers = array() ) {
		if ( empty( $this->listener->item_id ) ) {
			return '';
		}

		// Setup our querystring which we'll add to the Reply-To header
		$querystring = '';

		switch ( $this->listener->component ) {
			case 'activity' :
				$querystring = "a={$this->listener->item_id}&p={$this->listener->secondary_item_id}";
			break;

			// BP Group Email Subscripton (GES) plugin compatibility
			// GES will send out group forum emails, so let's setup our param.
			case 'forums' :
				$querystring = "t={$this->listener->item_id}&g={$this->listener->secondary_item_id}";
			break;

			case 'messages' :
				$querystring = "m={$this->listener->item_id}";
			break;

			// 3rd party plugins can hook into this
			default :
				$querystring = apply_filters( 'bp_rbe_extend_querystring', $querystring, $this->listener );
			break;
		}

		// last chance to disable the querystring with this filter!
		$querystring = apply_filters( 'bp_rbe_querystring', $querystring, $this->listener, $headers );

		// Add our special querystring to the Reply-To header!
		if ( ! empty( $querystring ) ) {

			// Encode the qs
			// Don't like this? there's a filter for that!
			$querystring = apply_filters( 'bp_rbe_encode_querystring', bp_rbe_encode( array( 'string' => $querystring ) ), $querystring );

			// Inject the querystring into the email address
			$querystring = bp_rbe_inject_qs_in_email( $querystring );
		}

		return $querystring;
	}

	/**
	 * Prepend our RBE marker to a string.
	 *
	 * This adds the '--- Reply ABOVE THIS LINE to add a comment ---' line to the
	 * beginning of an email's content.  Inspired by Basecamp!
	 *
	 * @since 1.0-RC4
	 *
	 * @param  string $content
	 * @return string
	 */
	protected function prepend_rbe_marker_to_content( $content = '' ) {
		$reply_line = bp_rbe_get_marker();
		return "{$reply_line}\n\n{$content}";
	}

	/**
	 * Prepend our non-RBE notice to a string.
	 *
	 * @since 1.0-RC5
	 *
	 * @param  string $content
	 * @return string
	 */
	protected function prepend_nonrbe_notice_to_content( $content = '', $args = array() ) {
		if ( true === (bool) apply_filters( 'bp_rbe_show_non_rbe_notice', true, $args ) ) {
			$notice = bp_rbe_get_nonrbe_notice();

			$content = "{$notice}\n\n" . $content;
		}

		return $content;
	}

}