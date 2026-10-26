(() => {
  'use strict';

  const config = window.dznStudentCheckout;
  if (!config || typeof config.apiRoot !== 'string' || typeof config.nonce !== 'string') return;

  const apiRoot = config.apiRoot.replace(/\/$/, '') + '/';
  const buttons = Array.from(document.querySelectorAll('[data-dzn-intro-checkout]'));
  const statusNodes = Array.from(document.querySelectorAll('[data-dzn-intro-status]'));

  const say = (message) => { statusNodes.forEach((node) => { node.textContent = message; }); };
  const api = (path, options) => {
    const request = Object.assign({ credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' } }, options || {});
    return fetch(apiRoot + path, request).then((response) => {
      if (!response.ok) throw new Error('checkout_unavailable');
      return response.json();
    });
  };

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const uid = String(button.dataset.obligation || '');
      if (!/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/.test(uid)) { say('اطلاعات پرداخت در دسترس نیست.'); return; }
      button.disabled = true;
      say('در حال آماده‌سازی پرداخت…');
      api('student/intro-checkout', {
        method: 'POST',
        headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ intro_obligation_uid: uid }),
      }).then((data) => {
        if (data.checkout_state === 'open' && typeof data.redirect_url === 'string' && /^https:\/\/checkout\.stripe\.com\//.test(data.redirect_url)) {
          window.location.assign(data.redirect_url);
          return;
        }
        say(data.checkout_state === 'pending' ? 'پرداخت در حال آماده‌سازی است. کمی بعد دوباره تلاش کنید.' : 'پرداخت اکنون در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
        button.disabled = false;
      }).catch(() => {
        say('پرداخت اکنون در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.');
        button.disabled = false;
      });
    });
  });

  const query = new URLSearchParams(window.location.search);
  const kind = query.get('checkout');
  const attempt = query.get('attempt');
  if ((kind === 'return' || kind === 'cancel') && attempt && /^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/.test(attempt)) {
    if (kind === 'cancel') say('پرداخت لغو شد. برای شروع دوباره، دوباره تلاش کنید.');
    else say('در حال بررسی نتیجهٔ پرداخت…');
    api('student/intro-checkout-status?attempt_uid=' + encodeURIComponent(attempt)).then((data) => {
      if (data.payment_state === 'paid') say('پرداخت جلسهٔ معارفه تأیید شد. زمان‌بندی و پیوند کلاس پس از ساخت تقویم نمایش داده می‌شود.');
      else if (data.payment_state === 'refunded') say('این پرداخت بازپرداخت شده است.');
      else if (data.action === 'retry') say('پرداخت تکمیل نشد. می‌توانید دوباره تلاش کنید.');
      else if (data.payment_state === 'processing') say('پرداخت در حال بررسی است. لطفاً کمی بعد دوباره بررسی کنید.');
      else say('پرداخت هنوز تکمیل نشده است.');
    }).catch(() => say('وضعیت پرداخت اکنون در دسترس نیست.'));
  }
})();
