( function ( $ ) {
	'use strict';

	$( function () {
		var frame;

		$( document ).on( 'click', '.commentgate-select-logo', function ( event ) {
			var target = $( '#' + $( this ).data( 'target' ) );

			event.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: window.commentGateAdmin ? window.commentGateAdmin.chooseLogo : 'Choose email logo',
				button: {
					text: window.commentGateAdmin ? window.commentGateAdmin.useLogo : 'Use this logo'
				},
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				if ( attachment && attachment.url ) {
					target.val( attachment.url ).trigger( 'change' );
				}
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.commentgate-remove-logo', function ( event ) {
			event.preventDefault();
			$( '#' + $( this ).data( 'target' ) ).val( '' ).trigger( 'change' );
		} );

		$( document ).on( 'change', '#commentgate-select-all', function () {
			$( 'input[name="payment_ids[]"]' ).prop( 'checked', $( this ).prop( 'checked' ) );
		} );

		$( document ).on( 'click', '.commentgate-row-action', function ( event ) {
			var button = $( this );
			var form = button.closest( 'form' );
			var message = button.data( 'confirm' );

			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
				return;
			}

			form.find( 'input[name="payment_id"]' ).val( button.data( 'payment-id' ) );
		} );
	} );
}( jQuery ) );
