(() => {
  'use strict';

  const config = window.dznThemePricing;
  const ledger = document.querySelector('[data-dzn-pricing]');

  if (!config || !ledger || !config.regions) return;

  const select = ledger.querySelector('[data-dzn-pricing-select]');
  const amount = ledger.querySelector('[data-dzn-pricing-amount]');
  const region = ledger.querySelector('[data-dzn-pricing-region]');
  const status = ledger.querySelector('[data-dzn-pricing-status]');
  const storageKey = String(config.storageKey || 'dzn-pricing-region');
  let manuallySelected = false;

  const storage = {
    get() { try { return window.localStorage.getItem(storageKey); } catch { return null; } },
    set(value) { try { window.localStorage.setItem(storageKey, value); } catch { /* Optional presentation preference. */ } },
  };

  const showNeutral = (message) => {
    if (amount) amount.textContent = 'منطقهٔ خود را انتخاب کنید';
    if (region) region.textContent = 'برای نمایش شهریه، یکی از مناطق فعال را انتخاب کنید.';
    if (status) status.textContent = message;
  };

  const showRegion = (code, source) => {
    const selected = config.regions[code];
    if (!selected) { showNeutral('منطقه‌ای را از فهرست انتخاب کنید.'); return; }
    if (select) select.value = code;
    if (amount) amount.textContent = selected.displayPersian || selected.display;
    if (region) region.textContent = 'برای یک ترم، برای همهٔ دوره‌ها';
    if (status) status.textContent = source === 'manual'
      ? 'انتخاب شما در این دستگاه ذخیره شد.'
      : 'این منطقه فقط بر اساس موقعیت تقریبی پیشنهاد شده است؛ می‌توانید آن را تغییر دهید.';
  };

  const stored = storage.get();
  if (stored && config.regions[stored]) {
    manuallySelected = true;
    showRegion(stored, 'manual');
  } else {
    showNeutral('منطقهٔ قیمت‌گذاری را انتخاب کنید.');
  }

  if (select) select.addEventListener('change', () => {
    const code = select.value;
    manuallySelected = Boolean(code);
    if (!code || !config.regions[code]) { showNeutral('منطقهٔ قیمت‌گذاری را انتخاب کنید.'); return; }
    storage.set(code);
    showRegion(code, 'manual');
  });

  if (manuallySelected || !config.detectUrl || typeof window.fetch !== 'function') return;

  window.fetch(config.detectUrl, {
    cache: 'no-store',
    credentials: 'omit',
    headers: { Accept: 'application/json' },
  })
    .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Location lookup unavailable'))))
    .then((payload) => {
      if (manuallySelected) return;
      const country = String(payload && payload.country_code ? payload.country_code : '').toUpperCase();
      const code = config.countryToRegion && config.countryToRegion[country];
      if (code && config.regions[code]) showRegion(code, 'suggested');
      else showNeutral('موقعیت شما به‌صورت خودکار پشتیبانی نمی‌شود؛ منطقهٔ خود را انتخاب کنید.');
    })
    .catch(() => {
      if (!manuallySelected) showNeutral('موقعیت شما تعیین نشد؛ منطقهٔ خود را انتخاب کنید.');
    });
})();
