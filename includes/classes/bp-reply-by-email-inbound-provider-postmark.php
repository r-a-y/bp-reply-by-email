<?php
/**
 * BP Reply By Email Postmark Inbound Provider Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add Postmark as an inbound provider for RBE.
 *
 * @since 1.0-RC4
 *
 * @see http://developer.postmarkapp.com/developer-process-parse.html
 */
class BP_Reply_By_Email_Inbound_Provider_Postmark extends BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var string Display name for our inbound provider
	 */
	public static $name = 'Postmark';

	/**
	 * Webhook parser class method for Mandrill.
	 */
	public function webhook_parser() {
		if ( empty( $_SERVER['CONTENT_TYPE'] ) || ( ! empty( $_SERVER['CONTENT_TYPE'] ) && 'application/json' !== $_SERVER['CONTENT_TYPE'] ) ) {
			return;
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && 'Postmark' !== $_SERVER['HTTP_USER_AGENT'] ) {
			return;
		}

		bp_rbe_log( '- Postmark webhook received -' );

		$response = file_get_contents( 'php://input' );
		if ( empty( $response ) ) {
			bp_rbe_log( '- Postmark webhook response failed -' );
		}

		$response = json_decode( $response );

		// Format email headers to fit RBE spec.
		$headers = array();
		foreach ( $response->Headers as $header ) {
			$headers[$header->Name] = $header->Value;
		}

		// Postmark separates parsed email headers; add them back for RBE parsing.
		$headers['From'] = $response->From;
		$headers['To']   = $response->OriginalRecipient;

		$data = array(
			'headers'    => $headers,
			'to_email'   => $response->OriginalRecipient,
			'from_email' => $response->From,
			'content'    => $response->TextBody,
			'subject'    => $response->Subject
		);

		$parser = BP_Reply_By_Email_Parser::init( $data, 1 );

		if ( is_wp_error( $parser ) ) {
			do_action( 'bp_rbe_no_match', $parser, $data, 1, false );
		}

		bp_rbe_log( '- Webhook parsing completed -' );
		die();
	}
}