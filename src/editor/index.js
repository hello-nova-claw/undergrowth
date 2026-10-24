import { useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

import StagePanel from './stage-panel';

/**
 * Adds the Undergrowth panel to the editor's Post tab.
 *
 * @return {Element} Panel.
 */
function UndergrowthPanel() {
	const { postId, status } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			status: select( editorStore ).getCurrentPostAttribute( 'status' ),
		} ),
		[]
	);

	return (
		<PluginDocumentSettingPanel
			name="undergrowth"
			className="undergrowth-editor-panel"
			title={ __( 'Undergrowth', 'undergrowth' ) }
		>
			{ postId && <StagePanel postId={ postId } status={ status } /> }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'undergrowth', { render: UndergrowthPanel } );
