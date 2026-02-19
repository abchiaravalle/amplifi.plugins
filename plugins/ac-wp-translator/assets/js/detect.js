(function() {
	'use strict';

	if (typeof acwptDetect === 'undefined') return;

	var config      = acwptDetect;
	var enabled     = config.languages || [];
	var currentLang = config.currentLang || 'en';

	if (enabled.length === 0) return;

	// Get the browser's preferred languages.
	var browserLangs = navigator.languages
		? Array.from(navigator.languages)
		: [navigator.language || navigator.userLanguage || ''];

	// Find the best matching enabled language.
	var bestMatch = null;
	for (var i = 0; i < browserLangs.length; i++) {
		var code = browserLangs[i].split('-')[0].toLowerCase();
		if (code !== currentLang && enabled.indexOf(code) !== -1) {
			bestMatch = code;
			break;
		}
	}

	if (!bestMatch) return;

	// Check if the user has previously dismissed this suggestion.
	var dismissKey = 'acwpt_dismissed_' + bestMatch;
	try {
		if (localStorage.getItem(dismissKey)) return;
	} catch(e) {}

	// Build the suggestion banner.
	var langName = config.names[bestMatch] || bestMatch;
	var switchUrl = config.homeUrl + '/' + bestMatch + config.currentPath;

	var banner = document.createElement('div');
	banner.className = 'acwpt-suggestion-banner';
	banner.innerHTML =
		'<span>' + escHtml(langName) + '</span>' +
		'<a href="' + escAttr(switchUrl) + '" class="acwpt-switch-link">Switch</a>' +
		'<button class="acwpt-dismiss" aria-label="Dismiss">&times;</button>';

	document.body.appendChild(banner);

	banner.querySelector('.acwpt-dismiss').addEventListener('click', function() {
		banner.remove();
		try {
			localStorage.setItem(dismissKey, '1');
		} catch(e) {}
	});

	function escHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	function escAttr(str) {
		return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}
})();
