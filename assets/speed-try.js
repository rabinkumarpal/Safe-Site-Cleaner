/* Safe Site Cleaner, Speed tab: test a rule on real pages, then apply it or undo it. */
(function () {
	'use strict';
	var ui = window.SSCUI, el = ui.el, api = ui.api, box = ui.box;
	var host = el('div', {}), running = false;
	var LOAD_LIMIT = 40000;

	function clean(list) {
		return (list || []).map(function (e) { return String(e).replace(/\d+/g, '#'); }).filter(function (e, i, a) { return a.indexOf(e) === i; });
	}

	/* What got worse. Problems stop or undo a rule. Notes are only worth knowing, because one load is noisy. */
	function compare(b, a) {
		var problems = [], notes = [], seen = clean(b.errors), seenFiles = b.failed || [];
		clean(a.errors).forEach(function (e) { if (seen.indexOf(e) < 0) { problems.push('New script error: ' + e); } });
		(a.failed || []).forEach(function (f) { if (seenFiles.indexOf(f) < 0) { problems.push('A file failed to load: ' + f); } });
		if (a.forms < b.forms) { problems.push('A form disappeared (' + b.forms + ' before, ' + a.forms + ' after).'); }
		if (a.broken > b.broken) { problems.push((a.broken - b.broken) + ' more broken image(s).'); }
		if (a.stuck_css > 0) { problems.push(a.stuck_css + ' stylesheet(s) never finished loading.'); }
		if (a.pending_js > 0) { problems.push(a.pending_js + ' delayed script(s) did not start.'); }
		if (b.text > 500 && a.text < b.text * 0.8) { problems.push('The page lost more than 20% of its text.'); }
		if (b.height > 500 && (a.height < b.height * 0.75 || a.height > b.height * 1.25)) { problems.push('The page height changed by more than 25%.'); }
		if (!b.painted || !a.painted) {
			notes.push('Layout and timing were not compared, because this tab was not in view for both loads.');
			return { problems: problems, notes: notes };
		}
		if (a.cls > b.cls + 0.1) { problems.push('Layout shift got worse (' + b.cls + ' to ' + a.cls + ').'); }
		if (b.lcp && a.lcp > b.lcp * 1.5 && a.lcp - b.lcp > 1000) { problems.push('The main content appeared much later (' + b.lcp + ' ms to ' + a.lcp + ' ms).'); }
		if (b.lcp && a.lcp > b.lcp + 300 && problems.join().indexOf('main content') < 0) { notes.push('The main content appeared a little later in this one test.'); }
		if (a.cls > b.cls + 0.02 && a.cls <= b.cls + 0.1) { notes.push('Layout shift rose slightly.'); }
		return { problems: problems, notes: notes };
	}

	function stage() {
		var labels = { before: el('b', {}, 'Without'), after: el('b', {}, 'With') };
		var frames = {};
		['before', 'after'].forEach(function (k) {
			// No allow-forms or top navigation, so a click here can't send a form or leave the admin.
			frames[k] = el('iframe', { sandbox: 'allow-scripts allow-same-origin', title: k, class: 'preview-frame' });
		});
		var node = el('div', { class: 'preview-stage' }, ['before', 'after'].map(function (k) {
			return el('div', { class: 'preview-col' }, [el('div', { class: 'preview-label' }, labels[k]), frames[k]]);
		}));
		return { node: node, frames: frames, labels: labels };
	}

	/* One retry, because one stalled load shouldn't count as a verdict on the page. */
	function measure(frame, url) {
		return measureOnce(frame, url).then(function (data) {
			return data || measureOnce(frame, url + '1');
		});
	}

	function measureOnce(frame, url) {
		return new Promise(function (resolve) {
			var done = false;
			var load = new URL(url).searchParams.get('ssc_r');
			function finish(data) {
				if (done) { return; }
				done = true;
				window.removeEventListener('message', on);
				clearTimeout(timer);
				resolve(data);
			}
			function on(e) {
				if (e.origin === location.origin && e.source === frame.contentWindow && e.data && e.data.ssc === 'probe' && e.data.load === load) { finish(e.data.data); }
			}
			var timer = setTimeout(function () { finish(null); }, LOAD_LIMIT);
			window.addEventListener('message', on);
			frame.src = url;
		});
	}

	function metricRows(b, a) {
		function kb(n) { return Math.round(n / 1024) + ' KB'; }
		function ms(d) { return d.painted && d.lcp ? d.lcp + ' ms' : 'Not measured'; }
		function shift(d) { return d.painted ? d.cls : 'Not measured'; }
		return [
			['Main content appears (LCP)', ms(b), ms(a)],
			['Layout shift (CLS)', shift(b), shift(a)],
			['Files that block first paint', b.blocking, a.blocking],
			['Requests', b.requests, a.requests],
			['Files, compressed size', kb(b.bytes), kb(a.bytes)],
			['Script errors', b.errors.length, a.errors.length],
			['Forms', b.forms, a.forms]
		].map(function (r) {
			return el('tr', {}, [el('td', {}, r[0]), el('td', { class: 'num' }, r[1]), el('td', { class: 'num' }, r[2])]);
		});
	}

	function finishUp(node) {
		running = false;
		host.textContent = '';
		if (node) { host.appendChild(node); host.scrollIntoView({ block: 'start' }); }
		if (ui.speedReload) { ui.speedReload(); }
		if (ui.speedCheck) { ui.speedCheck.refresh(); }
	}

	function removeCreated(opts) {
		return (opts.created || []).reduce(function (p, id) {
			return p.then(function () { return api('speed/delete', 'POST', { id: id }); });
		}, Promise.resolve());
	}

	/* Each test page, chosen one last, so it is the one left on screen for comparing. */
	function test(opts) {
		if (running) { return Promise.resolve(); }
		running = true;
		var s = stage(), status = el('p', {}, 'Starting…'), results = [];
		host.textContent = '';
		host.appendChild(el('div', { class: 'card' }, [
			el('h2', {}, 'Testing: ' + opts.label),
			el('p', { class: 'muted' }, 'Pages load here with and without the change' + (opts.device ? ', as a ' + opts.device : '') + '. Your visitors see nothing yet.'),
			status,
			s.node
		]));
		host.scrollIntoView({ block: 'start' });
		var pages = opts.pages;
		var chain = Promise.resolve();
		pages.forEach(function (url, i) {
			chain = chain.then(function () {
				var path = url.replace(location.origin, '');
				return api('speed/preview', 'POST', { url: url, ids: opts.ids, device: opts.device }).then(function (links) {
					status.textContent = 'Page ' + (i + 1) + ' of ' + pages.length + ', without the change: ' + path + '. Keep this tab in view.';
					return measure(s.frames.before, links.before).then(function (before) {
						status.textContent = 'Page ' + (i + 1) + ' of ' + pages.length + ', with the change: ' + path;
						return measure(s.frames.after, links.after).then(function (after) {
							results.push({ url: url, path: path, before: before, after: after, links: links, diff: before && after ? compare(before, after) : null });
						});
					});
				});
			});
		});
		return chain.then(function () {
			var measured = results.filter(function (r) { return r.diff; });
			var problems = [];
			measured.forEach(function (r) { r.diff.problems.forEach(function (p) { problems.push(r.path + ': ' + p); }); });
			var unmeasured = results.length - measured.length;
			if (opts.auto && !problems.length && !unmeasured) { return apply(opts, results, s, status); }
			review(opts, results, s, problems, unmeasured);
		}).catch(function (e) { fail(opts, e.message); });
	}

	function fail(opts, message) {
		removeCreated(opts).catch(function () {}).then(function () {
			finishUp(el('div', { class: 'card' }, [el('h2', {}, 'Test stopped'), box('bad', message), el('p', { class: 'muted' }, 'Nothing was changed for your visitors.')]));
		});
	}

	function review(opts, results, s, problems, unmeasured) {
		var last = results[results.length - 1];
		var notes = [];
		results.forEach(function (r) { if (r.diff) { r.diff.notes.forEach(function (n) { notes.push(r.path + ': ' + n); }); } });
		var parts = [
			el('h2', {}, 'Compare: ' + opts.label),
			problems.length ? box('bad', [el('b', {}, 'This change looks harmful, so it is better left off.'), el('ul', { class: 'list' }, problems.slice(0, 15).map(function (p) { return el('li', {}, p); }))])
				: unmeasured ? box('', 'Some pages did not report back in time, so they were not compared. A slow page or a security setting that blocks frames can cause this. Check them yourself before turning this on.')
				: box('good', 'No problems found on ' + results.length + ' page(s). Look at both versions below, then decide.'),
			notes.length ? el('ul', { class: 'list muted' }, notes.slice(0, 6).map(function (n) { return el('li', {}, n); })) : null
		];
		if (last.diff) {
			parts.push(el('div', { class: 'scroll section' }, el('table', { class: 'widefat striped data' }, [
				el('thead', {}, el('tr', {}, [el('th', {}, last.path), el('th', { class: 'num' }, 'Without'), el('th', { class: 'num' }, 'With')])),
				el('tbody', {}, metricRows(last.before, last.after))
			])));
			parts.push(el('p', { class: 'muted' }, 'One load on this computer, at phone width. Use these to compare the two versions, not as real-world speed.'));
		}
		parts.push(el('div', { class: 'toolbar' }, [
			el('button', { class: problems.length ? 'button' : 'button button-primary', on: { click: function () { apply(opts, results, s); } } }, problems.length ? 'Turn on anyway' : 'Turn on'),
			el('button', { class: problems.length ? 'button button-primary' : 'button', on: { click: function () {
				removeCreated(opts).catch(function () {}).then(function () { finishUp(null); });
			} } }, 'Leave it off'),
			el('a', { href: last.links.before, target: '_blank', rel: 'noopener' }, 'Open "without" in a new tab'),
			el('a', { href: last.links.after, target: '_blank', rel: 'noopener' }, 'Open "with" in a new tab')
		]));
		host.textContent = '';
		host.appendChild(el('div', { class: 'card' }, parts.concat([s.node])));
		host.scrollIntoView({ block: 'start' });
	}

	/* Goes live as a trial. It stays only if the real pages still pass; otherwise it undoes itself. */
	function apply(opts, results, s, status) {
		if (!status) {
			status = el('p', {}, '');
			host.textContent = '';
			host.appendChild(el('div', { class: 'card' }, [el('h2', {}, 'Applying: ' + opts.label), status, s.node]));
		}
		status.textContent = 'Turning it on and checking the server still returns the page…';
		var measured = results.filter(function (r) { return r.diff; });
		return api('speed/trial', 'POST', { ids: opts.ids, url: results[results.length - 1].url }).then(function (t) {
			if (t.problems && t.problems.length) { return undone(opts, t.problems); }
			var problems = [], chain = Promise.resolve();
			measured.forEach(function (r, i) {
				chain = chain.then(function () {
					status.textContent = 'Checking the live page ' + (i + 1) + ' of ' + measured.length + ': ' + r.path;
					return api('speed/preview', 'POST', { url: r.url, ids: opts.ids, device: opts.device }).then(function (links) {
						return measure(s.frames.after, links.live);
					}).then(function (live) {
						if (!live) { problems.push(r.path + ': the live page could not be measured.'); return; }
						compare(r.before, live).problems.forEach(function (p) { problems.push(r.path + ': ' + p); });
					});
				});
			});
			return chain.then(function () {
				if (problems.length) {
					return api('speed/undo', 'POST').then(function () { return undone(opts, problems); });
				}
				return api('speed/keep', 'POST').then(function () {
					finishUp(el('div', { class: 'card' }, [el('h2', {}, 'Done'), box('good', 'Turned on: ' + opts.label + '. ' + measured.length + ' live page(s) passed the check. Please still try your forms, because a form can\'t be sent during this test.')]));
				});
			});
		}).catch(function (e) {
			// Trial or not, a failure here must not leave an unchecked change live.
			api('speed/undo', 'POST').catch(function () {}).then(function () {
				finishUp(el('div', { class: 'card' }, [el('h2', {}, 'Stopped'), box('bad', e.message), el('p', { class: 'muted' }, 'The change was undone.')]));
			});
		});
	}

	function undone(opts, problems) {
		finishUp(el('div', { class: 'card' }, [
			el('h2', {}, 'Undone automatically'),
			box('bad', [el('b', {}, 'Turned off again: ' + opts.label + '. Here is why:'), el('ul', { class: 'list' }, problems.slice(0, 15).map(function (p) { return el('li', {}, p); }))])
		]));
	}

	/* Adds rules (off), then tests them. Rules made here are removed again if they aren't kept. */
	function tryRules(rules, opts) {
		if (running) { return Promise.resolve(); }
		return api('speed/import', 'POST', { rules: rules }).then(function (d) {
			return test({ ids: d.ids, created: d.created, label: opts.label, auto: opts.auto, pages: opts.pages });
		});
	}

	ui.speedTry = { compare: compare, host: host, test: test, tryRules: tryRules, isRunning: function () { return running; } };
}());
