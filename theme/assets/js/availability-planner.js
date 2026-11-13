/**
 * Instrument-aware Teacher availability planner.
 *
 * One interactive 24-hour dial per weekday. The dial uses the same geometry as
 * the admin coverage clock: 06:00 at the top, 12:00 right, 18:00 bottom, 00:00
 * left, with larger six-hour marks and smaller hours between them.
 *
 * The dial is the primary control. Every range also has native 15-minute time
 * inputs; both edit the same pending schedule and the server validates the save.
 */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var SIZE = 720;
	var CX = SIZE / 2;
	var CY = SIZE / 2;
	var DAY_START = 360; // 06:00 is the top of the dial
	var STEP = 15;
	var WEEK_ORDER = [6, 7, 1, 2, 3, 4, 5];
	var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';

	function fa(value) {
		return String(value).replace(/[0-9]/g, function (digit) { return FA_DIGITS[Number(digit)]; }).replace(/\./g, '٫');
	}

	function num(value, digits) {
		var text = Number(value || 0).toFixed(digits === undefined ? 1 : digits);
		return fa(text.replace(/\.0$/, ''));
	}

	function minute(value) {
		var match = String(value || '').match(/^(\d{2}):(\d{2})/);
		if (!match) return null;
		return Number(match[1]) * 60 + Number(match[2]);
	}

	function clock(value) {
		var m = Math.max(0, Math.min(1440, Math.round(Number(value) / STEP) * STEP));
		if (m >= 1440) return '24:00';
		var h = Math.floor(m / 60);
		var mm = m % 60;
		return String(h).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
	}

	function valueClock(value) {
		var m = Math.max(0, Math.min(1440, Math.round(Number(value) / STEP) * STEP));
		if (m >= 1440) return '23:59';
		var h = Math.floor(m / 60);
		var mm = m % 60;
		return String(h).padStart(2, '0') + ':' + String(mm).padStart(2, '0');
	}

	function minuteLabel(value) {
		if (value >= 1440) return '۲۴:۰۰';
		return fa(String(Math.floor(value / 60)).padStart(2, '0') + ':' + String(value % 60).padStart(2, '0'));
	}

	function point(radius, degrees) {
		var radians = degrees * Math.PI / 180;
		return [CX + radius * Math.sin(radians), CY - radius * Math.cos(radians)];
	}

	function arcPath(outer, inner, startDegrees, endDegrees) {
		var span = endDegrees - startDegrees;
		if (span >= 360) endDegrees = startDegrees + 359.99;
		var outerStart = point(outer, startDegrees);
		var outerEnd = point(outer, endDegrees);
		var innerEnd = point(inner, endDegrees);
		var innerStart = point(inner, startDegrees);
		var large = Math.abs(endDegrees - startDegrees) > 180 ? 1 : 0;
		return 'M' + outerStart[0].toFixed(2) + ' ' + outerStart[1].toFixed(2) +
			'A' + outer + ' ' + outer + ' 0 ' + large + ' 1 ' + outerEnd[0].toFixed(2) + ' ' + outerEnd[1].toFixed(2) +
			'L' + innerEnd[0].toFixed(2) + ' ' + innerEnd[1].toFixed(2) +
			'A' + inner + ' ' + inner + ' 0 ' + large + ' 0 ' + innerStart[0].toFixed(2) + ' ' + innerStart[1].toFixed(2) + 'Z';
	}

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

	function angleForMinute(value) {
		// 06:00 is 0°, 12:00 is 90°, 18:00 is 180°, 00:00 is 270°/-90°.
		// Do not wrap the end angle: the caller adds the real span so a
		// full-day or post-midnight band cannot collapse into a short arc.
		return (Number(value) - DAY_START) / 1440 * 360;
	}

	function arcFor(outer, inner, start, end) {
		var startAngle = angleForMinute(start);
		return arcPath(outer, inner, startAngle, startAngle + (Number(end) - Number(start)) / 1440 * 360);
	}

	function minuteForPoint(clientX, clientY, svg) {
		var rect = svg.getBoundingClientRect();
		var x = (clientX - rect.left) / rect.width * SIZE;
		var y = (clientY - rect.top) / rect.height * SIZE;
		var dx = x - CX;
		var dy = y - CY;
		var angle = Math.atan2(dx, -dy) * 180 / Math.PI;
		if (angle < 0) angle += 360;
		var raw = (angle / 360) * 1440 + DAY_START;
		var snapped = Math.round(raw / STEP) * STEP;
		return ((snapped % 1440) + 1440) % 1440;
	}

	function mergeIntervals(intervals) {
		var sorted = intervals.slice().sort(function (a, b) { return a.start - b.start || a.end - b.end; });
		var merged = [];
		sorted.forEach(function (interval) {
			var last = merged[merged.length - 1];
			if (last && interval.start <= last.end) last.end = Math.max(last.end, interval.end);
			else merged.push({ start: interval.start, end: interval.end });
		});
		return merged;
	}

	function totalMinutes(intervals) {
		return mergeIntervals(intervals).reduce(function (sum, interval) { return sum + interval.end - interval.start; }, 0);
	}

	function subtractIntervals(source, cuts) {
		var remaining = source.slice();
		cuts.forEach(function (cut) {
			var next = [];
			remaining.forEach(function (segment) {
				if (cut.end <= segment.start || cut.start >= segment.end) { next.push(segment); return; }
				if (cut.start > segment.start) next.push({ start: segment.start, end: cut.start, level: segment.level });
				if (cut.end < segment.end) next.push({ start: cut.end, end: segment.end, level: segment.level });
			});
			remaining = next;
		});
		return remaining;
	}

	function renderPlaceholder() {
		return;
	}

	document.querySelectorAll('[data-dzn-planner-root]').forEach(function (root) {
		var dataNode = root.querySelector('[data-dzn-planner-data]');
		if (!dataNode) return;
		var data;
		try { data = JSON.parse(dataNode.textContent || '{}'); } catch (error) { return; }
		var instruments = data.instruments || {};
		var ids = Object.keys(instruments).map(Number);
		if (!ids.length) return;

		var state = {
			instrument: Number(data.selected) || ids[0],
			day: 6,
			schedules: {},
			dirty: false,
			pendingStart: null,
			cursor: 540
		};
		if (ids.indexOf(state.instrument) === -1) state.instrument = ids[0];
		ids.forEach(function (id) {
			var schedule = {};
			WEEK_ORDER.forEach(function (weekday) { schedule[weekday] = []; });
			var days = (instruments[id] && instruments[id].days) || {};
			Object.keys(days).forEach(function (weekday) {
				(days[weekday] || []).forEach(function (range) {
					var start = minute(range.start);
					var end = minute(range.end);
					if (start !== null && end !== null && end > start) schedule[Number(weekday)].push({ start: start, end: end });
				});
			});
			WEEK_ORDER.forEach(function (weekday) { schedule[weekday] = mergeIntervals(schedule[weekday]); });
			state.schedules[id] = schedule;
		});
		WEEK_ORDER.some(function (weekday) {
			if (state.schedules[state.instrument][weekday].length) { state.day = weekday; return true; }
			return false;
		});

		var dial = root.querySelector('[data-dzn-ap-dial]');
		var rangesBox = root.querySelector('[data-dzn-ap-ranges]');
		var dayTitle = root.querySelector('[data-dzn-ap-day-title]');
		var status = root.querySelector('[data-dzn-ap-status]');
		var unsaved = root.querySelector('[data-dzn-ap-unsaved]');
		var form = root.querySelector('[data-dzn-ap-form]');
		var rangesField = root.querySelector('[data-dzn-ap-ranges-field]');
		var instrumentField = root.querySelector('[data-dzn-ap-instrument-field]');
		var emptyInstrumentField = root.querySelector('[data-dzn-ap-empty-instrument-field]');
		var emptyForm = root.querySelector('[data-dzn-ap-empty-form]');
		var labels = data.labels || {};
		var days = labels.days || {};
		var golden = data.golden || {};
		var gaps = data.gaps || {};
		var booked = data.booked || {};
		var intake = Number(data.intake || 0);
		var dragging = null;

		function activeSchedule() {
			return state.schedules[state.instrument][state.day];
		}

		function setStatus(message, error) {
			if (!status) return;
			status.textContent = message || '';
			status.classList.toggle('is-error', !!error);
		}

		function markDirty() {
			state.dirty = true;
			if (unsaved) unsaved.hidden = false;
		}

		function syncFields() {
			if (instrumentField) instrumentField.value = String(state.instrument);
			if (emptyInstrumentField) emptyInstrumentField.value = String(state.instrument);
			if (rangesField) {
				var ranges = [];
				WEEK_ORDER.forEach(function (weekday) {
					state.schedules[state.instrument][weekday].forEach(function (interval) {
						ranges.push({ weekday: weekday, start: clock(interval.start), end: clock(interval.end) });
					});
				});
				rangesField.value = JSON.stringify(ranges);
			}
		}

		function computeOverview() {
			var union = {};
			WEEK_ORDER.forEach(function (weekday) { union[weekday] = []; });
			ids.forEach(function (id) {
				WEEK_ORDER.forEach(function (weekday) {
					state.schedules[id][weekday].forEach(function (interval) { union[weekday].push({ start: interval.start, end: interval.end }); });
				});
			});
			var allocated = 0;
			var goldenMinutes = 0;
			WEEK_ORDER.forEach(function (weekday) {
				var merged = mergeIntervals(union[weekday]);
				allocated += totalMinutes(merged);
				var windows = [];
				(golden[weekday] || []).forEach(function (range) {
					var start = minute(range.start);
					var end = minute(range.end);
					if (start !== null && end !== null && end > start) windows.push({ start: start, end: end });
				});
				mergeIntervals(merged).forEach(function (a) {
					mergeIntervals(windows).forEach(function (b) {
						var start = Math.max(a.start, b.start);
						var end = Math.min(a.end, b.end);
						if (end > start) goldenMinutes += end - start;
					});
				});
			});
			var available = Math.max(0, allocated - intake);
			var allocatedNode = root.querySelector('[data-dzn-ap-metric="allocated"]');
			var availableNode = root.querySelector('[data-dzn-ap-metric="available"]');
			var goldenNode = root.querySelector('[data-dzn-ap-metric="golden"]');
			if (allocatedNode) allocatedNode.textContent = num(allocated / 60);
			if (availableNode) availableNode.textContent = num(available / 60);
			if (goldenNode) goldenNode.textContent = num(goldenMinutes / 60);
			var intakeNode = root.querySelector('[data-dzn-ap-intake]');
			if (intakeNode) intakeNode.textContent = num(intake / 60);
			WEEK_ORDER.forEach(function (weekday) {
				var node = root.querySelector('[data-dzn-ap-day-total="' + weekday + '"]');
				if (!node) return;
				var minutes = totalMinutes(state.schedules[state.instrument][weekday]);
				node.textContent = minutes > 0 ? num(minutes / 60) + ' س' : '—';
				var dayButton = node.closest('[data-dzn-ap-day]');
				if (dayButton) dayButton.classList.toggle('has-hours', minutes > 0);
			});
		}

		function drawBands(svg, day, instrument) {
			var goldenBands = golden[day] || [];
			goldenBands.forEach(function (range) {
				var start = minute(range.start);
				var end = minute(range.end);
				if (start === null || end === null || end <= start) return;
				svg.appendChild(el('path', {
					d: arcFor(334, 322, start, end),
					class: 'dzn-ap__band is-golden'
				}));
			});
			var bookedBands = booked[day] || [];
			bookedBands.forEach(function (range) {
				var start = minute(range.start);
				var end = minute(range.end);
				if (start === null || end === null || end <= start) return;
				svg.appendChild(el('path', {
					d: arcFor(244, 232, start, end),
					class: 'dzn-ap__band is-booked'
				}));
			});
			var gapBands = (gaps[instrument] && gaps[instrument][day]) || [];
			gapBands.forEach(function (range) {
				var start = minute(range.start);
				var end = minute(range.end);
				if (start === null || end === null || end <= start) return;
				// The gap overlay is a preview: a range the Teacher is currently
				// drawing would itself cover that gap, so subtract it immediately.
				subtractIntervals([{ start: start, end: end, level: range.level || 'gap' }], activeSchedule()).forEach(function (part) {
					svg.appendChild(el('path', {
						d: arcFor(260, 248, part.start, part.end),
						class: 'dzn-ap__band is-gap is-' + String(part.level || 'gap')
					}));
				});
			});
		}

		function draw() {
			if (!dial) return;
			dial.replaceChildren();
			var svg = el('svg', { viewBox: '0 0 ' + SIZE + ' ' + SIZE, class: 'dzn-ap__svg', role: 'img', 'aria-hidden': 'true' });
			var defs = el('defs');
			var pattern = el('pattern', { id: 'dzn-ap-gap-hatch', width: 6, height: 6, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' });
			pattern.appendChild(el('line', { x1: 0, y1: 0, x2: 0, y2: 6, stroke: '#b45a2a', 'stroke-width': 2 }));
			defs.appendChild(pattern);
			svg.appendChild(defs);
			svg.appendChild(el('circle', { cx: CX, cy: CY, r: 344, class: 'dzn-ap__face' }));
			svg.appendChild(el('circle', { cx: CX, cy: CY, r: 240, class: 'dzn-ap__inner' }));

			for (var hour = 0; hour < 24; hour++) {
				var degrees = angleForMinute(hour * 60);
				var major = hour % 6 === 0;
				var from = point(major ? 330 : 336, degrees);
				var to = point(350, degrees);
				svg.appendChild(el('line', { x1: from[0], y1: from[1], x2: to[0], y2: to[1], class: 'dzn-ap__tick' + (major ? ' is-major' : '') }));
				var labelPoint = point(352, degrees);
				svg.appendChild(el('text', {
					x: labelPoint[0], y: labelPoint[1],
					class: 'dzn-ap__hour' + (major ? ' is-major' : ' is-minor'),
					'text-anchor': 'middle', 'dominant-baseline': 'middle'
				}, [fa(String(hour).padStart(2, '0'))]));
			}

			drawBands(svg, state.day, state.instrument);

			activeSchedule().forEach(function (interval) {
				svg.appendChild(el('path', {
					d: arcFor(314, 274, interval.start, interval.end),
					class: 'dzn-ap__band is-selected'
				}));
			});

			if (state.pendingStart !== null) {
				var previewEnd = dragging ? dragging.current : state.cursor;
				var previewFrom = Math.min(state.pendingStart, previewEnd);
				var previewTo = Math.max(state.pendingStart, previewEnd);
				if (previewTo > previewFrom) {
					svg.appendChild(el('path', {
						d: arcFor(314, 274, previewFrom, previewTo),
						class: 'dzn-ap__band is-pending'
					}));
				}
			}
			var cursorPoint = point(294, angleForMinute(state.cursor));
			svg.appendChild(el('circle', { cx: cursorPoint[0], cy: cursorPoint[1], r: 7, class: 'dzn-ap__cursor' }));

			var total = totalMinutes(activeSchedule());
			svg.appendChild(el('circle', { cx: CX, cy: CY, r: 76, class: 'dzn-ap__hub' }));
			svg.appendChild(el('text', { x: CX, y: CY - 12, class: 'dzn-ap__hub-day', 'text-anchor': 'middle' }, [days[state.day] || '']));
			svg.appendChild(el('text', { x: CX, y: CY + 22, class: 'dzn-ap__hub-total', 'text-anchor': 'middle' }, [total > 0 ? num(total / 60) + ' ساعت' : 'بدون بازه']));

			dial.appendChild(svg);
		}

		function renderRanges() {
			if (!rangesBox) return;
			rangesBox.replaceChildren();
			var schedule = activeSchedule();
			if (!schedule.length) {
				var empty = document.createElement('p');
				empty.className = 'dzn-ap__empty';
				empty.textContent = 'برای این روز بازه‌ای انتخاب نشده است.';
				rangesBox.appendChild(empty);
				return;
			}
			schedule.forEach(function (interval, index) {
				var row = document.createElement('div');
				row.className = 'dzn-ap__range';
				var fromLabel = document.createElement('label');
				fromLabel.textContent = 'از';
				var fromInput = document.createElement('input');
				fromInput.type = 'time';
				fromInput.step = '900';
				fromInput.lang = 'en-GB';
				fromInput.dir = 'ltr';
				fromInput.value = valueClock(interval.start);
				fromLabel.appendChild(fromInput);
				var toLabel = document.createElement('label');
				toLabel.textContent = 'تا';
				var toInput = document.createElement('input');
				toInput.type = 'time';
				toInput.step = '900';
				toInput.lang = 'en-GB';
				toInput.dir = 'ltr';
				toInput.value = interval.end >= 1440 ? '00:00' : valueClock(interval.end);
				toLabel.appendChild(toInput);
				var remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'dzn-ap__remove';
				remove.textContent = 'حذف';
				remove.setAttribute('aria-label', 'حذف بازهٔ ' + minuteLabel(interval.start) + ' تا ' + minuteLabel(interval.end));
				fromInput.addEventListener('change', function () {
					var start = minute(fromInput.value);
					if (start === null) { setStatus('ساعت شروع معتبر نیست.', true); return; }
					var end = interval.end;
					if (end <= start) { setStatus('پایان بازه باید بعد از شروع باشد.', true); fromInput.value = valueClock(interval.start); return; }
					interval.start = start;
					commitChange();
				});
				toInput.addEventListener('change', function () {
					var end = minute(toInput.value);
					if (end === null) { setStatus('ساعت پایان معتبر نیست.', true); return; }
					if (end === 0 && interval.start > 0) end = 1440;
					if (end <= interval.start) { setStatus('پایان بازه باید بعد از شروع باشد.', true); toInput.value = interval.end >= 1440 ? '00:00' : valueClock(interval.end); return; }
					interval.end = end;
					commitChange();
				});
				remove.addEventListener('click', function () {
					schedule.splice(index, 1);
					state.schedules[state.instrument][state.day] = mergeIntervals(schedule);
					commitChange();
				});
				row.append(fromLabel, toLabel, remove);
				rangesBox.appendChild(row);
			});
		}

		function commitChange() {
			state.schedules[state.instrument][state.day] = mergeIntervals(activeSchedule());
			markDirty();
			draw();
			renderRanges();
			computeOverview();
			syncFields();
			setStatus('');
		}

		function selectRange(first, second) {
			if (first === second) return;
			// A span longer than half the dial is a midnight crossing, not an
			// accidentally huge daytime range. Persist it as two same-day ranges.
			if (Math.abs(first - second) > 720) {
				if (first > second) { addRange(first, 1440); addRange(0, second); }
				else { addRange(second, 1440); addRange(0, first); }
				return;
			}
			addRange(Math.min(first, second), Math.max(first, second));
		}

		function addRange(start, end) {
			if (start === end) return;
			var schedule = activeSchedule().slice();
			if (end < start) {
				// A selection that crosses midnight is stored as two same-day ranges.
				addRange(start, 1440);
				addRange(0, end);
				return;
			}
			for (var index = 0; index < schedule.length; index++) {
				var existing = schedule[index];
				if (start < existing.end && end > existing.start) {
					setStatus('این بازه با بازهٔ دیگری هم‌پوشانی دارد.', true);
					return;
				}
			}
			schedule.push({ start: start, end: end });
			state.schedules[state.instrument][state.day] = mergeIntervals(schedule);
			state.pendingStart = null;
			commitChange();
		}

		function setDay(weekday) {
			state.day = Number(weekday);
			state.pendingStart = null;
			state.cursor = 540;
			root.querySelectorAll('[data-dzn-ap-day]').forEach(function (button) {
				var active = Number(button.getAttribute('data-dzn-ap-day')) === state.day;
				button.classList.toggle('is-active', active);
				button.setAttribute('aria-selected', active ? 'true' : 'false');
			});
			if (dayTitle) dayTitle.textContent = days[state.day] || '';
			draw();
			renderRanges();
			setStatus('');
		}

		function setInstrument(id) {
			state.instrument = Number(id);
			state.pendingStart = null;
			root.querySelectorAll('[data-dzn-ap-instrument]').forEach(function (button) {
				var active = Number(button.getAttribute('data-dzn-ap-instrument')) === state.instrument;
				button.classList.toggle('is-active', active);
				button.setAttribute('aria-selected', active ? 'true' : 'false');
			});
			syncFields();
			draw();
			renderRanges();
			computeOverview();
			setStatus('');
		}

		root.querySelectorAll('[data-dzn-ap-day]').forEach(function (button) {
			button.addEventListener('click', function () { setDay(button.getAttribute('data-dzn-ap-day')); });
		});
		root.querySelectorAll('[data-dzn-ap-instrument]').forEach(function (button) {
			button.addEventListener('click', function () { setInstrument(button.getAttribute('data-dzn-ap-instrument')); });
		});

		var addButton = root.querySelector('[data-dzn-ap-add]');
		if (addButton) addButton.addEventListener('click', function () {
			var schedule = activeSchedule();
			var candidate = 540;
			while (candidate < 1380) {
				var clash = schedule.some(function (interval) { return candidate < interval.end && candidate + 60 > interval.start; });
				if (!clash) { addRange(candidate, candidate + 60); return; }
				candidate += 60;
			}
			setStatus('برای افزودن بازهٔ تازه، نخست یک بازه را حذف کنید.', true);
		});

		var clearButton = root.querySelector('[data-dzn-ap-clear-day]');
		if (clearButton) clearButton.addEventListener('click', function () {
			state.schedules[state.instrument][state.day] = [];
			commitChange();
		});

		if (dial) {
			dial.addEventListener('pointerdown', function (event) {
				if (event.button !== undefined && event.button !== 0) return;
				var svg = dial.querySelector('svg');
				if (!svg) return;
				var start = minuteForPoint(event.clientX, event.clientY, svg);
				dragging = { anchor: start, current: start, moved: false };
				dial.setPointerCapture && dial.setPointerCapture(event.pointerId);
				event.preventDefault();
			});
			dial.addEventListener('pointermove', function (event) {
				if (!dragging) return;
				var svg = dial.querySelector('svg');
				if (!svg) return;
				var current = minuteForPoint(event.clientX, event.clientY, svg);
				if (Math.abs(current - dragging.anchor) >= STEP) dragging.moved = true;
				dragging.current = current;
				state.cursor = current;
				if (state.pendingStart === null) state.pendingStart = dragging.anchor;
				draw();
			});
			dial.addEventListener('pointerup', function (event) {
				if (!dragging) return;
				var svg = dial.querySelector('svg');
				if (!svg) return;
				var current = minuteForPoint(event.clientX, event.clientY, svg);
				var anchor = dragging.anchor;
				var moved = dragging.moved || Math.abs(current - anchor) >= STEP;
				dragging = null;
				if (moved) {
					selectRange(anchor, current);
					return;
				}
				if (state.pendingStart === null) {
					state.pendingStart = current;
					state.cursor = current;
					draw();
					return;
				}
				var start = state.pendingStart;
				state.pendingStart = null;
				if (start === current) { draw(); return; }
				selectRange(start, current);
			});
			dial.addEventListener('pointercancel', function () { dragging = null; draw(); });
			dial.addEventListener('keydown', function (event) {
				if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
					state.cursor = (state.cursor + STEP) % 1440;
					if (state.pendingStart !== null) draw();
					event.preventDefault();
				} else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
					state.cursor = (state.cursor - STEP + 1440) % 1440;
					if (state.pendingStart !== null) draw();
					event.preventDefault();
				} else if (event.key === 'Enter' || event.key === ' ') {
					if (state.pendingStart === null) state.pendingStart = state.cursor;
					else {
						var start = state.pendingStart;
						state.pendingStart = null;
						if (start !== state.cursor) selectRange(start, state.cursor);
						else draw();
					}
					event.preventDefault();
				} else if (event.key === 'Escape') {
					state.pendingStart = null;
					draw();
					event.preventDefault();
				}
			});
		}

		if (form) form.addEventListener('submit', function () { syncFields(); });
		if (emptyForm) emptyForm.addEventListener('submit', function (event) {
			if (!window.confirm('همهٔ بازه‌های این ساز حذف و «بدون زمان» ذخیره شود؟')) { event.preventDefault(); return; }
			syncFields();
		});
		window.addEventListener('beforeunload', function (event) {
			if (!state.dirty) return;
			event.preventDefault();
			event.returnValue = '';
		});

		syncFields();
		setDay(state.day);
		setInstrument(state.instrument);
		draw();
		renderRanges();
		computeOverview();
	});
})();
