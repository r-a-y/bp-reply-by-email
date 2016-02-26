<?php
/**
 * BP Reply By Email Mandrill Inbound Provider Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add Mandrill as an inbound provider for RBE.
 *
 * @since 1.0-RC3
 *
 * @see http://help.mandrill.com/entries/22092308-What-is-the-format-of-inbound-email-webhooks-
 */
class BP_Reply_By_Email_Inbound_Provider_Mandrill extends BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var string Display name for our inbound provider
	 */
	public static $name = 'Mandrill';

	/**
	 * Webhook parser class method for Mandrill.
	 */
	public function webhook_parser() {
		if ( empty( $_SERVER['HTTP_X_MANDRILL_SIGNATURE'] ) ) {
			return;
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) && strpos( $_SERVER['HTTP_USER_AGENT'], 'Mandrill-Webhook/' ) === false ) {
			return;
		}

		if ( empty( $_POST ) || empty( $_POST['mandrill_events'] ) ) {
			return;
		}

		bp_rbe_log( '- Mandrill webhook received -' );

		// mandrill signature verification
		if ( defined( 'BP_RBE_MANDRILL_WEBHOOK_URL' ) && defined( 'BP_RBE_MANDRILL_WEBHOOK_KEY' ) ) {
			$signed_data  = constant( 'BP_RBE_MANDRILL_WEBHOOK_URL' );
			$signed_data .= 'mandrill_events';
			$signed_data .= stripslashes( $_POST['mandrill_events'] );
			$webhook_key  = constant( 'BP_RBE_MANDRILL_WEBHOOK_KEY' );

			$signature = base64_encode( hash_hmac( 'sha1', $signed_data, $webhook_key, true ) );

			// check if generated signature matches Mandrill's
			if ( $signature !== $_SERVER['HTTP_X_MANDRILL_SIGNATURE'] ) {
				bp_rbe_log( 'Mandrill signature verification failed.' );
				die();
			}

		}

		// get parsed content
		$response = json_decode( stripslashes( $_POST['mandrill_events'] ) );

		// log JSON errors if present
		if ( json_last_error() != JSON_ERROR_NONE ) {
			switch ( json_last_error() ) {
				case JSON_ERROR_DEPTH:
					bp_rbe_log( 'json error: - Maximum stack depth exceeded' );
					break;
				case JSON_ERROR_STATE_MISMATCH:
					bp_rbe_log( 'json error: - Underflow or the modes mismatch' );
					break;
				case JSON_ERROR_CTRL_CHAR:
					bp_rbe_log( 'json error: - Unexpected control character found' );
					break;
				case JSON_ERROR_SYNTAX:
					bp_rbe_log( 'json error: - Syntax error, malformed JSON' );
					break;
				case JSON_ERROR_UTF8:
					bp_rbe_log( 'json error: - Malformed UTF-8 characters, possibly incorrectly encoded' );
					break;
				default:
					bp_rbe_log( 'json error: - Unknown error' );
					break;
			}

			die();

		// ready to start the parsing!
		} else {

			$i = 1;
			foreach ( $response as $item ) {
				$data = array(
					'headers'    => $item->msg->headers,
					'to_email'   => $item->msg->email,
					'from_email' => $item->msg->from_email,
					'content'    => $item->msg->text,
					'subject'    => $item->msg->subject
				);

				$parser = BP_Reply_By_Email_Parser::init( $data, $i );

				if ( is_wp_error( $parser ) ) {
					do_action( 'bp_rbe_no_match', $parser, $data, $i, false );
				}

				++$i;
			}

			bp_rbe_log( '- Webhook parsing completed -' );

			die();
		}
	}
}