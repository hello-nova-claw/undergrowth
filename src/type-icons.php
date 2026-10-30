<?php
/**
 * An icon for each type of post, for the Garden feed's text cards.
 *
 * The drawings are Undergrowth's own, in the same lines as the growth stage
 * glyphs, so the two sit together in one card. Each core post format has
 * its own; a term from a category or a custom taxonomy gets a leaf, and a
 * theme or a child plugin can give any term its own through a filter.
 *
 * @package Undergrowth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Draws a type's icon.
 *
 * The icon is decorative: the type's word stays as text beside it, so it is
 * hidden from screen readers and never a link.
 *
 * @param string $slug     Term slug, or a post format slug such as aside.
 * @param string $taxonomy Taxonomy the slug belongs to.
 * @return string SVG markup. A filter's markup is cleaned: no link, no script,
 *                or an empty string when the filter gives the type no SVG.
 */
function undergrowth_type_icon( string $slug, string $taxonomy ): string {
	$drawings = array(
		// A sheet with a turned corner and lines of text.
		'standard' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M10 12h5"/><path d="M10 15.5h5"/>',
		// A small note: one short card with two lines.
		'aside'    => '<path d="M5 7.5A1.5 1.5 0 0 1 6.5 6h11A1.5 1.5 0 0 1 19 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 16.5z"/><path d="M9 10.5h6"/><path d="M9 13.5h4"/>',
		// Two links of a chain.
		'link'     => '<path d="M10.5 13.5a3.5 3.5 0 0 0 5 0l3-3a3.5 3.5 0 0 0-5-5l-1.2 1.2"/><path d="M13.5 10.5a3.5 3.5 0 0 0-5 0l-3 3a3.5 3.5 0 0 0 5 5l1.2-1.2"/>',
		// A pair of opening quotation marks.
		'quote'    => '<path d="M10 7.5c-3 .5-4.5 2.4-4.5 5.5v3.5H10V11H7.6c.2-1.6 1-2.5 2.4-2.8z"/><path d="M18.5 7.5c-3 .5-4.5 2.4-4.5 5.5v3.5h4.5V11h-2.4c.2-1.6 1-2.5 2.4-2.8z"/>',
		// One speech bubble with a short line: a quick update.
		'status'   => '<path d="M5 6.5A1.5 1.5 0 0 1 6.5 5h11A1.5 1.5 0 0 1 19 6.5v8a1.5 1.5 0 0 1-1.5 1.5H10l-4 3.5V16h.5A1.5 1.5 0 0 1 5 14.5z"/><path d="M9 10.5h6"/>',
		// A frame with a sun and hills.
		'image'    => '<path d="M4 6.5A1.5 1.5 0 0 1 5.5 5h13A1.5 1.5 0 0 1 20 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><path d="M4 16l4.5-5 4 4 2.5-2.5L20 17"/><circle cx="15.5" cy="9.5" r="1.25"/>',
		// Two frames, one behind the other.
		'gallery'  => '<path d="M8 8V5.5A1.5 1.5 0 0 1 9.5 4h9A1.5 1.5 0 0 1 20 5.5v9a1.5 1.5 0 0 1-1.5 1.5H16"/><path d="M4 9.5A1.5 1.5 0 0 1 5.5 8h9A1.5 1.5 0 0 1 16 9.5v9a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 4 18.5z"/><path d="M4 17l3.5-4 3 3 1.5-1.5 4 4.5"/>',
		// A frame with a play mark.
		'video'    => '<path d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5z"/><path d="M10 9.5v5l4-2.5z"/>',
		// Five bars of a waveform.
		'audio'    => '<path d="M4 10v4"/><path d="M8 7v10"/><path d="M12 4.5v15"/><path d="M16 8v8"/><path d="M20 10.5v3"/>',
		// Two speech bubbles answering each other.
		'chat'     => '<path d="M4 6.5A1.5 1.5 0 0 1 5.5 5h7A1.5 1.5 0 0 1 14 6.5v4a1.5 1.5 0 0 1-1.5 1.5H9l-3 2.5V12h-.5A1.5 1.5 0 0 1 4 10.5z"/><path d="M14 10h4.5A1.5 1.5 0 0 1 20 11.5v4a1.5 1.5 0 0 1-1.5 1.5H18v2.5l-3-2.5h-4.5A1.5 1.5 0 0 1 9 15.5V14"/>',
	);
	// A leaf with its midrib, for a term of any other taxonomy.
	$leaf = '<path d="M5 19C5 10.5 10.5 5 19 5c0 8.5-5.5 14-14 14z"/><path d="M5 19l8-8"/>';

	$drawing = 'post_format' === $taxonomy && isset( $drawings[ $slug ] ) ? $drawings[ $slug ] : $leaf;

	$svg = sprintf(
		'<svg class="undergrowth-type-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
		$drawing
	);

	/**
	 * Filters a type's icon in the Garden feed.
	 *
	 * The icon is decorative and sits outside the title's link, so it may
	 * hold no link of its own. Anything but inline SVG is stripped.
	 *
	 * @param string $svg      SVG markup.
	 * @param string $slug     Term slug, or a post format slug such as aside.
	 * @param string $taxonomy Taxonomy the slug belongs to.
	 */
	$filtered = (string) apply_filters( 'undergrowth_garden_type_icon', $svg, $slug, $taxonomy );

	// The plugin's own drawing is fixed markup, so it is used as it is: each
	// WordPress version's wp_kses() orders and cases attributes its own way.
	if ( $filtered === $svg ) {
		return $svg;
	}

	// Only the drawing itself: text left behind by a stripped tag would show.
	// The opening tag may carry attributes or none.
	preg_match( '#<svg[\s>].*?</svg>#s', wp_kses( $filtered, undergrowth_type_icon_allowed_html() ), $found );

	// WordPress 7.0's wp_kses() lowercases attribute names, and SVG's
	// viewBox is case-sensitive outside an HTML document.
	$cleaned = str_replace( ' viewbox=', ' viewBox=', $found[0] ?? '' );

	if ( '' === $cleaned ) {
		return '';
	}

	// The icon stays decorative whatever the filter put on its root: the
	// type's word is in the details line, and the title is the only link.
	// wp_kses() has already dropped any aria-hidden or focusable the filter
	// set, since neither is in the allow-list, and the fixed pair goes in
	// before the attributes that remain, so none of their text is read.
	return substr_replace( $cleaned, '<svg aria-hidden="true" focusable="false"', 0, 4 );
}

/**
 * Lists the SVG markup a type's icon may hold.
 *
 * @return array<string, array<string, bool>> For wp_kses().
 */
function undergrowth_type_icon_allowed_html(): array {
	$presentation = array(
		'class'           => true,
		'fill'            => true,
		'fill-rule'       => true,
		'stroke'          => true,
		'stroke-width'    => true,
		'stroke-linecap'  => true,
		'stroke-linejoin' => true,
		'opacity'         => true,
		'transform'       => true,
	);

	return array(
		'svg'      => array_merge(
			$presentation,
			array(
				// Not aria-hidden or focusable: the icon's own values are set
				// after cleaning, so a filter cannot expose it or make it a stop.
				'xmlns'   => true,
				'viewbox' => true,
				'width'   => true,
				'height'  => true,
			)
		),
		'g'        => $presentation,
		'path'     => array_merge( $presentation, array( 'd' => true ) ),
		'circle'   => array_merge(
			$presentation,
			array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			)
		),
		'ellipse'  => array_merge(
			$presentation,
			array(
				'cx' => true,
				'cy' => true,
				'rx' => true,
				'ry' => true,
			)
		),
		'rect'     => array_merge(
			$presentation,
			array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			)
		),
		'line'     => array_merge(
			$presentation,
			array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			)
		),
		'polyline' => array_merge( $presentation, array( 'points' => true ) ),
		'polygon'  => array_merge( $presentation, array( 'points' => true ) ),
	);
}
