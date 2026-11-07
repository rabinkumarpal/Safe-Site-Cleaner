/* Safe Site Cleaner, Speed tab. Rule names go in as text nodes, never as HTML. */
(function () {
	'use strict';
	var ui = window.SSCUI, el = ui.el, api = ui.api, box = ui.box, show = ui.show;

	function fail(err) { render(null, box('bad', err.message)); }

	var trial = false, others = [], overlaps = [], types = [];

	function keep(d) { trial = d.trial; others = d.others || []; overlaps = d.overlaps || []; types = d.types || types; }

	function lines(text) { return text.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean); }

	/* Where a rule applies. Nothing ticked and nothing typed means everywhere. */
	function whereEditor(r, done) {
		var w = r.where || { types: [], include: [], exclude: [], device: 'all', visitor: 'all' };
		var ticks = types.map(function (t) {
			var c = el('input', { type: 'checkbox', checked: w.types.indexOf(t.value) >= 0 });
			c.dataset.value = t.value;
			return el('label', { class: 'where-type' }, [c, ' ' + t.label]);
		});
		var include = el('textarea', { rows: 2, class: 'rules-json', placeholder: '/services/*' });
		include.value = w.include.join('\n');
		var exclude = el('textarea', { rows: 2, class: 'rules-json', placeholder: '/checkout/*' });
		exclude.value = w.exclude.join('\n');
		function pick(values, current) {
			var s = el('select', {}, values.map(function (v) { return el('option', { value: v[0] }, v[1]); }));
			s.value = current;
			return s;
		}
		var device = pick([['all', 'All devices'], ['phone', 'Phones only'], ['desktop', 'Desktop only']], w.device);
		var visitor = pick([['all', 'Everyone'], ['logged_out', 'Logged-out visitors only'], ['logged_in', 'Logged-in users only']], w.visitor);
		var msg = el('div', {});
		return el('div', { class: 'where-editor' }, [
			el('p', {}, el('b', {}, 'Page types')),
			el('p', { class: 'muted' }, 'Tick none to apply on every page type.'),
			el('div', { class: 'where-types' }, ticks),
			el('p', {}, [el('b', {}, 'Only at these addresses'), el('span', { class: 'muted' }, ' One per line. * matches anything, so /services/* covers every page under /services/. Leave empty for all.')]),
			include,
			el('p', {}, [el('b', {}, 'Never at these addresses'), el('span', { class: 'muted' }, ' For example /checkout/* or /contact/.')]),
			exclude,
			el('div', { class: 'toolbar' }, [el('label', {}, ['Device: ', device]), el('label', {}, ['Visitor: ', visitor])]),
			msg,
			el('div', { class: 'toolbar' }, [
				el('button', { class: 'button button-primary', on: { click: function () {
					var where = {
						types: ticks.map(function (l) { return l.firstChild; }).filter(function (c) { return c.checked; }).map(function (c) { return c.dataset.value; }),
						include: lines(include.value), exclude: lines(exclude.value), device: device.value, visitor: visitor.value
					};
					api('speed/where', 'POST', { id: r.id, where: where }).then(function (d) {
						keep(d);
						render(d.rules, box('good', 'Saved where "' + r.label + '" applies. ' + (r.enabled ? cleared(d.cleared) : '')));
					}).catch(function (e) { msg.textContent = ''; msg.appendChild(box('bad', e.message)); });
				} } }, 'Save'),
				el('button', { class: 'button', on: { click: done } }, 'Cancel')
			])
		]);
	}

	/* Tests on pages the rule may apply to, as the device it is for. */
	function testRule(r) {
		api('speed/test-pages', 'POST', { ids: [r.id], chosen: ui.speedCheck.chosen() }).then(function (t) {
			if (!t.pages.length) { render(null, box('bad', t.reason)); return; }
			ui.speedTry.test({ ids: [r.id], label: r.label, pages: t.pages, device: t.device });
		}).catch(fail);
	}

	function load(note) {
		return api('speed').then(function (d) { keep(d); render(d.rules, note); }).catch(fail);
	}

	/* Only what was really cleared. Other caches, a CDN or the host's, are the owner's to clear. */
	function cleared(list) {
		return (list && list.length ? 'Cleared: ' + list.join(', ') + '. ' : '') + 'If the site also uses another cache, such as a CDN or your host\'s cache, clear it too, then check the site in a private window.';
	}

	function othersBox() {
		if (!others.length) { return null; }
		return el('div', { class: 'section' }, [
			el('h3', {}, 'Other speed plugins on this site'),
			el('ul', { class: 'list' }, others.map(function (p) {
				return el('li', {}, p.name + ': ' + (p.does.length ? p.does.join(', ') : p.known ? 'none of the jobs these rules do' : 'its settings could not be read, so check them for overlap yourself') + '.');
			})),
			overlaps.length ? box('', [el('b', {}, 'Possible conflicts:'), el('ul', { class: 'list' }, overlaps.map(function (o) { return el('li', {}, o); }))]) : null
		]);
	}
	ui.speedReload = function () { return load(); };

	/* A test left unfinished, say by a closed tab. It undoes itself in minutes if nobody decides. */
	function trialBox() {
		if (!trial || ui.speedTry.isRunning()) { return null; }
		function act(path, msg) { api(path, 'POST').then(function () { load(box('good', msg)); ui.speedCheck.refresh(); }).catch(fail); }
		return box('', [
			el('b', {}, 'A speed test did not finish, so its change is on for now. '),
			'It will undo itself within 10 minutes unless you keep it.',
			el('div', { class: 'toolbar' }, [
				el('button', { class: 'button', on: { click: function () { act('speed/undo', 'The change was undone.'); } } }, 'Undo now'),
				el('button', { class: 'button', on: { click: function () { act('speed/keep', 'The change was kept.'); } } }, 'Keep it')
			])
		]);
	}

	function detail(r) {
		if (r.type === 'preload_image') { return r.file + (r.phone_file ? ' (phones: ' + r.phone_file + ')' : ''); }
		if (r.providers) { return r.providers.join(', ') + (r.nocookie && r.providers.indexOf('youtube') >= 0 ? ' (YouTube privacy mode)' : ''); }
		var list = r.files || r.handles;
		if (list) { return list.join(', ') + (r.except_pages && r.except_pages.length ? ' (not on page IDs ' + r.except_pages.join(', ') + ')' : ''); }
		return r.recipe || '';
	}

	function row(r) {
		var on = el('input', { type: 'checkbox', checked: r.enabled, on: { change: function (e) {
			if (ui.speedTry.isRunning()) { e.target.checked = !e.target.checked; return; }
			on.disabled = true;
			api('speed/toggle', 'POST', { id: r.id, enabled: e.target.checked }).then(function (d) {
				keep(d);
				render(d.rules, box('good', (e.target.checked ? 'Turned on: ' : 'Turned off: ') + r.label + '. ' + cleared(d.cleared)));
			}).catch(fail);
		} } });
		var editHost = el('div', {});
		var whereLine = el('div', { class: 'why where' }, [el('b', {}, 'Where: '), r.where_text + ' ', el('a', { href: '#', on: { click: function (e) {
			e.preventDefault();
			if (ui.speedTry.isRunning() || trial) { return; }
			editHost.textContent = '';
			editHost.appendChild(whereEditor(r, function () { editHost.textContent = ''; }));
		} } }, 'Change')]);
		var loggedInOnly = r.where && r.where.visitor === 'logged_in';
		return el('div', { class: 'row' }, [
			on,
			el('span', {}, [
				el('b', { class: 'label' }, r.label),
				el('div', { class: 'why' }, r.what),
				detail(r) ? el('div', { class: 'why target' }, detail(r)) : null,
				whereLine,
				r.where_note ? el('div', { class: 'why problem' }, r.where_note) : null,
				r.problem ? el('div', { class: 'why problem' }, r.problem) : null,
				editHost
			]),
			el('span', { class: 'rule-actions' }, [!r.enabled && !trial && !loggedInOnly ? el('button', { class: 'button', on: { click: function () {
				testRule(r);
			} } }, 'Test first') : null, ' ', el('button', { class: 'button-link button-link-delete', on: { click: function () {
				if (ui.speedTry.isRunning()) { return; }
				if (window.confirm('Remove this rule? You can add it back by importing it again.')) {
					api('speed/delete', 'POST', { id: r.id }).then(function (d) { keep(d); render(d.rules, box('good', 'Rule removed. ' + cleared(d.cleared))); }).catch(fail);
				}
			} } }, 'Remove')])
		]);
	}

	/* Rules as a file for another site. Nothing is saved until the check has been read and accepted. */
	function profileCard(rules) {
		var text = el('textarea', { class: 'rules-json', rows: 6, spellcheck: 'false', placeholder: 'Choose a profile file, or paste its contents here.' });
		var file = el('input', { type: 'file', accept: '.json,application/json', on: { change: function () {
			var f = file.files[0];
			if (!f) { return; }
			var reader = new FileReader();
			reader.onload = function () { text.value = String(reader.result); };
			reader.readAsText(f);
		} } });
		var out = el('div', {});
		function check() {
			var data;
			try { data = JSON.parse(text.value); } catch (e) { out.textContent = ''; out.appendChild(box('bad', 'That is not valid JSON: ' + e.message)); return; }
			api('speed/profile-check', 'POST', { profile: data }).then(function (d) { review(data, d.rows); }).catch(function (e) {
				out.textContent = '';
				out.appendChild(box('bad', e.message));
			});
		}
		function review(data, rows) {
			var bad = rows.filter(function (r) { return r.status === 'invalid'; }).length;
			var keep = el('input', { type: 'checkbox' });
			var words = { 'new': 'New', replace: 'Replaces a rule', invalid: 'Not valid' };
			out.textContent = '';
			out.appendChild(el('div', { class: 'scroll section' }, el('table', { class: 'widefat striped data' }, [
				el('thead', {}, el('tr', {}, [el('th', {}, 'Rule'), el('th', {}, 'Here')])),
				el('tbody', {}, rows.map(function (r) {
					return el('tr', {}, [
						el('td', {}, [r.label || '(no name)', r.what ? el('div', { class: 'sub' }, r.what) : null,
							r.error ? el('div', { class: 'sub problem' }, r.error) : null].concat(r.warnings.map(function (w) { return el('div', { class: 'sub problem' }, w); }))),
						el('td', {}, el('span', { class: 'pill ' + (r.status === 'invalid' ? 'poor' : 'state') }, words[r.status]))
					]);
				}))
			])));
			if (bad) {
				out.appendChild(box('bad', 'Nothing can be imported until the rule(s) marked "Not valid" are fixed. Nothing was changed.'));
				return;
			}
			out.appendChild(el('p', {}, el('label', {}, [keep, ' Also turn on rules the file marks as on, without testing them here'])));
			out.appendChild(el('div', { class: 'toolbar' }, el('button', { class: 'button button-primary', on: { click: function () {
				api('speed/profile-import', 'POST', { profile: data, keep_on: keep.checked }).then(function (d) {
					render(d.rules, box('good', d.imported + ' rule(s) imported' + (keep.checked ? '.' : ', all off. Use "Test first" to try each one on this site.')));
				}).catch(function (e) { out.appendChild(box('bad', e.message)); });
			} } }, 'Import ' + rows.length + ' rule(s)')));
		}
		function download() {
			api('speed/profile').then(function (profile) {
				var link = el('a', {
					href: URL.createObjectURL(new Blob([JSON.stringify(profile, null, 2)], { type: 'application/json' })),
					download: 'speed-profile-' + location.hostname + '-' + new Date().toISOString().slice(0, 10) + '.json'
				});
				document.body.appendChild(link);
				link.click();
				link.remove();
				setTimeout(function () { URL.revokeObjectURL(link.href); }, 2000);
			}).catch(fail);
		}
		return el('div', { class: 'card' }, [
			el('h2', {}, 'Use these rules on another site'),
			el('p', { class: 'muted' }, 'Download this site\'s rules as a profile, then import it on another site. You see what it would do there before anything changes, and imported rules start off.'),
			rules && rules.length ? el('div', { class: 'toolbar' }, el('button', { class: 'button', on: { click: download } }, 'Download profile')) : null,
			el('p', {}, file),
			text,
			el('div', { class: 'toolbar' }, el('button', { class: 'button', on: { click: check } }, 'Check profile')),
			out
		]);
	}

	function render(rules, note) {
		// Redrawing would move the test's frames, and a moved frame reloads and ruins the measurement.
		if (ui.speedTry.isRunning()) { return; }
		if (!rules) { show(note, ui.speedTry.host, ui.speedCheck.host, el('button', { class: 'button', on: { click: function () { load(); } } }, 'Back')); return; }
		show(
			note,
			trialBox(),
			ui.speedTry.host,
			ui.speedCheck.host,
			el('div', { class: 'card' }, [
				el('h2', {}, 'Speed rules'),
				el('p', { class: 'muted' }, 'Each rule changes how pages load for visitors, and only while its switch is on. Turning one off undoes it right away. "Test first" checks real pages before going live. The switch applies a rule straight away with no checks.'),
				rules.length ? el('div', { class: 'group speed-rules' }, el('div', { class: 'rows' }, rules.map(row))) : box('', 'No rules yet. Check a page above for suggested fixes, or import a profile below.'),
				othersBox()
			]),
			profileCard(rules)
		);
	}

	ui.speedCheck.start();
	load();
}());
