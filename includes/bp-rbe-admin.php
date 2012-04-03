<?php
/**
 * BP Reply By Email Admin
 *
 * @package BP_Reply_By_Email
 * @subpackage Admin
 */

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
		add_filter( 'plugin_action_links',  array( &$this, 'add_plugin_action_links' ), 10, 2 );
	}

	/**
	 * Setup our admin settings page and hooks
	 */
	function setup_admin() {
		$page = add_submenu_page( 'bp-general-settings', __( 'BuddyPress Reply By Email', 'bp-rbe' ), __( 'Reply By Email', 'bp-rbe' ), 'manage_options', 'bp-rbe', array( &$this, 'load' ) );

		//add_action( "admin_head-{$page}", array( &$this, 'head' ) );
		add_action( "admin_footer-{$page}", array( &$this, 'footer' ) );
	}

	/**
	 * Add extra links for RBE on the WP Admin plugins page
	 *
	 * @param array $links Plugin action links
	 * @param string $file A plugin's loader base filename
	 */
	function add_plugin_action_links( $links, $file ) {
		$plugin_basename = plugin_basename(__FILE__);
		$plugin_basedir	 = substr( $plugin_basename, 0, strpos( $plugin_basename, '/' ) );

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

			$( '.bp-rbe-username span' ).hide();

			if ( gmail == 1 ) {
				$( 'tr.bp-rbe-servername' ).hide();
				$( 'tr.bp-rbe-port' ).hide();
				$( 'tr.bp-rbe-tag' ).hide();
				$( 'tr.bp-rbe-email' ).hide();
				$( '.bp-rbe-username span' ).show();
			}

			// toggle fields based on gmail setting
			$( '#bp-rbe-gmail' ).change(function(){
				$( 'tr.bp-rbe-servername' ).toggle();
				$( 'tr.bp-rbe-port' ).toggle();
				$( 'tr.bp-rbe-tag' ).toggle();
				$( 'tr.bp-rbe-email' ).toggle();
				$( '.bp-rbe-username span' ).toggle();
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
			$output['password'] = $password;

		// check if port is numeric
		if ( is_numeric( $input['port'] ) )
			$output['port']     = $input['port'];

		// check if address tag is one character
		if ( strlen( $input['tag'] ) == 1 )
			$output['tag']      = $input['tag'];

		// override certain settings if "gmail" setting is true
		if ( $input['gmail'] == 1 ) {
			$output['servername'] = 'imap.gmail.com';
			$output['port']	      = 993;
			$output['tag']	      = '+';
			$output['gmail']      = 1;
		}
		// if unchecked, reset these settings
		elseif ( $output['port'] || $output['servername'] || $output['tag'] ) {
			unset( $output['port'] );
			unset( $output['servername'] );
			unset( $output['tag'] );
		}

		// check if key is alphanumeric
		if ( ctype_alnum( $input['key'] ) )
			$output['key']       = $input['key'];

		if ( is_numeric( $input['keepalive'] ) && $input['keepalive'] < 30 )
			$output['keepalive'] = $input['keepalive'];

		// keepalive for safe mode will never exceed the execution time
		if ( ini_get( 'safe_mode' ) )
			$output['keepalive'] = $input['keepalive'] = bp_rbe_get_execution_time( 'minutes' );

		/* error time! */

		if ( strlen( $input['tag'] ) > 1 && !$output['tag'] )
			$messages['tag_error']       = __( 'Error: <strong>Address tag</strong> must only be one character.', 'bp-rbe' );

		if ( !empty( $input['port'] ) && !is_numeric( $input['port'] ) && !$output['port'] )
			$messages['port_error']      = __( 'Error: <strong>Port</strong> must be numeric.', 'bp-rbe' );

		if ( !empty( $input['key'] ) && !$output['key'] )
			$messages['key_error']       = __( 'Error: <strong>Key</strong> must only contain letters and / or numbers.', 'bp-rbe' );

		if ( !empty( $input['keepalive'] ) && !is_numeric( $input['keepalive'] ) && !$output['keepalive'] )
			$messages['keepalive_error'] = __( 'Error: <strong>Keep Alive Connection</strong> value must be less than 30.', 'bp-rbe' );

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

				<h3><?php _e( 'Other Settings', 'bp-rbe' ) ?></h3>

				<table class="form-table">
					<?php $this->render_field(
						array(
							'name'      => 'key',
							'labelname' => __( 'Key *', 'bp-rbe' ),
							'desc'      => __( 'This key is used to verify incoming emails before anything is posted to BuddyPress.  By default, a key is randomly generated for you.  You should rarely have to change your key.  However, if you want to change it, please type in an alphanumeric key of your choosing.', 'bp-rbe' )
						) ) ?>

					<?php $this->render_field(
						array(
							'name'      => 'keepalive',
							'labelname' => __( 'Keep Alive Connection *', 'bp-rbe' ),
							'desc'      => sprintf( __( 'The length in minutes to stay connected to your inbox. Due to <a href="%s" target="_blank">RFC 2177 protocol</a>, this value cannot be larger than 29. If this value is changed, this will take effect on the next scheduled update.', 'bp-rbe' ), 'http://tools.ietf.org/html/rfc2177#page-2' ),
							'size'      => 'small'
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
		if ( !empty( $option['messages'] ) && is_array( $option['messages'] ) ) :
			foreach ( (array) $option['messages'] as $id => $message ) :
				echo "<div id='message' class='error fade $slug'><p>$message</p></div>";
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
	function webhost_warnings() {
		$warnings = array();

		if ( !function_exists( 'imap_open' ) )
			$warnings[] = __( 'IMAP extension for PHP is <strong>disabled</strong>.  This plugin will not run without it.  Please contact your webhost to enable this.', 'bp-rbe' );

		if ( ini_get('safe_mode') )
			$warnings[] = sprintf( __( 'PHP Safe Mode is <strong>on</strong>.  This is not a dealbreaker, but this means you cannot set the Keep Alive Connection value larger than %d minutes.', 'bp-rbe' ), bp_rbe_get_execution_time( 'minutes' ) );

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
			'type'      => 'text',    // text, password, checkbox, radio, dropdown
			'labelname' => '',        // the label for the field
			'labelfor'  => true,      // should <label> be used?
			'name'      => '',        // the input name of the field
			'desc'      => '',        // used to describe a checkbox, radio or option value
			'size'      => 'regular', // text field size - small
			'options'   => array()    // options for checkbox, radio, select - not used currently
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
					echo '<span class="description">' . $desc . '</span>';
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