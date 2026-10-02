(() => {
  'use strict';
  const root = document.querySelector('[data-dzn-booking]');
  if (!root || !window.dznBooking) return;

  const instrument = root.querySelector('[data-instrument]');
  const timezone = root.querySelector('[data-timezone]');
  const calendar = root.querySelector('[data-calendar]');
  const calendarLabel = root.querySelector('[data-calendar-label]');
  const timeOptions = root.querySelector('[data-time-options]');
  const dayTimes = root.querySelector('[data-day-times]');
  const dayTitle = root.querySelector('[data-day-title]');
  const timesList = root.querySelector('[data-times]');
  const preferenceCount = root.querySelector('[data-preference-count]');
  const errorBox = root.querySelector('[data-error]');
  const country = root.querySelector('[data-contact="country"]');
  const city = root.querySelector('[data-contact="city"]');
  const cityList = root.querySelector('[data-city-list]');
  const blockedTimes = new Set();
  const availabilityByTime = new Map();
  let gridAvailabilityRequestId = 0;
  const slots = [];
  let idempotencyKey = '';
  let availabilityRequestId = 0;
  let availabilityPreviewFailed = false;
  let selectedDate = '';
  let dateCursor;

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
  const parseDate = (value) => {
    const parts = value.split('-').map(Number);
    return new Date(parts[0], parts[1] - 1, parts[2]);
  };
  const tomorrow = new Date();
  tomorrow.setHours(0, 0, 0, 0);
  tomorrow.setDate(tomorrow.getDate() + 1);
  const latest = new Date(tomorrow);
  latest.setDate(latest.getDate() + 89);
  const minDate = localDate(tomorrow);
  const maxDate = localDate(latest);
  const detectedZone = detectZone();
  if (detectedZone && Array.from(timezone.options).some((option) => option.value === detectedZone)) timezone.value = detectedZone;
  dateCursor = new Date(tomorrow.getFullYear(), tomorrow.getMonth(), 1);

  const copy = {
    strong: ['تناسب زمانی خوب', 'is-strong'],
    possible: ['امکان محدود یا احتمالی', 'is-possible'],
    none: ['تطابق فعلی ندارد؛ همچنان قابل درخواست', 'is-none'],
    blocked: ['این زمان طبق سیاست دلنوازان قابل درخواست نیست', 'is-blocked'],
    checking: ['در حال بررسی زمان', 'is-checking']
  };
  const faDigits = (value) => String(value).replace(/[0-9]/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const selectedDateLabel = (value) => new Intl.DateTimeFormat('fa-IR-u-ca-gregory', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(value + 'T12:00:00Z'));
  const teacherTimeText = (slot) => {
    const times = Array.isArray(slot.teacher_times) ? slot.teacher_times : [];
    if (!times.length) return slot.status === 'none' ? 'برای این زمان هنوز مدرس منطبق پیدا نشده است؛ زمان محلی مدرس پس از هماهنگی مشخص می‌شود.' : '';
    const labels = times.map((time) => {
      try {
        return new Intl.DateTimeFormat('fa-IR', { weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit', timeZoneName: 'long', timeZone: time.timezone }).format(new Date(time.starts_at_utc.replace(' ', 'T') + 'Z'));
      } catch { return ''; }
    }).filter(Boolean);
    return labels.length ? 'به وقت مدرس: ' + labels.join(' · ') : '';
  };
  const phoneExamples = { AU: '+61', BR: '+55', IR: '+98', CA: '+1', US: '+1', GB: '+44', NZ: '+64', DE: '+49', FR: '+33', TR: '+90', AE: '+971', SE: '+46' };
  const updatePhoneHint = () => {
    const hint = root.querySelector('[data-phone-hint]');
    const mobile = root.querySelector('[data-contact="mobile"]');
    const prefix = phoneExamples[country.value] || '+…';
    if (hint) hint.textContent = 'کد تماس بین‌المللی را وارد کنید؛ برای این کشور معمولاً ' + prefix + '.';
    if (mobile) mobile.placeholder = prefix + ' …';
  };

  const countryCodes = 'AF AL DZ AD AO AG AR AM AU AT AZ BS BH BD BB BY BE BZ BJ BT BO BA BW BR BN BG BF BI CV KH CM CA CF TD CL CN CO KM CG CD CR CI HR CU CY CZ DK DJ DM DO EC EG SV GQ ER EE SZ ET FJ FI FR GA GM GE DE GH GR GD GT GN GW GY HT HN HU IS IN ID IR IQ IE IL IT JM JP JO KZ KE KI XK KW KG LA LV LB LS LR LY LI LT LU MG MW MY MV ML MT MH MR MU MX FM MD MC MN ME MA MZ MM NA NR NP NL NZ NI NE NG KP MK NO OM PK PW PA PG PY PE PH PL PT QA RO RU RW KN LC VC WS SM ST SA SN RS SC SL SG SK SI SB SO ZA KR SS ES LK SD SR SE CH SY TW TJ TZ TH TL TG TO TT TN TR TM TV UG UA AE GB US UY UZ VU VA VE VN YE ZM ZW'.split(' ');
  const citiesByCountry = {
    AU: ['Brisbane','Sydney','Melbourne','Perth','Adelaide','Gold Coast','Canberra','Hobart','Darwin'],
    NZ: ['Auckland','Wellington','Christchurch','Hamilton','Tauranga','Dunedin'],
    US: ['New York','Los Angeles','Chicago','Houston','San Francisco','Seattle','Boston','Washington'],
    CA: ['Toronto','Vancouver','Montreal','Calgary','Ottawa','Edmonton'],
    GB: ['London','Manchester','Birmingham','Glasgow','Edinburgh','Liverpool'],
    DE: ['Berlin','Hamburg','Munich','Frankfurt','Cologne'],
    FR: ['Paris','Lyon','Marseille','Toulouse','Nice'],
    SE: ['Stockholm','Gothenburg','Malmo','Uppsala'],
    TR: ['Istanbul','Ankara','Izmir','Antalya'],
    AE: ['Dubai','Abu Dhabi','Sharjah','Ajman'],
    BR: ['Sao Paulo','Rio de Janeiro','Brasilia','Curitiba'],
    IR: ['Tehran','Mashhad','Isfahan','Shiraz','Tabriz']
  };
  const populateCities = () => {
    if (!cityList) return;
    cityList.replaceChildren();
    (citiesByCountry[country.value] || []).forEach((name) => cityList.append(new Option(name)));
    city.placeholder = (citiesByCountry[country.value] || []).length ? 'انتخاب یا تایپ شهر' : 'نام شهر';
  };

  const populateCountries = () => {
    const keep = country.querySelector('option[value=""]');
    country.replaceChildren(keep || new Option('انتخاب کشور', ''));
    const names = new Intl.DisplayNames(['fa'], { type: 'region' });
    countryCodes.map((code) => ({ code, name: names.of(code) || code }))
      .sort((left, right) => left.name.localeCompare(right.name, 'fa'))
      .forEach(({ code, name }) => country.add(new Option(name, code)));
    let region = '';
    try { region = new Intl.Locale(navigator.language).region || ''; } catch { /* browser language has no region */ }
    if (!region) {
      if ((detectedZone || '').startsWith('Australia/')) region = 'AU';
      else if ((detectedZone || '').startsWith('Pacific/')) region = 'NZ';
      else if (detectedZone === 'Asia/Tehran') region = 'IR';
    }
    if (region && country.querySelector('option[value="' + region + '"]')) country.value = region;
    updatePhoneHint();
    populateCities();
  };
  populateCountries();
  country.addEventListener('change', () => { updatePhoneHint(); city.value = ''; populateCities(); });

  const renderCalendar = () => {
    calendar.replaceChildren();
    calendarLabel.textContent = new Intl.DateTimeFormat('fa-IR-u-ca-gregory', { month: 'long', year: 'numeric' }).format(dateCursor);
    const weekdays = root.querySelector('[data-calendar-weekdays]');
    if (!weekdays.children.length) {
      ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].forEach((name) => {
        const item = document.createElement('span');
        item.textContent = name;
        weekdays.append(item);
      });
    }
    const firstDay = new Date(dateCursor.getFullYear(), dateCursor.getMonth(), 1);
    const offset = (firstDay.getDay() + 1) % 7;
    const cellStart = new Date(firstDay);
    cellStart.setDate(firstDay.getDate() - offset);
    for (let i = 0; i < 42; i += 1) {
      const value = new Date(cellStart);
      value.setDate(cellStart.getDate() + i);
      const key = localDate(value);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'dzn-booking__calendar-day';
      button.textContent = faDigits(value.getDate());
      button.disabled = key < minDate || key > maxDate || value.getMonth() !== dateCursor.getMonth();
      button.setAttribute('aria-label', selectedDateLabel(key));
      button.setAttribute('aria-pressed', key === selectedDate ? 'true' : 'false');
      if (value.getMonth() !== dateCursor.getMonth()) button.classList.add('is-outside');
      if (key === selectedDate) button.classList.add('is-selected');

      button.addEventListener('click', () => {
        selectedDate = key;
        dayTitle.textContent = selectedDateLabel(key);
        dayTimes.hidden = false;
        renderTimeOptions();
        renderCalendar();
        refreshAvailabilityGrid();
      });
      calendar.append(button);
    }
    const previous = root.querySelector('[data-calendar-prev]');
    const next = root.querySelector('[data-calendar-next]');
    previous.disabled = dateCursor.getFullYear() === tomorrow.getFullYear() && dateCursor.getMonth() === tomorrow.getMonth();
    const maxMonth = new Date(latest.getFullYear(), latest.getMonth(), 1);
    next.disabled = dateCursor >= maxMonth;
  };
  const candidateTimes = Array.from({ length: 32 }, (_, index) => {
    const minutes = index * 45;
    return String(Math.floor(minutes / 60)).padStart(2, '0') + ':' + String(minutes % 60).padStart(2, '0');
  });
  const renderTimeOptions = () => {
    timeOptions.replaceChildren();
    const buttons = document.createElement('div');
    buttons.className = 'dzn-booking__time-buttons';
    candidateTimes.forEach((value) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = faDigits(value);
      const existing = slots.find((slot) => slot.local_date === selectedDate && slot.local_start_time === value);
      const preview = availabilityByTime.get(selectedDate + '|' + value);
      button.className = 'dzn-booking__time-option' + (preview && ['strong', 'possible', 'none'].includes(preview.status) ? ' is-' + preview.status : '');
      button.setAttribute('dir', 'ltr');
      button.disabled = slots.length >= 3 || Boolean(existing) || blockedTimes.has(selectedDate + '|' + value);
      if (blockedTimes.has(selectedDate + '|' + value)) return;
      button.addEventListener('click', () => addPreference(value));
      buttons.append(button);
    });
    timeOptions.append(buttons);
  };

  const refreshAvailabilityGrid = async () => {
    if (!activeInstrument() || !selectedDate || !timezone.value) return;
    const requestId = ++gridAvailabilityRequestId;
    availabilityByTime.clear();
    blockedTimes.clear();
    renderTimeOptions();
    try {
      const response = await fetch(apiUrl('delnavazan-platform/v1/booking-availability/preview'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          instrument_id: Number(instrument.value),
          course_id: Number(selectedOption().dataset.course),
          requested_times: candidateTimes.map((time) => ({ local_date: selectedDate, local_start_time: time, timezone: timezone.value }))
        })
      });
      const result = await response.json();
      if (!response.ok || !Array.isArray(result.times) || result.times.length !== candidateTimes.length) throw new Error('availability_grid_failed');
      if (requestId !== gridAvailabilityRequestId) return;
      result.times.forEach((row, index) => {
        const time = candidateTimes[index];
        if (!row || !['strong', 'possible', 'none', 'blocked'].includes(row.status)) return;
        availabilityByTime.set(selectedDate + '|' + time, row);
        if (row.status === 'blocked') blockedTimes.add(selectedDate + '|' + time);
      });
      slots.slice().forEach((slot) => {
        if (slot.local_date !== selectedDate) return;
        const row = availabilityByTime.get(slot.local_date + '|' + slot.local_start_time);
        if (row && row.status === 'blocked') slots.splice(slots.indexOf(slot), 1);
        else if (row) {
          slot.status = row.status;
          slot.teacher_times = Array.isArray(row.teacher_times) ? row.teacher_times : [];
        }
      });
      renderSlots();
      renderTimeOptions();
    } catch {
      if (requestId !== gridAvailabilityRequestId) return;
      availabilityByTime.clear();
      blockedTimes.clear();
      renderTimeOptions();
    }
  };

  const renderSlots = () => {
    timesList.replaceChildren();
    slots.forEach((slot, index) => {
      const item = document.createElement('li');
      item.className = 'dzn-booking__time';
      const details = document.createElement('div');
      const heading = document.createElement('strong');
      heading.textContent = 'اولویت ' + faDigits(index + 1) + ' · ' + selectedDateLabel(slot.local_date) + '، ساعت ' + slot.local_start_time;
      const state = copy[slot.status];
      details.append(heading);
      if (state) {
        const badge = document.createElement('span');
        badge.className = 'dzn-booking__badge ' + state[1];
        badge.textContent = state[0];
        details.append(badge);
      }
      const teacherTime = teacherTimeText(slot);
      if (teacherTime) {
        const note = document.createElement('small');
        note.className = 'dzn-booking__teacher-time';
        note.textContent = teacherTime;
        details.append(note);
      }
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'dzn-booking__remove';
      remove.textContent = 'حذف';
      remove.setAttribute('aria-label', 'حذف زمان اولویت ' + (index + 1));
      remove.addEventListener('click', () => {
        slots.splice(index, 1);
        renderSlots();
        renderTimeOptions();
        renderCalendar();
      });
      item.append(details, remove);
      timesList.append(item);
    });
    preferenceCount.textContent = faDigits(slots.length) + ' از ۳';
  };
  const assessSlots = async () => {
    const requestId = ++availabilityRequestId;
    availabilityPreviewFailed = false;
    if (!activeInstrument() || !slots.length || !timezone.value) return false;
    const requestedSlots = slots.map((slot) => ({ local_date: slot.local_date, local_start_time: slot.local_start_time }));
    slots.forEach((slot) => { slot.status = 'checking'; slot.teacher_times = []; });
    renderSlots();
    try {
      const response = await fetch(apiUrl('delnavazan-platform/v1/booking-availability/preview'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          instrument_id: Number(instrument.value),
          course_id: Number(selectedOption().dataset.course),
          requested_times: requestedSlots.map((slot) => ({ local_date: slot.local_date, local_start_time: slot.local_start_time, timezone: timezone.value }))
        })
      });
      const result = await response.json();
      if (!response.ok || !Array.isArray(result.times) || result.times.length !== slots.length || result.times.some((row) => !row || !['strong', 'possible', 'none', 'blocked'].includes(row.status))) throw new Error('availability_check_failed');
      if (requestId !== availabilityRequestId) return false;
      result.times.forEach((row, index) => {
        if (slots[index]) {
          slots[index].status = row.status;
          slots[index].teacher_times = Array.isArray(row.teacher_times) ? row.teacher_times.filter((time) => time && typeof time.timezone === 'string' && typeof time.starts_at_utc === 'string') : [];
        }
      });
      const blocked = slots.filter((slot) => slot.status === 'blocked');
      blocked.forEach((slot) => blockedTimes.add(slot.local_date + '|' + slot.local_start_time));
      if (blocked.length) {
        blocked.forEach((slot) => slots.splice(slots.indexOf(slot), 1));
        renderSlots();
        renderTimeOptions();
        renderCalendar();
        return false;
      }
      return true;
    } catch {
      if (requestId !== availabilityRequestId) return false;
      availabilityPreviewFailed = true;
      slots.forEach((slot) => { slot.status = null; slot.teacher_times = []; });
    }
    renderSlots();
    renderTimeOptions();
    renderCalendar();
    return false;
  };

  const addPreference = (value) => {
    showError('');
    if (!activeInstrument()) { showError('ابتدا ساز موردنظر را انتخاب کنید.'); goTo('instrument'); return; }
    if (!selectedDate || !timezone.value) { showError('روز و منطقهٔ زمانی را انتخاب کنید.'); return; }
    if (slots.length >= 3) { showError('حداکثر سه زمان پیشنهادی می‌توانید اضافه کنید.'); return; }
    if (slots.some((slot) => slot.local_date === selectedDate && slot.local_start_time === value)) { showError('این زمان را قبلاً اضافه کرده‌اید.'); return; }
    slots.push({ local_date: selectedDate, local_start_time: value, status: null, teacher_times: [] });
    renderSlots();
    renderTimeOptions();
    renderCalendar();
  };

  root.querySelector('[data-calendar-prev]').addEventListener('click', () => {
    dateCursor = new Date(dateCursor.getFullYear(), dateCursor.getMonth() - 1, 1);
    renderCalendar();
  });
  root.querySelector('[data-calendar-next]').addEventListener('click', () => {
    dateCursor = new Date(dateCursor.getFullYear(), dateCursor.getMonth() + 1, 1);
    renderCalendar();
  });
  instrument.addEventListener('change', () => {
    availabilityRequestId += 1;
    slots.splice(0);
    renderSlots();
    renderTimeOptions();
    renderCalendar();
    root.querySelectorAll('[data-instrument-choice]').forEach((button) => {
      const selected = button.dataset.instrumentChoice === instrument.value;
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  });
  root.querySelectorAll('[data-instrument-choice]').forEach((button) => {
    button.addEventListener('click', () => {
      instrument.value = button.dataset.instrumentChoice;
      instrument.dispatchEvent(new Event('change', { bubbles: true }));
      goTo('availability');
      refreshAvailabilityGrid();
    });
  });
  const currentStep = () => root.querySelector('.dzn-booking__step.is-active')?.dataset.step || 'instrument';
  const goTo = (name) => {
    showError('');
    root.querySelectorAll('.dzn-booking__step').forEach((section) => {
      const active = section.dataset.step === name;
      section.hidden = !active;
      section.classList.toggle('is-active', active);
    });
    const sequence = ['instrument', 'availability', 'contact'];
    const progressStep = name === 'success' ? 'contact' : name;
    root.querySelectorAll('[data-progress]').forEach((item) => {
      item.classList.toggle('is-current', item.dataset.progress === progressStep);
      item.classList.toggle('is-done', sequence.indexOf(item.dataset.progress) < sequence.indexOf(name));
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
      ['منطقهٔ زمانی شما', timezone.value],
      ['نام', fieldValue('full_name')],
      ['ایمیل', fieldValue('email')],
      ['موبایل', fieldValue('mobile')],
      ['کشور و شهر', fieldValue('country').toUpperCase() + ' · ' + fieldValue('city')],
      ['واتساپ', fieldValue('mobile')]
    ];
    const dl = document.createElement('dl');
    rows.forEach(([label, text]) => {
      const dt = document.createElement('dt');
      const dd = document.createElement('dd');
      dd.textContent = text;
      dt.textContent = label;
      dl.append(dt, dd);
    });
    const scheduleTitle = document.createElement('h4');
    scheduleTitle.textContent = 'زمان‌ها به ترتیب اولویت';
    const list = document.createElement('ol');
    slots.forEach((slot) => {
      const li = document.createElement('li');
      const state = copy[slot.status];
      li.textContent = selectedDateLabel(slot.local_date) + '، ' + slot.local_start_time + ' (' + state[0] + ')';
      const teacherTime = teacherTimeText(slot);
      if (teacherTime) {
        const note = document.createElement('small');
        note.className = 'dzn-booking__teacher-time';
        note.textContent = teacherTime;
        li.append(note);
      }
      list.append(li);
    });
    panel.append(dl, scheduleTitle, list);
  };
  root.querySelectorAll('[data-next]').forEach((button) => button.addEventListener('click', () => {
    const next = button.dataset.next;
    if (currentStep() === 'instrument' && !activeInstrument()) {
      showError('ابتدا ساز موردنظر را انتخاب کنید.');
      root.querySelector('[data-instrument-choice]')?.focus();
      return;
    }
    if (currentStep() === 'availability') {
      if (!slots.length) { showError('برای ادامه دست‌کم یک زمان پیشنهادی انتخاب کنید.'); root.querySelector('[data-calendar]')?.focus(); return; }
      if (!timezone.value) { showError('منطقهٔ زمانی را انتخاب کنید.'); timezone.focus(); return; }
    }
    goTo(next);
  }));
  root.querySelectorAll('[data-back]').forEach((button) => button.addEventListener('click', () => goTo(button.dataset.back)));
  root.querySelectorAll('[data-progress-target]').forEach((button) => button.addEventListener('click', () => {
    const target = button.dataset.progressTarget;
    const order = ['instrument', 'availability', 'contact'];
    if (order.indexOf(target) <= order.indexOf(currentStep())) goTo(target);
    else if (target === 'availability' && activeInstrument()) goTo(target);
    else if (target === 'contact' && activeInstrument() && slots.length && timezone.value) goTo(target);
  }));
  timezone.addEventListener('change', async () => {
    availabilityByTime.clear();
    blockedTimes.clear();
    slots.forEach((slot) => { slot.status = null; slot.teacher_times = []; });
    await refreshAvailabilityGrid();
    if (slots.length) await assessSlots();
    renderTimeOptions();
  });

  root.querySelector('[data-submit]').addEventListener('click', async (event) => {
    showError('');
    if (!validateSection('contact')) return;
    const availabilityOk = await assessSlots();
    if (!availabilityOk) {
      showError(availabilityPreviewFailed ? 'بررسی زمان‌ها انجام نشد. لطفاً دوباره تلاش کنید.' : 'یکی از زمان‌های انتخابی در دسترس نیست. زمان دیگری را انتخاب کنید.');
      goTo('availability');
      return;
    }
    buildReview();
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
      timezone: timezone.value,
      communication_language: 'fa',
      whatsapp_same_as_mobile: true,
      whatsapp_number: fieldValue('mobile'),
      privacy_notice_accepted: true,
      privacy_notice_version: dznBooking.privacyVersion,
      requested_times: slots.map((slot) => ({ local_date: slot.local_date, local_start_time: slot.local_start_time, timezone: timezone.value }))
    };
    try {
      const response = await fetch(apiUrl('delnavazan-platform/v1/booking-requests'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': idempotencyKey },
        body: JSON.stringify(payload)
      });
      const result = await response.json();
      if (!response.ok || !result.success || !result.request_reference) {
        if (response.status === 400) idempotencyKey = '';
        throw new Error(result.code || 'submission_unavailable');
      }
      root.querySelector('[data-reference]').textContent = result.request_reference;
      const successTimes = root.querySelector('[data-success-times]');
      successTimes.replaceChildren();
      slots.forEach((slot, index) => {
        const item = document.createElement('li');
        item.textContent = 'اولویت ' + faDigits(index + 1) + ' · ' + selectedDateLabel(slot.local_date) + '، ساعت ' + slot.local_start_time;
        const teacherTime = teacherTimeText(slot);
        if (teacherTime) {
          const note = document.createElement('small');
          note.className = 'dzn-booking__teacher-time';
          note.textContent = teacherTime;
          item.append(note);
        }
        successTimes.append(item);
      });
      goTo('success');
    } catch (error) {
      const messages = {
        invalid_request: 'بعضی از اطلاعات درخواست معتبر نیست. زمان پیشنهادی، منطقهٔ زمانی و اطلاعات تماس را بررسی کنید.',
        blocked_time: 'زمان انتخاب‌شده طبق سیاست دلنوازان قابل درخواست نیست. زمان دیگری انتخاب کنید.',
        rate_limited: 'درخواست‌های زیادی در مدت کوتاه ارسال شده است. کمی بعد دوباره تلاش کنید.',
        idempotency_conflict: 'برای جلوگیری از ثبت درخواست تکراری، دوباره تلاش نکنید. با دلنوازان تماس بگیرید تا وضعیت درخواست قبلی بررسی شود.',
        submission_unavailable: 'سامانه نتوانست ثبت درخواست را تأیید کند. اطلاعات این فرم باقی مانده است؛ همین صفحه را با همین اطلاعات دوباره ارسال کنید.'
      };
      const code = error instanceof Error ? error.message : '';
      showError(messages[code] || 'پاسخ سامانه دریافت نشد. فرم را نبندید؛ با همین صفحه و همان اطلاعات دوباره تلاش کنید. ارسال مجدد با همان کلید از ثبت تکراری جلوگیری می‌کند.');
    } finally {
      button.disabled = false;
      button.textContent = 'ثبت درخواست جلسهٔ معارفه';
    }
  });

  renderSlots();
  renderCalendar();
  renderTimeOptions();
})();