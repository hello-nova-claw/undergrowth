<?php
/**
 * Test-only provider for end-to-end runs: deterministic vectors, embedded at
 * once, and a fixed reply wherever Claude would be asked.
 *
 * Loaded into the wp-env tests site only, never shipped.
 *
 * @package Undergrowth
 */

// PHPUnit runs in this same site and expects no provider. Checking for the
// test suite rather than the command line keeps the provider working in
// WordPress Playground, where every request runs under the CLI SAPI.
if ( function_exists( 'tests_add_filter' ) ) {
	return;
}

add_filter(
	'undergrowth_embed_provider',
	static function ( $vector, $text ) {
		$text = strtolower( (string) $text );

		// Counting a few fixture words gives posts that share words similar vectors.
		return array(
			(float) substr_count( $text, 'oca' ),
			(float) substr_count( $text, 'yacon' ),
			(float) substr_count( $text, 'syrup' ),
			(float) substr_count( $text, 'ledger' ),
			1.0,
		);
	},
	10,
	2
);

add_filter(
	'undergrowth_embed_identity',
	static function () {
		return array(
			'provider' => 'e2e',
			'model'    => 'word-counts',
		);
	}
);

// Cron does not run reliably during a test, so posts are embedded on save.
add_action(
	'wp_after_insert_post',
	static function ( $post_id ) {
		if ( function_exists( 'undergrowth_embed_post' ) ) {
			undergrowth_embed_post( (int) $post_id );
		}
	},
	20
);

// Claude is never called from a test site. Every request to the Messages API
// gets the same question back, which quotes nobody, so it passes the check.
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://api.anthropic.com/' ) ) {
			return $preempt;
		}

		$body = json_decode( (string) ( $args['body'] ?? '' ), true );

		// A question about a composted post is checked once more on its own.
		$checking = function_exists( 'undergrowth_prompt_rest_check_system' ) && undergrowth_prompt_rest_check_system() === ( $body['system'] ?? '' );

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode(
				array(
					'content'     => array(
						array(
							'type' => 'text',
							'text' => $checking ? 'NO' : 'A reader asked how you keep oca through the winter. What do you actually do?',
						),
					),
					'stop_reason' => 'end_turn',
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Discord is never called from a test site either. A message gets a made-up
// ID back, as if it had been posted.
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://discord.com/' ) ) {
			return $preempt;
		}

		return array(
			'headers'  => array(),
			'body'     => '{"id":"100000000000000001"}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Cron does not run reliably during a test, so a test asks for the monthly
// clustering pass, and the resurfacing it ends with, by visiting any admin
// page with this argument. A test site has fewer posts than one batch, so
// one call is the whole pass. The stage cycle it schedules is left to cron.
add_action(
	'admin_init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test sites only; it changes nothing an author made.
		if ( ! isset( $_GET['undergrowth_e2e_cluster'] ) || ! current_user_can( 'manage_options' ) || ! function_exists( 'undergrowth_run_cluster_cycle' ) ) {
			return;
		}

		undergrowth_run_cluster_cycle( 0 );
	}
);

/**
 * Returns the value of a test cookie when it is one of the allowed values.
 *
 * The screenshot catalogue sets these cookies per browser context, so every
 * locale and state is served by one site without changing anything stored.
 *
 * @param string   $name    Cookie name.
 * @param string[] $allowed Values the cookie may take.
 * @return string The value, or an empty string.
 */
function undergrowth_e2e_cookie( string $name, array $allowed ): string {
	$value = isset( $_COOKIE[ $name ] ) ? sanitize_key( wp_unslash( $_COOKIE[ $name ] ) ) : '';

	foreach ( $allowed as $candidate ) {
		if ( strtolower( $candidate ) === $value ) {
			return $candidate;
		}
	}

	return '';
}

add_filter(
	'determine_locale',
	static function ( $locale ) {
		$forced = undergrowth_e2e_cookie( 'undergrowth_e2e_locale', array( 'en_US', 'he_IL', 'en_XA' ) );

		return '' === $forced ? $locale : $forced;
	},
	99
);

// Each fixture changes only what one screen reads, and only for requests that
// carry the cookie.
switch ( undergrowth_e2e_cookie( 'undergrowth_e2e_fixture', array( 'no-provider', 'key-saved', 'last-error', 'suggestions-off', 'prompt-key-saved', 'prompt-last-error', 'prompt', 'discord-saved', 'discord-invalid', 'discord-last-error', 'meter-below', 'meter-crossed', 'meter-no-answer' ) ) ) {
	case 'no-provider':
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return array(
					'provider' => '',
					'model'    => '',
				);
			},
			99
		);
		break;

	case 'key-saved':
		add_filter(
			'pre_option_undergrowth_openai_api_key',
			static function () {
				return 'not-a-real-key-wxyz';
			}
		);
		break;

	case 'last-error':
		add_filter(
			'pre_option_undergrowth_last_embed_error',
			static function () {
				return array(
					'time'   => 1767225600,
					'status' => 429,
				);
			}
		);
		break;

	case 'prompt-key-saved':
		add_filter(
			'pre_option_undergrowth_anthropic_api_key',
			static function () {
				return 'not-a-real-anthropic-key-mnop';
			}
		);
		break;

	case 'prompt-last-error':
		add_filter(
			'pre_option_undergrowth_anthropic_api_key',
			static function () {
				return 'not-a-real-anthropic-key-mnop';
			}
		);
		add_filter(
			'pre_option_undergrowth_last_prompt_error',
			static function () {
				return array(
					'time'   => 1767225600,
					'status' => 529,
				);
			}
		);
		break;

	// Cron does not run reliably during a test, so the Dashboard writes the
	// prompt as it loads, from whatever reader question the test left.
	case 'prompt':
		add_filter(
			'pre_option_undergrowth_anthropic_api_key',
			static function () {
				return 'not-a-real-anthropic-key-mnop';
			}
		);
		add_action(
			'load-index.php',
			static function () {
				if ( function_exists( 'undergrowth_write_prompt' ) ) {
					undergrowth_write_prompt();
				}
			}
		);
		break;

	case 'discord-saved':
		foreach ( array(
			'application_id' => '112233445566778899',
			'public_key'     => str_repeat( '0f1e2d3c', 8 ),
			'bot_token'      => 'not-a-real-discord-token-klmn',
			'channel_id'     => '998877665544332211',
		) as $name => $value ) {
			add_filter(
				'pre_option_undergrowth_discord_' . $name,
				static function () use ( $value ) {
					return $value;
				}
			);
		}
		break;

	case 'discord-last-error':
		add_filter(
			'pre_option_undergrowth_last_discord_error',
			static function () {
				return array(
					'time'   => 1767225600,
					'status' => 429,
				);
			}
		);
		break;

	case 'discord-invalid':
		add_action(
			'admin_init',
			static function () {
				undergrowth_sanitize_discord_application_id( 'abc' );
			},
			20
		);
		break;

	// Jetpack is not installed on the test site. The PHPUnit stand-ins take its
	// place, connected with Stats on, and answer with made-up daily views.
	case 'meter-below':
	case 'meter-crossed':
	case 'meter-no-answer':
		// Jetpack shows Stats to administrators unless its settings say otherwise.
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'view_stats' === $cap ? array( 'manage_options' ) : $caps;
			},
			10,
			2
		);
		add_action(
			'plugins_loaded',
			static function () {
				if ( ! defined( 'UNDERGROWTH_FILE' ) ) {
					return;
				}

				$includes = dirname( UNDERGROWTH_FILE ) . '/tests/phpunit/includes/';

				if ( ! class_exists( 'Jetpack' ) ) {
					require_once $includes . 'class-jetpack.php';
				}

				if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
					require_once $includes . 'class-wpcom-stats.php';
				}

				Jetpack::$active_modules = array( 'stats' );
				Jetpack::$connected      = true;

				$fixture = undergrowth_e2e_cookie( 'undergrowth_e2e_fixture', array( 'meter-crossed', 'meter-no-answer' ) );

				if ( 'meter-no-answer' === $fixture ) {
					Automattic\Jetpack\Stats\WPCOM_Stats::$visits = new WP_Error( 'stats_error', 'Failed to fetch Stats from WPCOM' );
				} else {
					$data = array();

					for ( $day = 0; $day < 30; $day++ ) {
						$data[] = array( gmdate( 'Y-m-d', 1789776000 + $day * DAY_IN_SECONDS ), 'meter-crossed' === $fixture ? 41 : 14, 5 );
					}

					Automattic\Jetpack\Stats\WPCOM_Stats::$visits = array(
						'fields' => array( 'period', 'views', 'visitors' ),
						'data'   => $data,
					);
				}

				// Each request shows its own fixture, never one cached by another.
				delete_transient( 'undergrowth_meter' );
			}
		);
		break;

	case 'suggestions-off':
		add_filter(
			'pre_option_undergrowth_suggest_links',
			static function () {
				return '0';
			}
		);
		break;
}
