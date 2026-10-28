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
  document.querySelectorAll('[data-dzn-tp-presentation]').forEach((button) => button.addEventListener('click', () => {
    const scope = button.closest('section, dialog') || document;
    const status = scope.querySelector('.dzn-tp-action-status');
    if (status) status.textContent = 'این کنترل نمایشی است؛ هیچ اطلاعات یا حقیقتی ثبت نشد.';
  }));
})();
