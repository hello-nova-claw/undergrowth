<?php
/**
 * Reading the text of an HTML fragment as a browser does.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists an HTML fragment's paragraphs and text nodes with their byte offsets.
 *
 * The tag processor knows where a tag really ends, even when an attribute
 * value holds a closing bracket or markup of its own, but it hands out text
 * already decoded and keeps where each token sits to itself. A bookmark on a
 * token is the one way it shares that position.
 */
class Undergrowth_HTML_Text extends WP_HTML_Tag_Processor {

	/**
	 * Finds every paragraph whose closing tag is present.
	 *
	 * A paragraph opened before the last one closed ends where the next one
	 * opens, as a browser reads it. One never closed is left out, since where
	 * it ends is unknown.
	 *
	 * @return array<int, array{0: string, 1: int}> Each paragraph's inner HTML and its byte offset, in order.
	 */
	public function paragraphs(): array {
		$paragraphs = array();
		$start      = null;

		while ( $this->next_token() ) {
			if ( 'P' !== $this->get_tag() ) {
				continue;
			}

			$span = $this->token_span();

			if ( null !== $start ) {
				$paragraphs[] = array( substr( $this->html, $start, $span[0] - $start ), $start );
			}

			$start = $this->is_tag_closer() ? null : $span[0] + $span[1];
		}

		return $paragraphs;
	}

	/**
	 * Reads every text node a reader sees, in order.
	 *
	 * Comments, tags with their attributes, and the contents of elements such
	 * as script and style are not text and are left out.
	 *
	 * @return array<int, array{0: string, 1: int, 2: bool}> Each node's raw HTML, its byte offset, and whether it sits inside a link.
	 */
	public function text_nodes(): array {
		$nodes = array();
		$depth = 0;

		while ( $this->next_token() ) {
			if ( 'A' === $this->get_tag() ) {
				$depth = $this->is_tag_closer() ? max( 0, $depth - 1 ) : $depth + 1;
			} elseif ( '#text' === $this->get_token_type() ) {
				$span    = $this->token_span();
				$nodes[] = array( substr( $this->html, $span[0], $span[1] ), $span[0], $depth > 0 );
			}
		}

		return $nodes;
	}

	/**
	 * Tells where the token the processor stopped on sits in the HTML.
	 *
	 * @return array{0: int, 1: int} Its byte offset and length.
	 */
	private function token_span(): array {
		$this->set_bookmark( 'here' );
		$span = $this->bookmarks['here'];
		$this->release_bookmark( 'here' );

		return array( $span->start, $span->length );
	}
}
