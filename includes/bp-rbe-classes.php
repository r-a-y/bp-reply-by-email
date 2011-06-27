<?php
/**
 * BP Reply By Email Classes
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

/**
 * Class: BP_Reply_By_Email_IMAP
 *
 * Handles checking an IMAP inbox and posting items to BuddyPress.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */
class BP_Reply_By_Email_IMAP {

	var $imap;

	/**
	 * The main function we use to parse an IMAP inbox.
	 */
	function init() {
		global $bp, $bp_rbe;

		// If safe mode isn't on, then let's set the execution time to unlimited
		if ( !ini_get( 'safe_mode' ) )
			set_time_limit(0);

		// Try to connect
		$this->connect();
		//error_log( 'Start connection to IMAP inbox' );

		// Total duration we should keep the IMAP stream alive for in seconds
		$duration = bp_rbe_get_execution_time();

		// Mark the current timestamp, mark the future time when we should close the IMAP connection;
		// Do our parsing until $future > $now; re-mark the timestamp at end of loop... rinse and repeat!
		for ( $now = time(), $future = time() + $duration; $future > $now; $now = time() ) :

			// Get number of messages
			$msg_num = imap_num_msg( $this->imap );

			// If there are messages in the inbox, let's start parsing!
			if( $msg_num != 0 ) :

				// According to this:
				// http://www.php.net/manual/pl/function.imap-headerinfo.php#95012
				// This speeds up rendering the email headers... could be wrong
				imap_headers( $this->imap );

				// Loop through each email message
				for ( $i = 1; $i <= $msg_num; ++$i ) :

					$headers = $this->header_parser( $this->imap, $i );

					//error_log( 'Message #' . $i . ': headers - start parsing' );

					if ( !$headers ) {
						do_action( 'bp_rbe_imap_no_match', $this->imap, $i, false, 'no_headers' );
						continue;
					}

					//error_log( 'Message #' . $i . ': get user id' );

					// Grab user ID via "From" email address
					if ( !function_exists( 'email_exists' ) )
						require_once( ABSPATH . WPINC . '/registration.php' );

					$user_id = email_exists( $this->address_parser( $headers, 'From' ) );

					if ( !$user_id ) {
						do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'no_user_id' );
						continue;
					}

					//error_log( 'Message #' . $i . ': get address tag' );

					// Grab address tag from "To" email address
					$qs = $this->get_address_tag( $this->address_parser( $headers, 'To' ) );

					if ( !$qs ) {
						do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'no_address_tag' );
						continue;
					}

					// Parse our encoded querystring into variables
					// Check if we're posting a new item or not
					if ( $this->is_new_item( $qs ) )
						$params = $this->querystring_parser( $qs, $user_id );
					else
						$params = $this->querystring_parser( $qs );

					//error_log( 'Message #' . $i . ': params = ' . print_r($params, true) );

					if ( !$params ) {
						do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'no_params' );
						continue;
					}

					//error_log( 'Message #' . $i . ': attempting to parse body' );

					// Parse email body
					$body = $this->body_parser( $this->imap, $i );

					// If there's no email body and this is a reply, stop!
					if ( !$body && !$this->is_new_item( $qs ) ) {
						do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'no_reply_body' );
						continue;
					}

					// Extract each param into its own variable
					extract( $params, EXTR_SKIP );

					// Activity reply
					if ( !empty( $a ) ) :

						// Check to see if the root activity ID and the parent activity ID exist before posting
						// "show_hidden" is used for BP 1.3 compatibility
						$activities_exist = bp_activity_get_specific( 'show_hidden=true&activity_ids=' . $a . ',' . $p );

						// If count != 2, this means either the super admin or activity author deleted the update(s)
						// If so, do not post the reply!
						if ( $activities_exist['total'] != 2 ) {
							do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'root_or_parent_activity_deleted' );
							continue;
						}

						/* Let's start posting! */
						// Add our filter to override the activity action in bp_activity_new_comment()
						bp_rbe_activity_comment_action_filter( $user_id );

						bp_activity_new_comment(
							 array(
								'content'	=> $body,
								'user_id'	=> $user_id,
								'activity_id'	=> $a,	// ID of the root activity item
								'parent_id'	=> $p	// ID of the parent comment
							)
						);

						// remove the filter after posting
						remove_filter( 'bp_activity_comment_action', 'bp_rbe_activity_comment_action' );
						unset( $activities_exist );

					// Forum reply
					elseif ( !empty( $t ) ) :

						if ( bp_is_active( $bp->groups->id ) && bp_is_active( $bp->forums->id ) ) :

							// If user is a member of the group and not banned, then let's post the forum reply!
							if ( groups_is_user_member( $user_id, $g ) && !groups_is_user_banned( $user_id, $g ) ) {
								$forum_post_id = bp_rbe_groups_new_group_forum_post( $body, $t, $user_id, $g );

								if ( !$forum_post_id ) {
									do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'forum_reply_fail' );
									continue;
								}

								// could potentially add attachments
								do_action( 'bp_rbe_email_new_forum_post', $this->imap, $i, $forum_post_id, $g, $user_id );
							}
						endif;

					// Private message reply
					elseif ( !empty( $m ) ) :
						if ( bp_is_active( $bp->messages->id ) ) :
							messages_new_message (
								array(
									'thread_id'	=> $m,
									'sender_id'	=> $user_id,
									'content'	=> $body
								)
							);
						endif;

					// New forum topic
					elseif ( !empty( $g ) ) :

						if ( bp_is_active( $bp->groups->id ) && bp_is_active( $bp->forums->id ) ) :
							$body		= $this->body_parser( $this->imap, $i, false );
							$subject	= $this->address_parser( $headers, 'Subject' );

							if ( empty( $body ) || empty( $subject ) ) {
								do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'new_forum_topic_empty' );
								continue;
							}

							// If user is a member of the group and not banned, then let's post the forum topic!
							if ( groups_is_user_member( $user_id, $g ) && !groups_is_user_banned( $user_id, $g ) ) {
								$topic = bp_rbe_groups_new_group_forum_topic( $subject, $body, false, false, $user_id, $g );

								if ( !$topic ) {
									do_action( 'bp_rbe_imap_no_match', $this->imap, $i, $headers, 'new_topic_fail' );
									continue;
								}

								// could potentially add attachments
								do_action_ref_array( 'bp_rbe_email_new_forum_topic', array( $this->imap, $i, &$topic, $g, $user_id ) );
							}
						endif;
					endif;

					// Do something at the end of the loop; useful for 3rd-party plugins
					do_action( 'bp_rbe_imap_loop', $this->imap, $i, $params, $body, $user_id );

					// Unset some variables to clear some memory
					unset( $headers );
					unset( $params );
					unset( $qs );
					unset( $body );
				endfor;

			endif;

			// do something after the loop
			do_action( 'bp_rbe_imap_after_loop', $this->imap );

			// stop the loop if necessary
			if ( $this->should_stop() ) {
				$this->close();
				//error_log( 'bp-rbe: Manual deactivate confirmed! Kaching!' );
				return;
			}

			// Give IMAP server a break
			sleep( 10 );

			// If the IMAP connection is down, reconnect
			if( !imap_ping( $this->imap ) )
				$this->connect();


			// Unset some variables to clear some memory
			unset( $msg_num );
		endfor;

		//error_log( 'Close connection to IMAP inbox' );

		$this->close();
	}

	/**
	 * Connects to the IMAP inbox.
	 */
	function connect() {
		global $bp_rbe;

		// Imap connection is already established!
		if ( is_resource( $this->imap ) )
			return;

		// Need to readjust this before public release
		// In the meantime, let's add a filter!
		$hostname = '{' . $bp_rbe->settings['servername'] . ':' . $bp_rbe->settings['port'] . '/imap/ssl}INBOX';
		$hostname = apply_filters( 'bp_rbe_hostname', $hostname );

		// Let's open the IMAP stream!
		$this->imap = imap_open( $hostname, $bp_rbe->settings['username'], $bp_rbe->settings['password'] ) or die( 'Cannot connect: ' . imap_last_error() );
	}

	/**
	 * Closes the IMAP connection.
	 */
	function close() {
		// Do something before closing
		do_action( 'bp_rbe_imap_before_close', $this->imap );

		imap_close( $this->imap );
	}

	/**
	 * Returns true when the main IMAP loop should finally stop in our version of a poor man's daemon.
	 *
	 * Info taken from Christopher Nadeau's post - {@link http://devlog.info/2010/03/07/creating-daemons-in-php/#lphp-4}.
	 *
	 * @see bp_rbe_stop_imap()
	 * @uses clearstatcache() Clear stat cache. Needed when using file_exists() in a script like this.
	 * @uses file_exists() Checks to see if our special txt file is created.
	 * @uses unlink() Deletes this txt file so we can do another check later.
	 * @return bool
	 */
	function should_stop() {
		clearstatcache();

		if ( file_exists( BP_AVATAR_UPLOAD_PATH . '/bp-rbe-stop.txt' ) ) {
			unlink( BP_AVATAR_UPLOAD_PATH . '/bp-rbe-stop.txt' ); // delete the file for next time
			return true;
		}

		return false;
	}

	/**
	 * Grabs and parses an email message's header and returns an array with each header item.
	 *
	 * @uses imap_fetchheader() Grabs full, raw unmodified email header
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @return mixed Array of email headers. False if no headers or if the email is junk.
	 */
	function header_parser( $imap, $i ) {
		// Grab full, raw email header
		$header = imap_fetchheader( $imap, $i );

		// Do a regex match
		$pattern = apply_filters( 'bp_rbe_header_regex', '/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m' );
		preg_match_all( $pattern, $header, $matches );

		// Parse headers into an array with descriptive key
		$headers = array_combine( $matches[1], $matches[2] );

		// No headers? Return false
		if ( empty( $headers ) )
			return false;

		// Test to see if our email is an auto-reply message
		// If so, return false
		if ( !empty( $headers['X-Autoreply'] ) && $headers['X-Autoreply'] == 'yes' )
			return false;

		// Test to see if our email is an out of office automated reply or mailing list email
		// If so, return false
		// See http://en.wikipedia.org/wiki/Email#Header_fields
		if ( !empty( $headers['Precedence'] ) ) :
			switch ( $headers['Precedence'] ) {
				case 'bulk' :
				case 'junk' :
				case 'list' :
					return false;
				break;
			}
		endif;

		// Want to do more checks? Here's the filter!
		return apply_filters( 'bp_rbe_parse_email_headers', $headers, $header );
	}

	/**
	 * Parses the plain text body of an email message.
	 *
	 * @uses imap_qprint() Convert email body from quoted-printable string to an 8 bit string
	 * @uses imap_fetchbody() Using the third parameter with value "1" returns the plain text body only
	 * @param resource $imap The current IMAP connection
	 * @param int $i The current email message number
	 * @param bool $reply If we're parsing a reply or not. Default set to true.
	 * @return mixed Either the email body on success or false on failure
	 */
	function body_parser( $imap, $i, $reply = true ) {
		// Grab the plain text of the email message
		$body = imap_qprint( imap_fetchbody( $imap, $i, 1 ) );

		// Check to see if we're parsing a reply
		if ( $reply ) {

			// Find our pointer
			$pointer = strpos( $body, __( '--- Reply ABOVE THIS LINE to add a comment ---', 'bp-rbe' ) );

			// If our pointer isn't found, return false
			if ( $pointer === false )
				return false;

			// Return email body up to our pointer only
			$body = apply_filters( 'bp_rbe_parse_email_body_reply', trim( substr( $body, 0, $pointer ) ), $body );
		}

		if ( empty( $body ) )
			return false;

		return apply_filters( 'bp_rbe_parse_email_body', trim( $body ) );
	}

	/**
	 * Parses an email header to return just the email address.
	 *
	 * eg. r-a-y <test@gmail.com> -> test@gmail.com
	 *
	 * @param array $headers The array of email headers
	 * @param string $key The key we want to check against the array.
	 * @return mixed Either the email address on success or false on failure
	 */
	function address_parser( $headers, $key ) {
		if ( empty( $headers[$key] ) || strpos( $headers[$key], '@' ) === false )
			return false;

		// Sender is attempting to send to multiple recipients in the "To" header
		// A legit BP reply will not add multiple recipients, so let's return false
		if ( $key == 'To' && strpos( $headers['To'], ',' ) !== false )
			return false;

		// grab email address in between triangular brackets if they exist
		// strip the rest
		$lbracket = strpos( $headers[$key], '<' );

		if ( $lbracket !== false ) {
			$rbracket = strpos( $headers[$key], '>' );

			$headers[$key] = substr( $headers[$key], ++$lbracket, $rbracket - $lbracket );
		}

		return $headers[$key];
	}

	/**
	 * Returns the address tag from an email address.
	 *
	 * eg. test+tag@gmail.com> -> tag
	 * In BP Reply By Email IMAP, this is an encoded querystring.
	 *
	 * @param string $address The email address containing the address tag
	 * @return mixed Either the address tag on success or false on failure
	 */
	function get_address_tag( $address ) {
		global $bp_rbe;

		// $address might already be false, so let's return false right away
		if ( !$address )
			return false;

		$at	= strpos( $address, '@' );
		$tag	= strpos( $address, $bp_rbe->settings['tag'] );

		if ( $at === false || $tag === false )
			return false;

		return substr( $address, ++$tag, $at - $tag );
	}

	/**
	 * Decodes the encoded querystring from {@link BP_Reply_By_Email_IMAP::get_address_tag()}.
	 * Then, extracts the params into an array.
	 *
	 * @uses bp_rbe_decode() To decode the encoded querystring
	 * @uses wp_parse_str() WP's version of parse_str() to parse the querystring
	 * @param string $qs The encoded address tag we want to decode
	 * @return mixed Either an array of params on success or false on failure
	 */
	function querystring_parser( $qs, $user_id = false ) {

		// New posted items will pass $user_id along with $qs for decoding
		// This is done as an additional security measure because the "From" header
		// can be spoofed and is similar to how Basecamp handles posting new items
		if ( $user_id ) {
			// check to see if $user_id is numeric, if not, return false
			if ( !is_numeric( $user_id ) )
				return false;

			// new items will always have "-new" appended to the querystring
			$new = strrpos( $qs, '-new' );

			if ( $new !== false ) {
				// get rid of "-new" from the querystring
				$qs = substr( $qs, 0, $new );

				// pass $user_id to bp_rbe_decode()
				$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( $qs, $user_id ), $qs, $user_id );
			}
			else
				return false;
		}

		// Replied items will use the regular $qs for decoding
		else {
			$qs = apply_filters( 'bp_rbe_decode_qs', bp_rbe_decode( $qs ), $qs, $user_id );
		}

		// These are the default params we want to check for
		$defaults = array(
			'a' => false,	// root activity id
			'p' => false,	// direct parent activity id
			't' => false,	// topic id
			'm' => false,	// message thread id
			'g' => false	// group id
		);

		// Let 3rd-party plugins whitelist additional params
		$defaults = apply_filters( 'bp_rbe_allowed_params', $defaults );

		// Parse querystring into an array
		wp_parse_str( $qs, $params );

		// Only allow parameters set from $defaults through
		$params = array_intersect_key( $params, $defaults );

		// If no params, return false
		if ( empty( $params ) )
			return false;

		return $params;
	}

	/**
	 * Check to see if we're parsing a new item (like a new forum topic).
	 *
	 * New items will always have "-new" appended to the address tag. This is what we're checking for.
	 * eg. djlkjkdjfkd-new = true
	 *     jkljd8fujkdjkdf = false
	 *
	 * @param string $tag The address tag we're checking for.
	 * @return bool
	 */
	function is_new_item( $qs ) {
		$new = '-new';

		if ( substr( $qs, -strlen( $new ) ) == $new )
			return true;

		return false;
	}
}


/**
 * Class: BP_Reply_By_Email_Admin
 *
 * Handles creation of the admin page.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 * @todo Make admin settings network-aware. I started building this admin page before I knew the Settings API wasn't network-aware!
 */
class BP_Reply_By_Email_Admin {

	var $name;
	var $settings;

	/**
	 * PHP4 constructor.
	 */
	function BP_Reply_By_Email_Admin() {
		$this->__construct();
	}

	/**
	 * PHP5 constructor.
	 */
	function __construct() {
		$this->name = 'bp-rbe';

		add_action( 'admin_init', array( &$this, 'init' ) );
		add_action( is_multisite() && function_exists( 'network_admin_menu' ) ? 'network_admin_menu' : 'admin_menu', array( &$this, 'setup_admin' ) );
	}

	/**
	 * Setup our items when a user is in the WP backend.
	 */
	function init() {
		// grab our settings when we're in the admin area only
		$this->settings = get_option( $this->name );

		// handles niceties like nonces and form submission
		register_setting( $this->name, $this->name, array( &$this, 'validate' ) );

		// add extra action links for our plugin
		add_filter( 'plugin_action_links', array( &$this, 'add_plugin_action_links' ), 10, 2 );
	}

	/**
	 * Setup our admin settings page and hooks
	 */
	function setup_admin() {
		$page = add_submenu_page( 'bp-general-settings', __( 'BuddyPress Reply By Email', 'bp-rbe' ), __( 'Reply By Email', 'bp-rbe' ), 'manage_options', 'bp-rbe', array( &$this, 'load' ) );

		//add_action( "admin_head-{$page}",	array( &$this, 'head' ) );
		add_action( "admin_footer-{$page}",	array( &$this, 'footer' ) );
	}

	/**
	 * Add extra links for RBE on the WP Admin plugins page
	 *
	 * @param array $links Plugin action links
	 * @param string $file A plugin's loader base filename
	 */
	function add_plugin_action_links( $links, $file ) {
		$plugin_basename	= plugin_basename(__FILE__);
		$plugin_basedir		= substr( $plugin_basename, 0, strpos( $plugin_basename, '/' ) );

		// Do not do anything for other plugins
		if ( $plugin_basedir . '/loader.php' != $file )
			return $links;

		$path = 'admin.php?page=bp-rbe';

		// Backwards compatibility with older WP versions
		$admin_page = is_multisite() && function_exists( 'is_network_admin' ) ? network_admin_url( $path ) : admin_url( $path );

		// Settings link - move to front
		$settings_link = sprintf( '<a href="%s">%s</a>', $admin_page, __( 'Settings', 'bp-rbe' ) );
		array_unshift( $links, $settings_link );

		// Donate link
		$links[] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=V9AUZCMECZEQJ" target="_blank">Donate!</a>';

		return $links;
	}

	/**
	 * JS hooked to footer of our settings page
	 */
	function footer() {
	?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {

			// hide fields if gmail setting is checked
			var gmail = $( '#bp-rbe-gmail:checked' ).val();

			if ( gmail == 1 ) {
				$( 'tr.bp-rbe-servername' ).hide();
				$( 'tr.bp-rbe-port' ).hide();
				$( 'tr.bp-rbe-tag' ).hide();
				$( 'tr.bp-rbe-email' ).hide();
			}

			// toggle fields based on gmail setting
			$( '#bp-rbe-gmail' ).change(function(){
				$( 'tr.bp-rbe-servername' ).toggle();
				$( 'tr.bp-rbe-port' ).toggle();
				$( 'tr.bp-rbe-tag' ).toggle();
				$( 'tr.bp-rbe-email' ).toggle();
			});
		});
		</script>
	<?php
	}

	/**
	 * Validate and sanitize our options before saving to the DB.
	 * Callback from register_setting().
	 *
	 * @param array $input The submitted values from the form
	 * @return array $output The sanitized and validated values from the form ready to be inserted into the DB
	 */
	function validate( $input ) {
		$output = array();

		$username = wp_filter_nohtml_kses( $input['username'] );
		$password = wp_filter_nohtml_kses( $input['password'] );

		if ( $email = is_email( $input['email'] ) ) {
			$output['email'] = $email;

			if ( $input['gmail'] == 1 )
				$output['username'] = $email;
		}

		if ( !empty( $username ) ) {
			$output['username'] = $username;

			if ( is_email( $username ) && $input['gmail'] == 1 )
				$output['email'] = $username;
		}

		if ( !empty( $password ) )
			$output['password']	= $password;

		// check if port is numeric
		if ( is_numeric( $input['port'] ) )
			$output['port']		= $input['port'];

		// check if address tag is one character
		if ( strlen( $input['tag'] ) == 1 )
			$output['tag']		= $input['tag'];

		// override certain settings if "gmail" setting is true
		if ( $input['gmail'] == 1 ) {
			$output['servername']	= 'imap.gmail.com';
			$output['port']		= 993;
			$output['tag']		= '+';
			$output['gmail']	= 1;
		}
		// if unchecked, reset these settings
		elseif ( $output['port'] || $output['servername'] || $output['tag'] ) {
			unset( $output['port'] );
			unset( $output['servername'] );
			unset( $output['tag'] );
		}

		// check if key is alphanumeric
		if ( ctype_alnum( $input['key'] ) )
			$output['key']		= $input['key'];

		if ( is_numeric( $input['keepalive'] ) && $input['keepalive'] < 30 )
			$output['keepalive']	= $input['keepalive'];

		// keepalive for safe mode will never exceed the execution time
		if ( ini_get( 'safe_mode' ) )
			$output['keepalive']	= $input['keepalive'] = bp_rbe_get_execution_time( 'minutes' );

		/* error time! */

		if ( strlen( $input['tag'] ) > 1 && !$output['tag'] )
			$messages['tag_error']	= __( 'Error: <strong>Address tag</strong> must only be one character.', 'bp-rbe' );

		if ( !empty( $input['port'] ) && !is_numeric( $input['port'] ) && !$output['port'] )
			$messages['port_error'] = __( 'Error: <strong>Port</strong> must be numeric.', 'bp-rbe' );

		if ( !empty( $input['key'] ) && !$output['key'] )
			$messages['key_error']	= __( 'Error: <strong>Key</strong> must only contain letters and / or numbers.', 'bp-rbe' );

		if ( !empty( $input['keepalive'] ) && !is_numeric( $input['keepalive'] ) && !$output['keepalive'] )
			$messages['keepalive_error']	= __( 'Error: <strong>Keep Alive Connection</strong> value must be less than 30.', 'bp-rbe' );

		if ( is_array( $messages ) )
			 $output['messages'] = $messages;

		return $output;
	}

	/**
	 * Output the admin page.
	 */
	function load() {
	?>
		<div class="wrap">
			<?php screen_icon('edit-comments'); ?>

			<h2><?php _e( 'Reply By Email Settings', 'bp-rbe' ) ?></h2>

			<?php $this->display_errors() ?>

			<?php $this->webhost_warnings() ?>

			<form action="options.php" method="post">
				<?php settings_fields( $this->name ); ?>

				<?php $this->schedule(); ?>

				<h3>Email Server Settings</h3>

				<p><?php _e( 'Please enter the IMAP settings for your email account.  Fields marked with * are required.', 'bp-rbe' ) ?></p>

				<table class="form-table">
					<?php $this->render_field(
						array(
							'type'		=> 'checkbox',
							'name'		=> 'gmail',
							'labelname'	=> __( 'GMail / Google Apps Mail', 'bp-rbe' ),
							'desc'		=> sprintf( __( 'Using GMail? (This will override the Server Name, Port and Address Tag fields on save.) Also make sure to <a href="%s" target="_blank">enable IMAP in GMail</a>.', 'bp-rbe' ), 'http://mail.google.com/support/bin/answer.py?answer=77695' )
						) ) ?>

					<?php $this->render_field(
						array(
							'name'		=> 'servername',
							'labelname'	=> __( 'Server Name *', 'bp-rbe' )
						) ) ?>

					<?php $this->render_field(
						array(
							'name'		=> 'port',
							'labelname'	=> __( 'Port *', 'bp-rbe' ),
							'size'		=> 'small'
						) ) ?>

					<?php $this->render_field(
						array(
							'name'		=> 'tag',
							'labelname'	=> __( 'Address Tag Separator *', 'bp-rbe' ),
							'desc'		=> sprintf( __( 'Example: test@gmail.com is my email address, I can also receive email that is sent to test+anything@gmail.com.  The address tag separator for GMail is "+".  For other email providers, <a href="%s" target="_blank">view this page</a>.', 'bp-rbe' ), 'http://en.wikipedia.org/wiki/Email_address#Address_tags' ),
							'size'		=> 'small'
						) ) ?>

					<?php $this->render_field(
						array(
							'name'		=> 'username',
							'labelname'	=> __( 'Username *', 'bp-rbe' )
						) ) ?>

					<?php $this->render_field(
						array(
							'type'		=> 'password',
							'name'		=> 'password',
							'labelname'	=> __( 'Password *', 'bp-rbe' )
						) ) ?>

					<?php $this->render_field(
						array(
							'name'		=> 'email',
							'labelname'	=> __( 'Email Address', 'bp-rbe' ),
							'desc'		=> __( 'If your username is <strong>not</strong> the same as your email address, please fill in this field as well.', 'bp-rbe' )
						) ) ?>

				</table>

				<h3><?php _e( 'Other Settings', 'bp-rbe' ) ?></h3>

				<table class="form-table">
					<?php $this->render_field(
						array(
							'name'		=> 'key',
							'labelname'	=> __( 'Key *', 'bp-rbe' ),
							'desc'		=> __( 'This key is used to verify incoming emails before anything is posted to BuddyPress.  By default, a key is randomly generated for you.  You should rarely have to change your key.  However, if you want to change it, please type in an alphanumeric key of your choosing.', 'bp-rbe' )
						) ) ?>

					<?php $this->render_field(
						array(
							'name'		=> 'keepalive',
							'labelname'	=> __( 'Keep Alive Connection *', 'bp-rbe' ),
							'desc'		=> sprintf( __( 'The length in minutes to stay connected to your inbox. Due to <a href="%s" target="_blank">RFC 2177 protocol</a>, this value cannot be larger than 29. If this value is changed, this will take effect on the next scheduled update.', 'bp-rbe' ), 'http://tools.ietf.org/html/rfc2177#page-2' ),
							'size'		=> 'small'
						) ) ?>
				</table>

				<table class="form-table">
					<tr><td>
					<p class="submit"><input type="submit" name="submit" class="button-primary" value="<?php _e( 'Save Changes', 'bp-rbe' ) ?>" /></p>
					</td></tr>
				</table>
			</form>

			<form action="https://www.paypal.com/cgi-bin/webscr" method="post" style="margin-left:7px;">
				<input type="hidden" name="cmd" value="_s-xclick" />
				<input type="hidden" name="hosted_button_id" value="V9AUZCMECZEQJ" />
				<input title="<?php _e( 'If you\'re a fan of this plugin, support further development with a donation!', 'bp-rbe' ); ?>" type="image" src="http<?php if ( is_ssl() ) echo 's'; ?>://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" name="submit" alt="PayPal - The safer, easier way to pay online!" />
				<img alt="" src="http<?php if ( is_ssl() ) echo 's'; ?>://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1" />
			</form>

			<!-- Output options data, so we can see how it currently looks -->
			<pre><?php print_r( $this->settings ) ?></pre>
		</div>
	<?php
	}

	/**
	 * Alternative approach to WP Settings API's add_settings_error().
	 *
	 * Show any messages/errors saved to a setting during validation in {@link BP_Reply_By_Email::validate()}.
	 * Used as a template tag.
	 *
	 * Uses a ['messages'] array inside $this->settings.
	 * Format should be ['messages'][{$id}_error], $id is the setting id.
	 *
	 * Lightly modified from Jeremy Clark's observations - {@link http://old.nabble.com/Re%3A-Settings-API%3A-Showing-errors-if-validation-fails-p26834868.html}
	 */
	function display_errors() {
		$option = $this->settings;

		// output error message(s)
		if ( is_array( $option['messages']) ) :
			foreach ( (array) $option['messages'] as $id => $message ) :
				echo "<div id='message' class='error fade $slug'><p>$message</p></div>";
				unset( $option['messages'][$id] );
			endforeach;

			update_option( $this->name, $option );

		// success!
		elseif ( esc_attr( $_REQUEST['updated'] ) ) :
			echo '<div id="message" class="updated"><p>' . __( 'Settings updated successfully!', 'bp-rbe' ) . '</p></div>';
		endif;
	}

	/**
	 * Adds webhost warnings to the admin page.
	 *
	 * If certain conditions for the webhost are not met, these warnings will be displayed on the admin page.
	 */
	function webhost_warnings() {
		$warnings = array();

		if ( !function_exists( 'imap_open' ) )
			$warnings[]	= __( 'IMAP extension for PHP is <strong>disabled</strong>.  This plugin will not run without it.  Please contact your webhost to enable this.', 'bp-rbe' );

		if ( ini_get('safe_mode') )
			$warnings[]	= sprintf( __( 'PHP Safe Mode is <strong>on</strong>.  This is not a dealbreaker, but this means you cannot set the Keep Alive Connection value larger than %d minutes.', 'bp-rbe' ), bp_rbe_get_execution_time( 'minutes' ) );

		if ( !empty( $warnings ) )
			echo '<h3>' . __( 'Webhost Warnings', 'bp-rbe' ) . '</h3>';

		foreach ( $warnings as $warning ) :
			echo '<div class="error"><p>' . $warning . '</p></div>';
		endforeach;
	}

	/**
	 * Outputs next scheduled run of the (pseudo) cron.
	 */
	function schedule() {

		// bp_rbe_is_required_completed() currently assumes that you've entered in
		// the correct IMAP server info...
		if ( bp_rbe_is_required_completed() ) :
			$next = wp_next_scheduled( 'bp_rbe_schedule' );
			
			if ( $next ) :
	?>
		<h3><?php _e( 'Schedule Info', 'bp-rbe' ); ?></h3>

		<p>
			<?php printf( __( '<strong>Reply By Email</strong> is currently checking your inbox continuously. The next scheduled stop and restart is <strong>%s</strong>.', 'bp-rbe' ), date("l, F j, Y, g:i a (e)", $next ) ) ?>
		</p>

		<p>
			<?php printf( __( 'What this means is a user will need to visit your website after %s in order for the plugin to check your inbox again. This is a limitation of Wordpress\' scheduling API.', 'bp-rbe' ), date("g:i a (e)", $next ) ) ?>
		</p>

		<p>
			<?php printf( __( 'View the <em>"WordPress\' pseudo-cron and workaround"</em> section in the <a href="%s">readme</a> for a potential solution.', 'bp-rbe' ), BP_RBE_DIR . 'readme.txt' ) ?>
		</p>

	<?php
			endif;
		endif;
	}

	/**
	 * Renders the output of a form field in the admin area. I like this better than add_settings_field() so sue me!
	 * Uses {@link BP_Reply_By_Email_Admin::field()} and {@link BP_Reply_By_Email_Admin::get_option()}.
	 *
	 * @param array $args Arguments for the field
	 */
	function render_field( $args = '' ) {
		$defaults = array(
			'type'		=> 'text',	// text, password, checkbox, radio, dropdown
			'labelname'	=> '',		// the label for the field
			'labelfor'	=> true,	// should <label> be used?
			'name'		=> '',		// the input name of the field
			'desc'		=> '',		// used to describe a checkbox, radio or option value
			'size'		=> 'regular',	// text field size - small
			'options'	=> array()	// options for checkbox, radio, select - not used currently
		);

		$r = wp_parse_args( $args, $defaults );
		extract( $r, EXTR_SKIP );

		echo '<tr valign="top" class="' . $this->field( $name, true, false ). '">';

		if ( $labelfor )
			echo '<th scope="row"><label for="' . $this->field( $name, true, false ) . '">' . $labelname . '</label></th>';
		else
			echo '<th scope="row">' . $labelname . '</th>';

		echo '<td>';

		switch ( $type ) {
			case 'checkbox' :
			?>
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo $labelname; ?></span></legend>

					<label for="<?php $this->field( $name, true ) ?>">
						<input type="checkbox" name="<?php $this->field( $name ) ?>" id="<?php $this->field( $name, true ) ?>" value="1" <?php checked( $this->settings[$name], 1 ); ?> />

						<?php echo $desc; ?>
				</label>
				<br />
				</fieldset>
			<?php
			break;

			case 'text' :
			case 'password' :
			?>
				<input class="<?php echo $size; ?>-text" value="<?php $this->get_option( $name ) ?>" name="<?php $this->field( $name ) ?>" id="<?php $this->field( $name, true ) ?>" type="<?php echo $type; ?>" />
			<?php
				if ( $desc )
					echo '<span class="setting-description">' . $desc . '</span>';
			break;
		}

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Returns or outputs a field name / ID in the admin area.
	 *
	 * @param string $name Input name for the field.
	 * @param bool $id Are we outputting the field's ID?  If so, output unique ID.
	 * @param bool $echo Are we echoing or returning?
	 * @return mixed Either echo or returns a string
	 */
	function field( $name, $id = false, $echo = true ) {
		$name = $id ? "{$this->name}-{$name}" : "{$this->name}[$name]";

		if( $echo )
			echo $name;

		else
			return $name;
	}

	/**
	 * Returns or outputs an admin setting in the admin area.
	 *
	 * Uses settings declared in $this->settings.
	 *
	 * @param string $name Name of the setting.
	 * @param bool $echo Are we echoing or returning?
	 * @return mixed Either echo or returns a string
	 */
	function get_option( $name, $echo = true ) {
		$val = '';

		if( is_array( $this->settings ) && isset( $this->settings[$name] ) )
			$val = $this->settings[$name];

		if( $echo )
			esc_attr_e( $val );
		else
			return esc_attr( $val );
	}
}

?>