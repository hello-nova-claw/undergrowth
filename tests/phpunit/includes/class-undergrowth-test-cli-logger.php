<?php
/**
 * Test helper: a WP-CLI logger that keeps what a command prints.
 *
 * @package Undergrowth
 */

/**
 * Records each line WP-CLI would print, with its kind.
 */
class Undergrowth_Test_CLI_Logger {

	/**
	 * Lines printed, each as its kind and its text.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public $lines = array();

	/**
	 * Records a plain line.
	 *
	 * @param string $message Line.
	 */
	public function info( $message ): void {
		$this->lines[] = array( 'log', (string) $message );
	}

	/**
	 * Records a success line.
	 *
	 * @param string $message Line.
	 */
	public function success( $message ): void {
		$this->lines[] = array( 'success', (string) $message );
	}

	/**
	 * Records a warning.
	 *
	 * @param string $message Line.
	 */
	public function warning( $message ): void {
		$this->lines[] = array( 'warning', (string) $message );
	}

	/**
	 * Records an error.
	 *
	 * @param string $message Line.
	 */
	public function error( $message ): void {
		$this->lines[] = array( 'error', (string) $message );
	}

	/**
	 * Records an error given as several lines.
	 *
	 * @param string[] $message_lines Lines.
	 */
	public function error_multi_line( $message_lines ): void {
		$this->lines[] = array( 'error', implode( "\n", (array) $message_lines ) );
	}

	/**
	 * Ignores debug output.
	 *
	 * @param string      $message Line.
	 * @param string|bool $group   Debug group.
	 */
	public function debug( $message, $group = false ): void {
		unset( $message, $group );
	}
}
