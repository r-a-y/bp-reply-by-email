<?php
/**
 * BP Reply By Email SparkPost Inbound Provider Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Add SparkPost as an inbound provider for RBE.
 *
 * @since 1.0-RC4
 *
 * @see https://support.sparkpost.com/customer/portal/articles/2039614-enabling-inbound-email-relaying-relay-webhooks
 */
class BP_Reply_By_Email_Inbound_Provider_Sparkpost extends BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var string Display name for our inbound provider
	 */
	public static $name = 'SparkPost';

	/**
	 * Webhook parser class method for SparkPost.
	 */
	public function webhook_parser() {
		if ( empty( $_SERVER['CONTENT_TYPE'] ) || ( ! empty( $_SERVER['CONTENT_TYPE'] ) && 'application/json' !== $_SERVER['CONTENT_TYPE'] ) ) {
			return;
		}

		if ( false === isset( $_SERVER['HTTP_X_MESSAGESYSTEMS_WEBHOOK_TOKEN'] ) ) {
			return;
		}

		bp_rbe_log( '- SparkPost webhook received -' );

		// SparkPost auth token verification.
		if ( defined( 'BP_RBE_SPARKPOST_WEBHOOK_TOKEN' ) && ! empty( $_SERVER['HTTP_X_MESSAGESYSTEMS_WEBHOOK_TOKEN'] ) ) {
			// Make sure auth token matches; if it doesn't, bail!
			if ( constant( 'BP_RBE_SPARKPOST_WEBHOOK_TOKEN' ) !== $_SERVER['HTTP_X_MESSAGESYSTEMS_WEBHOOK_TOKEN'] ) {
				bp_rbe_log( 'SparkPost token verification failed.' );
				die();
			}
		}

		$response = file_get_contents( 'php://input' );
		if ( empty( $response ) ) {
			bp_rbe_log( '- SporkPost webhook response failed -' );
		}

		$response = json_decode( $response );

		$i = 1;
		foreach ( $response as $item ) {
			// Format email headers to fit RBE spec.
			$headers = array();
			foreach ( $item->msys->relay_message->content->headers as $header ) {
				$headers = array_merge( $headers, (array) $header );
			}

			$data = array(
				'headers'    => $headers,
				'to_email'   => $item->msys->relay_message->rcpt_to,
				'from_email' => $item->msys->relay_message->friendly_from,
				'content'    => $item->msys->relay_message->content->text,
				'subject'    => $item->msys->relay_message->content->subject
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