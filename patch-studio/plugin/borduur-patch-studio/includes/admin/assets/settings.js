/**
 * Patch Studio – admin settings helpers (vanilla, no dependencies).
 * - live "incl. btw" hint next to money fields
 * - validation of numeric fields (decimal comma allowed)
 * - unsaved-changes warning, reset confirmation
 * - thread search / all / none, add colour rows, copy shortcode
 */
(function () {
	'use strict';

	var root = document.querySelector('.bps-admin');
	if (!root) {
		return;
	}
	var vat = parseFloat(root.getAttribute('data-bps-vat') || '0.21');
	var form = root.querySelector('[data-bps-form]');
	var i18n = {};
	try {
		i18n = JSON.parse(root.getAttribute('data-bps-i18n') || '{}');
	} catch (err) {
		i18n = {};
	}
	function t(key, fallback) {
		return typeof i18n[key] === 'string' ? i18n[key] : fallback;
	}

	function parseNum(value) {
		var s = String(value || '').replace(/[€%\s ]/g, '');
		if (/^-?\d{1,3}(\.\d{3})+,\d+$/.test(s)) {
			s = s.replace(/\./g, '');
		}
		s = s.replace(',', '.');
		if (s === '' || isNaN(Number(s))) {
			return null;
		}
		return Number(s);
	}

	function fmtMoney(n) {
		var fixed = (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
		return '€ ' + parts.join(',');
	}

	/* Money: show the incl. VAT amount the customer sees. */
	function updateIncl(input) {
		var field = input.closest('.bps-field');
		var out = field && field.querySelector('[data-bps-incl]');
		if (!out) {
			return;
		}
		var n = parseNum(input.value);
		out.textContent = n === null || n === 0 ? '' : t('incl', 'klant betaalt %s incl. btw').replace('%s', fmtMoney(n * (1 + vat)));
	}

	/* Numbers: soft validation against data-min / data-max. */
	function validate(input) {
		if (input.getAttribute('inputmode') !== 'decimal') {
			return true;
		}
		var v = input.value.trim();
		var n = parseNum(v);
		var min = input.hasAttribute('data-min') ? Number(input.getAttribute('data-min')) : null;
		var max = input.hasAttribute('data-max') ? Number(input.getAttribute('data-max')) : null;
		var ok = v === '' || (n !== null && (min === null || n >= min) && (max === null || n <= max));
		input.classList.toggle('is-invalid', !ok);
		input.setAttribute('aria-invalid', ok ? 'false' : 'true');
		return ok;
	}

	root.querySelectorAll('input[data-bps-money]').forEach(function (input) {
		updateIncl(input);
	});

	/* Dirty state. */
	var dirty = false;
	var dirtyBadge = root.querySelector('[data-bps-dirty]');
	function markDirty() {
		if (!dirty) {
			dirty = true;
			if (dirtyBadge) {
				dirtyBadge.hidden = false;
			}
		}
	}

	if (form) {
		form.addEventListener('input', function (e) {
			var t = e.target;
			if (t.matches('input[data-bps-money]')) {
				updateIncl(t);
			}
			if (t.matches('input[inputmode="decimal"]')) {
				validate(t);
			}
			if (t.id !== 'bps-thread-filter') {
				markDirty();
			}
		});
		form.addEventListener('change', function (e) {
			if (e.target.id !== 'bps-thread-filter') {
				markDirty();
			}
			if (e.target.matches('#bps-thread-grid input')) {
				updateThreadCount();
			}
		});
		form.addEventListener('submit', function (e) {
			var submitter = e.submitter || document.activeElement;
			if (submitter && submitter.hasAttribute('data-bps-confirm')) {
				if (!window.confirm(submitter.getAttribute('data-bps-confirm'))) {
					e.preventDefault();
					return;
				}
			} else {
				var bad = Array.prototype.filter.call(form.querySelectorAll('input[inputmode="decimal"]'), function (i) {
					return !validate(i);
				});
				if (bad.length) {
					e.preventDefault();
					bad[0].focus();
					return;
				}
			}
			dirty = false;
		});
	}
	window.addEventListener('beforeunload', function (e) {
		if (dirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	/* Threads: filter, all/none, live counter. */
	var counter = root.querySelector('[data-bps-thread-count]');
	function updateThreadCount() {
		if (!counter) {
			return;
		}
		var boxes = root.querySelectorAll('#bps-thread-grid input[type="checkbox"]');
		var on = 0;
		boxes.forEach(function (b) {
			if (b.checked && !b.disabled) {
				on++;
			}
		});
		counter.textContent = t('count', '%1$d van %2$d beschikbaar').replace('%1$d', on).replace('%2$d', boxes.length);
	}
	root.querySelectorAll('[data-bps-filter]').forEach(function (input) {
		var grid = root.querySelector(input.getAttribute('data-bps-filter'));
		input.addEventListener('input', function () {
			var q = input.value.trim().toLowerCase();
			grid.querySelectorAll('[data-bps-search]').forEach(function (li) {
				li.hidden = q !== '' && li.getAttribute('data-bps-search').indexOf(q) === -1;
			});
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
			}
		});
	});
	function setAll(selector, value) {
		var grid = root.querySelector(selector);
		if (!grid) {
			return;
		}
		grid.querySelectorAll('li:not([hidden]) input[type="checkbox"]:not(:disabled)').forEach(function (b) {
			b.checked = value;
		});
		updateThreadCount();
		markDirty();
	}
	root.querySelectorAll('[data-bps-check-all]').forEach(function (b) {
		b.addEventListener('click', function () {
			setAll(b.getAttribute('data-bps-check-all'), true);
		});
	});
	root.querySelectorAll('[data-bps-check-none]').forEach(function (b) {
		b.addEventListener('click', function () {
			setAll(b.getAttribute('data-bps-check-none'), false);
		});
	});

	/* Add a colour row: clone the empty row with the next index. */
	root.querySelectorAll('[data-bps-add-row]').forEach(function (button) {
		button.addEventListener('click', function () {
			var section = root.querySelector(button.getAttribute('data-bps-add-row')) || button.closest('section');
			var tbody = section && section.querySelector('tbody');
			var rows = tbody ? tbody.querySelectorAll('.bps-new-row') : [];
			if (!rows.length) {
				return;
			}
			var template = rows[rows.length - 1];
			var next = tbody.querySelectorAll('tr').length;
			var clone = template.cloneNode(true);
			clone.querySelectorAll('[name]').forEach(function (el) {
				el.name = el.name.replace(/^bps\[(\w+)\]\[\d+\]/, 'bps[$1][' + next + ']');
				if (el.type === 'text') {
					el.value = '';
				}
			});
			clone.querySelectorAll('[id]').forEach(function (el) {
				var old = el.id;
				el.id = old.replace(/-\d+-/, '-' + next + '-');
				var label = clone.querySelector('label[for="' + old + '"]');
				if (label) {
					label.setAttribute('for', el.id);
				}
			});
			tbody.appendChild(clone);
			var first = clone.querySelector('input[type="text"]');
			if (first) {
				first.focus();
			}
		});
	});

	/* Copy shortcode. */
	root.querySelectorAll('[data-bps-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			var src = button.parentNode.querySelector('[data-bps-copy-src]');
			var text = src ? src.textContent : '';
			var done = function () {
				var old = button.textContent;
				button.textContent = t('copied', 'Gekopieerd');
				setTimeout(function () {
					button.textContent = old;
				}, 1600);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, function () {});
			} else {
				var range = document.createRange();
				range.selectNodeContents(src);
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange(range);
				try {
					document.execCommand('copy');
					done();
				} catch (err) {
					/* selection stays so the owner can copy manually */
				}
			}
		});
	});
})();
