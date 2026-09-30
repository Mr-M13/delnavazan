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
  }));
  document.querySelectorAll('[data-dzn-tp-presentation]').forEach((button) => button.addEventListener('click', () => {
    const scope = button.closest('section, dialog') || document;
    const status = scope.querySelector('.dzn-tp-action-status');
    if (status) status.textContent = 'این کنترل نمایشی است؛ هیچ اطلاعات یا حقیقتی ثبت نشد.';
  }));
})();
