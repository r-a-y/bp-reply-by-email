<?php
/**
 * BP Reply By Email Classes
 *
 * Used if the current PHP install does not have autoload support.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

require dirname( __FILE__ ) . '/classes/bp-reply-by-email-connect.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-extension.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-imap.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-inbound-provider.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-inbound-provider-postmark.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-inbound-provider-sparkpost.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-inbound-provider-sendgrid.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-inbound-provider-mandrill.php';
require dirname( __FILE__ ) . '/classes/bp-reply-by-email-parser.php';