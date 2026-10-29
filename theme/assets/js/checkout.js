(function () {
	'use strict';

	const root = document.querySelector('[data-dzn-student-checkout]');
	const config = window.dznStudentCheckout;
	if (!root || !config || typeof config.apiRoot !== 'string' || typeof config.nonce !== 'string') return;

	const status = root.querySelector('[data-checkout-status]');
	const list = root.querySelector('[data-checkout-list]');
	const apiRoot = config.apiRoot.replace(/\/$/, '') + '/';

	const promotionCodeInput = root.querySelector('#dzn-promotion-code');
	const promotionApplyBtn = root.querySelector('#dzn-promotion-apply');
	const promotionStatus = root.querySelector('[data-promotion-status]');
	let promotionBusy = false;
	let lastObligations = null;
	const labels = {
		pay: 'پرداخت', continue: 'ادامهٔ پرداخت', resume: 'بررسی وضعیت پرداخت', retry: 'تلاش دوباره'
	};
	const promotionMessages = {
		promotion_not_found: 'این کد تخفیف معتبر نیست.',
		promotion_inactive: 'این کد تخفیف غیرفعال است.',
		promotion_expired: 'مهلت استفاده از این کد تخفیف به پایان رسیده است.',
		promotion_not_yet_valid: 'این کد تخفیف هنوز فعال نشده است.',
		promotion_usage_limit_reached: 'ظرفیت استفاده از این کد تخفیف تکمیل شده است.',
		promotion_beneficiary_limit_reached: 'این کد تخفیف قبلاً برای حساب شما استفاده شده است.',
		promotion_first_term_only: 'این کد تخفیف فقط برای ترم اول قابل استفاده است.',
		promotion_product_mismatch: 'این کد تخفیف برای دورهٔ شما معتبر نیست.',
		promotion_currency_mismatch: 'این کد تخفیف با واحد پول پرداخت شما سازگار نیست.',
		promotion_discount_too_small: 'مبلغ این تخفیف برای پرداخت شما قابل اعمال نیست.',
		promotion_discount_not_applicable: 'این کد تخفیف برای مبلغ قابل پرداخت شما قابل اعمال نیست.',
		promotion_already_applied: 'برای این طرح از قبل یک کد تخفیف اعمال شده است.',
		promotion_offer_already_paid: 'این طرح پرداخت شده و تغییر آن ممکن نیست.',
		promotion_offer_expired: 'زمان این طرح پرداخت به پایان رسیده است.',
		promotion_offer_superseded: 'وضعیت پرداخت تغییر کرده است. صفحه در حال به‌روزرسانی است.',
		promotion_offer_unavailable: 'این طرح در حال حاضر قابل پرداخت نیست.',
		promotion_rate_limited: 'تعداد تلاش‌ها زیاد است. لطفاً چند دقیقه دیگر دوباره تلاش کنید.',
		promotion_code_required: 'کد تخفیف را وارد کنید.',
		promotion_code_invalid: 'کد تخفیف واردشده معتبر نیست.',
		promotion_unavailable: 'اعمال کد تخفیف اکنون ممکن نیست. لطفاً دوباره تلاش کنید.'
	};
	const messages = {
		paid: 'پرداخت ثبت شده است.',
		processing: 'وضعیت پرداخت در حال بررسی است. لطفاً چند لحظه دیگر دوباره بررسی کنید.',
		unavailable: 'اطلاعات پرداخت اکنون در دسترس نیست.',
		pending: 'درخواست پرداخت ثبت شده و در حال بررسی است.',
		cancel: 'پرداخت لغو شد. برای شروع دوباره، وضعیت قسط را بررسی کنید.',
		return: 'در حال بررسی نتیجهٔ پرداخت…',
		empty: 'طرح پرداخت قابل نمایش وجود ندارد.'
	};

	function say(message) { status.textContent = message; }
	function api(path, options) {
		const request = Object.assign({ credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' } }, options || {});
		return fetch(apiRoot + path, request).then(function (response) {
			if (!response.ok) {
				return response.json().catch(function () { return {}; }).then(function (payload) {
					const error = new Error('checkout_unavailable');
					error.payload = payload || {};
					throw error;
				});
			}
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
	function termLabel(row) {
		const term = Number(row.term_sequence);
		return Number.isFinite(term) && term > 0 ? 'ترم ' + faDigits(term) : 'ترم';
	}
	function planLabel(row, plan) {
		const term = termLabel(row);
		if (plan === 'full') return term + ' · پرداخت کامل';
		if (plan === 'two_instalments') return term + ' · پرداخت در دو قسط';
		return term + ' · طرح پرداخت';
	}
	function instalmentLabel(row, plan) {
		if (plan === 'full') return 'پرداخت کامل ترم';
		const sequence = Number(row.obligation_sequence);
		return 'قسط ' + (Number.isFinite(sequence) && sequence > 0 ? faDigits(sequence) : '') + ' از ۲';
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
		return '';
	}
	function stateLabel(row, plan, lapsed) {
		const term = termLabel(row);
		if (row.payment_state === 'paid') {
			if (row.term_paid) return term + ' پرداخت شد.';
			return 'قسط ' + faDigits(row.obligation_sequence) + ' از ۲ پرداخت شد.';
		}
		if (row.payment_state === 'paid_prerequisite_pending') return 'پرداخت ثبت شد؛ پس از پرداخت قسط ۱ فعال می‌شود.';
		if (row.payment_state === 'prerequisite_pending') return 'پس از پرداخت قسط ۱ قابل پرداخت است.';
		if (row.payment_state === 'processing') return messages.processing;
		if (row.payment_state === 'required') return plan === 'full' ? 'پرداخت ترم ثبت نشده است.' : 'پرداخت این قسط ثبت نشده است.';
		if (lapsed) return 'این طرح منقضی شده است.';
		return messages.unavailable;
	}
	function renderPlan(group) {
		const first = group.rows[0];
		const plan = String(first.plan_kind || '');
		const lapsed = offerLapsed(first);
		const section = document.createElement('section');
		section.className = 'dzn-checkout__plan';
		const heading = document.createElement('div');
		heading.className = 'dzn-checkout__plan-heading';
		addText(heading, 'h3', 'dzn-checkout__plan-title', planLabel(first, plan));
		const currency = first.offer_currency || first.currency;
		const base = Number(first.offer_base_amount_minor);
		const discount = Number(first.offer_discount_total_minor || 0);
		const total = Number(first.offer_amount_due_minor);
		if (Number.isFinite(base) && Number.isFinite(discount) && discount > 0) {
			addText(heading, 'span', 'dzn-checkout__plan-original', 'مبلغ اصلی: ' + formatAmount(base, currency));
		}
		addText(heading, 'span', 'dzn-checkout__plan-total', formatAmount(total, currency));
		if (Number.isFinite(discount) && discount > 0) {
			const saving = document.createElement('span');
			saving.className = 'dzn-checkout__plan-saving';
			const percentage = first.offer_promotion_percentage;
			saving.textContent = 'تخفیف: ' + formatAmount(discount, currency) + (percentage !== null && percentage !== undefined ? ' (' + faDigits(percentage) + '٪)' : '');
			heading.appendChild(saving);
		}
		section.appendChild(heading);
		const meta = [];
		if (Number(first.committed_sessions) > 0) meta.push('تعداد جلسات: ' + faDigits(first.committed_sessions));
		if (first.offer_reference) meta.push('کد طرح: ' + String(first.offer_reference));
		if (Number(first.outstanding_minor) > 0) meta.push('مانده: ' + formatAmount(first.outstanding_minor, first.offer_currency));
		if (meta.length) addText(section, 'p', 'dzn-checkout__plan-meta', meta.join(' · '));
		group.rows.forEach(function (row) {
			const card = document.createElement('article');
			card.className = 'dzn-checkout__row';
			addText(card, 'strong', 'dzn-checkout__title', instalmentLabel(row, plan));
			addText(card, 'span', 'dzn-checkout__amount', amount(row));
			const sessions = sessionsLabel(row);
			addText(card, 'p', 'dzn-checkout__row-detail', [sessions, dueLabel(row, plan, lapsed)].filter(Boolean).join(' · '));
			addText(card, 'p', 'dzn-checkout__row-status', stateLabel(row, plan, lapsed));
			if (Object.prototype.hasOwnProperty.call(labels, row.action)) {
				const button = addText(card, 'button', 'dzn-checkout__button', labels[row.action]);
				button.type = 'button';
				button.addEventListener('click', function () { start(row, button); });
			}
			section.appendChild(card);
		});
		if (first.term_paid) {
			addText(section, 'p', 'dzn-checkout__next-term', 'پرداخت ترم بعد: با ثبت پرداخت کامل ترم جدید.');
		}
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
		if (payable) say('برای پرداخت، گزینهٔ موردنظر را انتخاب کنید.');
		else if (rows.some(function (row) { return row.payment_state === 'processing'; })) say(messages.processing);
		else say('وضعیت پرداخت ترم در بالا نمایش داده شده است.');
		syncPromotionUi(rows);
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

	function payableRow(rows) {
		return (rows || []).find(function (row) {
			return Object.prototype.hasOwnProperty.call(labels, row.action);
		}) || null;
	}
	function promotionAppliedRow(rows) {
		const payable = payableRow(rows);
		return (rows || []).find(function (row) {
			if (!row.offer_has_promotion) return false;
			return !payable || String(row.offer_uid || '') === String(payable.offer_uid || '');
		}) || null;
	}
	function promotionSummary(row) {
		if (!row) return '';
		const currency = row.offer_currency || row.currency;
		const discount = Number(row.offer_promotion_amount_minor || row.offer_discount_total_minor || 0);
		return 'مبلغ اصلی: ' + formatAmount(row.offer_base_amount_minor, currency)
			+ ' · تخفیف: ' + formatAmount(discount, currency)
			+ ' · مبلغ قابل پرداخت: ' + formatAmount(row.offer_amount_due_minor, currency);
	}
	function syncPromotionUi(rows) {
		if (!promotionCodeInput || !promotionApplyBtn || !promotionStatus) return;
		const applied = promotionAppliedRow(rows);
		const payable = payableRow(rows);
		if (applied) {
			promotionCodeInput.disabled = true;
			promotionApplyBtn.disabled = true;
			promotionApplyBtn.textContent = 'اعمال شده';
			promotionStatus.textContent = 'کد تخفیف اعمال شده است. ' + promotionSummary(applied);
			return;
		}
		promotionCodeInput.disabled = false;
		promotionApplyBtn.textContent = 'اعمال';
		promotionApplyBtn.disabled = promotionBusy || !payable;
		if (!payable) {
			promotionStatus.textContent = 'طرح قابل پرداختی برای اعمال کد تخفیف وجود ندارد.';
		}
	}
	function promotionErrorMessage(error) {
		const reason = error && error.payload && error.payload.reason ? String(error.payload.reason) : '';
		return promotionMessages[reason] || 'اعمال کد تخفیف ممکن نیست. لطفاً دوباره تلاش کنید.';
	}
	function refreshPayments() {
		return api('student/commercial-checkout').then(function (data) {
			lastObligations = data.obligations || [];
			render(lastObligations);
			syncPromotionUi(lastObligations);
			return lastObligations;
		});
	}
	function applyPromotion() {
		if (!promotionCodeInput || !promotionApplyBtn || !promotionStatus || promotionBusy) return;
		const row = payableRow(lastObligations || []);
		if (!row) {
			promotionStatus.textContent = 'طرح قابل پرداختی برای اعمال کد تخفیف وجود ندارد.';
			return;
		}
		const code = String(promotionCodeInput.value || '').trim();
		if (code === '') {
			promotionStatus.textContent = promotionMessages.promotion_code_required;
			promotionCodeInput.focus();
			return;
		}
		if (code.length > 64) {
			promotionStatus.textContent = promotionMessages.promotion_code_invalid;
			return;
		}
		promotionBusy = true;
		promotionApplyBtn.disabled = true;
		promotionCodeInput.disabled = true;
		promotionStatus.setAttribute('aria-busy', 'true');
		promotionStatus.textContent = 'در حال بررسی و اعمال کد تخفیف…';
		api('commercial/promotion/apply', {
			method: 'POST',
			headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify({ obligation_uid: row.obligation_uid, promotion_code: code }),
		}).then(function (data) {
			if (!data || data.applied !== true) {
				const refusal = new Error('promotion_refused');
				refusal.payload = data || {};
				throw refusal;
			}
			promotionStatus.textContent = 'کد تخفیف با موفقیت اعمال شد. '
				+ 'مبلغ اصلی: ' + formatAmount(data.original_amount_minor, data.currency)
				+ ' · تخفیف: ' + formatAmount(data.discount_total_minor, data.currency)
				+ ' · مبلغ قابل پرداخت: ' + formatAmount(data.final_amount_minor, data.currency);
			promotionCodeInput.value = code;
			promotionBusy = false;
			promotionStatus.removeAttribute('aria-busy');
			// The apply response is already authoritative; the refresh aligns the full obligation
			// list, and every Checkout click re-reads the server amount again.
			refreshPayments().catch(function () {
				promotionStatus.textContent = 'کد تخفیف اعمال شد. برای نمایش جزئیات، صفحه را دوباره بارگذاری کنید.';
			});
		}).catch(function (error) {
			promotionBusy = false;
			promotionStatus.removeAttribute('aria-busy');
			promotionStatus.textContent = promotionErrorMessage(error);
			promotionCodeInput.disabled = false;
			promotionApplyBtn.disabled = false;
			if (error && error.payload && error.payload.refresh_required) {
				refreshPayments().catch(function () {});
			}
		});
	}

	if (promotionApplyBtn) {
		promotionApplyBtn.addEventListener('click', applyPromotion);
	}
	if (promotionCodeInput) {
		promotionCodeInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				applyPromotion();
			}
		});
	}

	const query = new URLSearchParams(window.location.search);
	const returnKind = query.get('checkout');
	const attempt = query.get('attempt');
	if ((returnKind === 'return' || returnKind === 'cancel') && attempt) loadStatus(attempt, returnKind);
	api('student/commercial-checkout').then(function (data) {
		lastObligations = data.obligations || [];
		render(lastObligations);
		if ((returnKind === 'return' || returnKind === 'cancel') && attempt) loadStatus(attempt, returnKind);
	}).catch(function () { say(messages.unavailable); });
}());
