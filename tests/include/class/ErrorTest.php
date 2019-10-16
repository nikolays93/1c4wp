<?php
/**
 * Class ErrorTest
 *
 * @package Woocommerce.1c.Exchanger
 */

use NikolayS93\Exchanger\Error;
use const NikolayS93\Exchanger\PLUGIN_DIR;

require PLUGIN_DIR . 'tests/helper.php';

class ErrorTest extends WP_UnitTestCase {

	public function testInstance() {
		$this->assertInstanceOf( Error::class, Error::get_instance() );
	}

	/**
	 * Unrealized test
	 *
	 * @todo
	 */
	public function testGet_messages() {
		$this->assertTrue( true );
	}

	/**
	 * Unrealized test
	 *
	 * @todo
	 */
	public function testShow_messages() {
		$this->assertTrue( true );
	}

	/**
	 * Unrealized test
	 *
	 * @todo
	 */
	public function testAdd_message() {
		$this->assertTrue( true );
	}

	/**
	 * Unrealized test
	 *
	 * @todo
	 */
	public function testSet_strict_mode() {
		$this->assertTrue( true );
	}

	/**
	 * Unrealized test
	 *
	 * @todo
	 */
	public function testStrict_error_handler() {
		$this->assertTrue( true );
	}

	/**
	 * Unrealized test
	 *
	 * @todo
	 */
	public function testStrict_exception_handler() {
		$this->assertTrue( true );
	}
}
