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
	function amount(row) {
		try {
			const currency = String(row.currency || '');
			const digits = new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).resolvedOptions().maximumFractionDigits;
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(Number(row.amount_minor) / Math.pow(10, digits));
		} catch (error) {
			return String(row.amount_minor) + ' ' + String(row.currency || '');
		}
	}
	function addText(parent, tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		node.textContent = text;
		parent.appendChild(node);
		return node;
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
		let payable = false;
		rows.forEach(function (row) {
			const card = document.createElement('article');
			card.className = 'dzn-checkout__row';
			const sequence = Number(row.obligation_sequence);
			addText(card, 'strong', 'dzn-checkout__title', 'قسط ' + (Number.isFinite(sequence) && sequence > 0 ? sequence : ''));
			addText(card, 'span', 'dzn-checkout__amount', amount(row));
			const stateMessage = row.payment_state === 'paid' ? messages.paid : row.payment_state === 'processing' ? messages.processing : row.payment_state === 'required' ? 'پرداخت این قسط ثبت نشده است.' : messages.unavailable;
			addText(card, 'p', 'dzn-checkout__row-status', stateMessage);
			if (Object.prototype.hasOwnProperty.call(labels, row.action)) {
				payable = true;
				const button = addText(card, 'button', 'dzn-checkout__button', labels[row.action]);
				button.type = 'button';
				button.addEventListener('click', function () { start(row, button); });
			}
			list.appendChild(card);
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
