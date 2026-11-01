import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';
import metadata from '../../assets/blocks/review-summary/block.json';

const inherit = () => [
	{ value: '', label: __( 'Use Display settings', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'show', label: __( 'Show', 'dev3-ai-review-summarizer-for-woocommerce' ) },
	{ value: 'hide', label: __( 'Hide', 'dev3-ai-review-summarizer-for-woocommerce' ) },
];

function Empty() {
	return (
		<Placeholder icon="star-half" label={ __( 'AI Review Summary', 'dev3-ai-review-summarizer-for-woocommerce' ) }>
			{ __( 'The AI summary of this product’s reviews appears here once one has been generated. Pick a product ID in the sidebar, or place the block in a single product template.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
		</Placeholder>
	);
}

function Edit( { attributes, setAttributes, context } ) {
	const blockProps = useBlockProps();
	const productId = attributes.productId || ( context.postType === 'product' ? context.postId : 0 );
	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Summary', 'dev3-ai-review-summarizer-for-woocommerce' ) }>
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						label={ __( 'Product ID', 'dev3-ai-review-summarizer-for-woocommerce' ) }
						help={ __( 'Leave empty to use the current product.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
						value={ attributes.productId || '' }
						onChange={ ( v ) => setAttributes( { productId: Math.max( 0, parseInt( v, 10 ) || 0 ) } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'dev3-ai-review-summarizer-for-woocommerce' ) }
						value={ attributes.layout }
						options={ [
							{ value: '', label: __( 'Use Display settings', 'dev3-ai-review-summarizer-for-woocommerce' ) },
							{ value: 'card', label: __( 'Card', 'dev3-ai-review-summarizer-for-woocommerce' ) },
							{ value: 'compact', label: __( 'Compact', 'dev3-ai-review-summarizer-for-woocommerce' ) },
						] }
						onChange={ ( layout ) => setAttributes( { layout } ) }
					/>
					<SelectControl __nextHasNoMarginBottom label={ __( 'Scores', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ attributes.showScores } options={ inherit() } onChange={ ( showScores ) => setAttributes( { showScores } ) } />
					<SelectControl __nextHasNoMarginBottom label={ __( 'Sentiment bar', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ attributes.showSentiment } options={ inherit() } onChange={ ( showSentiment ) => setAttributes( { showSentiment } ) } />
					<SelectControl __nextHasNoMarginBottom label={ __( 'Keywords', 'dev3-ai-review-summarizer-for-woocommerce' ) } value={ attributes.showKeywords } options={ inherit() } onChange={ ( showKeywords ) => setAttributes( { showKeywords } ) } />
				</PanelBody>
			</InspectorControls>
			{ productId ? <ServerSideRender block={ metadata.name } attributes={ { ...attributes, productId } } EmptyResponsePlaceholder={ Empty } /> : <Empty /> }
		</div>
	);
}

registerBlockType( metadata.name, { ...metadata, edit: Edit, save: () => null } );
