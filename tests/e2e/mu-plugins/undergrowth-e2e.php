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

/**
 * Says whether this request shows Settings as a site without a provider of
 * its own sees it, with the OpenAI key field, so the e2e provider stands
 * aside.
 *
 * @return bool
 */
function undergrowth_e2e_openai_fixture(): bool {
	return '' !== undergrowth_e2e_cookie( 'undergrowth_e2e_fixture', array( 'key-saved', 'last-error', 'first-key-saved' ) );
}

/**
 * Says whether this site holds the demo blog, as the Playground preview does,
 * rather than the fixture posts the specs depend on.
 *
 * @return bool
 */
function undergrowth_e2e_demo(): bool {
	return (bool) get_option( 'undergrowth_e2e_demo' );
}

/**
 * Counts the words of a demo post, hashed into a fixed number of places.
 *
 * The demo posts have none of the fixture words. Counting all their longer
 * words still gives posts that share words similar vectors, with no model.
 *
 * @param string $text Text to embed.
 * @return float[]
 */
function undergrowth_e2e_demo_vector( string $text ): array {
	$common = array( 'which', 'there', 'their', 'would', 'these', 'those', 'could', 'about', 'where', 'every', 'should', 'after', 'before', 'while', 'being', 'other', 'shall', 'might', 'never', 'first', 'great', 'little', 'through', 'again', 'still', 'though', 'always', 'nothing', 'something', 'thing', 'things' );
	$vector = array_fill( 0, 2048, 0.0 );

	preg_match_all( '/\p{L}{5,}/u', mb_strtolower( $text ), $words );

	foreach ( array_diff( $words[0], $common ) as $word ) {
		// Masked, because crc32() is negative for some words where integers are 32 bits.
		$vector[ crc32( $word ) & 2047 ] += 1.0;
	}

	// Square roots keep a few repeated words from outweighing the rest.
	return array_map( 'sqrt', $vector );
}

add_filter(
	'undergrowth_embed_provider',
	static function ( $vector, $text ) {
		if ( undergrowth_e2e_openai_fixture() ) {
			return $vector;
		}

		if ( undergrowth_e2e_demo() ) {
			return undergrowth_e2e_demo_vector( (string) $text );
		}

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
	static function ( $identity ) {
		if ( undergrowth_e2e_openai_fixture() ) {
			return $identity;
		}

		return array(
			'provider' => 'e2e',
			'model'    => undergrowth_e2e_demo() ? 'demo-word-hash' : 'word-counts',
		);
	}
);

// Cron does not run reliably during a test, so posts are embedded on save.
// The demo blog is read in one go once it is imported instead.
add_action(
	'wp_after_insert_post',
	static function ( $post_id ) {
		if ( function_exists( 'undergrowth_embed_post' ) && 'importing' !== get_option( 'undergrowth_e2e_demo' ) ) {
			undergrowth_embed_post( (int) $post_id );
		}
	},
	20
);

/**
 * Reads the whole demo blog once it is imported, as the backfill and the
 * monthly cycle would over a few hours: every post is embedded, the cycle
 * measures the site's scores and finds groups, and each post gets its
 * suggestions once.
 *
 * Refreshing the neighbours of every post as it was imported would compare
 * each post with all the others again and again, which took minutes.
 */
function undergrowth_e2e_read_demo(): void {
	update_option( 'undergrowth_e2e_demo', 1 );

	$ids = get_posts(
		array(
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);

	remove_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around' );

	foreach ( $ids as $id ) {
		undergrowth_embed_post( (int) $id );
	}

	add_action( 'undergrowth_post_embedded', 'undergrowth_refresh_around' );

	// Suggestions use the thresholds the cycle measures from these posts. A
	// batch that fills up leaves the next to cron, which never runs here, so
	// each one is taken off the schedule and run now. Only those events carry
	// the last post ID; the monthly event has none.
	$after = 0;

	do {
		undergrowth_run_cluster_cycle( $after );
		$next = null;

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( $events['undergrowth_cluster_cycle'] ?? array() as $event ) {
				if ( isset( $event['args'][0] ) && (int) $event['args'][0] > $after ) {
					$next = (int) $event['args'][0];
				}
			}
		}

		if ( null !== $next ) {
			wp_clear_scheduled_hook( 'undergrowth_cluster_cycle', array( $next ) );
			$after = $next;
		}
	} while ( null !== $next );

	$pool   = undergrowth_comparable_vectors( (int) $ids[0] );
	$corpus = undergrowth_build_corpus( array_keys( $pool ) );

	foreach ( array_keys( $pool ) as $id ) {
		undergrowth_refresh_proposals( (int) $id, $pool, $corpus );
	}

	undergrowth_run_stage_cycle();
}

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
 * Makes every group on the Map dense enough for a hub, since accepting
 * links one by one would make a test far slower than what it checks.
 */
function undergrowth_e2e_any_group_is_dense(): void {
	add_filter( 'undergrowth_hub_min_size', '__return_zero' );
	add_filter(
		'undergrowth_hub_min_linked_share',
		static function () {
			return 0.0;
		}
	);
}

// A test asks for a hub draft for the first group on the Map, as Start a
// hub draft makes it, by visiting any admin page with this argument.
add_action(
	'admin_init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test sites only; it makes a draft no reader sees.
		if ( ! isset( $_GET['undergrowth_e2e_hub'] ) || ! current_user_can( 'manage_options' ) || ! function_exists( 'undergrowth_start_hub' ) ) {
			return;
		}

		undergrowth_e2e_any_group_is_dense();

		$map = undergrowth_cluster_map();

		if ( array() !== $map ) {
			undergrowth_start_hub( $map[0]['leader'] );
		}
	}
);

// A test that activates the plugin asks for a fresh first run, since the
// real one happens once per site. It is a REST route, so it needs the REST
// nonce as well as an administrator.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'undergrowth-e2e/v1',
			'/first-run',
			array(
				'methods'             => 'DELETE',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					return rest_ensure_response( delete_option( 'undergrowth_first_run' ) );
				},
			)
		);
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

// The dev tools are on in wp-env and the catalogue's Playground, which are
// local sites, so every screen would show their tab. Only a request that
// carries this cookie keeps them; the filter can only turn them off.
add_filter(
	'undergrowth_dev_tools_enabled',
	static function ( $enabled ) {
		return $enabled && '1' === undergrowth_e2e_cookie( 'undergrowth_e2e_dev_tools', array( '1' ) );
	}
);

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
switch ( undergrowth_e2e_cookie( 'undergrowth_e2e_fixture', array( 'no-provider', 'key-saved', 'code-provider-key-saved', 'last-error', 'file-last-error', 'accept-last-error', 'suggestions-off', 'post-types-by-code', 'digest-on', 'prompt-key-saved', 'prompt-asked', 'prompt-last-error', 'prompt-refused', 'prompt', 'discord-saved', 'discord-invalid', 'discord-last-error', 'index-partial', 'backfill-stalled', 'tight-gaps', 'hubs', 'meter-below', 'meter-crossed', 'meter-no-answer', 'first-run', 'first-key-saved' ) ) ) {
	case 'first-run':
		// As right after activation on a site with no key.
		add_filter(
			'pre_option_undergrowth_first_run',
			static function () {
				return 'waiting';
			}
		);
		// Falls through: the notice shows only while there is no provider.

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

	case 'first-key-saved':
		// As Settings looks right after the first key is saved.
		add_action(
			'load-settings_page_undergrowth',
			static function () {
				undergrowth_first_key_saved( '', 'not-a-real-key-wxyz' );
			}
		);
		break;

	// Both save the same key. With key-saved the e2e provider stands aside,
	// so OpenAI reads the posts; with code-provider-key-saved it keeps them.
	case 'key-saved':
	case 'code-provider-key-saved':
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

	case 'file-last-error':
		add_filter(
			'pre_option_undergrowth_last_file_error',
			static function () {
				return array(
					'code' => 'no_direct_access',
					'time' => 1767225600,
				);
			}
		);
		break;

	case 'digest-on':
		add_filter(
			'get_user_metadata',
			static function ( $value, $object_id, $meta_key ) {
				return 'undergrowth_digest' === $meta_key ? array( '1767225600' ) : $value;
			},
			10,
			3
		);
		break;

	case 'accept-last-error':
		add_filter(
			'pre_option_undergrowth_last_accept_error',
			static function () {
				return array(
					'code'   => 'empty_content',
					'status' => 0,
					'time'   => 1767225600,
				);
			}
		);
		break;

	case 'post-types-by-code':
		add_filter(
			'undergrowth_post_types',
			static function () {
				return array( 'post', 'page' );
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
		// Other specs leave questions behind on the test site, and this state
		// is the one before the first question.
		add_filter( 'pre_option_undergrowth_prompt', '__return_empty_array' );
		add_filter( 'pre_option_undergrowth_snoozed_prompts', '__return_empty_array' );
		add_filter( 'option_undergrowth_prompt_written', '__return_false' );
		break;

	case 'prompt-asked':
		add_filter(
			'pre_option_undergrowth_anthropic_api_key',
			static function () {
				return 'not-a-real-anthropic-key-mnop';
			}
		);
		add_filter(
			'pre_option_undergrowth_snoozed_prompts',
			static function () {
				return array( array( 'key' => 'comment:1' ) );
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

	case 'prompt-refused':
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
					'code'   => 'refusal',
					'time'   => 1767225600,
					'status' => 200,
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

				if ( ! class_exists( 'Automattic\Jetpack\Modules' ) ) {
					require_once $includes . 'class-modules.php';
				}

				if ( ! class_exists( 'Automattic\Jetpack\Connection\Manager' ) ) {
					require_once $includes . 'class-manager.php';
				}

				if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
					require_once $includes . 'class-wpcom-stats.php';
				}

				Automattic\Jetpack\Modules::$active               = array( 'stats' );
				Automattic\Jetpack\Connection\Manager::$connected = true;

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

	// Every vector reads as another model's, as just after the model changed,
	// so the settings page counts the posts as not indexed yet.
	// As on a site whose cron stopped a day ago, with every post still to read.
	case 'backfill-stalled':
		add_filter(
			'pre_option_undergrowth_backfill_progress',
			static function () {
				return array_merge( array( 'time' => time() - 25 * HOUR_IN_SECONDS ), undergrowth_index_counts() );
			}
		);
		// Falls through: posts are left to read only for another model.

	case 'index-partial':
		add_filter(
			'undergrowth_embed_identity',
			static function () {
				return array(
					'provider' => 'e2e',
					'model'    => 'word-counts-v2',
				);
			},
			99
		);
		break;

	// Fixture vectors are too few and too regular to leave a cluster's centre
	// empty at the real threshold, so only a perfectly tight cluster has none.
	case 'hubs':
		undergrowth_e2e_any_group_is_dense();
		break;

	case 'tight-gaps':
		add_filter(
			'undergrowth_gap_similarity',
			static function () {
				return 0.99;
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
