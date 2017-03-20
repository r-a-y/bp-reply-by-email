<?php
/**
 * BP Reply By Email Admin
 *
 * @package BP_Reply_By_Email
 * @subpackage Admin
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles all admin aspects of BP Reply By Email.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 * @todo Make admin settings network-aware. I started building this admin page before I knew the Settings API wasn't network-aware!
 */
class BP_Reply_By_Email_Admin {

	/**
	 * Internal name.
	 * @var string
	 */
	protected $name = 'bp-rbe';

	/**
	 * Settings from database.
	 * @var array
	 */
	protected $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_menu', array( $this, 'setup_admin' ) );

		add_action( 'wp_ajax_bp_rbe_admin_connect',        array( $this, 'ajax_connect' ) );
		add_action( 'wp_ajax_bp_rbe_admin_connect_notice', array( $this, 'ajax_connect_notice' ) );
	}

	/**
	 * Setup our items when a user is in the WP backend.
	 */
	public function admin_init() {
		// grab our settings when we're in the admin area only
		$this->settings = bp_get_option( $this->name );

		// handles niceties like nonces and form submission
		register_setting( $this->name, $this->name, array( $this, 'validate' ) );

		// add extra action links for our plugin
		add_filter( 'plugin_action_links',  array( $this, 'add_plugin_action_links' ), 10, 2 );

		// include github updater only if CBOX isn't installed
		// or if CBOX is installed and expert mode is on
		if ( ! function_exists( 'cbox' ) ||
			( function_exists( 'cbox' ) && defined( 'CBOX_OVERRIDE_PLUGINS' ) && constant( 'CBOX_OVERRIDE_PLUGINS' ) === true )
		) {
			$this->github_updater();
		}
	}

	/**
	 * Setup our admin settings page and hooks
	 */
	public function setup_admin() {

		// Temporary workaround for the fact that WP's settings API doesn't work with MS
		if ( bp_core_do_network_admin() ) {
			if ( !bp_is_root_blog() ) {
				return;
			}
			$parent = 'options-general.php';
		} else {
			$parent = 'bp-general-settings';
		}

		$page = add_submenu_page( $parent, __( 'BuddyPress Reply By Email', 'bp-rbe' ), __( 'BP Reply By Email', 'bp-rbe' ), 'manage_options', 'bp-rbe', array( $this, 'load' ) );

		add_action( "admin_head-{$page}",   array( $this, 'head' ) );
		add_action( "admin_footer-{$page}", array( $this, 'footer' ) );
	}

	/**
	 * Add extra links for RBE on the WP Admin plugins page
	 *
	 * @param array $links Plugin action links
	 * @param string $file A plugin's loader base filename
	 */
	public function add_plugin_action_links( $links, $file ) {

		// Do not do anything for other plugins
		if ( $this->get_plugin_basename() . '/loader.php' != $file )
			return $links;

		$path = 'admin.php?page=bp-rbe';

		// Backwards compatibility with older WP versions
		//$admin_page = is_multisite() && function_exists( 'is_network_admin' ) ? network_admin_url( $path ) : admin_url( $path );

		// Settings link - move to front
		$settings_link = sprintf( '<a href="%s">%s</a>', $path, __( 'Settings', 'bp-rbe' ) );
		array_unshift( $links, $settings_link );

		// Donate link
		$links[] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=V9AUZCMECZEQJ" target="_blank">Donate!</a>';

		return $links;
	}

	/**
	 * Checks a JSON file to see if our Github repo should be updated.
	 *
	 * Uses the Plugin Update Checker library by Janis Elsts.
	 * Licensed under the GPL.  Slightly modified by me for better Github support.
	 *
	 * @since 1.0-RC2
	 * @link https://github.com/YahnisElsts/plugin-update-checker
	 */
	protected function github_updater() {
		if ( ! class_exists( 'PluginUpdateChecker' ) ) {
			require( BP_RBE_DIR . '/includes/class.plugin-update-checker.php' );
		}

		$github_updater = new PluginUpdateChecker(
			// JSON file that gets updated when we release a new version of RBE
			'https://gist.githubusercontent.com/r-a-y/610fe45a0c5ed6344be5/raw',
			constant( 'BP_RBE_DIR' ) . '/loader.php',
			$this->get_plugin_basename()
		);

	}

	/**
	 * Inline CSS hooked to head of our settings page
	 */
	public function head() {
	?>
		<style type="text/css">
			p.connected span, p.not-connected span {font-weight:bold;}
			p.connected span     {color:green;}
			p.not-connected span {color:red;}
			div.imap-options .spinner {float:left; margin-top:0; margin-left:0; display:none;}
		</script>
	<?php
	}

	/**
	 * JS hooked to footer of our settings page
	 */
	public function footer() {
	?>
		<script type="text/javascript">
		jQuery(function($) {
			var mode  = $( '#bp-rbe-mode' );
			var gmail = $( '#bp-rbe-gmail' );

			// hide gmail username description by default
			$( '.bp-rbe-username span' ).hide();

			// mode - hide fields based on selection
			if ( mode.find('option:selected').val() == 'imap' ) {
				$( 'div.inbound-options, span.inbound-options' ).hide();
			} else {
				$( 'div.imap-options, span.imap-options' ).hide();
			}

			// mode - toggle fields based on selection
			mode.change(function() {
				$( 'div.inbound-options, span.inbound-options' ).toggle();
				$( 'div.imap-options, span.imap-options' ).toggle();
			});

			// gmail - hide fields based on gmail selection
			if ( gmail.filter(':checked').val() == 1 ) {
				$( 'tr.bp-rbe-servername' ).hide();
				$( 'tr.bp-rbe-port' ).hide();
				$( 'tr.bp-rbe-tag' ).hide();
				$( 'tr.bp-rbe-email' ).hide();
				$( '.bp-rbe-username span' ).show();
			}

			// gmail - toggle fields based on selection
			gmail.change(function(){
				$( 'tr.bp-rbe-servername' ).toggle();
				$( 'tr.bp-rbe-port' ).toggle();
				$( 'tr.bp-rbe-tag' ).toggle();
				$( 'tr.bp-rbe-email' ).toggle();
				$( '.bp-rbe-username span' ).toggle();
			});

			// Run inbox check.
			$('button.connect').on('click', function(e) {
				var btn = $(this);
				btn.prop( 'disabled', true );
				$('.imap-options .spinner').show();
				$('.error-rbe').remove();

				// Ping inbox check.
				$.post( {
					url: ajaxurl,
					data: {
						action: 'bp_rbe_admin_connect',
						'_wpnonce': $('#bp-rbe-ajax-connect-nonce').val()
					},
					timeout: 500
				} );

				// Run another AJAX to check if connected after five seconds.
				setTimeout( function() {
					$.post( ajaxurl, {
						action: 'bp_rbe_admin_connect_notice',
						'_wpnonce': $('#bp-rbe-ajax-connect-nonce').val()
					},
					function(response) {
						$('.imap-options .spinner').hide();

						if ( response.success ) {
							$('p.not-connected').removeClass('not-connected').addClass('connected').html( response.data.msg );
							$('button.connect').hide();
						} else {
							btn.prop( 'disabled', false );

							$( 'div.wrap form:first' ).before( '<div class="error error-rbe">' + response.data.msg + '</div>' );
						}
					});

				}, 5000 );

			});
		});
		</script>
	<?php
	}

	/**
	 * AJAX callback to connect to the IMAP inbox.
	 *
	 * @since 1.0-RC5.
	 */
	public function ajax_connect() {
		check_ajax_referer( 'bp_rbe_ajax_connect' );

		/*
		 * Stupid WordPress plugins using session_start() without closing the session
		 * properly.
		 *
		 * @link http://stackoverflow.com/a/9906646 Thanks for the hint!
		 */
		if ( session_id() ) {
			session_write_close();
		}

		// Run IMAP inbox check.
		bp_rbe_run_inbox_listener( array( 'force' => true ) );
	}

	/**
	 * AJAX callback to check if we're connected to the IMAP inbox.
	 *
	 * @since 1.0-RC5.
	 */
	public function ajax_connect_notice() {
		check_ajax_referer( 'bp_rbe_ajax_connect' );

		$success_msg = __( '<strong>Reply By Email</strong> is currently <span>CONNECTED</span> and checking your inbox continuously. To disconnect, deactivate the plugin.', 'bp-rbe' );

		if ( bp_rbe_is_connected() ) {
			// Schedule hourly check.
			if ( ! wp_next_scheduled ( 'bp_rbe_schedule' ) ) {
				wp_schedule_event( time() + 60 * 60, 'hourly', 'bp_rbe_schedule' );
			}

			wp_send_json_success( array(
				'msg' => $success_msg
			) );
		} else {
			if ( ! ini_get( 'safe_mode' ) ) {
				set_time_limit( 0 );
			}

			// If we're connecting, loop until we get a response.
			while ( bp_rbe_is_connecting( array( 'clearcache' => true ) ) ) {
				// Sleep for a sec.
				sleep( 1 );

				// Success!
				if ( bp_rbe_is_connected( array( 'clearcache' => true ) ) ) {
					// Schedule hourly check.
					if ( ! wp_next_scheduled ( 'bp_rbe_schedule' ) ) {
						wp_schedule_event( time() + 60 * 60, 'hourly', 'bp_rbe_schedule' );
					}

					wp_send_json_success( array(
						'msg' => $success_msg
					) );
				}
			}

			// If we're here, check connection for errors.
			$imap = BP_Reply_By_Email_Connect::init();
			if ( false === $imap ) {
				wp_send_json_error( array(
					'msg' => sprintf( __( 'Error: Unable to connect to inbox - %s', 'bp-rbe' ), join( '. ', (array) imap_errors() ) )
				) );

			// We shouldn't be here, so close the connection and die.
			} else {
				imap_close( $imap );
				die();
			}
		}
	}

	/**
	 * Validate and sanitize our options before saving to the DB.
	 *
	 * Callback from {@link register_setting()}.
	 *
	 * @param array $input The submitted values from the form
	 * @return array $output The validated values to be inserted into the DB
	 */
	public function validate( $input ) {
		$messages = false;

		$output = array();

		$output['mode'] = wp_filter_nohtml_kses( $input['mode'] );

		// switching from IMAP to inbound email mode
		if ( 'inbound' == $output['mode'] && 'imap' == bp_rbe_get_setting( 'mode' ) ) {
			// stop RBE if still connected via IMAP
			if ( bp_rbe_is_connected() ) {
				bp_rbe_stop_imap();
				wp_clear_scheduled_hook( 'bp_rbe_schedule' );
			}
			bp_rbe_log( '- Operating mode switched to inbound -' );
		}

		// check if key is alphanumeric
		if ( ctype_alnum( $input['key'] ) ) {
			$output['key'] = $input['key'];
		}

		/** INBOUND-related ***************************************************/
		$inbound_provider = wp_filter_nohtml_kses( $input['inbound-provider'] );
		if ( ! empty( $inbound_provider ) ) {
			$output['inbound-provider'] = $inbound_provider;
		}

		$inbound_domain = isset( $input['inbound-domain'] ) ? wp_filter_nohtml_kses( $input['inbound-domain'] ) : '';
		if ( ! empty( $inbound_domain ) ) {
			$output['inbound-domain'] = $inbound_domain;
		}

		/** IMAP-related ******************************************************/
		$username = wp_filter_nohtml_kses( $input['username'] );
		$password = $input['password'];

		if ( $email = is_email( $input['email'] ) ) {
			$output['email'] = $email;

			if ( $input['gmail'] == 1 ) {
				$output['username'] = $email;
			}
		}

		if ( ! empty( $username ) ) {
			$output['username'] = $username;

			if ( is_email( $username ) ) {
				$output['email'] = $username;
			}
		}

		if ( ! empty( $password ) ) {
			$output['password'] = $password;
		}

		// check if port is numeric
		if ( is_numeric( $input['port'] ) ) {
			$output['port']     = $input['port'];
		}

		// check if address tag is one character
		if ( strlen( $input['tag'] ) == 1 ) {
			$output['tag']      = $input['tag'];
		}

		// override certain settings if "gmail" setting is true
		if ( ! empty( $input['gmail'] ) && $input['gmail'] == 1 ) {
			$output['servername'] = 'imap.gmail.com';
			$output['port']	      = 993;
			$output['tag']	      = '+';
			$output['gmail']      = 1;
			$output['email']      = $username;

		// use alternate server settings as defined by the user
		} else {
			$output['servername'] = wp_filter_nohtml_kses( $input['servername'] );
			$output['port']	      = absint( $input['port'] );
			$output['tag']	      = wp_filter_nohtml_kses( $input['tag'] );
			$output['gmail']      = 0;
		}

		if ( is_numeric( $input['keepalive'] ) && $input['keepalive'] < 30 ) {
			$output['keepalive'] = $input['keepalive'];
		}

		// keepalive for safe mode will never exceed the execution time
		if ( ini_get( 'safe_mode' ) ) {
			$output['keepalive'] = $input['keepalive'] = bp_rbe_get_execution_time( 'minutes' );
		}

		// automatically reconnect after keep-alive limit
		if ( ! empty( $input['keepaliveauto'] ) ) {
			$output['keepaliveauto'] = 1;
		} else {
			$output['keepaliveauto'] = 0;

			wp_clear_scheduled_hook( 'bp_rbe_schedule' );
		}

		// do a quick imap check if we have valid credentials to check
		if ( $output['mode'] == 'imap' && ! empty( $output['servername'] ) && ! empty( $output['port'] ) && ! empty( $output['username'] ) && ! empty( $output['password'] ) ) {
			if ( function_exists( 'imap_open' ) ) {
				$imap = BP_Reply_By_Email_Connect::init( array (
			 		'host'     => $output['servername'],
			 		'port'     => $output['port'],
			 		'username' => $output['username'],
			 		'password' => $output['password'],
				) );

				// if connection failed, add an error
				if ( $imap === false ) {
					$messages['connect_error'] = sprintf( __( 'Error: Unable to connect to inbox - %s', 'bp-rbe' ), join( '. ', (array) imap_errors() ) );
					$output['connect'] = 0;

				// connection was successful, now close our temporary connection
				} else {
					// this tells bp_rbe_is_required_completed() that we're good to go!
					$output['connect'] = 1;
					imap_close( $imap );
				}
			}
		}

		// encode the password
		if ( ! empty( $password ) ) {
			$output['password'] = bp_rbe_encode( array( 'string' => $password, 'key' => wp_salt() ) );
		}

		/**********************************************************************/

		/* error time! */

		if ( strlen( $input['tag'] ) > 1 && ! $output['tag'] ) {
			$messages['tag_error']       = __( 'Error: <strong>Address tag</strong> must only be one character.', 'bp-rbe' );
		}

		if ( ! empty( $input['port'] ) && ! is_numeric( $input['port'] ) && ! $output['port'] ) {
			$messages['port_error']      = __( 'Error: <strong>Port</strong> must be numeric.', 'bp-rbe' );
		}

		if ( ! empty( $input['key'] ) && ! $output['key'] ) {
			$messages['key_error']       = __( 'Error: <strong>Key</strong> must only contain letters and / or numbers.', 'bp-rbe' );
		}

		if ( ! empty( $input['keepalive'] ) && ! is_numeric( $input['keepalive'] ) && ! $output['keepalive'] ) {
			$messages['keepalive_error'] = __( 'Error: <strong>Keep Alive Connection</strong> value must be less than 30.', 'bp-rbe' );
		}

		if ( is_array( $messages ) ) {
			$output['messages'] = $messages;
		}

		// For sites using an external object cache, make sure they get the new value.
		// This is all sorts of ugly! :(
		// I think this is a WP core bug with the WP Settings API...
		wp_cache_delete( 'alloptions', 'options' );

		return $output;
	}

	/**
	 * Output the admin page.
	 */
	public function load() {
	?>
		<div class="wrap">
			<?php screen_icon('edit-comments'); ?>

			<h2><?php _e( 'BP Reply By Email Settings', 'bp-rbe' ) ?></h2>

			<?php $this->display_errors() ?>

			<?php $this->webhost_warnings() ?>

			<form action="options.php" method="post">
				<?php settings_fields( $this->name ); ?>

				<h3><?php _e( 'Operating Mode', 'bp-rbe' ); ?></h3>

				<table class="form-table">

					<?php $this->render_field(
						array(
							'type'      => 'select',
							'name'      => 'mode',
							'labelname' => __( 'Mode', 'bp-rbe' ),
							'desc'      => '<span class="inbound-options">' . __( "Inbound email processing means RBE will route replied emails to a third-party inbound email service that you have configured and set up.  The service will receive the inbound emails and do all the necessary email parsing.  Once done, the parsed content will be sent back to the site where RBE will verify this content and post the content on success.", 'bp-rbe' ) . '</span>
									<span class="imap-options">' . __( "IMAP mode connects to an IMAP email server, which then checks the inbox for a specified interval and posts the content to your site on success.  Can be server-intensive.  Only recommended for VPS or dedicated servers.", 'bp-rbe' ) . '</span>',
							'options'   => array(
								'inbound' => __( 'Inbound Email', 'bp-rbe' ),
								'imap'    => __( 'IMAP', 'bp-rbe' )
							),
							'default'   => bp_rbe_is_inbound() ? 'inbound' : 'imap'
						) ) ?>

				</table>

				<div class="inbound-options">
					<table class="form-table">
					<?php $this->render_field(
						array(
							'type'      => 'select',
							'name'      => 'inbound-provider',
							'labelname' => __( 'Provider', 'bp-rbe' ),
							'desc'      => sprintf(
								__( 'Choose an inbound provider.  Make sure that you have set up an account with this provider and configured it properly.  By default, <a href="%s">Postmark</a> is supported.', 'bp-rbe' ),
								'https://github.com/r-a-y/bp-reply-by-email/wiki/Postmark'
							),
							'options'   => $this->get_inbound_providers(),
							'default'   => 'postmark',
						) ) ?>

					<?php $this->render_field( array(
							'name'      => 'inbound-domain',
							'labelname' => __( 'Inbound Domain *', 'bp-rbe' ),
							'desc'      => __( 'The domain you have configured for RBE email to be sent and parsed.  Make sure you have set up your domain properly with the inbound service you are using. (eg. reply.yourdomain.com)', 'bp-rbe' )
						) );
					?>
					</table>
				</div>

				<div class="imap-options">

					<?php
						if ( ! bp_rbe_is_inbound() ) {
							$this->schedule();
						}
					?>

					<h3><?php _e( 'Email Server Settings', 'bp-rbe' ); ?></h3>

					<p><?php _e( 'Please enter the IMAP settings for your email account.  Fields marked with * are required.', 'bp-rbe' ) ?></p>

					<table class="form-table">

						<?php $this->render_field(
							array(
								'type'      => 'checkbox',
								'name'      => 'gmail',
								'labelname' => __( 'GMail / Google Apps Mail', 'bp-rbe' ),
								'desc'      => sprintf( __( 'Using GMail? (This will override the Server Name, Port and Address Tag fields on save.) Also make sure to <a href="%s" target="_blank">enable IMAP in GMail</a>.', 'bp-rbe' ), 'http://mail.google.com/support/bin/answer.py?answer=77695' )
							) ) ?>

						<?php $this->render_field(
							array(
								'name'      => 'servername',
								'labelname' => __( 'Mail Server *', 'bp-rbe' )
							) ) ?>

						<?php $this->render_field(
							array(
								'name'      => 'port',
								'labelname' => __( 'Port *', 'bp-rbe' ),
								'size'      => 'small'
							) ) ?>

						<?php $this->render_field(
							array(
								'name'      => 'tag',
								'labelname' => __( 'Address Tag Separator *', 'bp-rbe' ),
								'desc'      => sprintf( __( 'Example: test@gmail.com is my email address, I can also receive email that is sent to test+anything@gmail.com.  The address tag separator for GMail is "+".  For other email providers, <a href="%s" target="_blank">view this page</a>.', 'bp-rbe' ), 'http://en.wikipedia.org/wiki/Email_address#Address_tags' ),
								'size'      => 'small'
							) ) ?>

						<?php $this->render_field(
							array(
								'name'      => 'username',
								'labelname' => __( 'Username *', 'bp-rbe' ),
								'desc'      => __( 'For GMail users, enter your <strong>full email address</strong> as your username.', 'bp-rbe' )
							) ) ?>

						<?php $this->render_field(
							array(
								'type'	    => 'password',
								'name'	    => 'password',
								'labelname' => __( 'Password *', 'bp-rbe' )
							) ) ?>

						<?php $this->render_field(
							array(
								'name'	    => 'email',
								'labelname' => __( 'Email Address', 'bp-rbe' ),
								'desc'      => __( 'If your username is <strong>not</strong> the same as your email address, please fill in this field as well.', 'bp-rbe' )
							) ) ?>

					</table>
				</div><!-- #imap-options -->

				<h3><?php _e( 'Other Settings', 'bp-rbe' ) ?></h3>

				<table class="form-table">
					<?php $this->render_field(
						array(
							'name'      => 'key',
							'labelname' => __( 'Key *', 'bp-rbe' ),
							'desc'      => __( 'This key is used to verify incoming emails before anything is posted to BuddyPress.  By default, a key is randomly generated for you.  You should rarely have to change your key.  However, if you want to change it, please type in an alphanumeric key of your choosing.', 'bp-rbe' )
						) ) ?>
				</table>

				<div class="imap-options">
					<table class="form-table">
					<?php $this->render_field(
						array(
							'name'      => 'keepalive',
							'labelname' => __( 'Keep Alive Connection *', 'bp-rbe' ),
							'desc'      => sprintf( __( 'The length in minutes to stay connected to your inbox. Due to <a href="%s" target="_blank">RFC 2177 protocol</a>, this value cannot be larger than 29. If this value is changed, this will take effect on the next scheduled update.', 'bp-rbe' ), 'http://tools.ietf.org/html/rfc2177#page-2' ),
							'size'      => 'small'
						) ) ?>

					<?php $this->render_field(
						array(
							'type'      => 'checkbox',
							'name'      => 'keepaliveauto',
							'labelname' => __( 'Automatically reconnect?', 'bp-rbe' ),
							'desc'      => __( 'When checked, after the keep-alive limit completes, RBE can automatically reconnect to the inbox.  If unchecked, a user will need to be active on the site after the keep-alive limit expires for RBE to reconnect. If this value is changed, this will take effect on the next scheduled update.', 'bp-rbe' ),
						) ) ?>
					</table>
				</div>

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

			<h3><?php _e( 'Need Help?', 'bp-rbe' ); ?></h3>

			<p><?php printf( __( '<a href="%s">Check out the BP Reply By Email wiki</a> for articles, FAQs and more information.', 'bp-rbe' ), 'https://github.com/r-a-y/bp-reply-by-email/wiki/' ); ?></p>

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
	protected function display_errors() {
		$option = $this->settings;

		// output error message(s)
		if ( !empty( $option['messages'] ) && is_array( $option['messages'] ) ) :
			foreach ( (array) $option['messages'] as $id => $message ) :
				echo "<div id='message' class='error fade $id'><p>$message</p></div>";
				unset( $option['messages'][$id] );
			endforeach;

			update_option( $this->name, $option );

		// success!
		elseif ( isset( $_REQUEST['updated'] ) && esc_attr( $_REQUEST['updated'] ) ) :
			echo '<div id="message" class="updated"><p>' . __( 'Settings updated successfully!', 'bp-rbe' ) . '</p></div>';
		endif;
	}

	/**
	 * Adds webhost warnings to the admin page.
	 *
	 * If certain conditions for the webhost are not met, these warnings will be displayed on the admin page.
	 */
	protected function webhost_warnings() {
		if ( bp_rbe_is_inbound() ) {
			return;
		}

		$warnings = array();

		if ( ! function_exists( 'imap_open' ) ) {
			$warnings[] = __( 'IMAP extension for PHP is <strong>disabled</strong>.  This plugin will not run without it.  Please contact your webhost to enable this.', 'bp-rbe' );
		}

		if ( ini_get('safe_mode') ) {
			$warnings[] = sprintf( __( 'PHP Safe Mode is <strong>on</strong>.  This is not a dealbreaker, but this means you cannot set the Keep Alive Connection value larger than %d minutes.', 'bp-rbe' ), bp_rbe_get_execution_time( 'minutes' ) );
		}

		if ( ! empty( $warnings ) ) {
			echo '<h3>' . __( 'Webhost Warnings', 'bp-rbe' ) . '</h3>';
		}

		foreach ( $warnings as $warning ) {
			echo '<div class="error"><p>' . $warning . '</p></div>';
		}
	}

	/**
	 * Outputs next scheduled run of the (pseudo) cron.
	 */
	protected function schedule() {

		// only show the following if required fields are filled in correctly
		if ( bp_rbe_is_required_completed() && ! bp_rbe_is_inbound() ) :
			$is_connected = bp_rbe_is_connected();

			$is_autoconnect = 1 === (int) bp_rbe_get_setting( 'keepaliveauto' );
	?>
		<h3><?php _e( 'Connection Info', 'bp-rbe' ); ?></h3>

		<div class="spinner is-active"></div>

		<p class="<?php echo $is_connected ? 'connected' : 'not-connected'; ?>">
			<?php if ( $is_connected ) : ?>
				<?php _e( '<strong>Reply By Email</strong> is currently <span>CONNECTED</span> and checking your inbox continuously. To disconnect, deactivate the plugin.', 'bp-rbe' ); ?>
			<?php elseif ( $is_autoconnect ) : ?>
				<?php _e( '<strong>Reply By Email</strong> is currently <span>NOT CONNECTED</span>.  Please click on the "Connect" button to initiate a connection.', 'bp-rbe' ); ?>

				<?php wp_nonce_field( 'bp_rbe_ajax_connect', 'bp-rbe-ajax-connect-nonce' ); ?>

				<p class="submit"><button type="button" class="button-primary connect"><?php esc_html_e( 'Connect', 'bp-rbe' ); ?></button></p>

			<?php else : ?>
				<?php _e( '<strong>Reply By Email</strong> is currently <span>NOT CONNECTED</span>.  Please refresh the page to initiate a connection.', 'bp-rbe' ); ?>

			<?php endif; ?>
		</p>

	<?php
		endif;
	}

	/**
	 * Get an array of available inbound providers.
	 *
	 * @since 1.0-RC3
	 *
	 * @return array Key/value pairs (provider internal name => provider display name)
	 */
	protected function get_inbound_providers() {
		$retval = array();

		foreach ( BP_Reply_By_Email::get_inbound_providers() as $provider => $class ) {
			$prop = new ReflectionProperty( $class, 'name' );
			if ( $prop->isStatic() ) {
				$retval[$provider] = $class::$name;
			} else {
				$retval[$provider] = ucfirst( $provider );
			}
		}

		unset( $prop, $provider, $class );

		return $retval;
	}

	/**
	 * Renders the output of a form field in the admin area.
	 *
	 * I like this better than {@link add_settings_field()} so sue me!
	 * Uses {@link BP_Reply_By_Email_Admin::field()} and {@link BP_Reply_By_Email_Admin::get_option()}.
	 *
	 * @param array $args Arguments for the field
	 */
	protected function render_field( $args = '' ) {
		$defaults = array(
			'type'      => 'text',    // text, password, checkbox, radio, dropdown
			'labelname' => '',        // the label for the field
			'labelfor'  => true,      // should <label> be used?
			'name'      => '',        // the input name of the field
			'desc'      => '',        // used to describe a checkbox, radio or option value
			'size'      => 'regular', // text field size - small,
			'value'     => '',        // pass a value to use as the default value
			'options'   => array(),   // options for checkbox, radio, select - not used currently
			'default'   => '',        // currently used to set default value for select dropdowns
		);

		$r = wp_parse_args( $args, $defaults );

		echo '<tr class="' . $this->field( $r['name'], true, false ). '">';

		if ( $r['labelfor'] ) {
			echo '<th scope="row"><label for="' . $this->field( $r['name'], true, false ) . '">' . $r['labelname'] . '</label></th>';
		} else {
			echo '<th scope="row">' . $r['labelname'] . '</th>';
		}

		echo '<td>';

		switch ( $r['type'] ) {
			case 'checkbox' :
			?>
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo $r['labelname']; ?></span></legend>

					<label for="<?php $this->field( $r['name'], true ) ?>">
						<input type="checkbox" name="<?php $this->field( $r['name'] ) ?>" id="<?php $this->field( $r['name'], true ) ?>" value="1" <?php if ( ! empty( $this->settings[$r['name']] ) ) checked( $this->settings[$r['name']], 1 ); ?> />

						<?php echo $r['desc']; ?>
				</label>
				<br />
				</fieldset>
			<?php
				break;

			case 'select' :
				$selected = array_key_exists( $this->settings[$r['name']], $r['options'] ) ? $this->settings[$r['name']] : $r['default'];
			?>

				<select id="<?php $this->field( $r['name'], true ) ?>" name="<?php $this->field( $r['name'] ) ?>">
					<?php
						foreach ( $r['options'] as $key => $option ) {
							echo '<option value="' . esc_attr( $key ) .'"';

							if ( $selected == $key ) {
								echo ' selected="selected"';
							}

							echo '>' . esc_html( $option ) . '</option>';
						}
					?>
				</select>

			<?php
				if ( $r['desc'] ) {
					echo '<p class="description">' . $r['desc'] . '</p>';
				}

				break;

			case 'text' :
			case 'password' :
				$value = $this->get_option( $r['name'], false );

				if ( $r['type'] == 'password' ) {
					$value = bp_rbe_decode( array( 'string' => $value, 'key' => wp_salt() ) );
				}
			?>
				<input class="<?php echo $r['size']; ?>-text" value="<?php echo $value; ?>" name="<?php $this->field( $r['name'] ) ?>" id="<?php $this->field( $r['name'], true ) ?>" type="<?php echo $r['type']; ?>" />
			<?php
				if ( $r['desc'] ) {
					echo '<p class="description">' . $r['desc'] . '</p>';
				}

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
	protected function field( $name, $id = false, $echo = true ) {
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
	protected function get_option( $name, $echo = true ) {
		$val = '';

		if( is_array( $this->settings ) && isset( $this->settings[$name] ) )
			$val = $this->settings[$name];

		if( $echo )
			esc_attr_e( $val );
		else
			return esc_attr( $val );
	}

	/**
	 * Gets RBE's plugin basename.
	 *
	 * The reason for this extra method is because we're using {@link plugin_basename()}
	 * from a subdirectory.  So we need to remove everything but the first directory.
	 *
	 * @since 1.0-RC2
	 *
	 * @return string
	 */
	public function get_plugin_basename() {
		return plugin_basename( constant( 'BP_RBE_DIR' ) );
	}
}