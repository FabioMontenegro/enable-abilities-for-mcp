( function () {
	/* ── Tab navigation ─────────────────────────────────────────────── */
	var tabBtns   = document.querySelectorAll( '.ewpa-tab-btn' );
	var tabPanels = document.querySelectorAll( '.ewpa-tab-panel' );

	function activateTab( tabId ) {
		tabBtns.forEach( function ( btn ) {
			btn.classList.toggle( 'is-active', btn.getAttribute( 'data-tab' ) === tabId );
		} );
		tabPanels.forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.id === 'ewpa-tab-' + tabId );
		} );
		history.replaceState( null, '', '#' + tabId );
	}

	tabBtns.forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			activateTab( btn.getAttribute( 'data-tab' ) );
		} );
	} );

	// Restore from URL hash, query param, or default to first tab.
	( function initTab() {
		var hash   = window.location.hash.replace( '#', '' );
		var params = new URLSearchParams( window.location.search );
		var tab    = ( hash && document.getElementById( 'ewpa-tab-' + hash ) )
			? hash
			: ( params.get( 'ewpa_tab' ) || 'connection' );
		activateTab( tab );
	} )();

	/* ── Clipboard helper (works on HTTP and HTTPS) ─────────────────── */
	function ewpaCopyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		// Fallback for HTTP / non-secure contexts.
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
		document.body.appendChild( ta );
		ta.focus();
		ta.select();
		try {
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			return Promise.resolve();
		} catch ( err ) {
			document.body.removeChild( ta );
			return Promise.reject( err );
		}
	}

	/* ── Copy buttons ───────────────────────────────────────────────── */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.ewpa-copy-btn' );
		if ( ! btn ) return;
		var targetId = btn.getAttribute( 'data-target' );
		var el = document.getElementById( targetId );
		if ( ! el ) return;
		ewpaCopyText( ( el.textContent || el.innerText ).trim() ).then( function () {
			var orig = btn.textContent.trim();
			btn.textContent = ewpaAdmin.i18n.copied;
			setTimeout( function () { btn.textContent = orig; }, 2000 );
		} );
	} );

	/* ── Bearer token toggle ────────────────────────────────────────── */
	var bearerToggle = document.getElementById( 'ewpa-bearer-toggle' );
	var bearerBody   = document.getElementById( 'ewpa-bearer-body' );
	var bearerLabel  = document.getElementById( 'ewpa-bearer-status-label' );

	if ( bearerToggle ) {
		bearerToggle.addEventListener( 'change', function () {
			var enabled = this.checked;
			bearerToggle.disabled = true;

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', ewpaAdmin.ajaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onload = function () {
				bearerToggle.disabled = false;
				if ( xhr.status === 200 ) {
					var res = JSON.parse( xhr.responseText );
					if ( res.success ) {
						bearerBody.style.display = res.data.enabled ? '' : 'none';
						bearerLabel.textContent  = res.data.enabled ? ewpaAdmin.i18n.enabled : ewpaAdmin.i18n.disabled;
					}
				}
			};
			xhr.send( 'action=ewpa_toggle_bearer&nonce=' + ewpaAdmin.nonce + '&enabled=' + enabled );
		} );
	}

	/* ── Bearer API Key management ──────────────────────────────────── */
	function ewpaKeyAjax( action, confirmMsg, onSuccess ) {
		if ( confirmMsg && ! confirm( confirmMsg ) ) return;
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ewpaAdmin.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function () {
			if ( xhr.status === 200 ) {
				var res = JSON.parse( xhr.responseText );
				if ( res.success ) {
					onSuccess( res.data );
				} else {
					alert( ( res.data && res.data.message ) || 'Error' );
				}
			}
		};
		xhr.send( 'action=' + action + '&nonce=' + ewpaAdmin.nonce );
	}

	function ewpaShowNewKey( key ) {
		var display   = document.getElementById( 'ewpa-api-key-display' );
		var valueEl   = document.getElementById( 'ewpa-api-key-value' );
		var statusEl  = document.getElementById( 'ewpa-api-key-status' );
		var actionsEl = document.querySelector( '.ewpa-key-actions' );

		valueEl.textContent   = key;
		display.style.display = 'block';

		statusEl.innerHTML =
			'<p class="ewpa-key-active">' +
			'<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ' +
			ewpaAdmin.i18n.keyActive + '</p>';

		actionsEl.innerHTML =
			'<button type="button" class="button" id="ewpa-regenerate-key">' + ewpaAdmin.i18n.regenerate + '</button>' +
			' <button type="button" class="button button-link-delete" id="ewpa-revoke-key">' + ewpaAdmin.i18n.revoke + '</button>';

		ewpaBindKeyButtons();
	}

	function ewpaBindKeyButtons() {
		var genBtn    = document.getElementById( 'ewpa-generate-key' );
		var regenBtn  = document.getElementById( 'ewpa-regenerate-key' );
		var revokeBtn = document.getElementById( 'ewpa-revoke-key' );

		if ( genBtn ) {
			genBtn.addEventListener( 'click', function () {
				ewpaKeyAjax( 'ewpa_generate_api_key', null, function ( data ) { ewpaShowNewKey( data.key ); } );
			} );
		}
		if ( regenBtn ) {
			regenBtn.addEventListener( 'click', function () {
				ewpaKeyAjax( 'ewpa_generate_api_key', ewpaAdmin.i18n.confirmRegenerate, function ( data ) { ewpaShowNewKey( data.key ); } );
			} );
		}
		if ( revokeBtn ) {
			revokeBtn.addEventListener( 'click', function () {
				ewpaKeyAjax( 'ewpa_revoke_api_key', ewpaAdmin.i18n.confirmRevoke, function () { location.reload(); } );
			} );
		}
	}
	ewpaBindKeyButtons();

	/* ── Application Password: show/hide toggle ─────────────────────── */
	var togglePassBtn = document.getElementById( 'ewpa-toggle-pass' );
	var appPassField  = document.getElementById( 'ewpa-cred-apppass' );

	if ( togglePassBtn && appPassField ) {
		togglePassBtn.addEventListener( 'click', function () {
			var isPassword = appPassField.type === 'password';
			appPassField.type      = isPassword ? 'text' : 'password';
			togglePassBtn.textContent = isPassword
				? ewpaAdmin.i18n.hide
				: ewpaAdmin.i18n.show;
		} );
	}

	/* ── Application Password: in-browser credential generator ─────── */
	var genCredsBtn   = document.getElementById( 'ewpa-gen-creds' );
	var credsOutput   = document.getElementById( 'ewpa-creds-output' );
	var credsValueEl  = document.getElementById( 'ewpa-creds-value' );
	var credsUsernameEl = document.getElementById( 'ewpa-cred-username' );

	if ( genCredsBtn ) {
		genCredsBtn.addEventListener( 'click', function () {
			var username = ( credsUsernameEl ? credsUsernameEl.value : '' ).trim();
			var password = ( appPassField ? appPassField.value : '' ).trim();

			if ( ! username || ! password ) {
				appPassField.focus();
				return;
			}

			// btoa is safe here: Application Passwords are ASCII.
			// For usernames/passwords with non-ASCII chars, encode first.
			try {
				var encoded = btoa( unescape( encodeURIComponent( username + ':' + password ) ) );
				credsValueEl.textContent  = encoded;
				credsOutput.style.display = 'block';

				// Also update the config snippet if it exists on the page.
				var configPre = document.getElementById( 'ewpa-apppass-config' );
				if ( configPre ) {
					configPre.querySelector( 'code' ).textContent =
						configPre.querySelector( 'code' ).textContent.replace(
							'YOUR_BASE64_CREDENTIALS',
							encoded
						);
				}
			} catch ( err ) {
				alert( ewpaAdmin.i18n.credError );
			}
		} );
	}

	/* ── Activity Log: clear buttons ────────────────────────────────── */
	document.querySelectorAll( '.ewpa-clear-logs' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var userId = btn.getAttribute( 'data-user' );
			var msg    = userId === '0'
				? ewpaAdmin.i18n.confirmClearAll
				: ewpaAdmin.i18n.confirmClearUser;

			if ( ! confirm( msg ) ) return;
			btn.disabled = true;

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', ewpaAdmin.ajaxUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.onload = function () {
				if ( xhr.status === 200 ) {
					var res = JSON.parse( xhr.responseText );
					if ( res.success ) {
						location.reload();
					} else {
						alert( ( res.data && res.data.message ) || 'Error' );
						btn.disabled = false;
					}
				}
			};
			xhr.send( 'action=ewpa_clear_logs&nonce=' + ewpaAdmin.logsNonce + '&user_id=' + userId );
		} );
	} );

	/* ── Abilities Toggles ──────────────────────────────────────────── */
	var checkboxes    = document.querySelectorAll( '.ewpa-ability-check' );
	var sectionChecks = document.querySelectorAll( '.ewpa-section-check' );
	var enableAll     = document.getElementById( 'ewpa-enable-all' );
	var disableAll    = document.getElementById( 'ewpa-disable-all' );
	var countEl       = document.getElementById( 'ewpa-enabled-count' );
	var totalEl       = document.getElementById( 'ewpa-total-count' );

	if ( totalEl ) totalEl.textContent = checkboxes.length;

	function updateCount() {
		var count = 0;
		checkboxes.forEach( function ( cb ) { if ( cb.checked ) count++; } );
		if ( countEl ) countEl.textContent = count;
	}

	function updateSectionCheck( section ) {
		var items     = document.querySelectorAll( '.ewpa-ability-check[data-section="' + section + '"]' );
		var sectionCb = document.querySelector( '.ewpa-section-check[data-section="' + section + '"]' );
		if ( ! sectionCb ) return;
		var allChecked = true;
		items.forEach( function ( cb ) { if ( ! cb.checked ) allChecked = false; } );
		sectionCb.checked = allChecked;
	}

	checkboxes.forEach( function ( cb ) {
		cb.addEventListener( 'change', function () {
			updateCount();
			updateSectionCheck( this.getAttribute( 'data-section' ) );
		} );
	} );

	sectionChecks.forEach( function ( sc ) {
		sc.addEventListener( 'change', function () {
			var section = this.getAttribute( 'data-section' );
			var checked = this.checked;
			document.querySelectorAll( '.ewpa-ability-check[data-section="' + section + '"]' ).forEach( function ( cb ) {
				cb.checked = checked;
			} );
			updateCount();
		} );
	} );

	if ( enableAll ) {
		enableAll.addEventListener( 'click', function () {
			checkboxes.forEach( function ( cb ) { cb.checked = true; } );
			sectionChecks.forEach( function ( sc ) { sc.checked = true; } );
			updateCount();
		} );
	}
	if ( disableAll ) {
		disableAll.addEventListener( 'click', function () {
			checkboxes.forEach( function ( cb ) { cb.checked = false; } );
			sectionChecks.forEach( function ( sc ) { sc.checked = false; } );
			updateCount();
		} );
	}

	updateCount();
	sectionChecks.forEach( function ( sc ) { updateSectionCheck( sc.getAttribute( 'data-section' ) ); } );
} )();
