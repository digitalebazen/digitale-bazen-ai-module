/**
 * RankMath content bridge — appendt gerenderde ACF flex-content aan de string
 * die RankMath gebruikt voor zijn content-analyse. Zonder deze hook ziet
 * RankMath alleen `post_content` (leeg voor ACF-only sites) en mist daardoor
 * subkop-, density- en outbound-link checks.
 */
( function () {
	if ( ! window.wp || ! window.wp.hooks || typeof window.wp.hooks.addFilter !== 'function' ) {
		return;
	}

	var data = window.dbAiRankmathBridge || {};
	if ( ! data.html ) {
		return;
	}

	wp.hooks.addFilter( 'rank_math_content', 'db-ai/acf-flex-bridge', function ( content ) {
		var existing = typeof content === 'string' ? content : '';
		return existing + '\n' + data.html;
	} );
} )();
