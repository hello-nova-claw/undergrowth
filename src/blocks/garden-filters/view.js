import { getElement, store } from '@wordpress/interactivity';

import { formAddress } from './form-address';

const { actions } = store( 'undergrowth/garden', {
	actions: {
		/**
		 * Loads another garden address and swaps the feed and the filters in
		 * place, keeping the address in the browser's history. If the router
		 * cannot load, the browser goes there as it would without JavaScript.
		 *
		 * @param {string} href Address to load.
		 */
		*go( href ) {
			let router;

			try {
				router = yield import( '@wordpress/interactivity-router' );
			} catch {
				window.location.assign( href );
				return;
			}

			yield router.actions.navigate( href );
		},

		/**
		 * Follows a topic or page link in place. A link opened in a new tab
		 * or window is left to the browser.
		 *
		 * @param {MouseEvent} event Click on the link.
		 */
		*navigate( event ) {
			if (
				event.button !== 0 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return;
			}

			event.preventDefault();
			yield actions.go( getElement().ref.href );
		},

		/**
		 * Applies the form's filters in place, when it is sent or a select
		 * changes.
		 *
		 * @param {Event} event Submit or change event.
		 */
		*filter( event ) {
			event.preventDefault();
			yield actions.go( formAddress( getElement().ref ) );
		},
	},
} );
