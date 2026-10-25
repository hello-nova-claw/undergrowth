<?php
/**
 * A stand-in for Polylang's post language function, loaded only when
 * Polylang is not installed.
 *
 * It is defined after WordPress has loaded, so Undergrowth does not pick it
 * up on its own. A test turns the integration on with
 * undergrowth_hook_multilingual_language(). PHPStan reads it for the
 * signature too.
 *
 * @package Undergrowth
 */

/**
 * Returns a post's language, as a test set it.
 *
 * @param int    $post_id Post ID.
 * @param string $field   slug, locale or name.
 * @return string|false The language, or false when the post has none.
 */
function pll_get_post_language( $post_id, $field = 'slug' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Polylang's signature.
	return $GLOBALS['undergrowth_test_post_languages'][ $post_id ] ?? false;
}
