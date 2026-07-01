/**
 * Age Restriction meta box — mutual-exclusion toggle between the "Force
 * age verification" and "Exclude from age verification" checkboxes. When
 * Exclude is checked, Force is disabled + unchecked (Exclude wins).
 *
 * Enqueued from AgeWallet_Gating_Manager on post-edit screens.
 */
(function () {
	'use strict';
	document.addEventListener( 'DOMContentLoaded', function () {
		var restrictCheckbox = document.getElementById( 'agewallet_force_restrict' );
		var excludeCheckbox  = document.getElementById( 'agewallet_force_exclude' );
		if ( ! restrictCheckbox || ! excludeCheckbox ) {
			return;
		}
		function toggleRestrict() {
			restrictCheckbox.disabled = excludeCheckbox.checked;
			if ( excludeCheckbox.checked ) {
				restrictCheckbox.checked = false;
			}
		}
		excludeCheckbox.addEventListener( 'change', toggleRestrict );
		toggleRestrict();
	} );
})();
