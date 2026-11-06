/* Safe Site Cleaner admin screen. All page and file names go in as text nodes, never as HTML. */
(function () {
	'use strict';
	var app = document.getElementById('ssc-app');
	var state = { report: null, selected: {}, status: null };

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'on') { Object.keys(attrs.on).forEach(function (ev) { e.addEventListener(ev, attrs.on[ev]); }); }
			else if (k === 'class') { e.className = attrs[k]; }
			else if (k === 'checked' || k === 'disabled' || k === 'open') { e[k] = !!attrs[k]; }
			else { e.setAttribute(k, attrs[k]); }
		});
		[].concat(kids === undefined ? [] : kids).forEach(function (c) {
			if (c === null || c === false) { return; }
			e.appendChild(typeof c === 'object' ? c : document.createTextNode(String(c)));
		});
		return e;
	}

	function size(b) {
		b = +b || 0;
		if (b < 1024) { return b + ' B'; }
		var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
		do { b /= 1024; i++; } while (b >= 1024 && i < u.length - 1);
		return b.toFixed(b < 10 ? 1 : 0) + ' ' + u[i];
	}

	function api(path, method, body) {
		return fetch(SSC.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': SSC.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) { throw new Error(j && j.message ? j.message : 'Request failed (' + r.status + ')'); }
				return j;
			});
		});
	}

	function show() {
		app.textContent = '';
		[].slice.call(arguments).forEach(function (n) { if (n) { app.appendChild(n); } });
	}

	function box(kind, kids) { return el('div', { class: 'notice-box ' + (kind || '') }, kids); }
	function fail(err) { show(box('bad', err.message), el('button', { class: 'button', on: { click: load } }, 'Back')); }

	function load() {
		return api('status').then(function (s) {
			state.status = s;
			if (s.active) { return follow(s.active); }
			var latest = s.runs[0];
			if (latest && latest.kind === 'scan' && latest.status === 'done' && !latest.touched) { return openReport(latest.id); }
			home();
		}).catch(fail);
	}

	function history() {
		var runs = (state.status ? state.status.runs : []).filter(function (r) { return r.touched; });
		if (!runs.length) { return null; }
		var words = { applied: 'Cleaned and verified', rolled_back: 'Rolled back automatically', restored: 'Restored', stopped: 'Stopped' };
		return el('div', { class: 'card' }, [
			el('h2', {}, 'Past cleanups'),
			el('table', { class: 'widefat striped history' }, [
				el('thead', {}, el('tr', {}, [el('th', {}, 'Date'), el('th', {}, 'Result'), el('th', {}, 'In quarantine'), el('th', {}, 'DB backup'), el('th', {}, '')])),
				el('tbody', {}, runs.map(function (r) {
					return el('tr', {}, [
						el('td', {}, new Date((r.applied || r.created) * 1000).toLocaleString()),
						el('td', {}, (words[r.outcome] || r.outcome) + (r.purged ? ' (quarantine deleted)' : '')),
						el('td', {}, size(r.quarantine_bytes)),
						el('td', {}, r.backup_bytes ? size(r.backup_bytes) : '—'),
						el('td', {}, r.purged ? '' : el('button', { class: 'button', on: { click: function () { manage(r); } } }, 'Restore or delete…'))
					]);
				}))
			])
		]);
	}

	function home() {
		var keep = el('input', { type: 'number', min: 1, max: 50, value: 3, style: 'width:60px' });
		var warn = state.status && state.status.web_root ? box('', 'Backups are stored inside the web folder because the host allows nothing else. The folder has a random name and access rules, but on some servers those rules are ignored. Delete backups when you are done.') : null;
		show(
			el('div', { class: 'card' }, [
				el('h2', {}, 'Find what your site no longer uses'),
				el('p', { class: 'muted' }, 'The scan reads your database, your active code and your real pages. It changes nothing, and you choose what to clean afterwards.'),
				warn,
				el('div', { class: 'toolbar' }, [
					el('label', {}, ['Keep ', keep, ' revisions per post ']),
					el('button', { class: 'button button-primary', on: { click: function () {
						api('scan', 'POST', { keep: +keep.value || 3 }).then(function (v) { follow(v.id); }).catch(fail);
					} } }, 'Scan site')
				])
			]),
			history()
		);
	}

	function follow(run) {
		var bar = el('i', { style: 'width:2%' }), label = el('p', {}, 'Starting…'), sub = el('p', { class: 'muted' }, '');
		var note = el('p', { class: 'muted' }, 'You can leave this page open. If it closes, come back and the job will continue from where it stopped.');
		show(el('div', { class: 'card' }, [el('h2', {}, 'Working…'), label, el('div', { class: 'bar' }, bar), sub, note]));
		function step() {
			return api('tick', 'POST', { run: run }).then(function (v) {
				bar.style.width = Math.max(2, Math.round(((v.step + (v.status === 'running' ? 0.3 : 1)) / v.total) * 100)) + '%';
				label.textContent = (v.kind === 'restore' && v.reason === 'auto' ? 'Rolling back: ' : '') + v.label;
				sub.textContent = v.sub || '';
				if (v.status === 'running') { return step(); }
				if (v.kind === 'scan' && v.status === 'done') { return openReport(run); }
				return result(v);
			});
		}
		return step().catch(fail);
	}

	function openReport(run) {
		return api('report?run=' + encodeURIComponent(run)).then(function (r) {
			state.report = r;
			state.selected = {};
			review();
		}).catch(fail);
	}

	function review() {
		var r = state.report, run = r.run, items = r.scan.items, stats = run.stats || {};
		var groups = {}, order = [];
		items.forEach(function (it) {
			if (!groups[it.group]) { groups[it.group] = []; order.push(it.group); }
			groups[it.group].push(it);
		});
		var sum = el('div', { class: 'sum' }), go = el('button', { class: 'button button-primary', on: { click: confirmApply } }, 'Clean selected…');
		var checks = [];

		function refresh() {
			var n = 0, b = 0, rv = 0;
			items.forEach(function (it) {
				if (state.selected[it.id]) { n++; b += it.bytes || 0; if (it.risk === 'review') { rv++; } }
			});
			sum.textContent = n ? n + ' selected, ' + size(b) + (rv ? ', including ' + rv + ' to review' : '') : 'Nothing selected. Nothing changes until you choose.';
			go.disabled = !n || !run.can_verify;
			checks.forEach(function (c) { c.el.checked = !!state.selected[c.id]; });
		}

		var cards = order.map(function (g) {
			var list = groups[g], total = list.reduce(function (a, i) { return a + (i.bytes || 0); }, 0);
			var rows = el('div', { class: 'rows' });
			var open = false;
			var det = el('details', { class: 'group', on: { toggle: function () {
				if (open || !det.open) { return; }
				open = true;
				list.slice(0, 500).forEach(function (it) {
					var c = el('input', { type: 'checkbox', checked: state.selected[it.id], on: { change: function (e) { state.selected[it.id] = e.target.checked; refresh(); } } });
					checks.push({ id: it.id, el: c });
					rows.appendChild(el('label', { class: 'row' }, [c,
						el('span', {}, [el('span', { class: 'label' }, it.label), ' ', el('span', { class: 'pill ' + it.risk }, it.risk === 'low' ? 'low risk' : 'review'), el('div', { class: 'why' }, it.why)]),
						el('span', { class: 'size muted' }, it.bytes ? size(it.bytes) : '')]));
				});
				if (list.length > 500) { rows.appendChild(el('div', { class: 'row muted' }, ['', 'Showing 500 of ' + list.length + '. "Select all" includes the rest.', ''])); }
			} } }, [
				el('summary', {}, [el('span', { class: 'name' }, g + ' (' + list.length + ')'), el('span', { class: 'muted' }, total ? size(total) : '')]),
				rows
			]);
			var all = el('a', { href: '#', on: { click: function (e) {
				e.preventDefault();
				var on = !list.every(function (i) { return state.selected[i.id]; });
				list.forEach(function (i) { state.selected[i.id] = on; });
				refresh();
			} } }, 'Select all or none');
			det.firstChild.appendChild(all);
			return det;
		});

		var kept = {};
		r.scan.kept.forEach(function (k) { (kept[k.group] = kept[k.group] || []).push(k); });
		var keptCards = Object.keys(kept).map(function (g) {
			return el('details', { class: 'group kept' }, [
				el('summary', {}, el('span', { class: 'name' }, g + ' (' + kept[g].length + ')')),
				el('div', { class: 'rows' }, kept[g].slice(0, 300).map(function (k) {
					return el('div', { class: 'row' }, el('span', {}, [el('span', { class: 'label' }, k.label), el('div', { class: 'why' }, k.why)]));
				}))
			]);
		});

		var broken = stats.broken || [];
		var totalBytes = items.reduce(function (a, i) { return a + (i.bytes || 0); }, 0);
		show(
			el('div', { class: 'card' }, [
				el('h2', {}, 'Scan results'),
				el('div', { class: 'stats' }, [
					el('div', { class: 'stat' }, [el('b', {}, size(totalBytes)), 'could be cleaned']),
					el('div', { class: 'stat' }, [el('b', {}, stats.pages_ok + ' / ' + stats.pages), 'pages load']),
					el('div', { class: 'stat' }, [el('b', {}, stats.assets), 'files checked']),
					el('div', { class: 'stat' }, [el('b', {}, broken.length), 'already broken'])
				]),
				!run.can_verify ? box('bad', 'This server could not load its own home page. Cleaning is disabled because the result could not be checked.') : null,
				broken.length ? el('details', {}, [el('summary', {}, 'See files that were already broken before any cleaning'), el('ul', { class: 'list' }, broken.slice(0, 100).map(function (b) { return el('li', {}, b.path + ' (' + b.code + ')'); }))]) : null,
				el('div', { class: 'toolbar' }, [
					el('button', { class: 'button', on: { click: function () { items.forEach(function (i) { state.selected[i.id] = i.risk === 'low'; }); refresh(); } } }, 'Select all low-risk'),
					el('button', { class: 'button', on: { click: function () { state.selected = {}; refresh(); } } }, 'Clear'),
					el('button', { class: 'button', on: { click: function () { api('scan', 'POST', { keep: 3 }).then(function (v) { follow(v.id); }).catch(fail); } } }, 'Scan again')
				])
			]),
			el('div', {}, cards.length ? cards : el('p', {}, 'Nothing to clean.')),
			el('div', { class: 'card' }, [el('h2', {}, 'Kept on purpose'), el('p', { class: 'muted' }, 'These looked unused at first but something points to them, or they are off limits.'), el('div', {}, keptCards)]),
			history(),
			el('div', { class: 'sticky' }, [sum, go])
		);
		refresh();
	}

	function confirmApply() {
		var ids = Object.keys(state.selected).filter(function (k) { return state.selected[k]; });
		var picked = state.report.scan.items.filter(function (i) { return state.selected[i.id]; });
		var review = picked.filter(function (i) { return i.risk === 'review'; });
		show(el('div', { class: 'card' }, [
			el('h2', {}, 'Before anything changes'),
			el('p', {}, 'Selected: ' + ids.length + ' item(s), ' + size(picked.reduce(function (a, i) { return a + (i.bytes || 0); }, 0)) + '.'),
			el('ul', { class: 'list' }, [
				el('li', {}, 'The database is backed up, and the backup is test-restored before it is relied on.'),
				el('li', {}, 'Files move to quarantine. Nothing is deleted.'),
				el('li', {}, 'Every page is checked again afterwards. If anything got worse, everything is rolled back automatically.')
			]),
			review.length ? box('', [el('b', {}, review.length + ' item(s) need your judgement:'), el('ul', { class: 'list' }, review.slice(0, 30).map(function (i) { return el('li', {}, i.label + ': ' + i.why); }))]) : null,
			el('p', { class: 'muted' }, 'Checks cover public pages and the admin Plugins screen. They cannot test form submissions, emails, or pages that need a login, so please try those yourself.'),
			el('div', { class: 'toolbar' }, [
				el('button', { class: 'button button-primary', on: { click: function () {
					api('apply', 'POST', { run: state.report.run.id, ids: ids }).then(function (v) { follow(v.id); }).catch(fail);
				} } }, 'Back up and clean'),
				el('button', { class: 'button', on: { click: review_back } }, 'Go back')
			])
		]));
	}
	function review_back() { review(); }

	function result(v) {
		var head;
		if (v.outcome === 'applied') {
			var after = v.stats_after || {};
			head = box('good', [el('b', {}, 'Cleaned and verified. '), size(after.moved_bytes) + ' moved to quarantine, ' + (after.repaired || 0) + ' repaired. ' + after.pages + ' pages and ' + after.assets + ' files match or improve on the earlier check.']);
		} else if (v.outcome === 'rolled_back') {
			head = box('bad', [el('b', {}, 'Problems were found, so everything was put back. '), 'Your site is as it was before the cleanup.', el('ul', { class: 'list' }, v.problems.slice(0, 20).map(function (p) { return el('li', {}, p); }))]);
		} else if (v.outcome === 'restored') {
			head = box('good', 'Restored.');
		} else {
			head = box('bad', [el('b', {}, 'Stopped. '), v.error || 'The job did not finish.', v.touched ? '' : ' Nothing was changed.']);
		}
		var rest = (v.restore_problems || []).length ? box('', [el('b', {}, 'Differences after restore:'), el('ul', { class: 'list' }, v.restore_problems.slice(0, 20).map(function (p) { return el('li', {}, p); }))]) : null;
		api('status').then(function (s) {
			state.status = s;
			show(
				el('div', { class: 'card' }, [el('h2', {}, 'Result'), head, rest,
					v.outcome === 'applied' ? el('p', {}, 'Now please click through your site: forms, logged-in pages, and the page builder. The quarantine stays until you delete it.') : null,
					v.outcome === 'applied' && v.admin_checked === false ? el('p', { class: 'muted' }, 'The admin screen could not be checked automatically.') : null,
					el('details', {}, [el('summary', {}, 'Details'), el('ul', { class: 'list' }, v.log.map(function (l) { return el('li', {}, l); }))]),
					el('div', { class: 'toolbar' }, el('button', { class: 'button', on: { click: home } }, 'Done'))
				]),
				history()
			);
		}).catch(fail);
	}

	function manage(r) {
		var kept = el('input', { type: 'checkbox' });
		var phrase = el('input', { type: 'text', class: 'phrase', autocomplete: 'off' });
		var restorePhrase = el('input', { type: 'text', class: 'phrase', autocomplete: 'off' });
		var early = r.cooling_off_left > 0;
		var need = early ? 'DELETE NOW' : 'DELETE';
		show(
			el('div', { class: 'card' }, [
				el('h2', {}, 'Restore'),
				el('p', {}, 'Put every moved file back, and optionally replace the database with the backup. Replacing the database loses any content, orders or form entries added since the cleanup.'),
				el('div', { class: 'toolbar' }, [
					el('button', { class: 'button', on: { click: function () {
						api('restore', 'POST', { run: r.id, with_db: false }).then(function (v) { follow(v.id); }).catch(fail);
					} } }, 'Put files back only')
				]),
				el('div', { class: 'toolbar' }, [
					el('label', {}, ['Type RESTORE to also replace the database: ', restorePhrase]),
					el('button', { class: 'button', on: { click: function () {
						api('restore', 'POST', { run: r.id, with_db: true, phrase: restorePhrase.value }).then(function (v) { follow(v.id); }).catch(fail);
					} } }, 'Restore files and database')
				])
			]),
			r.outcome === 'applied' ? el('div', { class: 'card' }, [
				el('h2', {}, 'Delete permanently'),
				box('bad', 'This cannot be undone. Quarantined files (' + size(r.quarantine_bytes) + ') are gone for good, so make sure the site has been checked first.'),
				early ? el('p', { class: 'muted' }, 'This cleanup is under 24 hours old. Waiting a day gives problems time to show up.') : null,
				el('p', {}, el('label', {}, [kept, ' Also delete the database backup (' + size(r.backup_bytes) + '). Keeping it is recommended.'])),
				el('div', { class: 'toolbar' }, [
					el('label', {}, ['Type ' + need + ': ', phrase]),
					el('button', { class: 'button button-link-delete', on: { click: function () {
						api('purge', 'POST', { run: r.id, phrase: phrase.value, include_backup: kept.checked }).then(function () { return load(); }).catch(fail);
					} } }, 'Delete permanently')
				])
			]) : null,
			el('button', { class: 'button', on: { click: load } }, 'Back')
		);
	}

	load();
}());
