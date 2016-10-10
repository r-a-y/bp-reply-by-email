<?php
/*
Plugin Name: BuddyPress Reply By Email
Description: Reply to BuddyPress items from the comfort of your email inbox.
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
Version: 1.0-RC5.dev
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

	if ( ! $settings = bp_get_option( 'bp-rbe' ) ) {
		$settings = array();
	}

	// Set default mode to Inbound if no mode exists
	if ( ! isset( $settings['mode'] ) ) {
		$settings['mode'] = 'inbound';
	}

	// generate a unique key if one doesn't exist
	if ( ! isset( $settings['key'] ) ) {
		$settings['key'] = uniqid( '' );
	}

	// set a default value for the keepalive value
	if ( ! isset( $settings['keepalive'] ) ) {
		$settings['keepalive'] = bp_rbe_get_execution_time( 'minutes' );
	}

	bp_update_option( 'bp-rbe', $settings );

	// remove remnants from any previous failed attempts to stop the inbox
	bp_rbe_cleanup();
}
add_action( 'activate_' . basename( BP_RBE_DIR ) . '/loader.php', 'bp_rbe_activate' );

/**
 * Remove our scheduled function from WP and stop the IMAP loop.
 */
function bp_rbe_deactivate() {
	// stop IMAP connection if active
	if ( bp_rbe_is_connected() ) {
		bp_rbe_stop_imap();

		// give plugin a chance to stop IMAP connection as it could be sleeping
		sleep( 10 );

		bp_rbe_log( 'Daisy, Daisy, give me your answer, do...' );
	}

	// remove remnants from any previous failed attempts to stop the inbox
	bp_rbe_cleanup();

	bp_rbe_log( 'Plugin deactivated!' );
}
add_action( 'deactivate_' . basename( BP_RBE_DIR ) . '/loader.php', 'bp_rbe_deactivate' );

/**
 * BP Reply By Email default extensions.
 *
 * Currently supports BuddyPress Docs and bbPress
 * More to come in the future?
 *
 * @since 1.0-RC1
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

	// bbPress
	if ( function_exists( 'bbpress' ) ) {
		require( BP_RBE_DIR . '/includes/bp-rbe-extend-bbpress.php' );

		// initialize the bbPress RBE extension!
		new BBP_RBE_Extension;
	}
}
add_action( 'bp_include', 'bp_rbe_default_extensions', 20 );