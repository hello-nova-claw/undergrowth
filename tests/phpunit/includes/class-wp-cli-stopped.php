<?php
/**
 * Test helper: what the WP-CLI stand-in throws where WP-CLI would end the command.
 *
 * @package Undergrowth
 */

/**
 * Thrown where WP-CLI would end the command.
 */
class WP_CLI_Stopped extends Exception {
}
