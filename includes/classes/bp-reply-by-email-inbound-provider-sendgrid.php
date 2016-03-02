<?php
/**
 * BP Reply By Email SendGrid Inbound Provider Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add SendGrid as an inbound provider for RBE.
 *
 * @since 1.0-RC4
 *
 * @see https://sendgrid.com/docs/API_Reference/Webhooks/parse.html
 */
class BP_Reply_By_Email_Inbound_Provider_SendGrid extends BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var string Display name for our inbound provider
	 */
	public static $name = 'SendGrid';

	/**
	 * Webhook parser class method for SendGrid.
	 */
	public function webhook_parser() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) || ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && 0 !== strpos( $_SERVER['HTTP_USER_AGENT'], 'SendGrid' ) ) ) {
			return;
		}

		if ( empty( $_POST ) || empty( $_POST['headers'] ) ) {
			return;
		}

		bp_rbe_log( '- SendGrid webhook received -' );

		// Format email headers to fit RBE spec.
		$temp = explode( "\n", $_POST['headers'] );

		$headers = array();
		foreach ( $temp as $line ) {
			$colun = strpos( $line, ':' );
			if ( false === $colun ) {
				continue;
			}

			$key = substr( $line, 0, $colun );
			$headers[ $key ] = stripslashes( trim( substr( $line, $colun + 1 ) ) );
		}

		$data = array(
			'headers'    => $headers,
			'to_email'   => BP_Reply_By_Email_Parser::get_header( $headers, 'To' ),
			'from_email' => BP_Reply_By_Email_Parser::get_header( $headers, 'From' ),
			'content'    => $_POST['text'],
			'subject'    => $_POST['subject']
		);

		$parser = BP_Reply_By_Email_Parser::init( $data, 1 );

		if ( is_wp_error( $parser ) ) {
			do_action( 'bp_rbe_no_match', $parser, $data, 1, false );
		}

		bp_rbe_log( '- Webhook parsing completed -' );
		die();
	}
}