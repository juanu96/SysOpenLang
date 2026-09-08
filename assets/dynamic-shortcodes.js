(function () {
	'use strict';

	var config = window.OpenLinguaShortcodes;
	if (!config || !config.endpoint) return;

	var attributes = ['aria-label', 'alt', 'placeholder', 'title'];
	var cache = new Map();
	var inFlight = new Set();
	var appliedText = new WeakMap();
	var appliedAttributes = new WeakMap();
	var stored = loadStored();

	Object.keys(stored.values || {}).forEach(function (key) { cache.set(key, stored.values[key]); });
	Object.keys(config.preloaded || {}).forEach(function (key) { cache.set(key, config.preloaded[key]); });

	function normalized(value) {
		return String(value || '').replace(/\s+/g, ' ').trim();
	}

	function isText(value) {
		return value.length > 0 && value.length <= 1000 && /[A-Za-zÀ-ÖØ-öø-ÿ]/.test(value);
	}

	function loadStored() {
		try {
			var value = JSON.parse(window.localStorage.getItem(config.cacheKey) || '{}');
			return value.saved && Date.now() - value.saved < 600000 ? value : { values: {} };
		} catch (error) { return { values: {} }; }
	}

	function saveStored() {
		try {
			var values = {};
			cache.forEach(function (value, key) { values[key] = value; });
			window.localStorage.setItem(config.cacheKey, JSON.stringify({ saved: Date.now(), values: values }));
		} catch (error) {}
	}

	function collect(root) {
		var entries = [];
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
			acceptNode: function (node) {
				var parent = node.parentElement;
				var text = normalized(node.nodeValue);
				if (!parent || parent.closest('script,style,code,pre,noscript,svg') || appliedText.get(node) === text) return NodeFilter.FILTER_REJECT;
				return isText(text) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
			}
		});
		var node;
		while ((node = walker.nextNode())) entries.push({ target: node, kind: 'element-text', text: normalized(node.nodeValue), attribute: '' });
		root.querySelectorAll('*').forEach(function (element) {
			attributes.forEach(function (attribute) {
				var value = normalized(element.getAttribute(attribute));
				var applied = appliedAttributes.get(element) || {};
				if (isText(value) && applied[attribute] !== value) entries.push({ target: element, kind: 'attribute-' + attribute, text: value, attribute: attribute });
			});
		});
		return entries;
	}

	function apply(entry, value) {
		if (!value || value === entry.text) return;
		if (entry.attribute) {
			entry.target.setAttribute(entry.attribute, value);
			var values = appliedAttributes.get(entry.target) || {};
			values[entry.attribute] = normalized(value);
			appliedAttributes.set(entry.target, values);
		} else {
			entry.target.nodeValue = entry.target.nodeValue.replace(entry.text, value);
			appliedText.set(entry.target, normalized(entry.target.nodeValue));
		}
	}

	function requestBatch(shortcode, items) {
		items.forEach(function (item) { inFlight.add(item.key); });
		return fetch(config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: Object.assign({ 'Content-Type': 'application/json' }, config.nonce ? { 'X-WP-Nonce': config.nonce } : {}),
			body: JSON.stringify({ shortcode: shortcode, language: config.language, entries: items.map(function (item) { return { kind: item.entry.kind, text: item.entry.text }; }) })
		}).then(function (response) { return response.ok ? response.json() : Promise.reject(); }).then(function (data) {
			(data.translations || []).forEach(function (translation, index) {
				var item = items[index];
				if (!item) return;
				cache.set(item.key, translation);
				apply(item.entry, translation);
			});
		}).finally(function () { items.forEach(function (item) { inFlight.delete(item.key); }); });
	}

	function scan(root) {
		var shortcode = root.getAttribute('data-openlingua-shortcode');
		if (!shortcode) return Promise.resolve();
		var pending = [];
		collect(root).forEach(function (entry) {
			var key = shortcode + '|' + entry.kind + '|' + entry.text;
			if (cache.has(key)) apply(entry, cache.get(key));
			else if (!inFlight.has(key)) pending.push({ entry: entry, key: key });
		});
		if (!pending.length) return Promise.resolve();
		var requests = [];
		for (var index = 0; index < pending.length; index += 50) requests.push(requestBatch(shortcode, pending.slice(index, index + 50)));
		return Promise.all(requests).then(saveStored).catch(function () {});
	}

	function watch(root) {
		scan(root);
		new MutationObserver(function () { scan(root); }).observe(root, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: attributes });
	}

	function start() {
		document.querySelectorAll('[data-openlingua-shortcode]').forEach(watch);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
	else start();
}());
