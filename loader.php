<?php
/*
Plugin Name: BuddyPress Reply By Email
Description: Reply to BuddyPress items from the comfort of your email inbox.
Author: r-a-y
Author URI: http://buddypress.org/community/members/r-a-y/
Version: 1.0-beta
*/

/**
 * BuddyPress Reply By Email
 *
 * @package BP_Reply_By_Email
 * @subpackage Loader
 */

/**
 * Loads BP Reply By Email only if BuddyPress is activated
 */
function bp_rbe_init() {
	global $bp_rbe;

	if ( !function_exists( 'bp_is_active' ) )
		return;

	require_once( dirname( __FILE__ ) . '/bp-rbe-core.php' );

	// initialize!
	$bp_rbe = new BP_Reply_By_Email();
	$bp_rbe->init();

	// admin area
	if ( is_admin() )
		$bp_rbe->admin = new BP_Reply_By_Email_Admin();
}
add_action( 'bp_include', 'bp_rbe_init' );

/**
 * Adds default settings when plugin is activated
 */
function bp_rbe_activate() {
	// Load the bp-rbe functions file
	require_once( dirname( __FILE__ ) . '/includes/bp-rbe-functions.php' );
	
	if ( !$settings = get_option( 'bp-rbe' ) )
		$settings = array();

	// generate a unique key if one doesn't exist
	if ( !$settings['key'] )
		$settings['key'] = uniqid( '' );

	// set a default value for the keepalive value
	if ( !$settings['keepalive'] )
		$settings['keepalive'] = bp_rbe_get_execution_time( 'minutes' );
	
	update_option( 'bp-rbe', $settings );
}
register_activation_hook( __FILE__, 'bp_rbe_activate' );

/**
 * Remove our scheduled function from WP and stop the IMAP loop.
 */
function bp_rbe_deactivate() {
	global $bp_rbe;

	// remove the cron job
	wp_clear_scheduled_hook( 'bp_rbe_schedule' );

	// stop IMAP connection
	bp_rbe_stop_imap();
}
register_deactivation_hook( __FILE__, 'bp_rbe_deactivate' );

?>