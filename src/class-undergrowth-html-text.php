<?php
/**
 * Reading the text of an HTML fragment as a browser does.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists an HTML fragment's text nodes with their byte offsets.
 *
 * The tag processor knows where a tag really ends, even when an attribute
 * value holds a closing bracket, but it hands out text already decoded and
 * keeps where each node sits to itself. A bookmark on each text node is the
 * one way it shares that position.
 */
class Undergrowth_HTML_Text extends WP_HTML_Tag_Processor {

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
			} elseif ( '#text' === $this->get_token_type() && $this->set_bookmark( 'text' ) ) {
				$span    = $this->bookmarks['text'];
				$nodes[] = array( substr( $this->html, $span->start, $span->length ), $span->start, $depth > 0 );
			}
		}

		$this->release_bookmark( 'text' );

		return $nodes;
	}
}
