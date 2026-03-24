( function () {
	/* ── API Key Management ─────────────────────────────────────────── */
	function ewpaDoAjax( action, confirmMsg ) {
		if ( confirmMsg && ! confirm( confirmMsg ) ) {
			return;
		}
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', ewpaAdmin.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onload = function () {
			if ( xhr.status === 200 ) {
				var response = JSON.parse( xhr.responseText );
				if ( response.success ) {
					if ( response.data.key ) {
						document.getElementById( 'ewpa-api-key-value' ).textContent = response.data.key;
						document.getElementById( 'ewpa-api-key-display' ).style.display = 'block';
						var statusEl = document.getElementById( 'ewpa-api-key-status' );
						statusEl.innerHTML = '<p class="ewpa-key-active">' +
							'<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ' +
							ewpaAdmin.i18n.keyActive + '</p>';
						var actionsEl = document.querySelector( '.ewpa-key-actions' );
						actionsEl.innerHTML =
							'<button type="button" class="button" id="ewpa-regenerate-key">' + ewpaAdmin.i18n.regenerate + '</button>' +
							'<button type="button" class="button button-link-delete" id="ewpa-revoke-key" style="margin-left: 8px;">' + ewpaAdmin.i18n.revoke + '</button>';
						ewpaBindKeyButtons();
					} else {
						location.reload();
					}
				} else {
					alert( response.data.message || 'Error' );
				}
			}
		};
		xhr.send( 'action=' + action + '&nonce=' + ewpaAdmin.nonce );
	}

	function ewpaBindKeyButtons() {
		var genBtn = document.getElementById( 'ewpa-generate-key' );
		var regenBtn = document.getElementById( 'ewpa-regenerate-key' );
		var revokeBtn = document.getElementById( 'ewpa-revoke-key' );
		var copyBtn = document.getElementById( 'ewpa-copy-key' );

		if ( genBtn ) {
			genBtn.addEventListener( 'click', function () {
				ewpaDoAjax( 'ewpa_generate_api_key', null );
			} );
		}
		if ( regenBtn ) {
			regenBtn.addEventListener( 'click', function () {
				ewpaDoAjax( 'ewpa_generate_api_key', ewpaAdmin.i18n.confirmRegenerate );
			} );
		}
		if ( revokeBtn ) {
			revokeBtn.addEventListener( 'click', function () {
				ewpaDoAjax( 'ewpa_revoke_api_key', ewpaAdmin.i18n.confirmRevoke );
			} );
		}
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				var keyText = document.getElementById( 'ewpa-api-key-value' ).textContent;
				navigator.clipboard.writeText( keyText ).then( function () {
					copyBtn.textContent = ewpaAdmin.i18n.copied;
					setTimeout( function () {
						copyBtn.textContent = ewpaAdmin.i18n.copy;
					}, 2000 );
				} );
			} );
		}
	}
	ewpaBindKeyButtons();

	/* ── Abilities Toggles ──────────────────────────────────────────── */
	var checkboxes = document.querySelectorAll( '.ewpa-ability-check' );
	var sectionChecks = document.querySelectorAll( '.ewpa-section-check' );
	var enableAll = document.getElementById( 'ewpa-enable-all' );
	var disableAll = document.getElementById( 'ewpa-disable-all' );
	var countEl = document.getElementById( 'ewpa-enabled-count' );
	var totalEl = document.getElementById( 'ewpa-total-count' );

	totalEl.textContent = checkboxes.length;

	function updateCount() {
		var count = 0;
		checkboxes.forEach( function ( cb ) {
			if ( cb.checked ) {
				count++;
			}
		} );
		countEl.textContent = count;
	}

	function updateSectionCheck( section ) {
		var items = document.querySelectorAll( '.ewpa-ability-check[data-section="' + section + '"]' );
		var sectionCb = document.querySelector( '.ewpa-section-check[data-section="' + section + '"]' );
		if ( ! sectionCb ) {
			return;
		}
		var allChecked = true;
		items.forEach( function ( cb ) {
			if ( ! cb.checked ) {
				allChecked = false;
			}
		} );
		sectionCb.checked = allChecked;
	}

	function updateAllSections() {
		sectionChecks.forEach( function ( sc ) {
			updateSectionCheck( sc.getAttribute( 'data-section' ) );
		} );
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

	enableAll.addEventListener( 'click', function () {
		checkboxes.forEach( function ( cb ) {
			cb.checked = true;
		} );
		sectionChecks.forEach( function ( sc ) {
			sc.checked = true;
		} );
		updateCount();
	} );

	disableAll.addEventListener( 'click', function () {
		checkboxes.forEach( function ( cb ) {
			cb.checked = false;
		} );
		sectionChecks.forEach( function ( sc ) {
			sc.checked = false;
		} );
		updateCount();
	} );

	updateCount();
	updateAllSections();
} )();
