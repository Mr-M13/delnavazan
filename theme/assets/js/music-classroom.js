(function () {
  'use strict';

  var config = window.dznMusicClassroom || {};
  var LK = window.LivekitClient;
  var root = document.querySelector('.dzn-classroom');
  if (!root) return;

  var $ = function (selector) { return document.querySelector(selector); };
  var connection = $('#dzn-classroom-connection');
  var connectionText = connection ? connection.querySelector('.dzn-classroom__connection-text') : null;
  var lessonStatus = $('#dzn-classroom-lesson-status');
  var alertBox = $('#dzn-classroom-alert');
  var course = $('#dzn-classroom-course');
  var teacher = $('#dzn-classroom-teacher');
  var student = $('#dzn-classroom-student');
  var roleBadge = $('#dzn-classroom-role');
  var participants = $('#dzn-classroom-participants');
  var localVideo = $('#dzn-classroom-local');
  var localPlaceholder = $('#dzn-classroom-local-placeholder');
  var remoteVideo = $('#dzn-classroom-remote-video');
  var remoteAudio = $('#dzn-classroom-remote-audio');
  var remotePlaceholder = $('#dzn-classroom-remote-placeholder');
  var localLabel = $('#dzn-classroom-local-label');
  var remoteLabel = $('#dzn-classroom-remote-label');
  var micButton = $('#dzn-classroom-mic');
  var cameraButton = $('#dzn-classroom-camera');
  var music = $('#dzn-classroom-music');
  var micDevice = $('#dzn-classroom-mic-device');
  var cameraDevice = $('#dzn-classroom-camera-device');
  var leave = $('#dzn-classroom-leave');
  var meetForm = $('#dzn-classroom-meet');

  if (!LK || !LK.Room) {
    setConnection('کتابخانهٔ کلاس بارگذاری نشد. صفحه را دوباره باز کنید.', 'error');
    return;
  }

  var state = {
    ticket: readTicket(),
    ticketClaim: null,
    room: null,
    session: null,
    micOn: true,
    cameraOn: true,
    musicOn: false,
    connecting: false,
    leaving: false,
    reconnectAttempt: 0,
    reconnectTimer: null
  };
  state.ticketClaim = readTicketClaim(state.ticket);

  if (!state.ticket) {
    setConnection('پیوند ورود به کلاس معتبر نیست.', 'error');
    showAlert('لطفاً از کارت کلاس در پرتال خود دوباره «ورود به کلاس» را انتخاب کنید.');
    return;
  }

  /* ---------------------------------------------------------------------
   * Session and connection lifecycle
   * ------------------------------------------------------------------- */

  function requestSession() {
    return fetch(config.sessionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
      body: JSON.stringify({ ticket: state.ticket })
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (!response.ok) {
          var error = new Error((body && body.code) || 'livekit_unavailable');
          error.code = (body && body.code) || 'livekit_unavailable';
          throw error;
        }
        return body;
      });
    });
  }

  function roomOptions() {
    var preset = state.musicOn ? (LK.AudioPresets.musicHighQualityStereo || LK.AudioPresets.music) : LK.AudioPresets.speech;
    return {
      adaptiveStream: true,
      dynacast: true,
      audioCaptureDefaults: captureOptions(),
      videoCaptureDefaults: { resolution: { width: 1280, height: 720, frameRate: 24 } },
      publishDefaults: {
        audioPreset: preset,
        dtx: false,
        red: false,
        forceStereo: state.musicOn,
        videoCodec: 'h264',
        simulcast: true
      }
    };
  }

  function captureOptions() {
    if (state.musicOn) {
      return {
        echoCancellation: false,
        noiseSuppression: false,
        autoGainControl: false,
        channelCount: 2,
        sampleRate: 48000,
        sampleSize: 16
      };
    }
    return {
      echoCancellation: true,
      noiseSuppression: true,
      autoGainControl: true,
      channelCount: 1
    };
  }

  function publishOptions() {
    return {
      audioPreset: state.musicOn ? (LK.AudioPresets.musicHighQualityStereo || LK.AudioPresets.music) : LK.AudioPresets.speech,
      dtx: false,
      red: false,
      forceStereo: state.musicOn
    };
  }

  async function connect(isReconnect) {
    if (state.connecting || state.leaving) return;
    state.connecting = true;
    clearAlert();
    setConnection(isReconnect ? 'در حال اتصال دوباره…' : 'در حال اتصال به کلاس…', 'connecting');
    try {
      var session = await requestSession();
      state.session = session;
      renderSession(session);
      await startRoom(session);
      state.reconnectAttempt = 0;
      setConnection('کلاس متصل است.', 'connected');
    } catch (error) {
      if (isReconnect) {
        scheduleReconnect();
      } else {
        handleFatalSession(error);
      }
    } finally {
      state.connecting = false;
    }
  }

  async function startRoom(session) {
    var previous = state.room;
    state.room = null;
    if (previous) {
      try { await previous.disconnect(); } catch (ignored) {}
    }
    var room = new LK.Room(roomOptions());
    state.room = room;
    bindRoom(room);
    if (localVideo) {
      localVideo.muted = true;
      localVideo.setAttribute('playsinline', '');
      localVideo.setAttribute('autoplay', '');
    }
    await room.connect(session.url, session.token, { autoSubscribe: true });
    if (state.room !== room) return;
    await enableMicrophone();
    await enableCamera();
    setControlsEnabled(true);
    updateParticipants();
    refreshDevices();
  }

  function bindRoom(room) {
    room.on(LK.RoomEvent.Reconnecting, function () {
      if (state.room !== room) return;
      setConnection('اتصال ناپایدار است؛ تلاش برای اتصال دوباره…', 'reconnecting');
    });
    room.on(LK.RoomEvent.Reconnected, function () {
      if (state.room !== room) return;
      state.reconnectAttempt = 0;
      setConnection('اتصال دوباره برقرار شد.', 'connected');
      clearAlert();
      updateParticipants();
    });
    room.on(LK.RoomEvent.Disconnected, function (reason) {
      if (state.room !== room) return;
      if (state.leaving) {
        setConnection('از کلاس خارج شدید.', 'ended');
        return;
      }
      if (reason === LK.DisconnectReason.DUPLICATE_IDENTITY) {
        setConnection('این حساب در دستگاه یا تب دیگری وارد شده است.', 'error');
        showAlert('برای حضور در کلاس، فقط یک تب یا دستگاه را باز نگه دارید.');
        showMeetFallback(true);
        return;
      }
      setConnection('اتصال کلاس قطع شد.', 'reconnecting');
      scheduleReconnect();
    });
    room.on(LK.RoomEvent.ParticipantConnected, function (participant) {
      if (state.room !== room) return;
      updateParticipants(participant);
      if (remotePlaceholder) remotePlaceholder.hidden = true;
    });
    room.on(LK.RoomEvent.ParticipantDisconnected, function () {
      if (state.room !== room) return;
      clearRemoteMedia();
      updateParticipants();
      if (remotePlaceholder) {
        remotePlaceholder.hidden = false;
        remotePlaceholder.textContent = waitingMessage();
      }
    });
    room.on(LK.RoomEvent.TrackSubscribed, function (track, publication, participant) {
      if (state.room !== room) return;
      if (track.kind === LK.Track.Kind.Video) attachRemoteVideo(track);
      if (track.kind === LK.Track.Kind.Audio) attachRemoteAudio(track);
      if (participant) updateParticipants(participant);
    });
    room.on(LK.RoomEvent.TrackUnsubscribed, function (track) {
      if (state.room !== room) return;
      track.detach();
    });
  }

  function scheduleReconnect() {
    if (state.leaving || state.connecting) return;
    if (state.reconnectAttempt >= 4) {
      setConnection('اتصال به کلاس برقرار نشد.', 'error');
      showAlert('اتصال به کلاس زنده ممکن نشد. می‌توانید از گزینهٔ پشتیبان گوگل میت استفاده کنید یا به پرتال بازگردید.');
      showMeetFallback(ticketMeetAvailable());
      setControlsEnabled(false);
      return;
    }
    state.reconnectAttempt += 1;
    var delay = Math.min(8000, 1000 * Math.pow(2, state.reconnectAttempt - 1));
    setConnection('تلاش دوباره برای اتصال… (' + toPersianDigits(state.reconnectAttempt) + ' از ۴)', 'reconnecting');
    state.reconnectTimer = window.setTimeout(function () {
      state.reconnectTimer = null;
      connect(true);
    }, delay);
  }

  function handleFatalSession(error) {
    var code = (error && error.code) || 'livekit_unavailable';
    setControlsEnabled(false);
    if (code === 'livekit_link_expired') {
      setConnection('پیوند ورود منقضی شده است.', 'error');
      showAlert('زمان این پیوند ورود به پایان رسیده است. به پرتال بازگردید و دوباره «ورود به کلاس» را انتخاب کنید.');
      return;
    }
    if (code === 'livekit_access_denied') {
      setConnection('دسترسی به این کلاس تأیید نشد.', 'error');
      showAlert('این کلاس برای حساب شما در دسترس نیست یا زمان ورود آن نرسیده است. به پرتال بازگردید و وضعیت کلاس را بررسی کنید.');
      return;
    }
    if (code === 'livekit_rate_limited') {
      setConnection('درخواست‌های ورود بیش از حد مجاز است.', 'error');
      showAlert('لطفاً یک دقیقه صبر کنید و دوباره تلاش کنید.');
      return;
    }
    setConnection('کلاس زندهٔ دلنوازان در دسترس نیست.', 'error');
    showAlert('سرویس کلاس زنده در این لحظه پاسخ نمی‌دهد. اگر گزینهٔ پشتیبان فعال است، از دکمهٔ «ورود با گوگل میت» استفاده کنید.');
    showMeetFallback(ticketMeetAvailable());
  }

  /* ---------------------------------------------------------------------
   * Media controls
   * ------------------------------------------------------------------- */

  async function enableMicrophone() {
    if (!state.room) return;
    try {
      await state.room.localParticipant.setMicrophoneEnabled(true, captureOptions(), publishOptions());
      state.micOn = true;
    } catch (error) {
      state.micOn = false;
      showAlert(mediaError(error, 'میکروفن'));
    }
    updateMicButton();
  }

  async function enableCamera() {
    if (!state.room) return;
    try {
      await state.room.localParticipant.setCameraEnabled(true, { resolution: { width: 1280, height: 720, frameRate: 24 } });
      state.cameraOn = true;
      attachLocalCamera();
    } catch (error) {
      state.cameraOn = false;
      showAlert(mediaError(error, 'دوربین'));
    }
    updateCameraButton();
  }

  async function setMicrophone(on) {
    if (!state.room) return;
    try {
      await state.room.localParticipant.setMicrophoneEnabled(on, captureOptions(), publishOptions());
      state.micOn = on;
      clearAlert();
    } catch (error) {
      state.micOn = !on ? true : false;
      showAlert(mediaError(error, 'میکروفن'));
    }
    updateMicButton();
    refreshDevices();
  }

  async function setCamera(on) {
    if (!state.room) return;
    try {
      await state.room.localParticipant.setCameraEnabled(on, { resolution: { width: 1280, height: 720, frameRate: 24 } });
      state.cameraOn = on;
      if (on) attachLocalCamera();
      else detachLocalCamera();
      clearAlert();
    } catch (error) {
      state.cameraOn = !on ? true : false;
      showAlert(mediaError(error, 'دوربین'));
    }
    updateCameraButton();
    refreshDevices();
  }

  async function changeMusicMode() {
    state.musicOn = !!music.checked;
    if (state.room && state.micOn) {
      setConnection(state.musicOn ? 'در حال فعال‌سازی حالت موسیقی…' : 'در حال بازگشت به حالت گفت‌وگو…', 'connected');
      try {
        await state.room.localParticipant.setMicrophoneEnabled(false);
        await state.room.localParticipant.setMicrophoneEnabled(true, captureOptions(), publishOptions());
        setConnection('کلاس متصل است.', 'connected');
      } catch (error) {
        showAlert(mediaError(error, 'میکروفن') + ' حالت قبلی صدا حفظ شد.');
        setConnection('کلاس متصل است.', 'connected');
      }
    }
    updateMusicLabel();
  }

  function attachLocalCamera() {
    var publication = state.room && state.room.localParticipant.getTrackPublication(LK.Track.Source.Camera);
    if (publication && publication.track && localVideo) {
      publication.track.attach(localVideo);
      localVideo.play().catch(function () {});
      if (localPlaceholder) localPlaceholder.hidden = true;
    }
  }

  function detachLocalCamera() {
    if (localVideo) {
      localVideo.srcObject = null;
      if (localPlaceholder) localPlaceholder.hidden = false;
    }
  }

  function attachRemoteVideo(track) {
    if (!remoteVideo) return;
    remoteVideo.replaceChildren();
    var element = document.createElement('video');
    element.autoplay = true;
    element.playsInline = true;
    element.setAttribute('playsinline', '');
    element.setAttribute('aria-label', 'ویدئوی شرکت‌کنندهٔ دیگر');
    remoteVideo.appendChild(element);
    track.attach(element);
    element.play().catch(function () {});
    if (remotePlaceholder) remotePlaceholder.hidden = true;
  }

  function attachRemoteAudio(track) {
    if (!remoteAudio) return;
    remoteAudio.replaceChildren();
    var element = document.createElement('audio');
    element.autoplay = true;
    element.setAttribute('playsinline', '');
    remoteAudio.appendChild(element);
    track.attach(element);
    element.play().catch(function () {});
  }

  function clearRemoteMedia() {
    if (remoteVideo) remoteVideo.replaceChildren();
    if (remoteAudio) remoteAudio.replaceChildren();
  }

  async function refreshDevices() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    try {
      var devices = await navigator.mediaDevices.enumerateDevices();
      fillDeviceSelect(micDevice, devices.filter(function (d) { return d.kind === 'audioinput'; }), 'میکروفن');
      fillDeviceSelect(cameraDevice, devices.filter(function (d) { return d.kind === 'videoinput'; }), 'دوربین');
    } catch (ignored) {}
  }

  function fillDeviceSelect(select, devices, label) {
    if (!select) return;
    var current = select.value;
    select.replaceChildren();
    var fallback = document.createElement('option');
    fallback.value = '';
    fallback.textContent = 'پیش‌فرض سیستم';
    select.appendChild(fallback);
    devices.forEach(function (device, index) {
      var option = document.createElement('option');
      option.value = device.deviceId;
      option.textContent = device.label || (label + ' ' + toPersianDigits(index + 1));
      select.appendChild(option);
    });
    if (current) select.value = current;
    select.disabled = devices.length === 0;
  }

  async function switchDevice(kind, deviceId) {
    if (!state.room || !deviceId) return;
    try {
      await state.room.switchActiveDevice(kind, deviceId);
      clearAlert();
    } catch (error) {
      showAlert('تغییر دستگاه ' + (kind === 'audioinput' ? 'میکروفن' : 'دوربین') + ' انجام نشد.');
    }
  }

  /* ---------------------------------------------------------------------
   * UI state
   * ------------------------------------------------------------------- */

  function renderSession(session) {
    if (course) course.textContent = session.course_name || 'کلاس موسیقی';
    if (teacher) teacher.textContent = session.teacher_name || 'مدرس';
    if (student) student.textContent = session.student_name || 'هنرجو';
    if (localLabel) localLabel.textContent = 'شما · ' + (session.display_name || '');
    if (roleBadge) {
      roleBadge.textContent = session.role === 'teacher' ? 'مدرس' : 'هنرجو';
      roleBadge.hidden = false;
    }
    if (remotePlaceholder) {
      remotePlaceholder.hidden = false;
      remotePlaceholder.textContent = waitingMessage();
    }
    if (remoteLabel) remoteLabel.textContent = session.peer_name || 'شرکت‌کننده';
    if (lessonStatus) {
      lessonStatus.textContent = lessonStatusText(session.starts_at_utc, session.ends_at_utc);
      lessonStatus.hidden = false;
    }
    showMeetFallback(!!session.meet_available);
  }

  function lessonStatusText(starts, ends) {
    var start = parseUtc(starts);
    var end = parseUtc(ends);
    if (!start || !end) return 'وضعیت زمان کلاس نامشخص است';
    var now = Date.now();
    if (now < start - 60 * 60 * 1000) return 'کلاس به‌زودی آغاز می‌شود';
    if (now < start) return 'کلاس در آستانهٔ آغاز است';
    if (now <= end) return 'کلاس در جریان است';
    return 'زمان کلاس به پایان رسیده است';
  }

  function parseUtc(value) {
    if (!value) return null;
    var parsed = Date.parse(String(value).replace(' ', 'T') + 'Z');
    return Number.isNaN(parsed) ? null : parsed;
  }

  function updateParticipants(participant) {
    var room = state.room;
    var count = room ? room.remoteParticipants.size + 1 : 1;
    if (participants) participants.textContent = toPersianDigits(count) + ' از ۲ نفر';
    if (participant && remoteLabel) remoteLabel.textContent = participant.name || participant.identity || 'شرکت‌کننده';
  }

  function waitingMessage() {
    return state.session && state.session.role === 'teacher' ? 'در انتظار ورود هنرجو…' : 'در انتظار ورود مدرس…';
  }

  function updateMicButton() {
    if (!micButton) return;
    micButton.setAttribute('aria-pressed', String(state.micOn));
    micButton.classList.toggle('is-off', !state.micOn);
    micButton.querySelector('span').textContent = state.micOn ? 'میکروفن روشن' : 'میکروفن خاموش';
  }

  function updateCameraButton() {
    if (!cameraButton) return;
    cameraButton.setAttribute('aria-pressed', String(state.cameraOn));
    cameraButton.classList.toggle('is-off', !state.cameraOn);
    cameraButton.querySelector('span').textContent = state.cameraOn ? 'دوربین روشن' : 'دوربین خاموش';
  }

  function updateMusicLabel() {
    if (!music) return;
    var small = music.closest('.dzn-classroom__switch').querySelector('small');
    if (small) small.textContent = state.musicOn ? 'استریو و ۱۲۸ کیلوبیت بر ثانیه در صورت پشتیبانی، بدون حذف نویز' : 'کیفیت گفت‌وگو با حذف نویز و کنترل خودکار صدا';
  }

  function setControlsEnabled(enabled) {
    [micButton, cameraButton, music, leave].forEach(function (control) {
      if (control) control.disabled = !enabled;
    });
    if (!enabled) {
      if (micDevice) micDevice.disabled = true;
      if (cameraDevice) cameraDevice.disabled = true;
    }
  }

  function setConnection(message, mode) {
    if (connectionText) connectionText.textContent = message;
    if (connection) connection.setAttribute('data-state', mode || 'connecting');
  }

  function showAlert(message) {
    if (!alertBox) return;
    alertBox.textContent = message;
    alertBox.hidden = false;
  }

  function clearAlert() {
    if (!alertBox) return;
    alertBox.textContent = '';
    alertBox.hidden = true;
  }

  function showMeetFallback(show) {
    if (!meetForm) return;
    meetForm.hidden = !show;
    var input = meetForm.querySelector('input[name="ticket"]');
    if (input) input.value = state.ticket;
  }

  function ticketMeetAvailable() {
    return !!(state.ticketClaim && state.ticketClaim.meet_available === true);
  }

  function mediaError(error, device) {
    var name = error && error.name ? error.name : '';
    if (name === 'NotAllowedError' || name === 'SecurityError') return 'دسترسی به ' + device + ' رد شده است. از تنظیمات مرورگر اجازهٔ دسترسی بدهید و دوباره تلاش کنید.';
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') return device + ' پیدا نشد. اتصال دستگاه را بررسی کنید.';
    if (name === 'NotReadableError' || name === 'TrackStartError') return device + ' در اختیار برنامهٔ دیگری است یا خوانده نمی‌شود.';
    if (name === 'OverconstrainedError') return 'دستگاه ' + device + ' کیفیت درخواستی را پشتیبانی نمی‌کند؛ حالت گفت‌وگو را امتحان کنید.';
    return 'تنظیم ' + device + ' انجام نشد.';
  }

  function readTicket() {
    var hash = String(window.location.hash || '');
    var match = hash.match(/(?:^#|&)ticket=([^&]+)/);
    if (!match) return '';
    try {
      var ticket = decodeURIComponent(match[1]);
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, document.title, window.location.pathname + window.location.search);
      }
      return ticket;
    } catch (ignored) {
      return '';
    }
  }

  function readTicketClaim(ticket) {
    if (!ticket) return null;
    var body = String(ticket).split('.')[0] || '';
    body = body.replace(/-/g, '+').replace(/_/g, '/');
    while (body.length % 4) body += '=';
    try {
      var json = decodeURIComponent(Array.prototype.map.call(window.atob(body), function (character) {
        return '%' + ('00' + character.charCodeAt(0).toString(16)).slice(-2);
      }).join(''));
      return JSON.parse(json);
    } catch (ignored) {
      return null;
    }
  }

  function toPersianDigits(value) {
    return String(value).replace(/[0-9]/g, function (digit) {
      return '۰۱۲۳۴۵۶۷۸۹'[Number(digit)];
    });
  }

  /* ---------------------------------------------------------------------
   * Events
   * ------------------------------------------------------------------- */

  if (micButton) micButton.addEventListener('click', function () { setMicrophone(!state.micOn); });
  if (cameraButton) cameraButton.addEventListener('click', function () { setCamera(!state.cameraOn); });
  if (music) music.addEventListener('change', changeMusicMode);
  if (micDevice) micDevice.addEventListener('change', function () { switchDevice('audioinput', micDevice.value); });
  if (cameraDevice) cameraDevice.addEventListener('change', function () { switchDevice('videoinput', cameraDevice.value); });
  if (leave) {
    leave.addEventListener('click', function () {
      state.leaving = true;
      if (state.reconnectTimer) window.clearTimeout(state.reconnectTimer);
      setControlsEnabled(false);
      if (state.room) state.room.disconnect();
      setConnection('از کلاس خارج شدید.', 'ended');
    });
  }
  if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener('devicechange', refreshDevices);
  }
  window.addEventListener('pagehide', function () {
    state.leaving = true;
    if (state.room) state.room.disconnect();
  });

  updateMicButton();
  updateCameraButton();
  updateMusicLabel();
  connect(false);
})();
