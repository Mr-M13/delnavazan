(() => {
  'use strict';
  const openers = new WeakMap();
  const close = (dialog) => {
    if (!dialog) return;
    const opener = openers.get(dialog);
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
    dialog.removeAttribute('data-dzn-tp-fallback');
    if (opener && typeof opener.focus === 'function') opener.focus();
    openers.delete(dialog);
  };
  document.querySelectorAll('[data-dzn-tp-open]').forEach((button) => button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.dznTpOpen);
    if (!dialog) return;
    openers.set(dialog, button);
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else { dialog.dataset.dznTpFallback = 'disclosure'; dialog.setAttribute('open', ''); }
  }));
  document.querySelectorAll('.dzn-tp-dialog').forEach((dialog) => {
    dialog.querySelector('[data-dzn-tp-close]')?.addEventListener('click', () => close(dialog));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(dialog); });
    dialog.addEventListener('close', () => { const opener = openers.get(dialog); if (opener) opener.focus(); openers.delete(dialog); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(dialog); });
  });
  document.querySelectorAll('[data-dzn-tp-toggle]').forEach((button) => button.addEventListener('click', () => {
    const panel = document.getElementById(button.dataset.dznTpToggle);
    if (!panel) return;
    panel.hidden = !panel.hidden;
    button.setAttribute('aria-expanded', String(!panel.hidden));
    const label = panel.hidden ? button.dataset.dznTpLabelClosed : button.dataset.dznTpLabelOpen;
    // Icon-only disclosure controls expose their state through aria-label; text controls keep
    // their visible label. Neither path ever changes the submitted form fields.
    if (label && button.querySelector('.dzn-tp-chevron__icon')) button.setAttribute('aria-label', label);
    else if (label) button.textContent = label;
  }));
  // Real backend-authorised forms: show progress and block double submission. The server remains the only authority;
  // this changes presentation only and never alters the submitted fields.
  const busyForms = document.querySelectorAll('form[data-dzn-tp-submit]');
  const scopeButtons = (form) => Array.from((form.closest('dialog, article') || form).querySelectorAll('button[type="submit"]'));
  const restore = (form) => {
    scopeButtons(form).forEach((button) => {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      if (button.dataset.dznTpLabel) button.textContent = button.dataset.dznTpLabel;
    });
    const status = (form.closest('dialog, article') || form).querySelector('.dzn-tp-action-status');
    if (status) status.textContent = '';
  };
  busyForms.forEach((form) => form.addEventListener('submit', () => {
    const submitter = form.querySelector('button[type="submit"]');
    const label = submitter && submitter.dataset.dznTpBusyLabel;
    const scope = form.closest('dialog, article') || form;
    const status = scope.querySelector('.dzn-tp-action-status');
    if (submitter && label) {
      submitter.dataset.dznTpLabel = submitter.textContent;
      submitter.textContent = label;
      submitter.setAttribute('aria-busy', 'true');
    }
    if (status && label) status.textContent = label;
    // Disable after the browser has captured the submission so the request is never dropped.
    window.setTimeout(() => scopeButtons(form).forEach((button) => { button.disabled = true; }), 0);
    const delay = parseInt(form.dataset.dznTpReenable || '', 10);
    if (delay > 0) window.setTimeout(() => restore(form), delay);
  }));
  // Returning via the back button restores a cached page; never leave a form stuck in its busy state.
  window.addEventListener('pageshow', (event) => { if (event.persisted) busyForms.forEach(restore); });
  const tour = document.querySelector('[data-dzn-tp-tour-auto="1"]');
  if (tour) {
    const key = 'dznTeacherPortalTourV1';
    let seen = false;
    try { seen = window.localStorage.getItem(key) === 'seen'; } catch (e) {}
    if (!seen) {
      try { window.localStorage.setItem(key, 'seen'); } catch (e) {}
      if (typeof tour.showModal === 'function') tour.showModal();
      else { tour.dataset.dznTpFallback = 'disclosure'; tour.setAttribute('open', ''); }
    }
  }
  document.querySelectorAll('.dzn-tp-instrument input[type="checkbox"]').forEach((input) => {
    input.addEventListener('change', () => {
      const card = input.closest('.dzn-tp-instrument');
      if (card) card.classList.toggle('is-selected', input.checked);
    });
  });
  document.querySelectorAll('[data-dzn-tp-presentation]').forEach((button) => button.addEventListener('click', () => {
    const scope = button.closest('section, dialog') || document;
    const status = scope.querySelector('.dzn-tp-action-status');
    if (status) status.textContent = 'این کنترل نمایشی است؛ هیچ اطلاعات یا حقیقتی ثبت نشد.';
  }));
  // Safe induction knowledge checks. Nothing is submitted or persisted; the server remains the
  // only authority for real scheduling, absence and finance actions.
  document.querySelectorAll('[data-dzn-induction-check]').forEach((button) => {
    button.addEventListener('click', () => {
      const fieldset = button.closest('[data-dzn-induction-question]');
      if (!fieldset) return;
      const answer = parseInt(fieldset.dataset.answer || '', 10);
      const selected = fieldset.querySelector('input[type="radio"]:checked');
      const output = fieldset.querySelector('.dzn-tp-induction-answer');
      if (!output) return;
      if (!selected) {
        output.textContent = 'یک گزینه را انتخاب کنید.';
        return;
      }
      const correct = parseInt(selected.value, 10) === answer;
      output.textContent = (correct ? 'درست است. ' : 'پاسخ درست نیست. ') + (fieldset.dataset.explanation || '');
    });
  });
  // The induction module highlights an existing navigation element. It never fabricates a tab.
  document.querySelectorAll('[data-dzn-induction-highlight]').forEach((button) => {
    button.addEventListener('click', () => {
      const selector = button.dataset.dznInductionHighlight || '';
      let candidates = [];
      try { candidates = selector ? Array.from(document.querySelectorAll(selector)) : []; } catch (e) { candidates = []; }
      // Prefer the visible copy of a navigation target: desktop and mobile navigation can both
      // carry the same selector, and only one is rendered at a time.
      const target = candidates.find((element) => element.getClientRects().length > 0) || candidates[0] || null;
      const status = button.parentElement?.querySelector('.dzn-tp-induction-highlight-status');
      if (!target) {
        if (status) status.textContent = 'این بخش در صفحهٔ فعلی قابل نمایش نیست؛ از فهرست بخش‌ها باز کنید.';
        return;
      }
      target.classList.add('is-dzn-induction-highlight');
      if (status) status.textContent = 'بخش مرتبط در ناوبری مشخص شد.';
      if (typeof target.scrollIntoView === 'function') target.scrollIntoView({ block: 'center', behavior: 'smooth' });
      window.setTimeout(() => target.classList.remove('is-dzn-induction-highlight'), 4000);
    });
  });
  // The compact dashboard card is optional after the first days. The full induction screen stays
  // reachable from Settings and from the card's own links.
  document.querySelectorAll('[data-dzn-tp-induction-skip]').forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('.dzn-tp-induction-entry');
      if (!card) return;
      const now = new Date();
      const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
      try { window.localStorage.setItem('dznInductionCardSkipped', today); } catch (e) {}
      card.hidden = true;
    });
  });
  document.querySelectorAll('.dzn-tp-induction-entry').forEach((card) => {
    const now = new Date();
    const today = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
    let skipped = '';
    try { skipped = window.localStorage.getItem('dznInductionCardSkipped') || ''; } catch (e) {}
    if (skipped === today) card.hidden = true;
  });
})();
