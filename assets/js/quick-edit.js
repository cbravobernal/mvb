/**
 * MVB Quick Edit for the videogame post type.
 *
 * Populates the completion-date input when WordPress opens the Quick Edit row.
 *
 * @package MVB
 */

( function ( $ ) {
	'use strict';

	if ( typeof inlineEditPost === 'undefined' ) {
		return;
	}

	var originalEdit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		originalEdit.apply( this, arguments );

		var postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ), 10 );
		}

		if ( postId <= 0 ) {
			return;
		}

		var $row = $( '#post-' + postId );
		var $editRow = $( '#edit-' + postId );
		var completionDate = $row
			.find( '.column-videogame_completion_date' )
			.text()
			.trim();

		$editRow
			.find( 'input[name="videogame_completion_date"]' )
			.val( completionDate );
	};
} )( jQuery );
