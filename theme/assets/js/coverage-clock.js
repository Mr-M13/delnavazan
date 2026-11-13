/**
 * Delnavazan 24-hour instrument coverage clock.
 *
 * Progressive enhancement only: the server renders the summary, legend and a
 * compact JSON payload. This file turns the payload into an accessible SVG clock
 * with roving keyboard focus, segment details and (in the Teacher portal) an
 * unsaved availability preview.
 */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var SIZE = 440;
	var CENTER = SIZE / 2;
	var SEGMENTS = 96;
	var SLOT_MINUTES = 15;
	var DEGREES = 360 / SEGMENTS;
	var STATE = { UNCOVERED: 0, LIMITED: 1, COVERED: 2, UNKNOWN: 3 };
	var REASON = { NONE: 0, NO_AVAILABILITY: 1, FULLY_BOOKED: 2, NONEXISTENT: 3, UNKNOWN: 4 };

	function el(name, attributes, children) {
		var node = document.createElementNS(NS, name);
		if (attributes) Object.keys(attributes).forEach(function (key) {
			if (attributes[key] !== null && attributes[key] !== undefined) node.setAttribute(key, String(attributes[key]));
		});
		(children || []).forEach(function (child) {
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function polar(radius, angle) {
		var radians = (angle - 90) * Math.PI / 180;
		return [CENTER + radius * Math.cos(radians), CENTER + radius * Math.sin(radians)];
	}

	function arcPath(outer, inner, startAngle, endAngle) {
		var outerStart = polar(outer, startAngle);
		var outerEnd = polar(outer, endAngle);
		var innerEnd = polar(inner, endAngle);
		var innerStart = polar(inner, startAngle);
		var large = endAngle - startAngle > 180 ? 1 : 0;
		return 'M' + outerStart[0].toFixed(2) + ' ' + outerStart[1].toFixed(2) +
			'A' + outer + ' ' + outer + ' 0 ' + large + ' 1 ' + outerEnd[0].toFixed(2) + ' ' + outerEnd[1].toFixed(2) +
			'L' + innerEnd[0].toFixed(2) + ' ' + innerEnd[1].toFixed(2) +
			'A' + inner + ' ' + inner + ' 0 ' + large + ' 0 ' + innerStart[0].toFixed(2) + ' ' + innerStart[1].toFixed(2) + 'Z';
	}

	function minuteLabel(minute) {
		if (minute >= 1440) return '24:00';
		var hours = Math.floor(minute / 60);
		var minutes = minute % 60;
		return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
	}

	function parseTime(value) {
		if (!value || !/^\d{2}:\d{2}/.test(value)) return null;
		var parts = value.slice(0, 5).split(':');
		return Number(parts[0]) * 60 + Number(parts[1]);
	}

	function dateInZone(zone) {
		try {
			var formatter = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' });
			return formatter.format(new Date());
		} catch (error) {
			var now = new Date();
			return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
		}
	}

	function localNowMinutes(zone) {
		try {
			var formatter = new Intl.DateTimeFormat('en-GB', { timeZone: zone, hour: '2-digit', minute: '2-digit', hour12: false });
			return parseTime(formatter.format(new Date()));
		} catch (error) {
			var now = new Date();
			return now.getHours() * 60 + now.getMinutes();
		}
	}

	function stateKey(code) {
		return code === STATE.COVERED ? 'covered' : code === STATE.LIMITED ? 'limited' : code === STATE.UNKNOWN ? 'unknown' : 'uncovered';
	}

	function reasonKey(code) {
		return code === REASON.FULLY_BOOKED ? 'booked' : code === REASON.NONEXISTENT ? 'nonExistent' : code === REASON.UNKNOWN ? 'unknown' : code === REASON.NO_AVAILABILITY ? 'noAvailability' : '';
	}

	function teacherLabel(count, labels) {
		return count + ' ' + (count === 1 ? labels.teacher : labels.teachers);
	}

	function segmentLabel(segment, clock, labels, index) {
		var state = stateKey(segment[0]);
		var count = segment[1];
		var golden = !!segment[3];
		var past = !!segment[4];
		var reason = reasonKey(segment[5]);
		var start = minuteLabel(index * SLOT_MINUTES);
		var end = minuteLabel(index * SLOT_MINUTES + SLOT_MINUTES);
		var parts = [start + '–' + end, labels[state] || state];
		if (state === 'covered' || state === 'limited') parts.push(teacherLabel(count, labels));
		if (reason && labels[reason]) parts.push(labels[reason]);
		if (golden) parts.push(labels.golden);
		if (past) parts.push(labels.past);
		return parts.join(', ');
	}

	function summaryText(clock) {
		var summary = clock.summary || {};
		return summary;
	}

	function render(root) {
		var dataNode = root.querySelector('[data-dzn-clock-data]');
		if (!dataNode) return;
		var data;
		try { data = JSON.parse(dataNode.textContent || '{}'); } catch (error) { return; }
		if (!data || !data.instruments || !data.instruments.length) return;
		var labels = data.labels || {};
		var canvas = root.querySelector('[data-dzn-clock-canvas]');
		var details = root.querySelector('[data-dzn-clock-details]');
		if (!canvas) return;

		var state = {
			instrumentId: Number(data.selected_instrument || data.instruments[0].id),
			weekday: Number(data.weekday || 1),
			selectedIndex: null,
			proposed: null
		};

		function instrument() {
			var found = data.instruments[0];
			data.instruments.forEach(function (item) { if (Number(item.id) === state.instrumentId) found = item; });
			return found;
		}

		function clock() {
			var current = instrument();
			var clocks = current.clocks || {};
			return clocks[String(state.weekday)] || clocks[Object.keys(clocks)[0]] || { segments: [], summary: {} };
		}

		function updateMetrics() {
			var current = instrument();
			var summary = summaryText(clock());
			var values = {
				coverage: summary.coverage_percent === null || summary.coverage_percent === undefined ? '—' : summary.coverage_percent + '%',
				golden: summary.golden_coverage_percent === null || summary.golden_coverage_percent === undefined ? (labels.noWindow || 'No window') : summary.golden_coverage_percent + '%',
				uncovered: duration(summary.uncovered_minutes),
				single: duration(summary.single_teacher_minutes),
				teachers: String(current.eligible_teacher_count || 0),
				'golden-gap': duration(summary.golden_uncovered_minutes)
			};
			Object.keys(values).forEach(function (key) {
				var target = root.querySelector('[data-dzn-metric="' + key + '"] strong');
				if (target) target.textContent = values[key];
			});
			var period = root.querySelector('[data-dzn-clock-period-label]');
			if (period) {
				var clockData = clock();
				period.textContent = data.calendar === 'persian' && clockData.date_label_fa ? clockData.date_label_fa : (clockData.date_label || '');
			}
			var sub = root.querySelector('[data-dzn-clock-period-sub]');
			if (sub) sub.textContent = (data.timezone_label || data.timezone || '');
			var meta = root.querySelector('[data-dzn-clock-assumption]');
			if (meta) meta.textContent = assumption(current);
			var currentName = root.querySelector('[data-dzn-clock-current] strong');
			if (currentName) currentName.textContent = current.name || '';
			root.querySelectorAll('[data-dzn-clock-instrument]').forEach(function (button) {
				var selected = Number(button.getAttribute('data-dzn-clock-instrument')) === state.instrumentId;
				button.classList.toggle('is-selected', selected);
				button.setAttribute('aria-pressed', selected ? 'true' : 'false');
			});
		}

		function assumption(current) {
			return String(labels.assumptionTemplate || '{lesson}-minute class + {buffer}-minute buffer')
				.replace('{lesson}', current.lesson_minutes || 0)
				.replace('{buffer}', current.buffer_minutes || 0);
		}

		function duration(minutes) {
			minutes = Math.max(0, Number(minutes) || 0);
			if (!minutes) return '0m';
			var hours = Math.floor(minutes / 60);
			var rest = minutes % 60;
			return (hours ? hours + 'h ' : '') + rest + 'm';
		}

		function segmentStatus(segment) {
			var stateCode = segment[0];
			var reason = reasonKey(segment[5]);
			if (stateCode === STATE.UNKNOWN) return labels.unknown || 'Unknown';
			if (stateCode === STATE.COVERED) return labels.covered || 'Covered';
			if (stateCode === STATE.LIMITED) return labels.limited || 'One teacher';
			if (reason && labels[reason]) return labels[reason];
			return labels.uncovered || 'No remaining capacity';
		}

		function showDetails(index) {
			state.selectedIndex = index;
			var clockData = clock();
			var segment = clockData.segments[index];
			if (!segment || !details) return;
			var start = index * SLOT_MINUTES;
			var end = start + SLOT_MINUTES;
			var stateName = stateKey(segment[0]);
			var reason = reasonKey(segment[5]);
			details.innerHTML = '';
			var heading = document.createElement('div');
			heading.className = 'dzn-clock__detail-head';
			var time = document.createElement('strong');
			time.textContent = minuteLabel(start) + '–' + minuteLabel(end);
			var badge = document.createElement('span');
			badge.className = 'dzn-clock__detail-state is-' + stateName;
			badge.textContent = segmentStatus(segment);
			heading.appendChild(time);
			heading.appendChild(badge);
			details.appendChild(heading);
			var list = document.createElement('dl');
			list.className = 'dzn-clock__detail-list';
			addDetail(list, labels.capacity || 'Remaining capacity', (segment[0] === STATE.COVERED || segment[0] === STATE.LIMITED) ? teacherLabel(segment[1], labels) : '0');
			if (segment[1] !== segment[2]) addDetail(list, labels.declared || 'Declared availability', teacherLabel(segment[2], labels));
			addDetail(list, labels.golden || 'Golden Hour', segment[3] ? (labels.yes || 'Yes') : (labels.no || 'No'));
			addDetail(list, labels.assumedLesson || 'Assumes', assumption(instrument()));
			if (reason && labels[reason]) addDetail(list, labels.reason || 'Note', labels[reason]);
			details.appendChild(list);
			if (segment[4]) {
				var past = document.createElement('p');
				past.className = 'dzn-clock__detail-note';
				past.textContent = labels.past || 'Already passed';
				details.appendChild(past);
			}
			var use = root.querySelector('[data-dzn-clock-use]');
			if (use) {
				use.disabled = segment[0] === STATE.UNKNOWN || !!segment[4];
				use.setAttribute('data-dzn-clock-use-index', String(index));
			}
			paintSelection();
		}

		function addDetail(list, term, description) {
			var dt = document.createElement('dt');
			dt.textContent = term;
			var dd = document.createElement('dd');
			dd.textContent = description;
			list.appendChild(dt);
			list.appendChild(dd);
		}

		function paintSelection() {
			var groups = canvas.querySelectorAll('[data-dzn-clock-segment]');
			groups.forEach(function (group) {
				var index = Number(group.getAttribute('data-dzn-clock-segment'));
				var selected = state.selectedIndex === index;
				group.classList.toggle('is-selected', selected);
				group.setAttribute('aria-pressed', selected ? 'true' : 'false');
				group.setAttribute('tabindex', selected || (state.selectedIndex === null && index === 0) ? '0' : '-1');
			});
			paintProposed();
		}

		function paintProposed() {
			if (!state.proposed) return;
			var start = state.proposed.start;
			var end = state.proposed.end;
			canvas.querySelectorAll('[data-dzn-clock-segment]').forEach(function (group) {
				var index = Number(group.getAttribute('data-dzn-clock-segment'));
				var minute = index * SLOT_MINUTES;
				group.classList.toggle('is-proposed', minute < end && minute + SLOT_MINUTES > start);
			});
		}

		function draw() {
			canvas.innerHTML = '';
			var current = instrument();
			var clockData = clock();
			var segments = clockData.segments || [];
			var svg = el('svg', {
				viewBox: '-20 -20 480 480',
				class: 'dzn-clock__svg',
				role: 'group',
				'aria-label': (current.name || 'Instrument') + ' ' + (labels.clock || '24-hour coverage clock')
			});
			var defs = el('defs');
			defs.appendChild(pattern('dzn-clock-limited-hatch', 4, 4, function (p) {
				p.appendChild(el('rect', { width: 4, height: 4, fill: 'transparent' }));
				p.appendChild(el('path', { d: 'M0 4L4 0', stroke: 'rgba(255,255,255,.72)', 'stroke-width': 1.2 }));
			}));
			defs.appendChild(pattern('dzn-clock-unknown-hatch', 5, 5, function (p) {
				p.appendChild(el('rect', { width: 5, height: 5, fill: 'transparent' }));
				p.appendChild(el('path', { d: 'M0 5L5 0', stroke: 'rgba(83,74,110,.35)', 'stroke-width': 1 }));
			}));
			defs.appendChild(pattern('dzn-clock-booked-dot', 6, 6, function (p) {
				p.appendChild(el('rect', { width: 6, height: 6, fill: 'transparent' }));
				p.appendChild(el('circle', { cx: 3, cy: 3, r: 1.4, fill: 'rgba(83,74,110,.65)' }));
			}));
			svg.appendChild(defs);

			// Golden designation ring: always visible, independent of capacity.
			for (var i = 0; i < SEGMENTS; i++) {
				var segment = segments[i] || [0, 0, 0, 0, 0, 0];
				var startAngle = i * DEGREES + 0.35;
				var endAngle = (i + 1) * DEGREES - 0.35;
				var goldenPath = el('path', {
					d: arcPath(207, 199, startAngle, endAngle),
					class: 'dzn-clock__golden' + (segment[3] ? ' is-golden' : '')
				});
				svg.appendChild(goldenPath);
			}

			var segmentGroups = [];
			for (var index = 0; index < SEGMENTS; index++) {
				var item = segments[index] || [0, 0, 0, 0, 0, 0];
				var stateName = stateKey(item[0]);
				var reason = reasonKey(item[5]);
				var classes = ['dzn-clock__segment', 'is-' + stateName];
				if (item[3]) classes.push('is-golden');
				if (item[4]) classes.push('is-past');
				if (item[0] === STATE.UNCOVERED && item[2] > 0 && reason === 'booked') classes.push('is-booked');
				if (index === state.selectedIndex) classes.push('is-selected');
				var group = el('g', {
					class: classes.join(' '),
					'data-dzn-clock-segment': index,
					role: 'button',
					tabindex: index === 0 ? '0' : '-1',
					'aria-pressed': index === state.selectedIndex ? 'true' : 'false',
					'aria-label': segmentLabel(item, clockData, labels, index)
				});
				group.appendChild(el('title', null, [segmentLabel(item, clockData, labels, index)]));
				var main = el('path', {
					d: arcPath(192, 150, index * DEGREES + 0.55, (index + 1) * DEGREES - 0.55),
					class: 'dzn-clock__segment-shape'
				});
				group.appendChild(main);
				if (item[0] === STATE.LIMITED) group.appendChild(el('path', { d: arcPath(192, 150, index * DEGREES + 0.55, (index + 1) * DEGREES - 0.55), class: 'dzn-clock__segment-hatch', fill: 'url(#dzn-clock-limited-hatch)' }));
				if (item[0] === STATE.UNKNOWN) group.appendChild(el('path', { d: arcPath(192, 150, index * DEGREES + 0.55, (index + 1) * DEGREES - 0.55), class: 'dzn-clock__segment-hatch', fill: 'url(#dzn-clock-unknown-hatch)' }));
				if (item[0] === STATE.UNCOVERED && item[2] > 0 && reason === 'booked') {
					var midpoint = index * DEGREES + DEGREES / 2;
					var dot = polar(171, midpoint);
					group.appendChild(el('circle', { cx: dot[0], cy: dot[1], r: 2.4, class: 'dzn-clock__booked-dot' }));
				}
				group.addEventListener('click', function (event) { event.preventDefault(); showDetails(Number(this.getAttribute('data-dzn-clock-segment'))); });
				group.addEventListener('keydown', function (event) {
					var currentIndex = Number(this.getAttribute('data-dzn-clock-segment'));
					var next = currentIndex;
					if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (currentIndex + 1) % SEGMENTS;
					else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (currentIndex + SEGMENTS - 1) % SEGMENTS;
					else if (event.key === 'Home') next = 0;
					else if (event.key === 'End') next = SEGMENTS - 1;
					else if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); showDetails(currentIndex); return; }
					else return;
					event.preventDefault();
					showDetails(next);
					var target = canvas.querySelector('[data-dzn-clock-segment="' + next + '"]');
					if (target) target.focus();
				});
				svg.appendChild(group);
				segmentGroups.push(group);
			}

			for (var hour = 0; hour < 24; hour++) {
				var hourAngle = hour * 15;
				var tickOuter = polar(214, hourAngle);
				var tickInner = polar(210, hourAngle);
				svg.appendChild(el('line', {
					x1: tickInner[0].toFixed(2), y1: tickInner[1].toFixed(2),
					x2: tickOuter[0].toFixed(2), y2: tickOuter[1].toFixed(2),
					class: 'dzn-clock__tick' + (hour % 3 === 0 ? ' is-major' : '')
				}));
			}
			[0, 3, 6, 9, 12, 15, 18, 21].forEach(function (hour) {
				var point = polar(226, hour * 15);
				svg.appendChild(el('text', {
					x: point[0].toFixed(2), y: point[1].toFixed(2),
					class: 'dzn-clock__hour-label',
					'text-anchor': 'middle',
					'dominant-baseline': 'middle'
				}, [String(hour).padStart(2, '0')]));
			});

			var today = dateInZone(data.timezone || 'UTC');
			if (clockData.date === today) {
				var nowMinute = localNowMinutes(data.timezone || 'UTC');
				if (nowMinute !== null) {
					var nowAngle = (nowMinute / SLOT_MINUTES) * DEGREES;
					var nowPoint = polar(145, nowAngle);
					svg.appendChild(el('line', {
						x1: CENTER, y1: CENTER, x2: nowPoint[0].toFixed(2), y2: nowPoint[1].toFixed(2),
						class: 'dzn-clock__now-hand'
					}));
					svg.appendChild(el('circle', { cx: nowPoint[0], cy: nowPoint[1], r: 4, class: 'dzn-clock__now-dot' }));
				}
			}

			var hub = el('g', { class: 'dzn-clock__hub' });
			hub.appendChild(el('circle', { cx: CENTER, cy: CENTER, r: 118, class: 'dzn-clock__hub-disc' }));
			var selected = state.selectedIndex === null ? null : clockData.segments[state.selectedIndex];
			var title = selected ? minuteLabel(state.selectedIndex * SLOT_MINUTES) + '–' + minuteLabel(state.selectedIndex * SLOT_MINUTES + SLOT_MINUTES) : (labels.clock || '24h');
			var subtitle = selected ? segmentStatus(selected) : (labels.select || 'Select a segment');
			var count = selected && (selected[0] === STATE.COVERED || selected[0] === STATE.LIMITED) ? teacherLabel(selected[1], labels) : '';
			hub.appendChild(el('text', { x: CENTER, y: CENTER - 14, class: 'dzn-clock__hub-time', 'text-anchor': 'middle' }, [title]));
			hub.appendChild(el('text', { x: CENTER, y: CENTER + 14, class: 'dzn-clock__hub-state', 'text-anchor': 'middle' }, [subtitle]));
			if (count) hub.appendChild(el('text', { x: CENTER, y: CENTER + 38, class: 'dzn-clock__hub-count', 'text-anchor': 'middle' }, [count]));
			svg.appendChild(hub);

			canvas.appendChild(svg);
			updateMetrics();
			paintProposed();
			if (state.selectedIndex !== null) {
				showDetails(state.selectedIndex);
			} else if (details) {
				details.innerHTML = '';
				var empty = document.createElement('p');
				empty.className = 'dzn-clock__details-empty';
				empty.textContent = labels.select || 'Select a segment to inspect remaining capacity.';
				details.appendChild(empty);
				var use = root.querySelector('[data-dzn-clock-use]');
				if (use) use.disabled = true;
			}
		}

		function pattern(id, width, height, builder) {
			var p = el('pattern', { id: id, width: width, height: height, patternUnits: 'userSpaceOnUse' });
			builder(p);
			return p;
		}

		root.querySelectorAll('[data-dzn-clock-instrument]').forEach(function (button) {
			button.addEventListener('click', function () {
				state.instrumentId = Number(button.getAttribute('data-dzn-clock-instrument'));
				state.selectedIndex = null;
				var select = root.querySelector('select[name="coverage_instrument"]');
				if (select) select.value = String(state.instrumentId);
				draw();
			});
		});

		var instrumentSelect = root.querySelector('select[name="coverage_instrument"]');
		if (instrumentSelect) {
			instrumentSelect.addEventListener('change', function () {
				state.instrumentId = Number(instrumentSelect.value) || state.instrumentId;
				state.selectedIndex = null;
				draw();
			});
		}

		var weekdaySelect = root.querySelector('[data-dzn-clock-weekday-select]');
		if (weekdaySelect && data.mode === 'date') weekdaySelect.disabled = true;
		if (weekdaySelect) {
			weekdaySelect.addEventListener('change', function () {
				state.weekday = Number(weekdaySelect.value) || 1;
				state.selectedIndex = null;
				draw();
			});
		}

		var form = document.querySelector('[data-dzn-availability-form]');
		if (form && root.hasAttribute('data-dzn-clock-teacher')) {
			var weekdayField = form.querySelector('[name="weekday"]');
			var startField = form.querySelector('[name="local_start_time"]');
			var endField = form.querySelector('[name="local_end_time"]');
			var useButton = root.querySelector('[data-dzn-clock-use]');
			var proposalInitialized = false;
			var updateProposal = function () {
				var weekday = weekdayField ? Number(weekdayField.value) : state.weekday;
				var start = startField ? parseTime(startField.value) : null;
				var end = endField ? parseTime(endField.value) : null;
				if (proposalInitialized && data.mode === 'weekly' && weekday !== state.weekday) {
					state.weekday = weekday;
					state.selectedIndex = null;
					if (weekdaySelect) weekdaySelect.value = String(weekday);
					draw();
				}
				state.proposed = (start !== null && end !== null && end > start) ? { start: start, end: end } : null;
				paintProposed();
				if (state.proposed) {
					var note = root.querySelector('[data-dzn-clock-proposal]');
					if (note) note.textContent = (labels.proposed || 'Proposed availability') + ': ' + minuteLabel(start) + '–' + minuteLabel(end);
				}
			};
			[weekdayField, startField, endField].forEach(function (field) {
				if (!field) return;
				field.addEventListener('input', updateProposal);
				field.addEventListener('change', updateProposal);
			});
			if (useButton) {
				useButton.addEventListener('click', function () {
					var index = Number(useButton.getAttribute('data-dzn-clock-use-index'));
					if (isNaN(index)) return;
					var start = index * SLOT_MINUTES;
					var lesson = Number(instrument().lesson_minutes || 60);
					var buffer = Number(instrument().buffer_minutes || 0);
					var end = Math.min(1439, start + Math.max(SLOT_MINUTES, Math.ceil((lesson + buffer) / SLOT_MINUTES) * SLOT_MINUTES));
					if (weekdayField) weekdayField.value = String(state.weekday);
					if (startField) startField.value = minuteLabel(start);
					if (endField) endField.value = minuteLabel(end);
					var section = form.closest('.dzn-tp-availability-editor') || form;
					section.classList.add('is-clock-target');
					if (startField) { startField.focus({ preventScroll: true }); }
					updateProposal();
				});
			}
			updateProposal();
			proposalInitialized = true;
		}

		draw();
	}

	document.querySelectorAll('[data-dzn-clock-root]').forEach(render);
})();
