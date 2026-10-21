/*! amplifi AOS — subtle staggered reveal on scroll. Vanilla, no dependencies. */
(function (w, d) {
	'use strict';

	var cfg = w.amplifiAos || {};
	var api = w.amplifiAos = cfg;
	api.ready = false;

	if (!('IntersectionObserver' in w) || !w.requestAnimationFrame || !d.querySelectorAll) { return; }
	var reduce = w.matchMedia ? w.matchMedia('(prefers-reduced-motion: reduce)') : null;
	if (reduce && reduce.matches) { api.reduced = true; return; }

	function num(v, def) { v = parseFloat(v); return isFinite(v) ? v : def; }
	var small = w.matchMedia && w.matchMedia('(max-width: 767px)').matches;
	var DIST = num(small ? cfg.distanceMobile : cfg.distance, small ? 14 : 20);
	var DUR = num(cfg.duration, 800);
	var STAG = num(cfg.stagger, 90);
	var MAXD = num(cfg.maxDelay, 450);
	var EASE = (typeof cfg.easing === 'string' && /^[a-z0-9.,()\s-]+$/i.test(cfg.easing)) ? cfg.easing : 'cubic-bezier(0.16, 1, 0.3, 1)';
	var RATIO = 0.12;

	var items = [], queue = [], raf = 0, nextSlot = 0, io = null, scrollTimer = 0;
	var now = (w.performance && w.performance.now) ? function () { return w.performance.now(); } : function () { return Date.now(); };

	function variant(v) {
		v = String(v || '').toLowerCase();
		if (v.indexOf('zoom') !== -1) { return 'zoom'; }
		if (v === 'fade') { return 'fade'; }
		return 'up';
	}

	function hiddenTransform(v, base) {
		var t = v === 'fade' ? '' : (v === 'zoom' ? 'scale(0.98)' : 'translate3d(0,' + DIST + 'px,0)');
		if (!t) { return ''; }
		return (base && base !== 'none') ? t + ' ' + base : t;
	}

	function collect() {
		var found = [], els = [];
		function add(el, v) {
			if (!el || el.nodeType !== 1 || els.indexOf(el) !== -1) { return; }
			els.push(el);
			found.push({ el: el, v: v });
		}
		var i, j, nodes = d.querySelectorAll('[data-aos]');
		for (i = 0; i < nodes.length; i++) { add(nodes[i], ''); }
		var rules = cfg.rules || [];
		for (i = 0; i < rules.length; i++) {
			var r = rules[i], s = typeof r === 'string' ? r : (r && r.s);
			if (!s) { continue; }
			try { nodes = d.querySelectorAll(s); } catch (e) { continue; } // invalid selector: skip it
			for (j = 0; j < nodes.length; j++) { add(nodes[j], (r && r.v) || ''); }
		}
		// data-aos wins over a rule's variant; data-aos="none" opts an element out.
		found = found.filter(function (it) {
			var a = it.el.getAttribute('data-aos');
			if (a === 'none' || a === 'false') { return false; }
			it.v = variant(a || it.v);
			return true;
		});
		// A target inside another target would double-animate: the outer one wins.
		var kept = found.map(function (it) { return it.el; });
		return found.filter(function (it) {
			for (var p = it.el.parentElement; p; p = p.parentElement) {
				if (kept.indexOf(p) !== -1) { return false; }
			}
			return true;
		});
	}

	var PROPS = ['opacity', 'transform', 'transition'];
	function saveStyle(el) {
		var s = {};
		PROPS.forEach(function (p) { s[p] = [el.style.getPropertyValue(p), el.style.getPropertyPriority(p)]; });
		return s;
	}
	function restoreProp(it, p) {
		var v = it.saved[p];
		if (v[0]) { it.el.style.setProperty(p, v[0], v[1]); } else { it.el.style.removeProperty(p); }
	}

	function reveal(it, delay, instant) {
		if (it.state !== 1) { return; }
		var el = it.el;
		if (io) { io.unobserve(el); }
		if (instant) {
			// transition is still "none": restore the end state, commit it, then hand transitions back.
			restoreProp(it, 'opacity');
			restoreProp(it, 'transform');
			void el.offsetWidth;
			restoreProp(it, 'transition');
			it.state = 3;
			return;
		}
		it.state = 2;
		var tr = DUR + 'ms ' + EASE + ' ' + delay + 'ms';
		el.style.setProperty('transition', 'opacity ' + tr + ', transform ' + tr, 'important');
		restoreProp(it, 'opacity');
		restoreProp(it, 'transform');
		var timer = 0;
		function done(e) {
			if (e && (e.target !== el || e.propertyName !== 'opacity')) { return; }
			if (it.state !== 2) { return; }
			el.removeEventListener('transitionend', done);
			clearTimeout(timer);
			restoreProp(it, 'transition'); // the element's own CSS transitions work normally again
			it.state = 3;
		}
		el.addEventListener('transitionend', done);
		timer = setTimeout(done, delay + DUR + 150);
	}

	function enqueue(it) {
		if (it.state !== 1 || it.queued) { return; }
		it.queued = true;
		queue.push(it);
		if (!raf) { raf = w.requestAnimationFrame(flush); }
	}

	// Everything that became visible in the same frame is one batch: order it by on-screen
	// position (row, then left) and stagger it, never letting the batch run past MAXD.
	function flush() {
		raf = 0;
		var batch = queue.splice(0, queue.length);
		if (!batch.length) { return; }
		var t = now();
		batch.forEach(function (it) {
			var r = it.el.getBoundingClientRect();
			it.top = r.top;
			it.left = r.left;
		});
		// Reading order: tops within 12px of each other form one row, then left to right.
		batch.sort(function (a, b) { return a.top - b.top; });
		var row = 0, rowTop = batch[0].top;
		batch.forEach(function (it) {
			if (it.top - rowTop > 12) { row++; rowTop = it.top; }
			it.row = row;
		});
		batch.sort(function (a, b) { return (a.row - b.row) || (a.left - b.left); });
		var base = Math.max(0, Math.min(nextSlot - t, MAXD));
		var n = batch.length;
		var step = n > 1 ? Math.min(STAG, Math.max(0, MAXD - base) / (n - 1)) : 0;
		var last = 0;
		batch.forEach(function (it, i) {
			last = Math.round(base + i * step);
			reveal(it, last, false);
		});
		nextSlot = t + last + STAG;
	}

	function onIntersect(entries) {
		entries.forEach(function (en) {
			var it = en.target._amplifiAos;
			if (!it || it.state !== 1) { return; }
			if (en.isIntersecting && en.intersectionRatio >= RATIO - 0.005) {
				enqueue(it);
			} else if (!en.isIntersecting && en.boundingClientRect.bottom <= 0) {
				reveal(it, 0, true); // scrolled past it: just show it
			}
		});
	}

	// When scrolling settles: show anything skipped past above the viewport, and reveal anything
	// sitting in view that can never reach the threshold (very end of the page).
	function onScrollSettle() {
		var vh = w.innerHeight;
		items.forEach(function (it) {
			if (it.state !== 1) { return; }
			var r = it.el.getBoundingClientRect();
			if (r.bottom <= 0) { reveal(it, 0, true); } else if (r.top < vh * 0.92) { enqueue(it); }
		});
	}
	function onScroll() {
		clearTimeout(scrollTimer);
		scrollTimer = setTimeout(onScrollSettle, 150);
	}

	function revealAll() {
		items.forEach(function (it) { reveal(it, 0, true); });
	}

	// Keyboard users must never land on something invisible.
	function onFocus(e) {
		for (var n = e.target; n && n !== d; n = n.parentNode) {
			if (n._amplifiAos) { reveal(n._amplifiAos, 0, true); return; }
		}
	}

	function init() {
		if (api.ready) { return; }
		api.ready = true;
		var vh = w.innerHeight || d.documentElement.clientHeight;
		var found = collect();
		var hide = [];
		// Reads first.
		found.forEach(function (it) {
			var r = it.el.getBoundingClientRect();
			if (!r.width && !r.height) { return; }   // not rendered
			if (r.height > vh * 1.2) { return; }     // taller than the screen: leave it alone
			var below = r.top >= vh;
			var inView = r.top < vh && r.bottom > 0;
			if (!below && !(cfg.animateOnLoad && inView)) { return; } // never hide what is already on screen
			it.base = w.getComputedStyle(it.el).transform;
			hide.push(it);
		});
		// Then writes.
		hide.forEach(function (it) {
			var el = it.el;
			it.saved = saveStyle(el);
			el.style.setProperty('transition', 'none', 'important');
			el.style.setProperty('opacity', '0', 'important');
			var t = hiddenTransform(it.v, it.base);
			if (t) { el.style.setProperty('transform', t, 'important'); }
			it.state = 1;
			el._amplifiAos = it;
			items.push(it);
		});
		api.targets = found.length;
		if (!items.length) { return; }

		io = new IntersectionObserver(onIntersect, { threshold: [0, RATIO], rootMargin: '0px 0px -8% 0px' });
		items.forEach(function (it) { io.observe(it.el); });
		w.addEventListener('scroll', onScroll, { passive: true });
		if (cfg.animateOnLoad) { setTimeout(onScrollSettle, 300); }
		w.addEventListener('beforeprint', revealAll);
		d.addEventListener('focusin', onFocus, true);
		if (reduce) {
			var onReduce = function (e) { if (e.matches) { revealAll(); } };
			if (reduce.addEventListener) { reduce.addEventListener('change', onReduce); } else if (reduce.addListener) { reduce.addListener(onReduce); }
		}
	}

	api.state = function () {
		var s = { targets: api.targets || 0, managed: items.length, hidden: 0, animating: 0, done: 0 };
		items.forEach(function (it) { if (it.state === 1) { s.hidden++; } else if (it.state === 2) { s.animating++; } else { s.done++; } });
		return s;
	};
	api.revealAll = revealAll;

	// Back/forward navigation and #anchor links restore the scroll position after load; start
	// after that so content at the restored position is never hidden.
	var nav = (w.performance && w.performance.getEntriesByType) ? w.performance.getEntriesByType('navigation')[0] : null;
	var late = (nav && nav.type === 'back_forward') || (w.location.hash && w.location.hash.length > 1);
	if (late && d.readyState !== 'complete') {
		w.addEventListener('load', function () { w.requestAnimationFrame(function () { setTimeout(init, 0); }); });
	} else if (d.readyState === 'loading') {
		d.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}(window, document));
