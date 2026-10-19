/* global jQuery, wp, wptpAdmin */
( function ( $ ) {
	'use strict';

	// ---------------------------------------------------------------- media
	$( document ).on( 'click', '.wptp-media-pick', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.wptp-media' );
		var frame = wp.media( {
			title: 'Выберите изображение',
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
			$box.find( '.wptp-media-id' ).val( att.id ).trigger( 'change' );
			$box.find( '.wptp-media-preview' ).attr( 'src', url ).show();
			$box.find( '.wptp-media-clear' ).show();
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.wptp-media-clear', function ( e ) {
		e.preventDefault();
		var $box = $( this ).closest( '.wptp-media' );
		$box.find( '.wptp-media-id' ).val( '0' ).trigger( 'change' );
		$box.find( '.wptp-media-preview' ).attr( 'src', '' ).hide();
		$( this ).hide();
	} );

	// The term "add" form is submitted by AJAX and not reloaded: reset the picker.
	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( settings && typeof settings.data === 'string' && settings.data.indexOf( 'action=add-tag' ) !== -1 ) {
			$( '#addtag .wptp-media-clear' ).trigger( 'click' );
		}
	} );

	// ------------------------------------------------------- taxonomy rules
	function renumberRules( $table ) {
		$table.find( 'tbody tr.wptp-rule' ).each( function ( i ) {
			$( this ).find( '[name]' ).each( function () {
				this.name = this.name.replace( /\[tax_rules\]\[\d+\]/, '[tax_rules][' + i + ']' );
			} );
		} );
	}

	$( document ).on( 'click', '.wptp-add-rule', function ( e ) {
		e.preventDefault();
		var $table = $( this ).closest( 'td' ).find( '.wptp-rules' );
		var $last = $table.find( 'tbody tr.wptp-rule' ).last();
		var $row = $last.clone();
		$row.find( 'input[type=text]' ).val( '' );
		$row.find( 'select' ).each( function () {
			this.selectedIndex = 0;
		} );
		$row.find( 'input[type=checkbox]' ).prop( 'checked', true );
		$table.find( 'tbody' ).append( $row );
		renumberRules( $table );
	} );

	$( document ).on( 'click', '.wptp-remove-rule', function ( e ) {
		e.preventDefault();
		var $table = $( this ).closest( '.wptp-rules' );
		var $rows = $table.find( 'tbody tr.wptp-rule' );
		var $row = $( this ).closest( 'tr' );
		if ( $rows.length > 1 ) {
			$row.remove();
		} else {
			$row.find( 'input[type=text]' ).val( '' );
			$row.find( 'select' ).each( function () {
				this.selectedIndex = 0;
			} );
		}
		renumberRules( $table );
	} );

	// --------------------------------------------------- image source order
	$( function () {
		$( '.wptp-sortable' ).each( function () {
			var $list = $( this );
			var $order = $list.siblings( '.wptp-sortable-order' );
			$list.sortable( {
				handle: '.dashicons-menu',
				axis: 'y',
				update: function () {
					$order.val( $list.children( 'li' ).map( function () {
						return $( this ).data( 'source' );
					} ).get().join( ',' ) );
				}
			} );
		} );

		if ( $.fn.wpColorPicker ) {
			$( '.wptp-color' ).wpColorPicker();
		}
	} );

	// ------------------------------------------------------- preset preview
	$( document ).on( 'click', '.wptp-preview-btn', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $pane = $btn.closest( '.wptp-preview-pane' );
		var $form = $( '.wptp-preset-form' );
		var data = $form.serializeArray().filter( function ( f ) {
			return f.name.indexOf( 'preset[' ) === 0;
		} );
		data.push( { name: 'action', value: 'wptp_preview_preset' } );
		data.push( { name: 'nonce', value: wptpAdmin.previewNonce } );
		data.push( { name: 'post_id', value: $pane.find( '.wptp-preview-post' ).val() || '' } );

		$btn.prop( 'disabled', true ).text( 'Собираю…' );
		$.post( wptpAdmin.ajaxUrl, $.param( data ) )
			.done( function ( res ) {
				if ( res && res.success ) {
					$pane.find( '.wptp-preview-img' ).attr( 'src', res.data.url ).show();
					$pane.find( '.wptp-preview-note' ).text( res.data.note );
				} else {
					$pane.find( '.wptp-preview-note' ).text( 'Ошибка: ' + ( res && res.data ? res.data : 'неизвестно' ) );
				}
			} )
			.fail( function () {
				$pane.find( '.wptp-preview-note' ).text( 'Ошибка запроса' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( 'Собрать' );
			} );
	} );
}( jQuery ) );
