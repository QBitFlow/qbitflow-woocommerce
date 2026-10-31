const settings = globalThis.window.wc.wcSettings.getSetting( 'qbitflow_data', {} );
const label = settings.title || 'Pay with Crypto';
const description = settings.description || '';
const acceptedCoins = settings.acceptedCoins || '';
const icon = settings.icon || '';

const { registerPaymentMethod } = globalThis.window.wc.wcBlocksRegistry;
const { decodeEntities } = globalThis.window.wp.htmlEntities;
const { createElement } = globalThis.window.wp.element;

/**
 * Label component — shown in the payment method list.
 */
const Label = () => {
	return createElement(
		'span',
		{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
		icon && createElement( 'img', {
			src: icon,
			alt: decodeEntities( label ),
			style: { maxHeight: '24px', width: 'auto' }
		}),
		createElement( 'span', null, decodeEntities( label ) )
	);
};


/**
 * Content component — shown when the payment method is selected.
 */
const Content = () => {
	return createElement(
		'div',
		{ style: { padding: '8px 0' } },
		decodeEntities( description ),
		acceptedCoins && createElement(
			'div',
			{ className: 'qbitflow-accepted-coins', style: { marginTop: '6px', fontSize: '0.9em', opacity: 0.8 } },
			acceptedCoins
		)
	);
};

/**
 * Register the payment method with WooCommerce Blocks.
 */
registerPaymentMethod({
	name: 'qbitflow',
	label: createElement( Label, null ),
	content: createElement( Content, null ),
	edit: createElement( Content, null ),
	canMakePayment: () => true,
	ariaLabel: decodeEntities( label ),
	supports: {
		features: settings.supports || [ 'products' ],
	},
});