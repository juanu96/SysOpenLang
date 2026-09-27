document.addEventListener('DOMContentLoaded', function () {
	document.addEventListener('click', function (event) {
		var link = event.target.closest('[data-openlingua-confirm]');
		if (link && !window.confirm(link.getAttribute('data-openlingua-confirm'))) {
			event.preventDefault();
		}
	});

	var fields = Array.prototype.slice.call(document.querySelectorAll('[data-openlingua-translation]'));
	var progress = document.querySelector('[data-openlingua-progress]');
	var bar = document.querySelector('[data-openlingua-progress-bar]');
	var search = document.querySelector('.openlingua-editor__search input');
	var richSegments = Array.prototype.slice.call(document.querySelectorAll('[data-openlingua-rich-segment]'));
	var memory = window.SysOpenLangTranslationMemory || { fields: {}, label: '' };
	var memoryGroups = {};
	var activeFilter = 'all';
	var segments = Array.prototype.slice.call(document.querySelectorAll('[data-openlingua-segment]'));
	var filters = memory.filters || { all: 'All fields', untranslated: 'Needs translation', translated: 'Translated', visible: 'Visible fields' };

	function richVisual(field) {
		var segment = field.closest('[data-openlingua-rich-segment]');
		return segment ? segment.querySelector('[data-openlingua-target-visual]') : null;
	}

	function setFieldValue(field, value, showBadge) {
		field.value = value;
		var visual = richVisual(field);
		if (visual) { visual.innerHTML = value; }
		if (showBadge) {
			var target = field.closest('.openlingua-editor__target');
			if (target && !target.querySelector('.openlingua-memory-badge')) {
				var badge = document.createElement('span');
				badge.className = 'openlingua-memory-badge';
				badge.textContent = memory.label;
				target.appendChild(badge);
			}
		}
	}

	fields.forEach(function (field) {
		var config = memory.fields[field.id];
		if (!config) { return; }
		memoryGroups[config.key] = memoryGroups[config.key] || [];
		memoryGroups[config.key].push(field);
		if (config.applied) { setFieldValue(field, field.value, true); }
	});
	Object.keys(memoryGroups).forEach(function (key) {
		var translated = memoryGroups[key].find(function (field) {
			var config = memory.fields[field.id];
			return field.value.trim() !== '' && config && !config.replaceable;
		});
		if (!translated) { return; }
		memoryGroups[key].forEach(function (field) {
			var config = memory.fields[field.id];
			if (config && config.replaceable) { setFieldValue(field, translated.value, true); config.replaceable = false; }
		});
	});

	function propagateTranslation(field) {
		var config = memory.fields[field.id];
		if (!config || field.value.trim() === '') { return; }
		(memoryGroups[config.key] || []).forEach(function (peer) {
			var peerConfig = memory.fields[peer.id];
			if (peer !== field && peerConfig && peerConfig.replaceable) { setFieldValue(peer, field.value, true); peerConfig.replaceable = false; }
		});
		config.replaceable = false;
		updateProgress();
	}

	function updateProgress() {
		var complete = fields.filter(function (field) { return field.value.trim() !== ''; }).length;
		var percent = fields.length ? Math.round((complete / fields.length) * 100) : 0;
		progress.textContent = percent + '%';
		bar.style.width = percent + '%';
		applyFilters();
	}

	function segmentIsTranslated(segment) {
		var field = segment.querySelector('[data-openlingua-translation]');
		return !!field && field.value.trim() !== '';
	}

	function applyFilters() {
		var query = search ? search.value.toLowerCase().trim() : '';
		var visible = 0;
		segments.forEach(function (segment) {
			var translated = segmentIsTranslated(segment);
			var matchesSearch = query === '' || segment.textContent.toLowerCase().indexOf(query) !== -1;
			var matchesStatus = activeFilter === 'all' || (activeFilter === 'translated' && translated) || (activeFilter === 'untranslated' && !translated);
			segment.hidden = !(matchesSearch && matchesStatus);
			if (!segment.hidden) { visible++; }
		});
		var count = document.querySelector('[data-openlingua-visible-count]');
		if (count) { count.textContent = filters.visible + ': ' + visible + '/' + segments.length; }
	}

	function addFilterToolbar() {
		var main = document.querySelector('.openlingua-editor__segments');
		if (!main || !segments.length) { return; }
		var toolbar = document.createElement('div');
		toolbar.className = 'openlingua-editor__filters';
		toolbar.setAttribute('role', 'group');
		['all', 'untranslated', 'translated'].forEach(function (status) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'button' + (status === 'all' ? ' is-active' : '');
			button.textContent = filters[status];
			button.setAttribute('aria-pressed', status === 'all' ? 'true' : 'false');
			button.addEventListener('click', function () {
				activeFilter = status;
				toolbar.querySelectorAll('button').forEach(function (item) {
					var selected = item === button;
					item.classList.toggle('is-active', selected);
					item.setAttribute('aria-pressed', selected ? 'true' : 'false');
				});
				applyFilters();
			});
			toolbar.appendChild(button);
		});
		var count = document.createElement('span');
		count.dataset.openlinguaVisibleCount = '';
		toolbar.appendChild(count);
		main.parentNode.insertBefore(toolbar, main);
	}

	fields.forEach(function (field) {
		field.addEventListener('input', updateProgress);
		field.addEventListener('change', function () { propagateTranslation(field); });
	});
	richSegments.forEach(function (segment) {
		var toggle = segment.querySelector('[data-openlingua-html-toggle]');
		var toggleLabel = segment.querySelector('[data-openlingua-toggle-label]');
		var sourceVisual = segment.querySelector('[data-openlingua-source-visual]');
		var sourceCode = segment.querySelector('[data-openlingua-source-code]');
		var targetVisual = segment.querySelector('[data-openlingua-target-visual]');
		var targetCode = segment.querySelector('[data-openlingua-target-code]');

		function syncVisualToCode() {
			targetCode.value = targetVisual.innerHTML;
			updateProgress();
		}

		targetVisual.addEventListener('input', syncVisualToCode);
		targetVisual.addEventListener('blur', function () { syncVisualToCode(); propagateTranslation(targetCode); });
		targetVisual.addEventListener('paste', function (event) {
			var clipboard = event.clipboardData || window.clipboardData;
			if (!clipboard) { return; }
			event.preventDefault();
			var text = clipboard.getData('text/plain').replace(/\u00a0/g, ' ').replace(/\s+$/, '');
			document.execCommand('insertText', false, text);
			syncVisualToCode();
		});
		targetCode.addEventListener('input', function () {
			targetVisual.innerHTML = targetCode.value;
		});
		toggle.addEventListener('click', function () {
			var showCode = !segment.classList.contains('is-code-mode');
			if (showCode) { syncVisualToCode(); } else { targetVisual.innerHTML = targetCode.value; }
			segment.classList.toggle('is-code-mode', showCode);
			sourceVisual.hidden = showCode;
			sourceCode.hidden = !showCode;
			targetVisual.hidden = showCode;
			targetCode.hidden = !showCode;
			toggleLabel.textContent = showCode ? toggle.getAttribute('data-hide-html') : toggle.getAttribute('data-show-html');
			toggle.setAttribute('aria-pressed', showCode ? 'true' : 'false');
		});
	});

	var form = document.querySelector('.openlingua-editor form');
	if (form) {
		form.addEventListener('submit', function () {
			richSegments.forEach(function (segment) {
				if (!segment.classList.contains('is-code-mode')) {
					segment.querySelector('[data-openlingua-target-code]').value = segment.querySelector('[data-openlingua-target-visual]').innerHTML;
				}
			});
		});
	}
	if (search) {
		search.addEventListener('input', applyFilters);
	}
	addFilterToolbar();
	updateProgress();
});
