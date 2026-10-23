<?php
/**
 * Test helper: a stand-in for WP-CLI, which the PHPUnit suite does not load.
 *
 * @package Undergrowth
 */

/**
 * Records the commands added and the lines printed, and stops on an error
 * as WP-CLI would.
 */
class WP_CLI {

	/**
	 * Commands added, keyed by name.
	 *
	 * @var array<string, callable>
	 */
	public static $commands = array();

	/**
	 * Lines printed, each as its kind and its text.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public static $lines = array();

	/**
	 * Forgets what earlier tests added and printed.
	 */
	public static function reset(): void {
		self::$commands = array();
		self::$lines    = array();
	}

	/**
	 * Adds a command.
	 *
	 * @param string   $name     Command name.
	 * @param callable $callable What runs it.
	 */
	public static function add_command( $name, $callable ): void {
		self::$commands[ $name ] = $callable;
	}

	/**
	 * Prints a line.
	 *
	 * @param string $message Line.
	 */
	public static function log( $message ): void {
		self::$lines[] = array( 'log', (string) $message );
	}

	/**
	 * Prints a success line.
	 *
	 * @param string $message Line.
	 */
	public static function success( $message ): void {
		self::$lines[] = array( 'success', (string) $message );
	}

	/**
	 * Prints a warning.
	 *
	 * @param string $message Line.
	 */
	public static function warning( $message ): void {
		self::$lines[] = array( 'warning', (string) $message );
	}

	/**
	 * Stops with an error.
	 *
	 * @param string $message Line.
	 * @throws WP_CLI_Stopped Always.
	 */
	public static function error( $message ): void {
		self::$lines[] = array( 'error', (string) $message );
		throw new WP_CLI_Stopped( (string) $message );
	}

	/**
	 * Asks before going on. With --yes it goes on; without, the test stand-in
	 * answers no.
	 *
	 * @param string               $question   Question.
	 * @param array<string, mixed> $assoc_args Options the command was given.
	 * @throws WP_CLI_Stopped When the answer is no.
	 */
	public static function confirm( $question, $assoc_args = array() ): void {
		self::$lines[] = array( 'confirm', (string) $question );

		if ( empty( $assoc_args['yes'] ) ) {
			throw new WP_CLI_Stopped( 'No.' );
		}
	}
}
