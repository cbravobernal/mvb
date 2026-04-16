/**
 * MVB Block Bindings helper panel.
 *
 * Renders a sidebar panel on the videogame post editor that lists the
 * available `mvb/videogame` binding keys, shows their current value and
 * offers a one-click copy of a ready-to-paste block markup snippet.
 *
 * No JSX. Uses `wp.element.createElement` so the script runs without a
 * build step and only depends on globals exposed by WordPress core.
 *
 * @package MVB
 */

( function ( wp, data ) {
	'use strict';

	if (
		! wp ||
		! wp.plugins ||
		! wp.editor ||
		! wp.element ||
		! wp.components ||
		! wp.data
	) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
	var createElement = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useSelect = wp.data.useSelect;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var __ = wp.i18n.__;

	var keys = ( data && data.keys ) || {};
	var postType = ( data && data.postType ) || 'videogame';

	function snippet( key ) {
		return (
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"mvb/videogame","args":{"key":"' +
			key +
			'"}}}}} -->\n<p>placeholder</p>\n<!-- /wp:paragraph -->'
		);
	}

	function copy( text ) {
		if ( navigator && navigator.clipboard ) {
			navigator.clipboard.writeText( text );
		}
	}

	function Panel() {
		var state = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				currentPostType: editor.getCurrentPostType(),
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
			};
		}, [] );

		if ( state.currentPostType !== postType ) {
			return null;
		}

		var rows = Object.keys( keys ).map( function ( key ) {
			var value = state.meta[ key ];
			var display =
				value === undefined || value === null || value === ''
					? __( '— empty —', 'mvb' )
					: String( value );

			return createElement(
				'div',
				{
					key: key,
					style: {
						marginBottom: '12px',
						paddingBottom: '12px',
						borderBottom: '1px solid #ddd',
					},
				},
				createElement(
					'strong',
					null,
					keys[ key ]
				),
				createElement(
					'div',
					{ style: { color: '#666', fontSize: '12px' } },
					key
				),
				createElement(
					'div',
					{ style: { margin: '6px 0', fontFamily: 'monospace' } },
					display
				),
				createElement(
					Button,
					{
						variant: 'secondary',
						size: 'small',
						onClick: function () {
							copy( snippet( key ) );
						},
					},
					__( 'Copy paragraph binding', 'mvb' )
				)
			);
		} );

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'mvb-bindings-panel',
				title: __( 'Videogame Bindings', 'mvb' ),
				className: 'mvb-bindings-panel',
			},
			createElement(
				Notice,
				{ status: 'info', isDismissible: false },
				__(
					'Paste the copied markup into the Code Editor, then bind any block attribute to these keys via mvb/videogame.',
					'mvb'
				)
			),
			createElement( Fragment, null, rows )
		);
	}

	registerPlugin( 'mvb-bindings-panel', { render: Panel } );
} )( window.wp, window.MVBBindingsData );
