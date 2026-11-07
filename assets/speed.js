/* Safe Site Cleaner, Speed tab. Rule names go in as text nodes, never as HTML. */
(function () {
	'use strict';
	var ui = window.SSCUI, el = ui.el, api = ui.api, box = ui.box, show = ui.show;

	function fail(err) { render(null, box('bad', err.message)); }

	function load(note) {
		return api('speed').then(function (d) { render(d.rules, note); }).catch(fail);
	}

	function detail(r) {
		var list = r.files || r.handles;
		if (list) { return list.join(', ') + (r.except_pages && r.except_pages.length ? ' (not on page IDs ' + r.except_pages.join(', ') + ')' : ''); }
		return r.recipe || '';
	}

	function row(r) {
		var on = el('input', { type: 'checkbox', checked: r.enabled, on: { change: function (e) {
			on.disabled = true;
			api('speed/toggle', 'POST', { id: r.id, enabled: e.target.checked }).then(function (d) {
				render(d.rules, box('good', (e.target.checked ? 'Turned on: ' : 'Turned off: ') + r.label + '. Caches were cleared. Please check your site in a private window.'));
			}).catch(fail);
		} } });
		return el('div', { class: 'row' }, [
			on,
			el('span', {}, [
				el('b', { class: 'label' }, r.label),
				el('div', { class: 'why' }, r.what),
				detail(r) ? el('div', { class: 'why target' }, detail(r)) : null,
				r.problem ? el('div', { class: 'why problem' }, r.problem) : null
			]),
			el('button', { class: 'button-link button-link-delete', on: { click: function () {
				if (window.confirm('Remove this rule? You can add it back by importing it again.')) {
					api('speed/delete', 'POST', { id: r.id }).then(function (d) { render(d.rules, box('good', 'Rule removed.')); }).catch(fail);
				}
			} } }, 'Remove')
		]);
	}

	function importCard(rules) {
		var text = el('textarea', { class: 'rules-json', rows: 8, spellcheck: 'false', placeholder: '[ { "type": "delay_script", "label": "Delay captcha", "handles": ["hcaptcha"], "enabled": false } ]' });
		return el('div', { class: 'card' }, [
			el('h2', {}, 'Import or copy rules'),
			el('p', { class: 'muted' }, 'Paste a JSON list of rules to add them. A rule for the same files or handles is replaced. Rules stay off unless the JSON says "enabled": true.'),
			text,
			el('div', { class: 'toolbar' }, [
				el('button', { class: 'button', on: { click: function () {
					var rows;
					try { rows = JSON.parse(text.value); } catch (e) { return render(rules, box('bad', 'That is not valid JSON: ' + e.message)); }
					if (!Array.isArray(rows)) { return render(rules, box('bad', 'The JSON must be a list of rules, inside [ ].')); }
					api('speed/import', 'POST', { rules: rows }).then(function (d) {
						render(d.rules, box('good', d.imported + ' rule(s) imported. Caches were cleared.'));
					}).catch(fail);
				} } }, 'Import'),
				rules && rules.length ? el('button', { class: 'button', on: { click: function () {
					text.value = JSON.stringify(rules.map(function (r) {
						var c = Object.assign({}, r);
						delete c.id; delete c.what; delete c.problem;
						return c;
					}), null, 2);
				} } }, 'Show this site\'s rules as JSON') : null
			])
		]);
	}

	function render(rules, note) {
		if (!rules) { show(note, ui.speedCheck.host, el('button', { class: 'button', on: { click: function () { load(); } } }, 'Back')); return; }
		show(
			note,
			ui.speedCheck.host,
			el('div', { class: 'card' }, [
				el('h2', {}, 'Speed rules'),
				el('p', { class: 'muted' }, 'Each rule changes how pages load for visitors, and only while its switch is on. Turning one off undoes it right away. Automatic checks do not cover these rules yet, so after a change please try your pages and forms yourself.'),
				rules.length ? el('div', { class: 'group speed-rules' }, el('div', { class: 'rows' }, rules.map(row))) : box('', 'No rules yet. Import some below.')
			]),
			importCard(rules)
		);
	}

	ui.speedCheck.start();
	load();
}());
