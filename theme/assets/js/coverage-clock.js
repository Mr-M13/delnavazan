/**
 * Seven-day, 24-hour instrument coverage clock.
 *
 * One Golden Hours ring, then seven weekday rings in Saturday–Friday order.
 * The server sends 48 visual 30-minute segments per weekday; each segment keeps
 * both 15-minute halves so partial availability stays visible. Booking precision
 * remains in the server-side analyzer.
 */
(function () {
	'use strict';

	var NS = 'http://www.w3.org/2000/svg';
	var SIZE = 720;
	var CENTER = SIZE / 2;
	var SEGMENTS = 48;
	var DEGREES = 360 / SEGMENTS;
	var GOLD_OUTER = 342;
	var GOLD_INNER = 334;
	var RING_OUTER = 322;
	var RING_THICKNESS = 29;
	var RING_GAP = 4;
	var HUB_RADIUS = 86;
	var STATE = { UNCOVERED: 0, LIMITED: 1, COVERED: 2, UNKNOWN: 3, PARTIAL: 4 };
	var WEEK_ORDER = [6, 7, 1, 2, 3, 4, 5];

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
		return String(Math.floor(minute / 60)).padStart(2, '0') + ':' + String(minute % 60).padStart(2, '0');
	}

	function render(root) {
		var dataNode = root.querySelector('[data-dzn-clock-data]');
		if (!dataNode) return;
		var data;
		try { data = JSON.parse(dataNode.textContent || '{}'); } catch (error) { return; }
		if (!data || !Array.isArray(data.week) || !data.week.length) return;
		var labels = data.labels || {};
		var isFa = data.scope === 'teacher';
		var faDigits = function (value) { return String(value).replace(/[0-9]/g, function (digit) { return '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]; }); };
		var clockLabel = function (minute) { var value = minuteLabel(minute); return isFa ? faDigits(value) : value; };
		var timeLabel = function (value) { return isFa ? faDigits(value) : String(value); };
		var numberLabel = function (value) { return isFa ? faDigits(value) : String(value); };
		var canvas = root.querySelector('[data-dzn-clock-canvas]');
		var details = root.querySelector('[data-dzn-clock-details]');
		var detailsBody = root.querySelector('[data-dzn-clock-details-body]');
		var useButton = root.querySelector('[data-dzn-clock-use]');
		if (!canvas) return;

		var state = { ring: null, segment: null, golden: false, proposed: null };
		var week = data.week.slice();
		var goldenRing = Array.isArray(data.golden_ring) ? data.golden_ring : [];
		var goldenBonus = data.golden_bonus || {};
		var ringGroups = [];

		function dayFor(index) { return week[index] || null; }
		function weekdayLabel(day) { return (labels.weekdays || {})[String(day.weekday)] || ''; }
		function dateLabel(day) {
			try {
				var locale = isFa ? (data.calendar === 'persian' ? 'fa-IR-u-ca-persian' : 'fa-IR-u-ca-gregory') : (data.calendar === 'persian' ? 'en-GB-u-ca-persian' : 'en-GB');
				return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(day.date + 'T12:00:00Z'));
			} catch (error) { return data.calendar === 'persian' ? (day.date_label_fa || day.date_label) : (day.date_label || day.date_label_fa); }
		}
		function stateName(code) {
			return code === STATE.COVERED ? (labels.covered || 'Capacity')
				: code === STATE.LIMITED ? (labels.limited || 'One teacher')
				: code === STATE.PARTIAL ? (labels.partial || 'Partial')
				: code === STATE.UNKNOWN ? (labels.unknown || 'Unknown')
				: (labels.uncoveredState || 'No capacity');
		}
		function halfStateName(code) {
			return code === STATE.COVERED ? (labels.covered || 'Capacity')
				: code === STATE.LIMITED ? (labels.limited || 'One teacher')
				: code === STATE.UNKNOWN ? (labels.unknown || 'Unknown')
				: (labels.uncoveredState || 'No capacity');
		}
		function capacityText(segment) {
			var first = Number(segment[8] || 0);
			var second = Number(segment[9] || 0);
			var teachers = labels.teachers || 'eligible teachers';
			var teacher = labels.teacher || 'eligible teacher';
			if (first === second) return numberLabel(first) + ' ' + (first === 1 ? teacher : teachers);
			return (labels.partialFirst || 'First half') + ': ' + numberLabel(first) + ' ' + (first === 1 ? teacher : teachers) + ' · ' + (labels.partialSecond || 'Second half') + ': ' + numberLabel(second) + ' ' + (second === 1 ? teacher : teachers);
		}
		function reasonName(code) {
			return code === 1 ? (labels.reasonNoAvailability || 'No declared availability')
				: code === 2 ? (labels.reasonFullyBooked || 'Fully booked')
				: code === 3 ? (labels.reasonFullyHeld || 'Held by another booking')
				: code === 4 ? (labels.reasonNonExistent || 'Nonexistent local time')
				: code === 5 ? (labels.reasonUnknown || 'Unknown coverage data')
				: code === 6 ? (labels.reasonOneTeacher || 'One teacher remaining')
				: code === 7 ? (labels.reasonPartial || 'Partial 15-minute coverage')
				: (labels.reasonGap || 'Coverage gap');
		}
		function causeText(segment) {
			var code = Number(segment[13] || 0);
			var text = reasonName(code);
			if (code === 7) {
				var firstReason = Number(segment[14] || 0);
				var secondReason = Number(segment[15] || 0);
				if (firstReason && secondReason && firstReason !== secondReason) {
					text += ' · ' + (labels.partialFirst || 'First half') + ': ' + reasonName(firstReason) + ' · ' + (labels.partialSecond || 'Second half') + ': ' + reasonName(secondReason);
				}
			}
			var booked = Number(segment[17] || 0);
			var held = Number(segment[18] || 0);
			if (booked > 0) text += ' · ' + numberLabel(booked) + ' ' + (labels.bookedCount || 'booked');
			if (held > 0) text += ' · ' + numberLabel(held) + ' ' + (labels.heldCount || 'held');
			return text;
		}
		function firstGapSegment(day) {
			var best = null;
			var bestRank = 9;
			(day.segments || []).forEach(function (segment, index) {
				if (Number(segment[5])) return;
				var code = Number(segment[0]);
				var rank = code === STATE.UNCOVERED && Number(segment[3]) ? 0 : (code === STATE.UNCOVERED ? 1 : (code === STATE.LIMITED ? 2 : 9));
				if (rank < bestRank) { bestRank = rank; best = index; }
			});
			return best;
		}
		function addDetail(list, term, value) {
			var dt = document.createElement('dt'); dt.textContent = term;
			var dd = document.createElement('dd'); dd.textContent = value;
			list.append(dt, dd);
		}
		function clearDetails() {
			if (!detailsBody) return;
			detailsBody.replaceChildren();
			var empty = document.createElement('p');
			empty.className = 'dzn-clock__details-empty';
			empty.textContent = labels.select || 'Select a segment for details.';
			detailsBody.appendChild(empty);
			if (useButton) useButton.disabled = true;
		}
		function setUse(index) {
			if (!useButton) return;
			useButton.disabled = index === null;
			useButton.setAttribute('data-dzn-clock-use-ring', index === null ? '' : String(index));
			useButton.setAttribute('data-dzn-clock-use-seg', state.segment === null ? '' : String(state.segment));
		}

		function selectWeekday(ringIndex, segmentIndex, focus, gapRange) {
			var day = dayFor(ringIndex);
			if (!day) return;
			var segment = day.segments[segmentIndex];
			if (!segment) return;
			state.ring = ringIndex; state.segment = segmentIndex; state.golden = false;
			state.gapRange = gapRange && gapRange.length ? gapRange : null;
			if (!detailsBody) return;
			detailsBody.replaceChildren();
			var head = document.createElement('div'); head.className = 'dzn-clock__detail-head';
			var time = document.createElement('strong'); time.textContent = clockLabel(segmentIndex * 30) + '–' + clockLabel(segmentIndex * 30 + 30);
			var badge = document.createElement('span'); badge.className = 'dzn-clock__detail-state is-state-' + Number(segment[0]); badge.textContent = (labels.selected || 'Selected') + ' · ' + stateName(segment[0]);
			head.append(time, badge); detailsBody.appendChild(head);
			var meta = document.createElement('p'); meta.className = 'dzn-clock__detail-meta'; meta.textContent = weekdayLabel(day) + ' · ' + dateLabel(day);
			detailsBody.appendChild(meta);
			var list = document.createElement('dl'); list.className = 'dzn-clock__detail-list';
			addDetail(list, labels.remainingAtTime || 'Remaining at this interval', capacityText(segment));
			addDetail(list, (labels.eligibleTeachers || 'Eligible teachers') + ' (' + (labels.instrumentWide || 'instrument-wide') + ')', numberLabel(Number(data.eligible_teacher_count || 0)) + ' ' + (labels.teachers || 'eligible teachers'));
			if (Number(segment[0]) !== STATE.COVERED) addDetail(list, labels.reason || 'Cause', causeText(segment));
			var goldenEntries = (day.golden_details || {})[String(segmentIndex)] || [];
			addDetail(list, labels.goldenTitle || 'Golden Hours', segment[3] ? (labels.dayGoldenDemand || 'Golden Hours demand on this day') : (labels.noDayGoldenDemand || 'Not a Golden Hour on this day'));
			if (goldenEntries.length) {
				goldenEntries.slice(0, 5).forEach(function (entry) {
					addDetail(list, (labels.regions || 'Regions') + ' · ' + (entry.label || entry.region), timeLabel(entry.local_start) + '–' + timeLabel(entry.local_end));
				});
			}
			if (Number(segment[4])) addDetail(list, labels.partial || 'Partial', labels.goldenTitle || 'Golden Hours');
			if (goldenBonus.enabled && Number(segment[12])) addDetail(list, labels.bonus || 'Bonus', numberLabel(goldenBonus.percent) + '% · ' + (labels.bonusConditional || 'only if the booking falls within the global bonus window'));
			if (segment[5]) addDetail(list, labels.past || 'Past', '');
			detailsBody.appendChild(list);
			if (useButton) { useButton.disabled = false; useButton.setAttribute('data-dzn-clock-use-ring', String(ringIndex)); useButton.setAttribute('data-dzn-clock-use-seg', String(segmentIndex)); }
			syncTabindex(); paintSelection(); paintGapList(); updateHub();
			if (focus) focusSegment(ringIndex, segmentIndex, false);
		}

		function selectGolden(segmentIndex, focus) {
			var segment = goldenRing[segmentIndex] || {};
			state.golden = true; state.ring = null; state.segment = segmentIndex; state.gapRange = null;
			if (!detailsBody) return;
			detailsBody.replaceChildren();
			var head = document.createElement('div'); head.className = 'dzn-clock__detail-head';
			var time = document.createElement('strong'); time.textContent = clockLabel(segmentIndex * 30) + '–' + clockLabel(segmentIndex * 30 + 30);
			var badge = document.createElement('span'); badge.className = 'dzn-clock__detail-state ' + (segment.golden ? 'is-golden' : 'is-state-0'); badge.textContent = segment.golden ? (labels.golden || 'Golden Hours') : (labels.noDemandWindow || 'No regional demand window');
			head.append(time, badge); detailsBody.appendChild(head);
			var note = document.createElement('p'); note.className = 'dzn-clock__detail-meta'; note.textContent = labels.aggregateGolden || ''; detailsBody.appendChild(note);
			var list = document.createElement('dl'); list.className = 'dzn-clock__detail-list';
			var regions = Array.isArray(segment.regions) ? segment.regions : [];
			if (!regions.length) addDetail(list, labels.goldenTitle || 'Golden Hours', labels.noDemandWindow || 'No regional demand window');
			var payApplies = regions.some(function (entry) {
				var day = week.find(function (candidate) { return Number(candidate.weekday) === Number(entry.weekday); });
				return !!(day && day.segments[segmentIndex] && Number(day.segments[segmentIndex][12]));
			});
			regions.slice(0, 12).forEach(function (entry) {
				var day = week.find(function (candidate) { return Number(candidate.weekday) === Number(entry.weekday); });
				var dayName = day ? weekdayLabel(day) + ' ' + dateLabel(day) : '';
				addDetail(list, dayName + ' · ' + (entry.label || entry.region), timeLabel(entry.local_start) + '–' + timeLabel(entry.local_end) + ' · ' + timeLabel((entry.timezone || '').replace(/_/g, ' ')));
			});
			if (goldenBonus.enabled && payApplies) addDetail(list, labels.bonus || 'Bonus', numberLabel(goldenBonus.percent) + '% · ' + (labels.bonusConditional || 'only if the booking falls within the global bonus window'));
			detailsBody.appendChild(list);
			if (useButton) useButton.disabled = true;
			syncTabindex(); paintSelection(); paintGapList(); updateHub();
			if (focus) focusSegment(null, segmentIndex, true);
		}

		function gapRangeFor(weekday, segmentIndex) {
			var gaps = Array.isArray(data.priority_gaps) ? data.priority_gaps : [];
			for (var index = 0; index < gaps.length; index++) {
				var gap = gaps[index] || {};
				if (Number(gap.instrument_id) !== Number(data.selected_instrument) || Number(gap.weekday) !== Number(weekday)) continue;
				var indexes = (gap.visual_indexes || []).map(Number);
				if (indexes.indexOf(Number(segmentIndex)) !== -1) return indexes;
			}
			return null;
		}

		function revealDetails() {
			if (!details || !window.matchMedia || !window.matchMedia('(max-width: 760px)').matches) return;
			var rect = details.getBoundingClientRect();
			if (rect.top >= window.innerHeight || rect.bottom <= 0) details.scrollIntoView({ block: 'start' });
		}

		function updateHub() {
			var hub = canvas.querySelector('.dzn-clock__hub-time');
			var sub = canvas.querySelector('.dzn-clock__hub-state');
			if (!hub || !sub) return;
			if (state.segment === null) {
				hub.textContent = labels.clock || '24h';
				sub.textContent = labels.select || '';
				return;
			}
			hub.textContent = clockLabel(state.segment * 30) + '–' + clockLabel(state.segment * 30 + 30);
			if (state.golden) { sub.textContent = labels.golden || 'Golden Hours'; return; }
			var day = dayFor(state.ring || 0);
			var segment = day ? day.segments[state.segment] : null;
			sub.textContent = segment ? stateName(segment[0]) : '';
		}

		function paintSelection() {
			canvas.querySelectorAll('[data-dzn-ring]').forEach(function (group) {
				var ring = Number(group.getAttribute('data-dzn-ring'));
				var seg = Number(group.getAttribute('data-dzn-seg'));
				var selected = !state.golden && ring === state.ring && seg === state.segment;
				var inGap = !state.golden && ring === state.ring && state.gapRange && state.gapRange.indexOf(seg) !== -1;
				group.classList.toggle('is-selected', selected);
				group.classList.toggle('is-gap-selected', inGap);
				group.setAttribute('aria-pressed', selected ? 'true' : 'false');
			});
			canvas.querySelectorAll('[data-dzn-golden-seg]').forEach(function (group) {
				var selected = state.golden && Number(group.getAttribute('data-dzn-golden-seg')) === state.segment;
				group.classList.toggle('is-selected', selected);
				group.setAttribute('aria-pressed', selected ? 'true' : 'false');
			});
			paintProposed();
		}

		function paintGapList() {
			var rows = root.querySelectorAll('[data-dzn-gap]');
			if (!rows.length) return;
			var day = !state.golden && state.ring !== null ? dayFor(state.ring) : null;
			var activeDate = day ? String(day.date) : '';
			var indexes = day ? (state.gapRange || [state.segment]) : [];
			rows.forEach(function (row) {
				var sameInstrument = Number(row.getAttribute('data-dzn-gap-instrument')) === Number(data.selected_instrument);
				var sameDate = String(row.getAttribute('data-dzn-gap-date') || '') === activeDate;
				var listed = String(row.getAttribute('data-dzn-gap-visual-indexes') || '').split(',').map(Number);
				var hit = sameInstrument && sameDate && listed.some(function (index) { return indexes.indexOf(index) !== -1; });
				row.classList.toggle('is-active', hit);
				if (hit) row.setAttribute('aria-current', 'true'); else row.removeAttribute('aria-current');
			});
			root.querySelectorAll('[data-dzn-clock-ring]').forEach(function (button) {
				var ringIndex = Number(button.getAttribute('data-dzn-clock-ring'));
				button.classList.toggle('is-active', !state.golden && ringIndex === state.ring);
			});
		}

		function paintProposed() {
			canvas.querySelectorAll('[data-dzn-ring]').forEach(function (group) {
				var ring = Number(group.getAttribute('data-dzn-ring'));
				var seg = Number(group.getAttribute('data-dzn-seg'));
				var day = dayFor(ring);
				var proposed = state.proposed && day && Number(day.weekday) === Number(state.proposed.weekday) && seg * 30 < state.proposed.end && seg * 30 + 30 > state.proposed.start;
				group.classList.toggle('is-proposed', !!proposed);
			});
		}

		function syncTabindex() {
			var any = false;
			canvas.querySelectorAll('[data-dzn-ring], [data-dzn-golden-seg]').forEach(function (group) {
				var selected = false;
				if (group.hasAttribute('data-dzn-golden-seg')) selected = state.golden && Number(group.getAttribute('data-dzn-golden-seg')) === state.segment;
				else selected = !state.golden && Number(group.getAttribute('data-dzn-ring')) === state.ring && Number(group.getAttribute('data-dzn-seg')) === state.segment;
				group.setAttribute('tabindex', selected ? '0' : '-1');
				if (selected) any = true;
			});
			if (!any) {
				var first = canvas.querySelector('[data-dzn-ring="0"][data-dzn-seg="0"]');
				if (first) first.setAttribute('tabindex', '0');
			}
		}

		function focusSegment(ringIndex, segmentIndex, golden) {
			var selector = golden ? '[data-dzn-golden-seg="' + segmentIndex + '"]' : '[data-dzn-ring="' + ringIndex + '"][data-dzn-seg="' + segmentIndex + '"]';
			var target = canvas.querySelector(selector);
			if (target) target.focus({ preventScroll: true });
		}

		function draw() {
			canvas.replaceChildren();
			var svg = el('svg', { viewBox: '0 0 ' + SIZE + ' ' + SIZE, class: 'dzn-clock__svg', role: 'group', 'aria-label': labels.clock || 'Weekly coverage clock' });
			var defs = el('defs');
			defs.appendChild(pattern('dzn-clock-limited', 7, 7, function (p) { p.appendChild(el('path', { d: 'M0 7L7 0', stroke: 'rgba(255,255,255,.5)', 'stroke-width': 1 })); }));
			defs.appendChild(pattern('dzn-clock-unknown', 5, 5, function (p) { p.appendChild(el('path', { d: 'M0 5L5 0', stroke: 'rgba(58,48,82,.38)', 'stroke-width': 1 })); }));
			svg.appendChild(defs);

			for (var hour = 0; hour < 24; hour++) {
				var a = hour * 15;
				var t1 = polar(346, a), t2 = polar(342, a);
				svg.appendChild(el('line', { x1: t1[0], y1: t1[1], x2: t2[0], y2: t2[1], class: 'dzn-clock__tick' + (hour % 6 === 0 ? ' is-major' : '') }));
			}
			[[0, '00'], [6, '06'], [12, '12'], [18, '18']].forEach(function (entry) {
				var point = polar(358, entry[0] * 15);
				svg.appendChild(el('text', { x: point[0], y: point[1], class: 'dzn-clock__hour', 'text-anchor': 'middle', 'dominant-baseline': 'middle' }, [isFa ? faDigits(entry[1]) : entry[1]]));
			});

			for (var i = 0; i < SEGMENTS; i++) {
				var golden = goldenRing[i] || {};
				var group = el('g', {
					class: 'dzn-clock__golden-seg' + (golden.golden ? ' is-golden' : ''),
					'data-dzn-golden-seg': i,
					role: 'button',
					tabindex: '-1',
					'aria-pressed': 'false',
					'aria-label': (labels.golden || 'Golden Hours') + ', ' + clockLabel(i * 30) + '–' + clockLabel(i * 30 + 30) + ', ' + ((golden.regions || []).length) + ' ' + (labels.regions || 'regions')
				});
				group.appendChild(el('title', null, [clockLabel(i * 30) + '–' + clockLabel(i * 30 + 30)]));
				var goldenArc = arcPath(GOLD_OUTER, GOLD_INNER, i * DEGREES + 0.18, (i + 1) * DEGREES - 0.18);
				group.appendChild(el('path', { d: goldenArc, class: 'dzn-clock__golden-base' }));
				group.appendChild(el('path', {
					d: goldenArc,
					class: 'dzn-clock__golden-arc',
					'fill-opacity': golden.golden ? (0.16 + 0.34 * Number(golden.intensity || 0)).toFixed(2) : '0'
				}));
				group.addEventListener('keydown', function (event) {
					var index = Number(this.getAttribute('data-dzn-golden-seg'));
					if (event.key === 'ArrowRight') { event.preventDefault(); selectGolden((index + 1) % SEGMENTS, true); }
					else if (event.key === 'ArrowLeft') { event.preventDefault(); selectGolden((index + SEGMENTS - 1) % SEGMENTS, true); }
					else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); selectWeekday(0, index, true); }
					else if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectGolden(index, true); }
				});
				svg.appendChild(group);
			}

			week.forEach(function (day, ringIndex) {
				var outer = RING_OUTER - ringIndex * (RING_THICKNESS + RING_GAP);
				var inner = outer - RING_THICKNESS;
				var ring = el('g', { class: 'dzn-clock__ring dzn-clock__ring--' + day.weekday + (day.is_today ? ' is-today' : ''), 'aria-label': weekdayLabel(day) + ' ' + dateLabel(day) });
				if (day.is_today) {
					ring.appendChild(el('circle', { cx: CENTER, cy: CENTER, r: outer + 1.5, class: 'dzn-clock__today-outline' }));
					ring.appendChild(el('circle', { cx: CENTER, cy: CENTER, r: inner - 1.5, class: 'dzn-clock__today-outline' }));
				}
				day.segments.forEach(function (segment, segIndex) {
					var start = segIndex * DEGREES + 0.22;
					var end = (segIndex + 1) * DEGREES - 0.22;
					var mid = (start + end) / 2;
					var group = el('g', {
						class: 'dzn-clock__seg' + (segment[5] ? ' is-past' : ''),
						'data-dzn-ring': ringIndex,
						'data-dzn-seg': segIndex,
						role: 'button',
						tabindex: '-1',
						'aria-pressed': 'false',
						'aria-label': weekdayLabel(day) + ' ' + dateLabel(day) + ', ' + clockLabel(segIndex * 30) + '–' + clockLabel(segIndex * 30 + 30) + ', ' + stateName(segment[0]) + ', ' + capacityText(segment) + (segment[3] ? ', ' + (labels.golden || 'Golden Hours') : '')
					});
					group.appendChild(el('title', null, [weekdayLabel(day) + ' ' + dateLabel(day) + ' · ' + clockLabel(segIndex * 30) + '–' + clockLabel(segIndex * 30 + 30) + ' · ' + stateName(segment[0])]));
					var halves = [[segment[6], segment[8], start, mid - 0.08, segment[10]], [segment[7], segment[9], mid + 0.08, end, segment[11]]];
					halves.forEach(function (half) {
						var halfState = Number(half[0]);
						var fillClass = halfState === STATE.COVERED ? 'is-covered' : (halfState === STATE.LIMITED ? 'is-limited' : (halfState === STATE.UNKNOWN ? 'is-unknown' : 'is-uncovered'));
						var halfPath = arcPath(outer, inner, half[2], half[3]);
						group.appendChild(el('path', { d: halfPath, class: 'dzn-clock__seg-half ' + fillClass }));
						if (halfState === STATE.LIMITED) group.appendChild(el('path', { d: halfPath, class: 'dzn-clock__seg-hatch', fill: 'url(#dzn-clock-limited)' }));
						if (halfState === STATE.UNKNOWN) group.appendChild(el('path', { d: halfPath, class: 'dzn-clock__seg-hatch', fill: 'url(#dzn-clock-unknown)' }));
						if (Number(half[4])) group.appendChild(el('path', { d: arcPath(inner + 2.2, inner, half[2], half[3]), class: 'dzn-clock__golden-edge' }));
					});
					group.appendChild(el('path', { d: arcPath(outer + 0.6, inner - 0.6, start, end), class: 'dzn-clock__seg-outline' }));
					group.addEventListener('keydown', function (event) {
						var r = Number(this.getAttribute('data-dzn-ring')); var s = Number(this.getAttribute('data-dzn-seg'));
						if (event.key === 'ArrowRight') { event.preventDefault(); selectWeekday(r, (s + 1) % SEGMENTS, true); }
						else if (event.key === 'ArrowLeft') { event.preventDefault(); selectWeekday(r, (s + SEGMENTS - 1) % SEGMENTS, true); }
						else if (event.key === 'ArrowDown') { event.preventDefault(); selectWeekday(Math.min(week.length - 1, r + 1), s, true); }
						else if (event.key === 'ArrowUp') { event.preventDefault(); if (r === 0) selectGolden(s, true); else selectWeekday(r - 1, s, true); }
						else if (event.key === 'Home') { event.preventDefault(); selectWeekday(r, 0, true); }
						else if (event.key === 'End') { event.preventDefault(); selectWeekday(r, SEGMENTS - 1, true); }
						else if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectWeekday(r, s, true); }
					});
					ring.appendChild(group);
				});
				ringGroups.push(ring);
				svg.appendChild(ring);
			});

			var hub = el('g', { class: 'dzn-clock__hub' });
			hub.appendChild(el('circle', { cx: CENTER, cy: CENTER, r: HUB_RADIUS, class: 'dzn-clock__hub-disc' }));
			hub.appendChild(el('text', { x: CENTER, y: CENTER - 8, class: 'dzn-clock__hub-time', 'text-anchor': 'middle' }, [labels.clock || '24h']));
			hub.appendChild(el('text', { x: CENTER, y: CENTER + 16, class: 'dzn-clock__hub-state', 'text-anchor': 'middle' }, [labels.select || '']));
			svg.appendChild(hub);
			canvas.appendChild(svg);

			svg.addEventListener('click', function (event) {
				var rect = svg.getBoundingClientRect();
				var x = (event.clientX - rect.left) / rect.width * SIZE - CENTER;
				var y = (event.clientY - rect.top) / rect.height * SIZE - CENTER;
				var radius = Math.sqrt(x * x + y * y);
				var angle = (Math.atan2(y, x) * 180 / Math.PI + 90 + 360) % 360;
				var index = Math.floor(angle / DEGREES) % SEGMENTS;
				if (radius >= GOLD_INNER && radius <= GOLD_OUTER) { selectGolden(index, true); return; }
				if (radius > HUB_RADIUS && radius <= RING_OUTER + 2) {
					var ringIndex = Math.floor((RING_OUTER - radius) / (RING_THICKNESS + RING_GAP));
					ringIndex = Math.max(0, Math.min(week.length - 1, ringIndex));
					selectWeekday(ringIndex, index, true);
				}
			});

			canvas.querySelectorAll('[data-dzn-ring]').forEach(function (group) {
				group.addEventListener('focus', function () {
					var r = Number(this.getAttribute('data-dzn-ring')); var s = Number(this.getAttribute('data-dzn-seg'));
					if (!state.golden && state.ring === r && state.segment === s) return;
					selectWeekday(r, s, false);
				});
			});
			canvas.querySelectorAll('[data-dzn-golden-seg]').forEach(function (group) {
				group.addEventListener('focus', function () {
					var s = Number(this.getAttribute('data-dzn-golden-seg'));
					if (state.golden && state.segment === s) return;
					selectGolden(s, false);
				});
			});
			syncTabindex(); paintSelection(); updateHub();
		}

		function pattern(id, width, height, builder) {
			var p = el('pattern', { id: id, width: width, height: height, patternUnits: 'userSpaceOnUse' });
			builder(p);
			return p;
		}

		root.querySelectorAll('[data-dzn-clock-ring]').forEach(function (button) {
			button.addEventListener('click', function () {
				var ringIndex = Number(button.getAttribute('data-dzn-clock-ring'));
				var day = dayFor(ringIndex);
				if (!day) return;
				var target = firstGapSegment(day);
				if (target === null) target = state.ring === ringIndex && state.segment !== null ? state.segment : 0;
				selectWeekday(ringIndex, target, true, gapRangeFor(day.weekday, target));
				revealDetails();
			});
		});

		root.querySelectorAll('[data-dzn-gap]').forEach(function (row) {
			row.addEventListener('click', function (event) {
				if (Number(row.getAttribute('data-dzn-gap-instrument')) !== Number(data.selected_instrument)) return;
				var weekday = Number(row.getAttribute('data-dzn-gap-weekday'));
				var ringIndex = week.findIndex(function (day) { return Number(day.weekday) === weekday; });
				if (ringIndex < 0) return;
				var indexes = String(row.getAttribute('data-dzn-gap-visual-indexes') || '').split(',').map(Number).filter(function (index) { return index >= 0; });
				var target = indexes.length ? indexes[0] : Number(row.getAttribute('data-dzn-gap-visual-start') || 0);
				if (!dayFor(ringIndex) || !dayFor(ringIndex).segments[target]) return;
				event.preventDefault();
				selectWeekday(ringIndex, target, true, indexes.length ? indexes : null);
				root.querySelectorAll('[data-dzn-clock-ring]').forEach(function (ringButton) {
					var active = Number(ringButton.getAttribute('data-dzn-clock-ring')) === ringIndex;
					ringButton.classList.toggle('is-active', active);
					ringButton.setAttribute('aria-pressed', active ? 'true' : 'false');
				});
				revealDetails();
			});
		});

		var form = document.querySelector('[data-dzn-availability-form]');
		if (form && root.hasAttribute('data-dzn-clock-teacher')) {
			var weekdayField = form.querySelector('[name="weekday"]');
			var startField = form.querySelector('[name="local_start_time"]');
			var endField = form.querySelector('[name="local_end_time"]');
			var parseTime = function (value) {
				if (!value || !/^\d{2}:\d{2}/.test(value)) return null;
				var parts = value.slice(0, 5).split(':');
				return Number(parts[0]) * 60 + Number(parts[1]);
			};
			var updateProposal = function () {
				var weekday = weekdayField ? Number(weekdayField.value) : 0;
				var start = startField ? parseTime(startField.value) : null;
				var end = endField ? parseTime(endField.value) : null;
				state.proposed = (weekday >= 1 && weekday <= 7 && start !== null && end !== null && end > start) ? { weekday: weekday, start: start, end: end } : null;
				paintProposed();
			};
			[weekdayField, startField, endField].forEach(function (field) { if (!field) return; field.addEventListener('input', updateProposal); field.addEventListener('change', updateProposal); });
			if (useButton) useButton.addEventListener('click', function () {
				var ringIndex = Number(useButton.getAttribute('data-dzn-clock-use-ring'));
				var segIndex = Number(useButton.getAttribute('data-dzn-clock-use-seg'));
				var day = dayFor(ringIndex);
				if (!day) return;
				var start = segIndex * 30;
				var lesson = Number(data.lesson_minutes || 60);
				var buffer = Number(data.buffer_minutes || 0);
				var end = Math.min(1439, start + Math.max(30, Math.ceil((lesson + buffer) / 30) * 30));
				if (weekdayField) weekdayField.value = String(day.weekday);
				if (startField) startField.value = minuteLabel(start);
				if (endField) endField.value = minuteLabel(end);
				var section = form.closest('.dzn-tp-availability-editor') || form;
				section.classList.add('is-clock-target');
				updateProposal();
				if (startField) startField.focus({ preventScroll: true });
			});
			updateProposal();
		}

		draw();
		clearDetails();
		var focusValue = String(data.focus || root.getAttribute('data-dzn-clock-focus') || '');
		var focusMatch = focusValue.match(/^([1-7]):(\d{1,2})$/);
		if (focusMatch) {
			var focusWeekday = Number(focusMatch[1]);
			var focusSegment = Number(focusMatch[2]);
			var focusRing = week.findIndex(function (day) { return Number(day.weekday) === focusWeekday; });
			if (focusRing >= 0 && dayFor(focusRing).segments[focusSegment]) {
				selectWeekday(focusRing, focusSegment, false, gapRangeFor(focusWeekday, focusSegment));
				root.querySelectorAll('[data-dzn-clock-ring]').forEach(function (button) {
					var active = Number(button.getAttribute('data-dzn-clock-ring')) === focusRing;
					button.classList.toggle('is-active', active);
					button.setAttribute('aria-pressed', active ? 'true' : 'false');
				});
			}
		}
		paintGapList();
	}

	document.querySelectorAll('[data-dzn-clock-root]').forEach(render);
})();
