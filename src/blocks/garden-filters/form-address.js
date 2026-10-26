/**
 * Builds the address a filter form would send, without the empty filters.
 *
 * @param {HTMLFormElement} form The filters form.
 * @return {string} Address of the filtered garden.
 */
export function formAddress( form ) {
	// A field named action, carried over from the page's query args, would
	// shadow the form's action property, so the attribute is read.
	const url = new URL(
		form.getAttribute( 'action' ) ?? '',
		window.location.href
	);

	url.search = '';
	for ( const [ name, value ] of new FormData( form ) ) {
		if ( value !== '' ) {
			url.searchParams.append( name, value );
		}
	}

	return url.href;
}
