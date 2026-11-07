/* Safe Site Cleaner, Speed tab: check one page. Page data goes in as text nodes, never as HTML. */
(function () {
	'use strict';
	var ui = window.SSCUI, el = ui.el, api = ui.api, box = ui.box;
	var host = el('div', {});
	var state = { pages: [], url: '', page: null, hasKey: false, reachable: true, busy: '', errors: {}, filter: 'all' };

	var WORDS = { good: 'Good', average: 'Needs work', poor: 'Poor' };
	var LOADS = {
		blocking: ['Blocks first paint', 'blocking'],
		during_load: ['Runs while the page loads', 'state'],
		deferred: ['Does not block', 'good'],
		delayed: ['Waits for visitor', 'good'],
		conditional: ['Only for some screens', 'state']
	};

	function size(b) {
		if (b === null || b === undefined) { return 'Unknown'; }
		if (b < 1024) { return b + ' B'; }
		if (b < 1048576) { return Math.round(b / 1024) + ' KB'; }
		return (b / 1048576).toFixed(1) + ' MB';
	}
	function scoreBand(s) { return s >= 90 ? 'good' : s >= 50 ? 'average' : 'poor'; }
	function pill(band) { return band ? el('span', { class: 'pill ' + band }, WORDS[band]) : null; }
	function when(ts) { return new Date(ts * 1000).toLocaleString(); }

	function fetchSaved() {
		return api('speed/result?url=' + encodeURIComponent(state.url)).then(function (d) {
			state.page = d.page;
			state.errors = {};
			render();
		});
	}

	function runChecks() {
		state.busy = 'Reading the page and its files…';
		render();
		var chain = api('speed/scan', 'POST', { url: state.url }).then(function (d) {
			state.page = d.page;
		}).catch(function (e) { state.errors.assets = e.message; });
		// Some hosts stop a site loading itself, so a failed scan doesn't rule out Google's test.
		if (state.reachable) {
			['mobile', 'desktop'].forEach(function (strategy) {
				chain = chain.then(function () {
					state.busy = 'Asking Google to test the ' + strategy + ' version. This can take a minute…';
					render();
					return api('speed/psi', 'POST', { url: state.url, strategy: strategy }).then(function (d) { state.page = d.page; })
						.catch(function (e) { state.errors[strategy] = e.message; });
				});
			});
		}
		return chain.then(function () { state.busy = ''; render(); });
	}

	function check(typed) {
		state.errors = {};
		return api('speed/result?url=' + encodeURIComponent(typed || state.url)).then(function (d) {
			state.url = d.url;
			state.page = d.page;
			if (!state.pages.some(function (p) { return p.url === d.url; })) { state.pages.push({ url: d.url, label: d.url }); }
			return runChecks();
		}).catch(function (e) {
			// A refused address changes nothing: the page already chosen stays chosen.
			state.errors.address = e.message;
			render();
		});
	}

	function picker() {
		var select = el('select', { disabled: !!state.busy, on: { change: function (e) {
			state.url = e.target.value;
			fetchSaved().catch(function (err) { state.errors.assets = err.message; render(); });
		} } }, state.pages.map(function (p) { return el('option', { value: p.url }, p.label); }));
		select.value = state.url;
		var custom = el('input', { type: 'text', placeholder: 'Or type /a-page-address', disabled: !!state.busy, on: { keydown: function (e) {
			if (e.key === 'Enter') { e.preventDefault(); go.click(); }
		} } });
		var go = el('button', { class: 'button button-primary', disabled: !!state.busy, on: { click: function () {
			check(custom.value.trim());
		} } }, 'Check this page');
		return el('div', { class: 'picker' }, [select, custom, go]);
	}

	function scoreTile(name, psi, err) {
		if (!psi) {
			return el('div', { class: 'stat score-tile' }, [el('b', {}, '—'), name + (err ? ': not measured' : ': not checked yet')]);
		}
		return el('div', { class: 'stat score-tile', title: 'Tested ' + when(psi.at) }, [el('b', {}, psi.score), name + ' score ', pill(scoreBand(psi.score))]);
	}

	function scores() {
		var psi = (state.page && state.page.psi) || {}, m = psi.mobile, d = psi.desktop;
		var errs = [state.errors.mobile, state.errors.desktop].filter(Boolean).filter(function (e, i, a) { return a.indexOf(e) === i; });
		var nodes = [];
		if (!state.reachable) {
			nodes.push(box('', 'Google cannot reach this site from the internet, so there is no speed score here. The file list below still works. Use this tab on the live site to get a score.'));
		} else {
			nodes.push(el('div', { class: 'stats' }, [scoreTile('Mobile', m, state.errors.mobile), scoreTile('Desktop', d, state.errors.desktop)]));
		}
		errs.forEach(function (e) { nodes.push(box('bad', e)); });
		if (m || d) {
			var names = {};
			((m && m.lab) || []).concat((d && d.lab) || []).forEach(function (r) { names[r.id] = r.name; });
			var cell = function (src, id) {
				var r = src && src.lab.filter(function (x) { return x.id === id; })[0];
				return el('td', { class: 'num' }, r ? [r.value + ' ', pill(r.band)] : '—');
			};
			nodes.push(el('div', { class: 'section' }, [
				el('h3', {}, 'Lab test'),
				el('p', { class: 'muted' }, 'One simulated visit on Google\'s test device.'),
				el('div', { class: 'scroll' }, el('table', { class: 'widefat striped data' }, [
					el('thead', {}, el('tr', {}, [el('th', {}, 'Measure'), el('th', { class: 'num' }, 'Mobile'), el('th', { class: 'num' }, 'Desktop')])),
					el('tbody', {}, Object.keys(names).map(function (id) { return el('tr', {}, [el('td', {}, names[id]), cell(m, id), cell(d, id)]); }))
				]))
			]));
			var field = (m && m.field) || (d && d.field);
			if (field) {
				nodes.push(el('div', { class: 'section' }, [
					el('h3', {}, 'Real visitors'),
					el('p', { class: 'muted' }, (field.site_wide ? 'This page has too little traffic alone, so this is for the whole site. ' : '') + 'Chrome users over the last 28 days. This is what Google uses for search.'),
					el('div', { class: 'scroll' }, el('table', { class: 'widefat striped data' }, el('tbody', {}, field.rows.map(function (r) {
						return el('tr', {}, [el('td', {}, r.name), el('td', { class: 'num' }, [r.value + ' ', pill(r.band)])]);
					}))))
				]));
			} else {
				nodes.push(el('p', { class: 'muted section' }, 'Google has no real-visitor data for this site yet, usually because it has too little traffic.'));
			}
			var fixes = (m && m.fixes && m.fixes.length ? m.fixes : (d && d.fixes)) || [];
			if (fixes.length) {
				nodes.push(el('div', { class: 'section' }, [
					el('h3', {}, 'What Google suggests'),
					el('ul', { class: 'list' }, fixes.map(function (f) { return el('li', {}, f.title + (f.value ? ' (' + f.value + ')' : '')); }))
				]));
			}
		}
		return nodes;
	}

	function weights(owners) {
		var top = owners.filter(function (o) { return o.bytes > 0; }).slice(0, 10);
		var max = top.length ? top[0].bytes : 1;
		return el('div', { class: 'section' }, [
			el('h3', {}, 'What each plugin adds to this page'),
			el('div', { class: 'weights' }, top.map(function (o) {
				return el('div', { class: 'weight', title: o.owner + ': ' + size(o.bytes) + ' in ' + o.files + ' file(s)' }, [
					el('span', { class: 'name' }, o.owner),
					el('span', { class: 'track' }, el('i', { style: 'width:' + Math.max(1, Math.round(o.bytes / max * 100)) + '%' })),
					el('span', { class: 'value' }, size(o.bytes))
				]);
			}))
		]);
	}

	function assetTable(rows) {
		var groups = {
			all: ['All', function () { return true; }],
			blocking: ['Blocking', function (r) { return r.state === 'blocking'; }],
			css: ['CSS', function (r) { return r.kind === 'css'; }],
			js: ['JavaScript', function (r) { return r.kind === 'js'; }],
			third: ['Third party', function (r) { return r.third_party; }]
		};
		var shown = rows.filter(groups[state.filter][1]);
		return el('div', { class: 'section' }, [
			el('h3', {}, 'Files this page loads'),
			el('div', { class: 'toolbar segmented' }, Object.keys(groups).map(function (k) {
				return el('button', { class: 'button' + (state.filter === k ? ' active' : ''), on: { click: function () { state.filter = k; render(); } } }, groups[k][0] + ' (' + rows.filter(groups[k][1]).length + ')');
			})),
			el('div', { class: 'scroll' }, el('table', { class: 'widefat striped data' }, [
				el('thead', {}, el('tr', {}, [el('th', {}, 'File'), el('th', {}, 'From'), el('th', {}, 'How it loads'), el('th', { class: 'num' }, 'Size')])),
				el('tbody', {}, shown.map(function (r) {
					var load = LOADS[r.state] || [r.state, 'state'];
					return el('tr', {}, [
						el('td', {}, [r.file, el('div', { class: 'sub', title: r.url }, (r.handle ? r.handle + ' · ' : '') + r.kind.toUpperCase())]),
						el('td', {}, r.owner),
						el('td', {}, el('span', { class: 'pill ' + load[1] }, load[0])),
						el('td', { class: 'num' }, (r.bytes !== null && !r.exact ? '~' : '') + size(r.bytes))
					]);
				}))
			]))
		]);
	}

	function assets() {
		var a = state.page && state.page.assets;
		if (state.errors.assets) { return [box('bad', state.errors.assets)]; }
		if (!a) { return []; }
		var t = a.totals;
		return [
			el('div', { class: 'section' }, [
				el('h3', {}, 'Page weight'),
				el('div', { class: 'stats' }, [
					el('div', { class: 'stat' }, [el('b', {}, t.files), 'files']),
					el('div', { class: 'stat' }, [el('b', {}, t.blocking), 'block first paint (' + size(t.blocking_bytes) + ')']),
					el('div', { class: 'stat' }, [el('b', {}, size(t.js_bytes)), 'JavaScript files']),
					el('div', { class: 'stat' }, [el('b', {}, size(t.css_bytes)), 'CSS files']),
					el('div', { class: 'stat' }, [el('b', {}, t.third_party), 'from other sites']),
					el('div', { class: 'stat' }, [el('b', {}, size(t.html_bytes)), 'page HTML']),
					el('div', { class: 'stat' }, [el('b', {}, size(t.inline_css_bytes + t.inline_js_bytes)), 'code inside the HTML']),
					el('div', { class: 'stat', title: 'Measured from this server without the page cache' }, [el('b', {}, (t.response_ms / 1000).toFixed(1) + ' s'), 'server response, uncached'])
				]),
				el('p', { class: 'muted' }, 'Sizes are files on disk, before compression, so they are higher than what visitors download. A ~ means the size was reported by the server, usually compressed.' + (t.unknown_sizes ? ' ' + t.unknown_sizes + ' file(s) did not report a size and count as 0.' : '') + ' Checked ' + when(a.at) + '.')
			]),
			weights(a.owners),
			assetTable(a.assets)
		];
	}

	function render() {
		host.textContent = '';
		var nodes = [
			el('h2', {}, 'Check a page'),
			el('p', { class: 'muted' }, 'Shows how fast a page is and what is slowing it down. Checking changes nothing on your site.'),
			picker(),
			state.busy ? box('', state.busy) : null,
			state.errors.address ? box('bad', state.errors.address) : null
		].concat(scores(), assets(), [el('p', { class: 'muted section' }, ['PageSpeed API key: ', state.hasKey ? 'saved' : 'none', '. ', el('a', { href: SSC.page + '&tab=settings' }, 'Change in Settings'), '.'])]);
		host.appendChild(el('div', { class: 'card' }, nodes));
	}

	function start() {
		api('speed/pages').then(function (d) {
			state.pages = d.pages;
			state.hasKey = d.has_key;
			state.reachable = d.reachable;
			state.url = d.pages[0].url;
			return fetchSaved();
		}).catch(function (e) {
			host.textContent = '';
			host.appendChild(box('bad', e.message));
		});
	}

	ui.speedCheck = { host: host, start: start };
}());
