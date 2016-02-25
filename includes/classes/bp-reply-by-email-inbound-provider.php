<?php
/**
 * BP Reply By Email Inbound Provider class.
 *
 * @package BP_Reply_By_Email
 * @subpackage Classes
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstract base class for adding an inbound email provider.
 *
 * @since 1.0-RC3
 */
abstract class BP_Reply_By_Email_Inbound_Provider {
	/**
	 * @var array An array containing names and types of abstract properties that must
	 *      be implemented in child classes.
	 */
	private $_abstract_properties = array(
		'name' => array(
			'type'   => 'string',
			'static' => true,
		),
	);

	/**
	 * Constructor.
	 */
	final public function __construct() {
		$this->_abstract_properties_validate();
	}

	/**
	 * Make sure our class properties exist in extended classes.
	 *
	 * PHP doesn't accept abstract class properties, so this class method adds
	 * this capability.
	 */
	final protected function _abstract_properties_validate() {
		//check if the child class has defined the abstract properties or not
		$child = get_class( $this );

		foreach ( $this->_abstract_properties as $name => $settings ) {
			if ( isset( $settings['type'] ) && 'string' == $settings['type'] ) {
				if ( isset( $settings['static'] ) && true === $settings['static'] ) {
					$prop = new ReflectionProperty( $child, $name );

					if ( ! $prop->isStatic() ) {
						// property does not exist
						$error = $child . ' class must define $' . $name . ' property as static ' . $settings['type'];
						unset( $prop, $child );
						throw new \LogicException( $error );
					}
				} else {

					if ( property_exists( $this, $name ) && strtolower( gettype( $this->$name ) ) == $settings['type'] ) {
						continue;
					}

					// property does not exist
					$error = $child . ' class must define $' . $name . ' property as ' . $settings['type'];

					throw new \LogicException( $error );
				}
			}
		}

		unset( $error, $child );
	}

	/**
	 * Validates post callback and posts the data on success.
	 *
	 * This method must exist in extended classes.
	 */
	abstract public function webhook_parser();
}