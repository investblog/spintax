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

	// ----- ACF combobox (2.1.0) -----
	//
	// Replaces the native <datalist> ACF picker with a custom listbox so we
	// can group fields by ACF field group, search across name + label, and
	// auto-fill the sibling field-key hidden input on selection. WAI-ARIA
	// combobox pattern: aria-expanded, aria-activedescendant + roving
	// tabindex on the listbox items.
	var acfFieldsCache = []; // last fetched list of {name, label, group, field_key}.
	var acfActiveIndex = -1;

	function $acfCombo() {
		return $( '[data-spintax-acf-combobox]' );
	}

	function $acfInput() {
		return $( '#spintax-acf-combobox-input' );
	}

	function $acfList() {
		return $( '#spintax-acf-combobox-list' );
	}

	function comboHide() {
		$acfList().attr( 'hidden', 'hidden' );
		$acfInput().attr( 'aria-expanded', 'false' ).removeAttr( 'aria-activedescendant' );
		acfActiveIndex = -1;
	}

	function comboShow() {
		if ( $acfList().children().length === 0 ) {
			return;
		}
		$acfList().removeAttr( 'hidden' );
		$acfInput().attr( 'aria-expanded', 'true' );
	}

	function renderAcfOptions( filterText ) {
		var $list = $acfList().empty();
		var needle = ( filterText || '' ).toString().toLowerCase();

		var matches = $.grep( acfFieldsCache, function ( item ) {
			if ( ! needle ) {
				return true;
			}
			var hay = ( ( item.group || '' ) + ' ' + ( item.label || '' ) + ' ' + ( item.name || '' ) ).toLowerCase();
			return hay.indexOf( needle ) !== -1;
		} );

		if ( matches.length === 0 ) {
			$list.append(
				$( '<li>' )
					.addClass( 'spintax-acf-combobox-empty' )
					.text( 'No ACF fields match.' )
			);
			comboShow();
			return;
		}

		$.each( matches, function ( i, item ) {
			var label = ( item.label || item.name ) + ' (' + item.name + ')';
			var $li = $( '<li>' )
				.attr( 'role', 'option' )
				.attr( 'id', 'spintax-acf-combobox-opt-' + i )
				.attr( 'data-spintax-acf-name', item.name )
				.attr( 'data-spintax-acf-field-key', item.field_key || '' )
				.append( $( '<strong>' ).text( label ) );
			if ( item.group ) {
				$li.append( $( '<span>' ).addClass( 'spintax-acf-combobox-group' ).text( '  ·  ' + item.group ) );
			}
			$list.append( $li );
		} );

		acfActiveIndex = -1;
		comboShow();
	}

	function comboSelect( $li ) {
		if ( ! $li || $li.length === 0 ) {
			return;
		}
		var name = $li.attr( 'data-spintax-acf-name' ) || '';
		var fkey = $li.attr( 'data-spintax-acf-field-key' ) || '';
		if ( ! name ) {
			return;
		}

		// Display the picked field in the combobox input; canonical
		// values go into the hidden form inputs so the server payload
		// stays unchanged.
		var display = name + ( fkey ? ' (' + fkey + ')' : '' );
		$acfInput().val( display );
		$( '#spintax-target-key' ).val( name ).trigger( 'change' );
		setAcfFieldKey( fkey );
		comboHide();
	}

	function moveAcfActive( delta ) {
		var $items = $acfList().children( '[role="option"]' );
		if ( $items.length === 0 ) {
			return;
		}
		acfActiveIndex = ( acfActiveIndex + delta + $items.length ) % $items.length;
		$items.removeClass( 'spintax-acf-combobox-active' );
		var $current = $items.eq( acfActiveIndex ).addClass( 'spintax-acf-combobox-active' );
		$acfInput().attr( 'aria-activedescendant', $current.attr( 'id' ) );

		// Bring it into view.
		var listEl  = $acfList()[ 0 ];
		var itemEl  = $current[ 0 ];
		if ( listEl && itemEl ) {
			var listRect = listEl.getBoundingClientRect();
			var itemRect = itemEl.getBoundingClientRect();
			if ( itemRect.bottom > listRect.bottom ) {
				listEl.scrollTop += itemRect.bottom - listRect.bottom;
			} else if ( itemRect.top < listRect.top ) {
				listEl.scrollTop -= listRect.top - itemRect.top;
			}
		}
	}

	function bindAcfCombobox() {
		// Filter on keystroke + open the list. Mirror the typed value
		// into the canonical hidden `target_key` input so a user who
		// types an exact field name without clicking a list option
		// still submits a non-stale `target_key`. The list-click path
		// (comboSelect) overrides this with the picked option's name.
		$( document ).on( 'input', '#spintax-acf-combobox-input', function () {
			var typed = ( $( this ).val() || '' ).toString();
			renderAcfOptions( typed );
			// Strip the "(field_xxx)" suffix in case the user types over
			// a previous selection's display string.
			$( '#spintax-target-key' ).val( typed.replace( /\s*\(field_[a-z0-9]+\)\s*$/i, '' ) );
		} );

		// Re-open on focus (so a user who clicks back into the field
		// after dismissing the list can browse without retyping).
		$( document ).on( 'focus', '#spintax-acf-combobox-input', function () {
			if ( acfFieldsCache.length > 0 ) {
				renderAcfOptions( $( this ).val() );
			}
		} );

		// Click to select.
		$( document ).on( 'mousedown', '#spintax-acf-combobox-list [role="option"]', function ( e ) {
			// Prevent the input's blur from firing before click.
			e.preventDefault();
			comboSelect( $( this ) );
		} );

		// Keyboard nav per ARIA combobox spec.
		$( document ).on( 'keydown', '#spintax-acf-combobox-input', function ( e ) {
			var $items = $acfList().children( '[role="option"]' );
			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					if ( $acfList().attr( 'hidden' ) ) {
						renderAcfOptions( $( this ).val() );
					}
					moveAcfActive( 1 );
					break;
				case 'ArrowUp':
					e.preventDefault();
					moveAcfActive( -1 );
					break;
				case 'Enter':
					if ( acfActiveIndex >= 0 && $items.length > 0 ) {
						e.preventDefault();
						comboSelect( $items.eq( acfActiveIndex ) );
					}
					break;
				case 'Escape':
					comboHide();
					break;
			}
		} );

		// Close on outside click.
		$( document ).on( 'click', function ( e ) {
			if ( $( e.target ).closest( '[data-spintax-acf-combobox]' ).length === 0 ) {
				comboHide();
			}
		} );
	}

	function loadAcfFields() {
		var post_type = postTypeValue();
		if ( ! post_type ) {
			acfFieldsCache = [];
			$acfList().empty();
			comboHide();
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
			acfFieldsCache = resp.data || [];
			// Render fresh options keyed on whatever the user has already
			// typed so post-type switch mid-edit doesn't blank the list.
			renderAcfOptions( $acfInput().val() );
			comboHide(); // collapsed by default until focus / keystroke.
		} );
	}

	function refreshSuggestions() {
		clearSuggestions();
		var kind = kindValue();
		if ( kind === 'acf_field' ) {
			// Show combobox, hide the plain text input + its description.
			$acfCombo().removeAttr( 'hidden' );
			$( '#spintax-target-key, .spintax-target-key-help' ).attr( 'hidden', 'hidden' );
			$( '.spintax-target-field-key-row' ).removeAttr( 'hidden' );
			loadAcfFields();
		} else if ( kind === 'post_meta' ) {
			// Plain post-meta path keeps the legacy text+datalist UI;
			// the ACF combobox is hidden and the field-key row is
			// suppressed entirely (no field-key concept for post_meta).
			$acfCombo().attr( 'hidden', 'hidden' );
			comboHide();
			$( '#spintax-target-key, .spintax-target-key-help' ).removeAttr( 'hidden' );
			setAcfFieldKey( '' );
			$( '.spintax-target-field-key-row' ).attr( 'hidden', 'hidden' );
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

	function refreshTriggerWarning() {
		var $warning = $( '.spintax-trigger-warning' );
		if ( $warning.length === 0 ) {
			return;
		}
		var savePostOn = $( 'input[name="trigger_save_post"]' ).is( ':checked' );
		var cronVal    = ( $( '#spintax-trigger-cron' ).val() || 'disabled' ).toString();
		var inactive   = ! savePostOn && cronVal === 'disabled';
		if ( inactive ) {
			$warning.removeAttr( 'hidden' );
		} else {
			$warning.attr( 'hidden', 'hidden' );
		}
	}

	function bindTriggerWarning() {
		$( document ).on(
			'change',
			'input[name="trigger_save_post"], #spintax-trigger-cron',
			refreshTriggerWarning
		);
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
		bindAcfCombobox();
		bindTestPanel();
		bindTabSwitcher();
		bindTriggerWarning();
		bindDismissibleNotices();
	} );

}( window.jQuery ) );
