/* Printed only on signed preview links. Measures the page and tells the admin screen. */
(function () {
	if (window.parent === window) { return; }
	var errors = [], failed = [], lcp = 0, cls = 0, sent = false;
	addEventListener('error', function (e) {
		var t = e.target;
		if (t && t !== window) {
			var u = t.src || t.href;
			if (u) { failed.push(String(u).split('?')[0]); }
		} else {
			errors.push(String(e.message || 'Script error'));
		}
	}, true);
	addEventListener('unhandledrejection', function (e) {
		errors.push('Unhandled promise: ' + String((e.reason && e.reason.message) || e.reason || ''));
	});
	function watch(type, fn) {
		try { new PerformanceObserver(function (l) { l.getEntries().forEach(fn); }).observe({ type: type, buffered: true }); } catch (x) {}
	}
	watch('largest-contentful-paint', function (e) { lcp = e.startTime; });
	watch('layout-shift', function (e) { if (!e.hadRecentInput) { cls += e.value; } });

	function report() {
		if (sent) { return; }
		sent = true;
		var res = performance.getEntriesByType('resource');
		var images = [].slice.call(document.images).filter(function (i) { return i.currentSrc && i.complete && !i.naturalWidth; });
		parent.postMessage({ ssc: 'probe', load: new URLSearchParams(location.search).get('ssc_r'), data: {
			errors: errors.slice(0, 30),
			failed: failed.slice(0, 30),
			// A tab or pane out of view paints nothing, so its timing and layout numbers mean nothing.
			painted: performance.getEntriesByType('paint').length > 0,
			lcp: Math.round(lcp),
			cls: Math.round(cls * 1000) / 1000,
			requests: res.length,
			// Not transferSize: a file the browser already cached would count as nothing, flattering the second load.
			bytes: res.reduce(function (a, r) { return a + (r.encodedBodySize || 0); }, 0),
			blocking: res.filter(function (r) { return r.renderBlockingStatus === 'blocking'; }).length,
			forms: document.forms.length,
			text: document.body ? document.body.innerText.length : 0,
			height: document.documentElement.scrollHeight,
			broken: images.length,
			stuck_css: document.querySelectorAll('link[rel=stylesheet][media=print][onload]').length,
			pending_js: document.querySelectorAll('script[data-ssc-delay-src]').length
		} }, location.origin);
	}
	function finish() {
		// Wake what waits for a visitor, so anything it breaks shows up too. LCP is final by now.
		setTimeout(function () {
			var frozen = lcp;
			window.dispatchEvent(new Event('scroll'));
			window.dispatchEvent(new PointerEvent('pointermove'));
			setTimeout(function () { lcp = frozen; report(); }, 2000);
		}, 2500);
	}
	if (document.readyState === 'complete') { finish(); } else { addEventListener('load', finish); }
	setTimeout(report, 25000);
}());
