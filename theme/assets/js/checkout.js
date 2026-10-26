(function () {
	'use strict';

	const root = document.querySelector('[data-dzn-student-checkout]');
	const config = window.dznStudentCheckout;
	if (!root || !config || typeof config.apiRoot !== 'string' || typeof config.nonce !== 'string') return;

	const status = root.querySelector('[data-checkout-status]');
	const list = root.querySelector('[data-checkout-list]');
	const apiRoot = config.apiRoot.replace(/\/$/, '') + '/';
	const labels = {
		pay: 'پرداخت', continue: 'ادامهٔ پرداخت', resume: 'بررسی وضعیت پرداخت', retry: 'تلاش دوباره'
	};
	const messages = {
		paid: 'این قسط پرداخت شده است.',
		processing: 'وضعیت پرداخت در حال بررسی است. لطفاً چند لحظه دیگر دوباره بررسی کنید.',
		unavailable: 'اطلاعات پرداخت اکنون در دسترس نیست.',
		pending: 'درخواست پرداخت ثبت شده و در حال بررسی است.',
		cancel: 'پرداخت لغو شد. برای شروع دوباره، وضعیت قسط را بررسی کنید.',
		return: 'در حال بررسی نتیجهٔ پرداخت…',
		empty: 'قسط قابل پرداختی برای نمایش وجود ندارد.'
	};

	function say(message) { status.textContent = message; }
	function api(path, options) {
		const request = Object.assign({ credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' } }, options || {});
		return fetch(apiRoot + path, request).then(function (response) {
			if (!response.ok) throw new Error('checkout_unavailable');
			return response.json();
		});
	}
	function formatAmount(minor, currencyCode) {
		try {
			const currency = String(currencyCode || '');
			const digits = new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).resolvedOptions().maximumFractionDigits;
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(Number(minor) / Math.pow(10, digits));
		} catch (error) {
			return String(minor) + ' ' + String(currencyCode || '');
		}
	}
	function amount(row) {
		return formatAmount(row.amount_minor, row.currency);
	}
	function addText(parent, tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		node.textContent = text;
		parent.appendChild(node);
		return node;
	}
	function faDigits(value) {
		return String(value).replace(/[0-9]/g, function (digit) { return '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]; });
	}
	function planLabel(plan) {
		if (plan === 'full') return 'پرداخت کامل';
		if (plan === 'two_instalments') return 'پرداخت در دو قسط';
		return 'طرح پرداخت';
	}
	function instalmentLabel(row, plan) {
		if (plan === 'full') return 'پرداخت کامل';
		const sequence = Number(row.obligation_sequence);
		return 'قسط ' + (Number.isFinite(sequence) && sequence > 0 ? faDigits(sequence) : '');
	}
	function sessionsLabel(row) {
		const from = Number(row.sessions_from);
		const to = Number(row.sessions_to);
		if (!Number.isFinite(from) || !Number.isFinite(to) || from < 1 || to < from) return '';
		return from === to ? 'جلسهٔ ' + faDigits(from) : 'جلسات ' + faDigits(from) + ' تا ' + faDigits(to);
	}
	function offerLapsed(offer) {
		if (String(offer.offer_state || '') !== 'issued') return false;
		const expires = String(offer.offer_expires_at || '');
		if (!expires) return false;
		const at = new Date(expires.replace(' ', 'T') + 'Z');
		return !isNaN(at.getTime()) && at.getTime() <= Date.now();
	}
	function dueLabel(row, plan, lapsed) {
		if (row.due_at) {
			const date = new Date(String(row.due_at).replace(' ', 'T') + 'Z');
			if (!isNaN(date.getTime())) {
				try {
					return 'سررسید: ' + new Intl.DateTimeFormat('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' }).format(date);
				} catch (error) {
					return 'سررسید: ' + String(row.due_at);
				}
			}
		}
		if (lapsed) return 'طرح منقضی شده';
		if (plan === 'two_instalments' && Number(row.obligation_sequence) === 2) return 'سررسید: پیش از شروع جلسهٔ ۷';
		return 'سررسید: تعیین نشده';
	}
	function renderPlan(group) {
		const first = group.rows[0];
		const plan = String(first.plan_kind || '');
		const lapsed = offerLapsed(first);
		const section = document.createElement('section');
		section.className = 'dzn-checkout__plan';
		const heading = document.createElement('div');
		heading.className = 'dzn-checkout__plan-heading';
		addText(heading, 'h3', 'dzn-checkout__plan-title', planLabel(plan));
		addText(heading, 'span', 'dzn-checkout__plan-total', formatAmount(first.offer_amount_due_minor, first.offer_currency));
		section.appendChild(heading);
		const meta = [];
		if (Number(first.committed_sessions) > 0) meta.push('تعداد جلسات: ' + faDigits(first.committed_sessions));
		if (first.offer_reference) meta.push('کد طرح: ' + String(first.offer_reference));
		if (meta.length) addText(section, 'p', 'dzn-checkout__plan-meta', meta.join(' · '));
		group.rows.forEach(function (row) {
			const card = document.createElement('article');
			card.className = 'dzn-checkout__row';
			addText(card, 'strong', 'dzn-checkout__title', instalmentLabel(row, plan));
			addText(card, 'span', 'dzn-checkout__amount', amount(row));
			const sessions = sessionsLabel(row);
			const stateMessage = row.payment_state === 'paid' ? messages.paid : row.payment_state === 'processing' ? messages.processing : row.payment_state === 'required' ? 'پرداخت این قسط ثبت نشده است.' : (lapsed ? 'این طرح منقضی شده است.' : messages.unavailable);
			addText(card, 'p', 'dzn-checkout__row-detail', [sessions, dueLabel(row, plan, lapsed)].filter(Boolean).join(' · '));
			addText(card, 'p', 'dzn-checkout__row-status', stateMessage);
			if (Object.prototype.hasOwnProperty.call(labels, row.action)) {
				const button = addText(card, 'button', 'dzn-checkout__button', labels[row.action]);
				button.type = 'button';
				button.addEventListener('click', function () { start(row, button); });
			}
			section.appendChild(card);
		});
		return section;
	}
	function loadStatus(attempt, kind) {
		if (!/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/.test(attempt)) { say(messages.unavailable); return; }
		say(kind === 'cancel' ? messages.cancel : messages.return);
		api('student/checkout-status?attempt_uid=' + encodeURIComponent(attempt)).then(function (data) {
			if (data.payment_state === 'paid') say(messages.paid);
			else if (data.payment_state === 'processing' || data.checkout_state === 'creating' || data.checkout_state === 'completed') say(messages.processing);
			else if (data.action === 'retry') say(kind === 'cancel' ? messages.cancel : 'پرداخت تکمیل نشد. می‌توانید دوباره تلاش کنید.');
			else if (data.payment_state === 'required') say('پرداخت هنوز ثبت نشده است. وضعیت قسط را از فهرست بررسی کنید.');
			else say(messages.unavailable);
		}).catch(function () { say(messages.unavailable); });
	}
	function render(rows) {
		list.replaceChildren();
		if (!Array.isArray(rows) || rows.length === 0) { say(messages.empty); return; }
		const groups = [];
		const byOffer = Object.create(null);
		rows.forEach(function (row) {
			const key = String(row.offer_uid || '');
			if (!byOffer[key]) { byOffer[key] = { uid: key, rows: [] }; groups.push(byOffer[key]); }
			byOffer[key].rows.push(row);
		});
		let payable = false;
		groups.forEach(function (group) {
			list.appendChild(renderPlan(group));
			group.rows.forEach(function (row) {
				if (Object.prototype.hasOwnProperty.call(labels, row.action)) payable = true;
			});
		});
		if (payable) say('برای پرداخت، قسط موردنظر را انتخاب کنید.');
		else if (rows.some(function (row) { return row.payment_state === 'processing'; })) say(messages.processing);
		else say('وضعیت اقساط در بالا نمایش داده شده است.');
	}
	function start(row, button) {
		if (!/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/.test(String(row.obligation_uid || ''))) { say(messages.unavailable); return; }
		button.disabled = true;
		say('در حال آماده‌سازی پرداخت…');
		api('student/checkout', { method: 'POST', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ obligation_uid: row.obligation_uid }) })
			.then(function (data) {
				if (data.checkout_state === 'open' && typeof data.redirect_url === 'string' && /^https:\/\/checkout\.stripe\.com\//.test(data.redirect_url)) {
					window.location.assign(data.redirect_url);
					return;
				}
				if (data.checkout_state === 'pending' || data.checkout_state === 'creating') say(messages.pending);
				else say(messages.unavailable);
				button.disabled = false;
			}).catch(function () { say(messages.unavailable); button.disabled = false; });
	}

	const query = new URLSearchParams(window.location.search);
	const returnKind = query.get('checkout');
	const attempt = query.get('attempt');
	if ((returnKind === 'return' || returnKind === 'cancel') && attempt) loadStatus(attempt, returnKind);
	api('student/commercial-checkout').then(function (data) {
		render(data.obligations);
		if ((returnKind === 'return' || returnKind === 'cancel') && attempt) loadStatus(attempt, returnKind);
	}).catch(function () { say(messages.unavailable); });
}());
