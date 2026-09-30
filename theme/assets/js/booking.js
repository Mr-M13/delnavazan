(() => {
  'use strict';
  const root = document.querySelector('[data-dzn-booking]');
  if (!root || !window.dznBooking) return;
  const instrument = root.querySelector('[data-instrument]');
  const timezone = root.querySelector('[data-timezone]');
  const date = root.querySelector('[data-date]');
  const time = root.querySelector('[data-time]');
  const timesList = root.querySelector('[data-times]');
  const errorBox = root.querySelector('[data-error]');
  const whatsappSame = root.querySelector('[data-whatsapp-same]');
  const whatsappExtra = root.querySelector('[data-whatsapp-extra]');
  const whatsapp = root.querySelector('[data-whatsapp]');
  const slots = [];
  let idempotencyKey = '';

  const showError = (message) => {
    errorBox.textContent = message || '';
    errorBox.hidden = !message;
  };
  const selectedOption = () => instrument.options[instrument.selectedIndex];
  const activeInstrument = () => instrument.value && selectedOption().dataset.course;
  const apiUrl = (path) => String(dznBooking.apiRoot).replace(/\/?$/, '/') + path;
  const detectZone = () => {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch { return ''; }
  };
  const localDate = (value) => value.getFullYear() + '-' + String(value.getMonth() + 1).padStart(2, '0') + '-' + String(value.getDate()).padStart(2, '0');
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  date.min = localDate(tomorrow);
  const latest = new Date();
  latest.setDate(latest.getDate() + 90);
  date.max = localDate(latest);
  if (detectZone()) timezone.value = detectZone();
  time.value = '18:00';

  const copy = {
    strong: ['تناسب زمانی خوب', 'is-strong'],
    possible: ['امکان محدود یا احتمالی', 'is-possible'],
    none: ['بدون تطابق فعلی؛ همچنان قابل درخواست', 'is-none'],
    checking: ['در حال بررسی زمان', 'is-checking'],
    unavailable: ['بررسی در دسترس نیست؛ زمان همچنان قابل درخواست است', 'is-none']
  };
  const renderSlots = () => {
    timesList.replaceChildren();
    slots.forEach((slot, index) => {
      const item = document.createElement('li');
      item.className = 'dzn-booking__time';
      const details = document.createElement('div');
      const heading = document.createElement('strong');
      heading.textContent = 'اولویت ' + (index + 1) + ' · ' + slot.local_date + '، ساعت ' + slot.local_start_time;
      const badge = document.createElement('span');
      const state = copy[slot.status] || copy.unavailable;
      badge.className = 'dzn-booking__badge ' + state[1];
      badge.textContent = state[0];
      details.append(heading, badge);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'dzn-booking__remove';
      remove.textContent = 'حذف';
      remove.setAttribute('aria-label', 'حذف زمان اولویت ' + (index + 1));
      remove.addEventListener('click', () => {
        slots.splice(index, 1);
        idempotencyKey = '';
        renderSlots();
        assessSlots();
      });
      item.append(details, remove);
      timesList.append(item);
    });
  };
  const assessSlots = async () => {
    if (!activeInstrument() || !slots.length || !timezone.value) return;
    slots.forEach((slot) => { slot.status = 'checking'; });
    renderSlots();
    try {
      const response = await fetch(apiUrl('delnavazan-platform/v1/booking-availability/preview'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          instrument_id: Number(instrument.value),
          course_id: Number(selectedOption().dataset.course),
          requested_times: slots.map((slot) => ({ local_date: slot.local_date, local_start_time: slot.local_start_time, timezone: timezone.value }))
        })
      });
      const result = await response.json();
      if (!response.ok || !Array.isArray(result.times)) throw new Error('unavailable');
      result.times.forEach((row, index) => {
        if (slots[index]) slots[index].status = ['strong', 'possible', 'none'].includes(row.status) ? row.status : 'unavailable';
      });
    } catch {
      slots.forEach((slot) => { slot.status = 'unavailable'; });
    }
    renderSlots();
  };

  root.querySelector('[data-add-time]').addEventListener('click', () => {
    showError('');
    if (!activeInstrument()) { showError('ابتدا ساز موردنظر را انتخاب کنید.'); instrument.focus(); return; }
    if (!date.value || !time.value || !timezone.value.trim()) { showError('تاریخ، ساعت و منطقهٔ زمانی را کامل کنید.'); return; }
    if (slots.length >= 3) { showError('حداکثر سه زمان پیشنهادی می‌توانید اضافه کنید.'); return; }
    if (slots.some((slot) => slot.local_date === date.value && slot.local_start_time === time.value)) { showError('این زمان را قبلاً اضافه کرده‌اید.'); return; }
    slots.push({ local_date: date.value, local_start_time: time.value, status: 'checking' });
    idempotencyKey = '';
    renderSlots();
    assessSlots();
  });
  instrument.addEventListener('change', () => {
    idempotencyKey = '';
    slots.splice(0);
    renderSlots();
  });
  timezone.addEventListener('change', () => { idempotencyKey = ''; assessSlots(); });
  whatsappSame.addEventListener('change', () => {
    whatsappExtra.hidden = whatsappSame.checked;
    whatsapp.required = !whatsappSame.checked;
  });
  whatsappExtra.hidden = whatsappSame.checked;

  const currentStep = () => root.querySelector('.dzn-booking__step.is-active')?.dataset.step || 'instrument';
  const goTo = (name) => {
    showError('');
    root.querySelectorAll('.dzn-booking__step').forEach((section) => {
      const active = section.dataset.step === name;
      section.hidden = !active;
      section.classList.toggle('is-active', active);
    });
    const sequence = ['instrument', 'contact', 'review'];
    const progressStep = name === 'success' ? 'review' : name;
    root.querySelectorAll('[data-progress]').forEach((item) => {
      item.classList.toggle('is-current', item.dataset.progress === progressStep);
      item.classList.toggle('is-done', sequence.indexOf(item.dataset.progress) < sequence.indexOf(progressStep));
    });
    const heading = root.querySelector('[data-step="' + name + '"] h2');
    if (heading) heading.focus();
  };
  const validateSection = (step) => {
    const section = root.querySelector('[data-step="' + step + '"]');
    const fields = Array.from(section.querySelectorAll('input, select, textarea'));
    const invalid = fields.find((field) => field.required && !field.checkValidity());
    if (!invalid) return true;
    invalid.reportValidity();
    invalid.focus();
    return false;
  };
  const fieldValue = (name) => root.querySelector('[data-contact="' + name + '"]').value.trim();
  const buildReview = () => {
    const panel = root.querySelector('[data-review]');
    panel.replaceChildren();
    const rows = [
      ['ساز', selectedOption().textContent.trim()],
      ['منطقهٔ زمانی شما', timezone.value.trim()],
      ['نام', fieldValue('full_name')],
      ['ایمیل', fieldValue('email')],
      ['موبایل', fieldValue('mobile')],
      ['کشور و شهر', fieldValue('country').toUpperCase() + ' · ' + fieldValue('city')],
      ['زبان ارتباط', fieldValue('communication_language') === 'fa' ? 'فارسی' : 'English'],
      ['واتساپ', whatsappSame.checked ? fieldValue('mobile') : whatsapp.value.trim()]
    ];
    const dl = document.createElement('dl');
    rows.forEach(([label, text]) => {
      const dt = document.createElement('dt');
      const dd = document.createElement('dd');
      dt.textContent = label;
      dd.textContent = text;
      dl.append(dt, dd);
    });
    const scheduleTitle = document.createElement('h3');
    scheduleTitle.textContent = 'زمان‌های پیشنهادی به ترتیب اولویت';
    const list = document.createElement('ol');
    slots.forEach((slot) => {
      const li = document.createElement('li');
      const state = copy[slot.status] || copy.unavailable;
      li.textContent = slot.local_date + '، ' + slot.local_start_time + ' (' + state[0] + ')';
      list.append(li);
    });
    panel.append(dl, scheduleTitle, list);
  };
  root.querySelectorAll('[data-next]').forEach((button) => button.addEventListener('click', () => {
    const next = button.dataset.next;
    if (currentStep() === 'instrument') {
      if (!activeInstrument()) { showError('ابتدا ساز موردنظر را انتخاب کنید.'); instrument.focus(); return; }
      if (!slots.length) { showError('برای ادامه دست‌کم یک زمان پیشنهادی اضافه کنید.'); date.focus(); return; }
      if (!timezone.value.trim()) { showError('منطقهٔ زمانی را وارد کنید.'); timezone.focus(); return; }
    }
    if (next === 'review') {
      if (!validateSection('contact')) return;
      buildReview();
    }
    goTo(next);
  }));
  root.querySelectorAll('[data-back]').forEach((button) => button.addEventListener('click', () => goTo(button.dataset.back)));

  root.querySelector('[data-submit]').addEventListener('click', async (event) => {
    showError('');
    if (!validateSection('contact')) { goTo('contact'); return; }
    const button = event.currentTarget;
    button.disabled = true;
    button.textContent = 'در حال ثبت درخواست…';
    if (!idempotencyKey) idempotencyKey = window.crypto?.randomUUID?.() || (Date.now() + '-' + Math.random().toString(16).slice(2));
    const payload = {
      requested_instrument_id: Number(instrument.value),
      selected_intro_course_id: Number(selectedOption().dataset.course),
      full_name: fieldValue('full_name'),
      email: fieldValue('email'),
      mobile: fieldValue('mobile'),
      country: fieldValue('country').toUpperCase(),
      city: fieldValue('city'),
      timezone: timezone.value.trim(),
      communication_language: fieldValue('communication_language'),
      whatsapp_same_as_mobile: whatsappSame.checked,
      whatsapp_number: whatsappSame.checked ? fieldValue('mobile') : whatsapp.value.trim(),
      privacy_notice_accepted: true,
      privacy_notice_version: dznBooking.privacyVersion,
      requested_times: slots.map((slot) => ({ local_date: slot.local_date, local_start_time: slot.local_start_time, timezone: timezone.value.trim() }))
    };
    try {
      const response = await fetch(apiUrl('delnavazan-platform/v1/booking-requests'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': idempotencyKey },
        body: JSON.stringify(payload)
      });
      const result = await response.json();
      if (!response.ok || !result.success || !result.request_reference) {
        if (response.status === 400 || response.status === 409) idempotencyKey = '';
        throw new Error(result.code || 'submission_unavailable');
      }
      root.querySelector('[data-reference]').textContent = result.request_reference;
      goTo('success');
    } catch (error) {
      showError(error.message === 'rate_limited'
        ? 'درخواست‌های زیادی در مدت کوتاه ارسال شده است. کمی بعد دوباره تلاش کنید.'
        : 'درخواست ثبت نشد. اطلاعات را بررسی کنید و دوباره تلاش کنید.');
    } finally {
      button.disabled = false;
      button.textContent = 'ثبت درخواست جلسهٔ معارفه';
    }
  });
  renderSlots();
})();
