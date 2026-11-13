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
  // Availability workspace: day/instrument controls are keyboard-operable and keep the
  // precise form fields as the source of truth. The server still validates every value.
  document.querySelectorAll('[data-dzn-availability-workspace]').forEach((workspace) => {
    const days = Array.from(workspace.querySelectorAll('[data-dzn-day-tab]'));
    const instruments = Array.from(workspace.querySelectorAll('[data-dzn-instrument-tab]'));
    const weekday = workspace.querySelector('[data-dzn-weekday]');
    const instrument = workspace.querySelector('[data-dzn-instrument]');
    const rangeInstrument = workspace.querySelector('[data-dzn-range-instrument]');
    const heading = workspace.querySelector('[data-dzn-selected-day]');
    let activeInstrument = '';
    const names = days.map((node) => node.textContent.trim().replace(/\s+/g, ' ').replace(/[۰-۹].*$/, ''));
    const selectDay = (value) => {
      days.forEach((node, index) => {
        const active = String(value) === node.dataset.dznDayTab;
        node.classList.toggle('is-active', active);
        node.setAttribute('aria-selected', String(active));
        if (active && weekday) weekday.value = node.dataset.dznDayTab;
        if (active && heading) heading.textContent = names[index] || node.textContent.trim();
      });
      workspace.querySelectorAll('[data-dzn-rule-day]').forEach((row) => {
        row.hidden = String(value) !== row.dataset.dznRuleDay || (activeInstrument !== '' && activeInstrument !== row.dataset.dznRuleInstrument);
      });
    };
    days.forEach((node) => node.addEventListener('click', () => selectDay(node.dataset.dznDayTab)));
    instruments.forEach((node) => node.addEventListener('click', () => {
      instruments.forEach((tab) => { const active = tab === node; tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', String(active)); });
      activeInstrument = node.dataset.dznInstrumentTab || '';
      if (instrument) instrument.value = activeInstrument;
      if (rangeInstrument) rangeInstrument.value = activeInstrument;
      workspace.querySelector('[data-dzn-empty-instrument]')?.setAttribute('value', activeInstrument);
      const selectedDay = workspace.querySelector('.dzn-tp-day-tab.is-active')?.dataset.dznDayTab || '1';
      selectDay(selectedDay);
    }));
    workspace.querySelector('form[data-dzn-availability-form]')?.addEventListener('input', () => {
      const flag = workspace.querySelector('[data-dzn-unsaved]');
      if (flag) flag.hidden = false;
    });
    if (instruments[0] && instrument) {
      activeInstrument = instruments[0].dataset.dznInstrumentTab || '';
      instrument.value = activeInstrument;
      if (rangeInstrument) rangeInstrument.value = activeInstrument;
      workspace.querySelector('[data-dzn-empty-instrument]')?.setAttribute('value', activeInstrument);
    }
    if (days[0]) selectDay(days[0].dataset.dznDayTab);
  });
  document.querySelectorAll('[data-dzn-tp-presentation]').forEach((button) => button.addEventListener('click', () => {
    const scope = button.closest('section, dialog') || document;
    const status = scope.querySelector('.dzn-tp-action-status');
    if (status) status.textContent = 'این کنترل نمایشی است؛ هیچ اطلاعات یا حقیقتی ثبت نشد.';
  }));
  // Future-class student filter is presentation-only: it hides already-rendered cards and
  // never changes the model, schedule authority or backend action fields.
  document.querySelectorAll('[data-dzn-tp-student-filter]').forEach((filter) => {
    const select = filter.querySelector('[data-dzn-tp-student-filter-select]');
    const section = filter.closest('.dzn-tp-class-section');
    const list = section ? section.querySelector('[data-dzn-tp-upcoming-list]') : null;
    const empty = section ? section.querySelector('[data-dzn-tp-student-filter-empty]') : null;
    const status = filter.querySelector('[data-dzn-tp-student-filter-status]');
    if (!select || !list) return;
    const cards = Array.from(list.querySelectorAll('li[data-dzn-tp-student]'));
    const apply = () => {
      const value = select.value;
      let visible = 0;
      cards.forEach((card) => {
        const show = value === '' || card.dataset.dznTpStudent === value;
        card.hidden = !show;
        if (show) visible++;
      });
      list.hidden = visible === 0;
      if (empty) empty.hidden = visible !== 0;
      if (status) status.textContent = value === '' ? '' : visible.toLocaleString('fa-IR') + ' کلاس';
    };
    select.addEventListener('change', apply);
    apply();
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
  // A notification without a read action can still be dismissed for this visit. When the
  // read action exists, the close control is its submit button and marks the item read.
  document.querySelectorAll('[data-dzn-notification-close][type="button"]').forEach((button) => {
    button.addEventListener('click', () => {
      const banner = button.closest('[data-dzn-notification-banner]');
      if (banner) banner.hidden = true;
    });
  });
  // The Teacher must explicitly choose one of the Student's preferences. This is presentation
  // only: the acceptance authority receives the selected id and revalidates it server-side.
  document.querySelectorAll('[data-dzn-intro-card]').forEach((card) => {
    const form = card.querySelector('[data-dzn-tp-intro-accept]');
    const accept = card.querySelector('[data-dzn-tp-accept]');
    const hint = card.querySelector('[data-dzn-tp-select-hint]');
    if (!form || !accept) return;
    const inputs = Array.from(form.querySelectorAll('[data-dzn-tp-preference-input]'));
    const sync = () => {
      const selected = inputs.some((input) => input.checked);
      accept.disabled = !selected;
      if (hint) hint.hidden = selected;
    };
    inputs.forEach((input) => input.addEventListener('change', sync));
    sync();
  });
  document.querySelectorAll('[data-dzn-tp-reject-toggle]').forEach((button) => {
    const form = document.getElementById(button.dataset.dznTpRejectToggle || '');
    if (!form) return;
    const card = button.closest('[data-dzn-intro-card]');
    const other = form.querySelector('[data-dzn-tp-other-reason]');
    const otherInput = form.querySelector('[data-dzn-tp-other-reason-input]');
    const reasons = Array.from(form.querySelectorAll('input[name="reason_code"]'));
    const syncOther = () => {
      const selected = form.querySelector('input[name="reason_code"]:checked');
      const isOther = selected && selected.value === 'other';
      if (other) other.hidden = !isOther;
      if (otherInput) {
        otherInput.disabled = !isOther;
        otherInput.required = Boolean(isOther);
        if (isOther) otherInput.focus();
      }
    };
    const open = () => {
      form.hidden = false;
      button.setAttribute('aria-expanded', 'true');
      const first = reasons[0];
      if (first) first.focus();
    };
    const close = (focus = true) => {
      form.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      form.reset();
      syncOther();
      if (focus) button.focus();
    };
    button.addEventListener('click', () => { if (form.hidden) open(); else close(); });
    reasons.forEach((reason) => reason.addEventListener('change', syncOther));
    form.querySelectorAll('[data-dzn-tp-reject-cancel]').forEach((cancel) => cancel.addEventListener('click', (event) => { event.preventDefault(); close(); }));
    // Leaving the card via its accept action closes a stale, unsent rejection form.
    card?.querySelector('[data-dzn-tp-accept]')?.addEventListener('click', () => { if (!form.hidden) close(false); });
    syncOther();
  });
  // Teacher booking-request response window. The server owns acceptance; this only shows the
  // remaining time and closes the already-rendered action controls when the window elapses.
  const responseWindows = Array.from(document.querySelectorAll('[data-dzn-response-deadline]'));
  if (responseWindows.length) {
    const fa = (value) => Number(value).toLocaleString('fa-IR');
    const remainingLabel = (seconds) => {
      if (seconds <= 0) return 'مهلت پاسخ گذشته است';
      const hours = Math.floor(seconds / 3600);
      const minutes = Math.floor((seconds % 3600) / 60);
      return hours > 0 ? fa(hours) + ' ساعت و ' + fa(minutes) + ' دقیقه مانده' : fa(Math.max(1, minutes)) + ' دقیقه مانده';
    };
    let timer = null;
    const tick = () => {
      let active = 0;
      responseWindows.forEach((windowEl) => {
        const deadline = Date.parse(windowEl.dataset.dznResponseDeadline || '');
        if (!Number.isFinite(deadline)) return;
        const remaining = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
        const countdown = windowEl.querySelector('[data-dzn-response-countdown]');
        if (countdown) countdown.textContent = remainingLabel(remaining);
        if (remaining > 0) { active++; return; }
        windowEl.classList.add('is-expired');
        windowEl.dataset.dznResponseExpired = '1';
        const card = windowEl.closest('[data-dzn-intro-card]') || windowEl.closest('.dzn-tp-attention-card');
        if (!card) return;
        card.classList.add('is-expired');
        card.querySelectorAll('form[data-dzn-tp-submit] button[type="submit"], form[data-dzn-tp-submit] input[type="radio"]').forEach((control) => {
          control.disabled = true;
          if (control.matches('button')) control.setAttribute('aria-disabled', 'true');
        });
        if (!card.querySelector('.dzn-tp-response-expired')) {
          const note = document.createElement('p');
          note.className = 'dzn-tp-help dzn-tp-response-expired';
          note.textContent = 'مهلت پاسخ به پایان رسیده است؛ این درخواست دیگر از پرتال شما قابل پذیرش یا رد نیست و برای ارجاع به مدرس در دسترس بعدی در اختیار آموزشگاه است.';
          windowEl.insertAdjacentElement('afterend', note);
        }
      });
      if (active === 0 && timer !== null) window.clearInterval(timer);
    };
    tick();
    timer = window.setInterval(tick, 1000);
  }
})();
