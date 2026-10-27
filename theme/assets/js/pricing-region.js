(() => {
  'use strict';

  const config = window.dznThemePricing;
  const ledger = document.querySelector('[data-dzn-pricing]');

  if (!config || !ledger || !config.regions) return;

  const select = ledger.querySelector('[data-dzn-pricing-select]');
  const amount = ledger.querySelector('[data-dzn-pricing-amount]');
  const region = ledger.querySelector('[data-dzn-pricing-region]');
  const intro = ledger.querySelector('[data-dzn-pricing-intro]');
  const storageKey = String(config.storageKey || 'dzn-pricing-region');
  let manuallySelected = false;

  const storage = {
    get() { try { return window.localStorage.getItem(storageKey); } catch { return null; } },
    set(value) { try { window.localStorage.setItem(storageKey, value); } catch { /* Optional presentation preference. */ } },
  };

  const showNeutral = () => {
    if (amount) amount.textContent = 'واحد پول را انتخاب کنید';
    if (region) region.textContent = 'برای نمایش شهریه، یکی از واحدهای پول فعال را انتخاب کنید.';
    if (intro) intro.textContent = '';
  };

  const showRegion = (code) => {
    const selected = config.regions[code];
    if (!selected) { showNeutral(); return; }
    if (select) select.value = code;
    if (!selected.configured) {
      if (amount) amount.textContent = 'قیمت این منطقه در حال تنظیم است';
      if (region) region.textContent = 'برای مشاهدهٔ شهریه با ما تماس بگیرید.';
      if (intro) intro.textContent = '';
      return;
    }
    if (amount) amount.textContent = selected.displayPersian || selected.display;
    if (region) region.textContent = 'برای یک ترم، برای همهٔ دوره‌ها';
    if (intro) intro.textContent = selected.introductoryLabel || '';
  };

  const stored = storage.get();
  if (stored && config.regions[stored]) {
    manuallySelected = true;
    showRegion(stored);
  } else {
    showNeutral();
  }

  if (select) {
    const selected = String(select.value || '');
    select.innerHTML = '<option value="">واحد پول</option>';
    Object.keys(config.regions).forEach((code) => {
      const item = config.regions[code] || {};
      const option = document.createElement('option');
      option.value = code;
      option.textContent = String(item.currency || code) + (item.configured ? '' : ' — در حال تنظیم');
      select.appendChild(option);
    });
    if (selected && config.regions[selected]) select.value = selected;
  }

  if (select) select.addEventListener('change', () => {
    const code = select.value;
    manuallySelected = Boolean(code);
    if (!code || !config.regions[code]) { showNeutral(); return; }
    storage.set(code);
    showRegion(code);
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
      if (code && config.regions[code]) showRegion(code);
      else showNeutral();
    })
    .catch(() => {
      if (!manuallySelected) showNeutral();
    });
})();
