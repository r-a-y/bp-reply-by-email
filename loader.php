<?php
/*
Plugin Name: BuddyPress Reply By Email
Description: Reply to BuddyPress items from the comfort of your email inbox.
Author: r-a-y
Author URI: http://buddypress.org/community/members/r-a-y/
Version: 1.0-beta1
License: GPLv2 or later
*/

/**
 * BuddyPress Reply By Email
 *
 * @package BP_Reply_By_Email
 * @subpackage Loader
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// pertinent constants
define( 'BP_RBE_DIR', dirname( __FILE__ ) );
define( 'BP_RBE_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'BP_RBE_DEBUG' ) )
	define( 'BP_RBE_DEBUG',          true ); // this is true during dev period, will revert to false on release

if ( ! defined( 'BP_RBE_DEBUG_LOG_PATH' ) )
	define( 'BP_RBE_DEBUG_LOG_PATH', WP_CONTENT_DIR . '/bp-rbe-debug.log' );

/**
 * Loads BP Reply By Email only if BuddyPress is activated
 */
function bp_rbe_init() {
	global $bp_rbe;

	require( BP_RBE_DIR . '/bp-rbe-core.php' );

	// initialize!
	$bp_rbe = new BP_Reply_By_Email;
	$bp_rbe->init();

	// admin area
	if ( is_admin() ) {
		require( BP_RBE_DIR . '/includes/bp-rbe-admin.php' );
		new BP_Reply_By_Email_Admin;
	}
}
add_action( 'bp_include', 'bp_rbe_init' );

/**
 * Adds default settings when plugin is activated
 */
function bp_rbe_activate() {
	// Load the bp-rbe functions file
	require( BP_RBE_DIR . '/includes/bp-rbe-functions.php' );
	require( BP_RBE_DIR . '/includes/bp-rbe-classes.php' );

	if ( !$settings = bp_get_option( 'bp-rbe' ) )
		$settings = array();

	// generate a unique key if one doesn't exist
	if ( !$settings['key'] )
		$settings['key'] = uniqid( '' );

	// set a default value for the keepalive value
	if ( !$settings['keepalive'] )
		$settings['keepalive'] = bp_rbe_get_execution_time( 'minutes' );

	bp_update_option( 'bp-rbe', $settings );

	// remove remnants from any previous failed attempts to stop the inbox
	BP_Reply_By_Email_IMAP::should_stop();

	bp_delete_option( 'bp_rbe_is_connected' );
	bp_delete_option( 'bp_rbe_spawn_cron' );
}
register_activation_hook( __FILE__, 'bp_rbe_activate' );

/**
 * Remove our scheduled function from WP and stop the IMAP loop.
 */
function bp_rbe_deactivate() {
	// remove the cron job
	wp_clear_scheduled_hook( 'bp_rbe_schedule' );

	// stop IMAP connection if active
	if ( bp_rbe_is_connected() ) {
		bp_rbe_stop_imap();

		// give plugin a chance to stop IMAP connection as it could be sleeping
		sleep( 10 );

		bp_rbe_log( 'Daisy, Daisy, give me your answer, do...' );
	}

	bp_delete_option( 'bp_rbe_is_connected' );
	bp_delete_option( 'bp_rbe_spawn_cron' );

	bp_rbe_log( 'Plugin deactivated!' );
}
register_deactivation_hook( __FILE__, 'bp_rbe_deactivate' );

/**
 * BP Reply By Email default extensions.
 *
 * Currently supports BuddyPress Docs.
 * More to come in the future?
 *
 * @since 1.0-beta2
 */
function bp_rbe_default_extensions() {
	// if RBE requirements aren't fulfilled, stop now!
	if ( ! bp_rbe_is_required_completed() )
		return;

	// BuddyPress Docs
	if ( defined( 'BP_DOCS_VERSION' ) ) {
		require( BP_RBE_DIR . '/includes/bp-rbe-extend-bpdocs.php' );
		
		// initialize the BP Docs RBE extension!
		new BP_Docs_Comment_RBE_Extension;
	}
}
add_action( 'bp_include', 'bp_rbe_default_extensions', 20 );

?>