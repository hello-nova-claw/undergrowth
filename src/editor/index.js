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
 * Lists the post's link suggestions, with the paragraphs the editor holds.
 *
 * Mounted only while the sidebar is open, so the block tree is not walked
 * on every keystroke while it is closed.
 *
 * @param {Object} props        Component props.
 * @param {number} props.postId Post being edited.
 * @param {string} props.saved  Changes on every save.
 * @param {string} props.status The post's saved status; an unpublished post also offers a scan.
 * @return {Element} Suggestions.
 */
function OpenLinksSidebar( { postId, saved, status } ) {
	const blocks = useSelect(
		( select ) => select( blockEditorStore ).getBlocks(),
		[]
	);
	const paragraphs = useMemo( () => editorParagraphs( blocks ), [ blocks ] );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const onLink = ( id, content ) => updateBlockAttributes( id, { content } );

	return (
		<>
			<LinksSidebar
				postId={ postId }
				saved={ saved }
				paragraphs={ paragraphs }
				onLink={ onLink }
			/>
			{ 'publish' !== status && (
				<PanelBody title={ __( 'Scan this draft', 'undergrowth' ) }>
					<DraftScan
						postId={ postId }
						paragraphs={ paragraphs }
						onLink={ onLink }
						// Read when the button is pressed, so the scan has
						// exactly what the editor holds at that moment.
						getDraft={ () => ( {
							title: read( editorStore ).getEditedPostAttribute(
								'title'
							),
							content: read( editorStore ).getEditedPostContent(),
							password:
								read( editorStore ).getEditedPostAttribute(
									'password'
								) ?? '',
						} ) }
					/>
				</PanelBody>
			) }
		</>
	);
}

/**
 * Adds the Undergrowth sidebar, with the post's link suggestions.
 *
 * @return {Element} Sidebar.
 */
function UndergrowthSidebar() {
	const { postId, saved, status } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			saved: select( editorStore ).getCurrentPostAttribute( 'modified' ),
			status: select( editorStore ).getCurrentPostAttribute( 'status' ),
		} ),
		[]
	);
	const title = __( 'Undergrowth', 'undergrowth' );

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
					<OpenLinksSidebar
						postId={ postId }
						saved={ saved }
						status={ status }
					/>
				) }
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'undergrowth', { render: UndergrowthPanel } );
registerPlugin( 'undergrowth-links', { render: UndergrowthSidebar } );
