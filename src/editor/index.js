import { store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { select as read, useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

import DraftScan from './draft-scan';
import LinksSidebar, { editorParagraphs } from './links-sidebar';
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

/**
 * Adds the Undergrowth sidebar, with the post's link suggestions.
 *
 * @return {Element} Sidebar.
 */
function UndergrowthSidebar() {
	const { postId, saved, status, blocks } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			saved: select( editorStore ).getCurrentPostAttribute( 'modified' ),
			status: select( editorStore ).getCurrentPostAttribute( 'status' ),
			blocks: select( blockEditorStore ).getBlocks(),
		} ),
		[]
	);
	const paragraphs = useMemo( () => editorParagraphs( blocks ), [ blocks ] );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );
	const title = __( 'Undergrowth', 'undergrowth' );
	const onLink = ( id, content ) => updateBlockAttributes( id, { content } );

	return (
		<>
			<PluginSidebarMoreMenuItem
				target="undergrowth-links"
				icon="admin-links"
			>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="undergrowth-links"
				className="undergrowth-links-sidebar"
				title={ title }
				icon="admin-links"
			>
				{ postId && (
					<LinksSidebar
						postId={ postId }
						saved={ saved }
						paragraphs={ paragraphs }
						onLink={ onLink }
					/>
				) }
				{ postId && 'publish' !== status && (
					<PanelBody title={ __( 'Scan this draft', 'undergrowth' ) }>
						<DraftScan
							postId={ postId }
							paragraphs={ paragraphs }
							onLink={ onLink }
							// Read when the button is pressed, so the scan has
							// exactly what the editor holds at that moment.
							getDraft={ () => ( {
								title: read(
									editorStore
								).getEditedPostAttribute( 'title' ),
								content:
									read( editorStore ).getEditedPostContent(),
								password:
									read( editorStore ).getEditedPostAttribute(
										'password'
									) ?? '',
							} ) }
						/>
					</PanelBody>
				) }
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'undergrowth', { render: UndergrowthPanel } );
registerPlugin( 'undergrowth-links', { render: UndergrowthSidebar } );
