/**
 * Spintax Bindings — admin form behaviors.
 *
 * Phase 3:
 *  - Field discovery autocomplete (#spintax-target-key)
 *    - post_meta targets: distinct postmeta keys for the chosen post type
 *    - acf_field targets: top-level ACF text/textarea/wysiwyg fields
 *    - Selecting an ACF suggestion autofills #spintax-target-field-key
 *  - Test panel: post_id input + button → ajax_test_binding → results
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.spintaxBindings === 'undefined' ) {
		return;
	}

	var cfg = window.spintaxBindings;

	function postTypeValue() {
		// Field is `name="spintax_post_type"` (renamed in 2.0.1 to avoid
		// clobbering `$_REQUEST['post_type']` which WP uses to set `$typenow`).
		return ( $( '#spintax-post-type' ).val() || '' ).toString();
	}

	function kindValue() {
		return ( $( 'input[name="target_kind"]:checked' ).val() || '' ).toString();
	}

	function ensureDatalist() {
		var $list = $( '#spintax-target-key-suggestions' );
		if ( $list.length === 0 ) {
			$list = $( '<datalist id="spintax-target-key-suggestions"></datalist>' );
			$( 'body' ).append( $list );
			$( '#spintax-target-key' ).attr( 'list', 'spintax-target-key-suggestions' );
		}
		return $list;
	}

	function clearSuggestions() {
		ensureDatalist().empty();
	}

	function setAcfFieldKey( hint ) {
		var $hint = $( '#spintax-target-field-key' );
		if ( $hint.length && hint ) {
			$hint.val( hint );
		}
	}

	function loadMetaKeys() {
		var post_type = postTypeValue();
		if ( ! post_type ) {
			clearSuggestions();
			return;
		}
		$.get(
			cfg.ajaxUrl,
			{
				action: 'spintax_binding_meta_keys',
				nonce: cfg.nonce,
				post_type: post_type
			}
		).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				return;
			}
			var $list = ensureDatalist().empty();
			$.each( resp.data, function ( _, item ) {
				$list.append(
					$( '<option>' ).val( item.name ).text( item.label || item.name )
				);
			} );
		} );
	}

	function loadAcfFields() {
		var post_type = postTypeValue();
		if ( ! post_type ) {
			clearSuggestions();
			return;
		}
		$.get(
			cfg.ajaxUrl,
			{
				action: 'spintax_binding_acf_fields',
				nonce: cfg.nonce,
				post_type: post_type
			}
		).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				return;
			}
			var $list = ensureDatalist().empty();
			var keyByName = {};
			$.each( resp.data, function ( _, item ) {
				$list.append(
					$( '<option>' )
						.val( item.name )
						.text( ( item.group ? item.group + ' — ' : '' ) + ( item.label || item.name ) )
				);
				if ( item.field_key ) {
					keyByName[ item.name ] = item.field_key;
				}
			} );
			// Wire input → field_key autofill on exact name match.
			$( '#spintax-target-key' )
				.off( 'input.spintaxAcfFieldKey change.spintaxAcfFieldKey' )
				.on( 'input.spintaxAcfFieldKey change.spintaxAcfFieldKey', function () {
					var name = ( $( this ).val() || '' ).toString();
					if ( keyByName[ name ] ) {
						setAcfFieldKey( keyByName[ name ] );
					}
				} );
		} );
	}

	function refreshSuggestions() {
		clearSuggestions();
		var kind = kindValue();
		if ( kind === 'acf_field' ) {
			$( '#spintax-target-field-key' ).closest( 'tr' ).show();
			loadAcfFields();
		} else if ( kind === 'post_meta' ) {
			// post_meta has no field_key concept; clear and hide that row.
			setAcfFieldKey( '' );
			$( '#spintax-target-field-key' ).closest( 'tr' ).hide();
			loadMetaKeys();
		}
	}

	function bindForm() {
		$( '#spintax-post-type' ).on( 'change', refreshSuggestions );
		$( 'input[name="target_kind"]' ).on( 'change', refreshSuggestions );

		// Fire once on load to reflect the current state on edit screens.
		refreshSuggestions();
	}

	function renderTestResult( data ) {
		var $panel = $( '#spintax-binding-test-results' );
		if ( $panel.length === 0 ) {
			return;
		}

		var lines = [];
		lines.push( '<p><strong>' + cfg.i18n.result + ':</strong> <code>' + data.result + '</code></p>' );
		lines.push( '<p><strong>' + cfg.i18n.wouldWrite + ':</strong> ' + ( data.would_write ? cfg.i18n.yes : cfg.i18n.no ) + '</p>' );
		if ( data.post_title ) {
			lines.push( '<p><strong>' + cfg.i18n.post + ':</strong> ' + $( '<div>' ).text( data.post_title ).html() + ' (' + data.post_type + ' #' + data.post_id + ')</p>' );
		}
		lines.push( '<p><strong>' + cfg.i18n.rendered + ':</strong></p>' );
		lines.push( '<pre style="background:#f6f7f7;padding:8px;border-radius:4px;max-height:200px;overflow:auto;">' + $( '<div>' ).text( data.rendered_preview || '' ).html() + '</pre>' );
		lines.push( '<p><strong>' + cfg.i18n.currentTarget + ':</strong></p>' );
		lines.push( '<pre style="background:#f6f7f7;padding:8px;border-radius:4px;max-height:200px;overflow:auto;">' + $( '<div>' ).text( data.current_target || '' ).html() + '</pre>' );

		$panel.html( lines.join( '' ) );
	}

	function bindTestPanel() {
		var $btn = $( '#spintax-binding-test-button' );
		if ( $btn.length === 0 ) {
			return;
		}

		$btn.on( 'click', function ( e ) {
			e.preventDefault();
			var $panel = $( '#spintax-binding-test-results' ).text( cfg.i18n.testing );
			var postId = parseInt( $( '#spintax-binding-test-post-id' ).val() || '0', 10 );
			if ( ! postId ) {
				$panel.text( cfg.i18n.enterPostId );
				return;
			}
			$.post(
				cfg.ajaxUrl,
				{
					action: 'spintax_test_binding',
					nonce: cfg.nonce,
					binding_id: cfg.bindingId,
					post_id: postId
				}
			)
				.done( function ( resp ) {
					if ( resp && resp.success && resp.data ) {
						renderTestResult( resp.data );
					} else {
						$panel.text(
							( resp && resp.data && resp.data.message ) ? resp.data.message : cfg.i18n.error
						);
					}
				} )
				.fail( function () {
					$panel.text( cfg.i18n.error );
				} );
		} );
	}

	function activateTab( slug, opts ) {
		opts = opts || {};
		var $tabs   = $( '.spintax-binding-tabs [role="tab"]' );
		var $panels = $( '.spintax-binding-panel' );
		if ( $tabs.length === 0 ) {
			return;
		}

		var $targetTab = $tabs.filter( '[data-spintax-tab="' + slug + '"]' );
		if ( $targetTab.length === 0 ) {
			return;
		}

		$tabs.attr( 'aria-selected', 'false' ).attr( 'tabindex', '-1' );
		$panels.attr( 'hidden', 'hidden' );

		$targetTab.attr( 'aria-selected', 'true' ).attr( 'tabindex', '0' );
		$( '#spintax-panel-' + slug ).removeAttr( 'hidden' );
		$( '#spintax-active-tab' ).val( slug );

		if ( opts.focus ) {
			$targetTab.trigger( 'focus' );
		}
	}

	function bindTabSwitcher() {
		var $tabStrip = $( '.spintax-binding-tabs' );
		if ( $tabStrip.length === 0 ) {
			return;
		}

		// Mouse / pointer activation.
		$tabStrip.on( 'click', '[role="tab"]', function ( e ) {
			e.preventDefault();
			activateTab( $( this ).data( 'spintax-tab' ), { focus: false } );
		} );

		// Keyboard navigation per WAI-ARIA tabs pattern.
		$tabStrip.on( 'keydown', '[role="tab"]', function ( e ) {
			var $tabs   = $tabStrip.find( '[role="tab"]' );
			var index   = $tabs.index( this );
			var lastIdx = $tabs.length - 1;
			var nextIdx = null;

			switch ( e.key ) {
				case 'ArrowRight':
				case 'ArrowDown':
					nextIdx = index === lastIdx ? 0 : index + 1;
					break;
				case 'ArrowLeft':
				case 'ArrowUp':
					nextIdx = index === 0 ? lastIdx : index - 1;
					break;
				case 'Home':
					nextIdx = 0;
					break;
				case 'End':
					nextIdx = lastIdx;
					break;
			}

			if ( nextIdx !== null ) {
				e.preventDefault();
				var slug = $tabs.eq( nextIdx ).data( 'spintax-tab' );
				activateTab( slug, { focus: true } );
			}
		} );
	}

	function bindDismissibleNotices() {
		// Persist dismissals for spintax-tagged notices. WP's
		// `notice-dismiss` button hides the element locally; we layer
		// an AJAX write on top so subsequent page loads also stay clean.
		$( document ).on( 'click', '.notice[data-spintax-dismiss-notice] .notice-dismiss', function () {
			var $notice  = $( this ).closest( '.notice[data-spintax-dismiss-notice]' );
			var noticeId = ( $notice.data( 'spintax-dismiss-notice' ) || '' ).toString();
			if ( ! noticeId ) {
				return;
			}
			$.post( cfg.ajaxUrl, {
				action: 'spintax_dismiss_admin_notice',
				nonce: cfg.nonce,
				notice_id: noticeId
			} );
		} );
	}

	$( function () {
		bindForm();
		bindTestPanel();
		bindTabSwitcher();
		bindDismissibleNotices();
	} );

}( window.jQuery ) );
