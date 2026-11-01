import { Button, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export default function GeneralTab( { settings, save } ) {
	const [ form, setForm ] = useState( settings );
	const [ saving, setSaving ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );

	const submit = () => {
		setSaving( true );
		save( {
			min_reviews: form.min_reviews,
			max_reviews: form.max_reviews,
			auto_generate: form.auto_generate,
			debounce_minutes: form.debounce_minutes,
			nightly_refresh: form.nightly_refresh,
			language: form.language,
			usage_logging: form.usage_logging,
			delete_on_uninstall: form.delete_on_uninstall,
		} ).finally( () => setSaving( false ) );
	};

	return (
		<div className="dev3rs-general">
			<div className="dev3rs-card">
				<h2>{ __( 'Review summaries', 'dev3-ai-review-summarizer-for-woocommerce' ) }</h2>
				<p className="description">{ __( 'Only approved product reviews are used. Reviewer names and email addresses are never sent to the AI.', 'dev3-ai-review-summarizer-for-woocommerce' ) }</p>
				<RangeControl
					__nextHasNoMarginBottom
					label={ __( 'Minimum approved reviews', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					help={ __( 'Products with fewer reviews get no summary (and the widget stays hidden).', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					min={ 1 }
					max={ 50 }
					value={ Number( form.min_reviews ) }
					onChange={ set( 'min_reviews' ) }
				/>
				<RangeControl
					__nextHasNoMarginBottom
					label={ __( 'Reviews analysed per product', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					help={ __( 'The most recent reviews are sent. More reviews give a broader picture but use more tokens.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					min={ 5 }
					max={ 300 }
					step={ 5 }
					value={ Number( form.max_reviews ) }
					onChange={ set( 'max_reviews' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Summary language', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					value={ form.language }
					options={ [
						{ value: 'store', label: __( 'Store language (Settings → General)', 'dev3-ai-review-summarizer-for-woocommerce' ) },
						{ value: 'reviews', label: __( 'Language most reviews are written in', 'dev3-ai-review-summarizer-for-woocommerce' ) },
					] }
					onChange={ set( 'language' ) }
				/>
			</div>
			<div className="dev3rs-card">
				<h2>{ __( 'Automation', 'dev3-ai-review-summarizer-for-woocommerce' ) }</h2>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Update summaries automatically when reviews change', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					help={ __( 'New, approved, edited or removed reviews queue a background update. Locked summaries are skipped.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					checked={ !! form.auto_generate }
					onChange={ set( 'auto_generate' ) }
				/>
				<RangeControl
					__nextHasNoMarginBottom
					label={ __( 'Wait before updating (minutes)', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					help={ sprintf(
						/* translators: %d: minutes */
						__( 'Reviews arriving within %d minutes are summarised together in one request.', 'dev3-ai-review-summarizer-for-woocommerce' ),
						Number( form.debounce_minutes )
					) }
					min={ 0 }
					max={ 240 }
					value={ Number( form.debounce_minutes ) }
					disabled={ ! form.auto_generate }
					onChange={ set( 'debounce_minutes' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Nightly refresh of stale and missing summaries', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					checked={ !! form.nightly_refresh }
					disabled={ ! form.auto_generate }
					onChange={ set( 'nightly_refresh' ) }
				/>
			</div>
			<div className="dev3rs-card">
				<h2>{ __( 'Data', 'dev3-ai-review-summarizer-for-woocommerce' ) }</h2>
				<ToggleControl __nextHasNoMarginBottom label={ __( 'Keep usage logs', 'dev3-ai-review-summarizer-for-woocommerce' ) } checked={ !! form.usage_logging } onChange={ set( 'usage_logging' ) } />
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Delete all plugin data when uninstalling', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					help={ __( 'Removes settings, usage logs and every stored summary.', 'dev3-ai-review-summarizer-for-woocommerce' ) }
					checked={ !! form.delete_on_uninstall }
					onChange={ set( 'delete_on_uninstall' ) }
				/>
			</div>
			<Button variant="primary" className="dev3rs-save-general" isBusy={ saving } disabled={ saving } onClick={ submit }>
				{ __( 'Save settings', 'dev3-ai-review-summarizer-for-woocommerce' ) }
			</Button>
		</div>
	);
}
