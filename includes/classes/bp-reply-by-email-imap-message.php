<?php
/**
 * BP Reply By Email IMAP Message Class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class to parse an email message from an IMAP connection.
 *
 * Slightly, modified version of the EmailMessage class from Chris Hope over
 * at ElectricToolbox.com.
 *
 * @since 1.0-RC6
 *
 * @link https://www.electrictoolbox.com/php-email-message-class-extracting-attachments/
 */
class BP_Reply_By_Email_IMAP_Message {

	/**
	 * IMAP connection.
	 *
	 * @var resource
	 */
	protected $connection;

	/**
	 * Current IMAP message number.
	 *
	 * @var int
	 */
	protected $messageNumber;

	/**
	 * Attachments holder.
	 *
	 * @var array
	 */
	public $attachments = array();

	/**
	 * Whether to fetch attachments.
	 *
	 * @var bool Defaults to true.
	 */
	public $getAttachments = true;

	/**
	 * Constructor.
	 *
	 * @param resource $connection    IMAP connection.
	 * @param int      $messageNumber IMAP message number.
	 */
	public function __construct( $connection, $messageNumber ) {
		$this->connection    = $connection;
		$this->messageNumber = $messageNumber;
	}

	/**
	 * Static method to fetch attachments.
	 *
	 * @param  resource $connection    IMAP connection.
	 * @param  int      $messageNumber Current message number.
	 * @param  array    $parts         Current message parts. To get this, use imap_fetchstructure() and pass the
	 *                                 'parts' property.
	 * @return array
	 */
	public static function getAttachments( $connection, $messageNumber, $parts = array() ) {
		$message = new self( $connection, $messageNumber );
		$message->recurse( $parts );
		return $message->attachments;
	}

	/**
	 * Recurse method.
	 *
	 * Parses all message parts for attachments.
	 *
	 * @param array  $messageParts Array of message parts.
	 * @param string $prefix       Prefix for message part.
	 * @param int    $index        Index iterator.
	 * @param bool   $fullPrefix   Whether use full prefix.
	 */
	public function recurse( $messageParts, $prefix = '', $index = 1, $fullPrefix = true ) {
		foreach ( $messageParts as $part ) {
			$partNumber = $prefix . $index;

			/*
			// Commenting this out for now.
			if ( $part->type == 0 ) {
				if ( $part->subtype == 'PLAIN' ) {
					$this->bodyPlain .= $this->getPart( $partNumber, $part->encoding );
				} else {
					$this->bodyHTML .= $this->getPart( $partNumber, $part->encoding );
				}
			}
			*/

			if ( $part->type == 2 ) {
				$msg = new self( $this->connection, $this->messageNumber );
				$msg->getAttachments = $this->getAttachments;
				$msg->recurse( $part->parts, $partNumber . '.', 0, false );
				$this->attachments[] = array(
					'type'     => $part->type,
					'subtype'  => $part->subtype,
					'filename' => '',
					'data'     => $msg,
					'inline'   => false,
				 );
			} elseif ( isset( $part->parts ) ) {
				if ( $fullPrefix ) {
					$this->recurse( $part->parts, $prefix . $index . '.' );
				} else {
					$this->recurse( $part->parts, $prefix );
				}

			} elseif ( $part->type > 2 ) {
				if( isset( $part->id ) ) {
					$id = str_replace( array( '<', '>' ), '', $part->id );
					$this->attachments[$id] = array(
						'type'     => $part->type,
						'subtype'  => $part->subtype,
						'filename' => $this->getFilenameFromPart( $part ),
						'data'     => $this->getAttachments ? $this->getPart( $partNumber, $part->encoding ) : '',
						'inline'   => true,
					 );
				} else {
					$this->attachments[] = array(
						'type'     => $part->type,
						'subtype'  => $part->subtype,
						'filename' => $this->getFilenameFromPart( $part ),
						'data'     => $this->getAttachments ? $this->getPart( $partNumber, $part->encoding ) : '',
						'inline'   => false,
					 );
				}
			}

			$index++;
		}
	}

	/**
	 * Fetch a specific part of an email message.
	 *
	 * @param  string $partNumber Part number.
	 * @param  string $encoding   Message encoding.
	 * @return string
	 */
	public function getPart( $partNumber, $encoding ) {
		$data = imap_fetchbody( $this->connection, $this->messageNumber, $partNumber );

		switch ( $encoding ) {
			// Base-64.
			case 3:
				return base64_decode( $data );

			// Quoted-printable.
			case 4:
				return quoted_printable_decode( $data );

			/**
			 * 0 - 7-bit.
			 * 1 - 8-bit.
			 * 2 - Binary.
			 * 5 - Other.
			 */
			default :
				return $data;
		}
	}

	/**
	 * Get filename from part.
	 *
	 * @param  object $part Message part.
	 * @return string
	 */
	public function getFilenameFromPart( $part ) {
		$filename = '';

		if ( $part->ifdparameters ) {
			foreach ( $part->dparameters as $object ) {
				if ( strtolower( $object->attribute ) === 'filename' ) {
					$filename = $object->value;
				}
			}
		}

		if( ! $filename && $part->ifparameters ) {
			foreach ( $part->parameters as $object ) {
				if ( strtolower( $object->attribute ) === 'name' ) {
					$filename = $object->value;
				}
			}
		}

		return $filename;
	}
}