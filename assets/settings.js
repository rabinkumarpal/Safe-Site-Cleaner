/* Safe Site Cleaner, Settings tab. */
(function () {
	'use strict';
	var ui = window.SSCUI, el = ui.el, api = ui.api, box = ui.box, show = ui.show;

	function render(hasKey, note) {
		var input = el('input', { type: 'password', autocomplete: 'new-password', class: 'regular-text', placeholder: hasKey ? 'A key is saved. Paste a new one to replace it.' : 'Paste your API key' });
		var test = el('button', { class: 'button', disabled: !hasKey, on: { click: function () {
			test.disabled = true;
			test.textContent = 'Asking Google…';
			api('speed/key-test', 'POST').then(function () {
				render(true, box('good', 'The key works. Google answered a test request.'));
			}).catch(function (e) { render(true, box('bad', e.message)); });
		} } }, 'Test key');
		show(el('div', { class: 'card' }, [
			el('h2', {}, 'PageSpeed API key'),
			el('p', { class: 'muted' }, ['Optional. Speed scores come from Google PageSpeed Insights. Without a key, your tests share Google\'s public quota and can be refused. A key is free: ', el('a', { href: 'https://developers.google.com/speed/docs/insights/v5/get-started', target: '_blank', rel: 'noopener noreferrer' }, 'get one from Google'), '.']),
			note,
			el('p', {}, hasKey ? 'A key is saved. It is never shown again.' : 'No key saved.'),
			el('div', { class: 'toolbar' }, [
				input,
				el('button', { class: 'button button-primary', on: { click: function () {
					api('speed/key', 'POST', { key: input.value }).then(function (d) {
						render(d.has_key, box('good', d.has_key ? 'Key saved.' : 'No key entered, so nothing is saved.'));
					}).catch(function (e) { render(hasKey, box('bad', e.message)); });
				} } }, 'Save key'),
				test,
				hasKey ? el('button', { class: 'button-link button-link-delete', on: { click: function () {
					api('speed/key', 'POST', { key: '' }).then(function () { render(false, box('good', 'Key removed.')); })
						.catch(function (e) { render(true, box('bad', e.message)); });
				} } }, 'Remove key') : null
			]),
			el('p', { class: 'muted' }, 'Google still has to reach your site to score it, so a local site gets no score even with a key. In Google Cloud, restrict the key to the PageSpeed Insights API.')
		]));
	}

	api('speed/pages').then(function (d) { render(d.has_key); }).catch(function (e) { show(box('bad', e.message)); });
}());
