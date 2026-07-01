/**
 * Success handoff page — writes the verified-session cookie then redirects
 * back to the originally-requested URL. Config values (cookie name, cookie
 * value, cookie-attribute string, redirect target) are passed in via the
 * `agewalletOidcSuccess` object localized on this handle from PHP.
 */
(function () {
	'use strict';
	var cfg = ( typeof window.agewalletOidcSuccess === 'object' && window.agewalletOidcSuccess )
		? window.agewalletOidcSuccess
		: null;
	if ( ! cfg ) {
		return;
	}
	try {
		var cookieString = cfg.cookieName + '=' + encodeURIComponent( cfg.cookieValue ) + cfg.cookieAttributes;
		document.cookie = cookieString;
		if ( typeof console !== 'undefined' && console.log ) {
			console.log( '[AgeWallet] Set session cookie: ' + cookieString );
		}
		if ( typeof console !== 'undefined' && console.log ) {
			console.log( '[AgeWallet] Redirecting (replace) to: ' + cfg.redirectTo );
		}
		window.location.replace( cfg.redirectTo );
	} catch ( e ) {
		if ( typeof console !== 'undefined' && console.error ) {
			console.error( '[AgeWallet] Error during success page script execution.', e );
		}
		if ( typeof console !== 'undefined' && console.log ) {
			console.log( '[AgeWallet] Fallback redirect (href) to: ' + cfg.redirectTo );
		}
		window.location.href = cfg.redirectTo;
	}
})();
