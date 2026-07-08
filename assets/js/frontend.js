( function () {
	'use strict';

	function lockCommentForm() {
		var boxes = document.querySelectorAll( '.commentgate-box' );

		if ( ! boxes.length ) {
			return;
		}

		document.body.classList.add( 'commentgate-comments-locked' );

		boxes.forEach( function ( box ) {
			var form = box.closest( 'form' );

			if ( form ) {
				form.classList.add( 'commentgate-locked-form' );
				form.querySelectorAll( 'textarea, label[for="comment"], input[type="submit"], button[type="submit"]' ).forEach( function ( element ) {
					if ( ! element.closest( '.commentgate-box' ) ) {
						element.style.display = 'none';
					}
				} );
			}
		} );

		document.querySelectorAll( '.comment-form-comment, .form-submit.wp-block-button, .form-submit' ).forEach( function ( element ) {
			if ( ! element.closest( '.commentgate-box' ) ) {
				element.style.display = 'none';
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', lockCommentForm );
	} else {
		lockCommentForm();
	}
}() );
