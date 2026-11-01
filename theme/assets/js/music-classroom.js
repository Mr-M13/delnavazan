(function () {
  'use strict';

  var config = window.dznMusicClassroom || {};
  var LK = window.LivekitClient;
  var root = document.querySelector('.dzn-classroom');
  if (!root) return;

  var MUSIC_TOPIC = 'dzn-music-mode';
  var MUSIC_MESSAGE_VERSION = 1;
  var UID_PATTERN = /^[0-9A-Z]{26}$/;
  var portalUrls = {
    teacher: config.teacherPortalUrl || '/teacher-portal/',
    student: config.studentPortalUrl || '/student-portal/'
  };

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
  var stage = $('.dzn-classroom__stage');
  var rotateButton = $('#dzn-classroom-rotate');
  var fullscreenButton = $('#dzn-classroom-fullscreen');
  var finishButton = $('#dzn-classroom-finish');
  var musicStatus = $('#dzn-classroom-music-status');

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
    reconnectTimer: null,
    ignoreDisconnectRoom: null,
    rotation: 0,
    rotationObserver: null,
    musicChangedBy: '',
    musicStamp: { at: 0, sender: '' },
    musicApplyToken: 0,
    ending: false
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
    var failure = null;
    try {
      var session = await requestSession();
      state.session = session;
      renderSession(session);
      await startRoom(session);
      state.reconnectAttempt = 0;
      setConnection('کلاس متصل است.', 'connected');
    } catch (error) {
      failure = error;
    } finally {
      state.connecting = false;
    }
    if (failure) {
      if (isReconnect) scheduleReconnect();
      else handleFatalSession(failure);
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
    await connectWithTimeout(room, session);
    if (state.room !== room) return;
    // Publish the connected state before requesting media: a slow device-permission or capture
    // prompt must not leave the classroom looking disconnected after the LiveKit session is live.
    setConnection('کلاس متصل است.', 'connected');
    setControlsEnabled(true);
    updateParticipants();
    refreshDevices();
    await enableMicrophone();
    await enableCamera();
  }


  function connectWithTimeout(room, session) {
    return new Promise(function (resolve, reject) {
      var settled = false;
      var timer = window.setTimeout(function () {
        if (settled) return;
        settled = true;
        state.ignoreDisconnectRoom = room;
        try { room.disconnect(); } catch (ignored) {}
        reject(new Error('connect_timeout'));
      }, 15000);
      room.connect(session.url, session.token, { autoSubscribe: true }).then(function () {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        resolve();
      }).catch(function (error) {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        reject(error);
      });
    });
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
      // State survives the reconnect in memory; re-announce it so a peer that joined or
      // reconnected while this participant was away converges on the same shared mode.
      broadcastMusicMode();
    });
    room.on(LK.RoomEvent.Disconnected, function (reason) {
      if (state.room !== room) return;
      if (state.ignoreDisconnectRoom === room) { state.ignoreDisconnectRoom = null; return; }
      if (state.leaving) {
        setConnection('از کلاس خارج شدید.', 'ended');
        return;
      }
      if (reason === LK.DisconnectReason.DUPLICATE_IDENTITY) {
        setConnection('این حساب در دستگاه یا تب دیگری وارد شده است.', 'error');
        showAlert('برای حضور در کلاس، فقط یک تب یا دستگاه را باز نگه دارید.');
        showMeetFallback(ticketMeetAvailable());
        return;
      }
      setConnection('اتصال کلاس قطع شد.', 'reconnecting');
      scheduleReconnect();
    });
    room.on(LK.RoomEvent.ParticipantConnected, function (participant) {
      if (state.room !== room) return;
      updateParticipants(participant);
      if (remotePlaceholder) remotePlaceholder.hidden = true;
      // The existing participant is the source of truth for a newcomer or a page that has
      // just reloaded, so announce the shared mode without waiting for a local toggle.
      broadcastMusicMode();
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
    room.on(LK.RoomEvent.DataReceived, function (payload, participant, kind, topic) {
      if (state.room !== room) return;
      handleMusicModeMessage(payload, participant, topic);
    });
  }

  function scheduleReconnect() {
    if (state.leaving || state.reconnectTimer) return;
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
    state.musicChangedBy = 'شما';
    state.musicStamp = { at: Date.now(), sender: localIdentity() };
    updateMusicLabel();
    broadcastMusicMode();
    await reconfigureAudioForMusic();
  }

  function localIdentity() {
    var room = state.room;
    return room && room.localParticipant && room.localParticipant.identity ? String(room.localParticipant.identity) : '';
  }

  /**
   * Room-level music mode is a shared presentation request, not a device guarantee. The sender
   * announces the desired shared state; each participant then applies its own supported
   * capture/publish configuration locally.
   */
  function broadcastMusicMode() {
    var room = state.room;
    if (!room || !room.localParticipant) return;
    var message = {
      enabled: !!state.musicOn,
      sender: room.localParticipant.identity || '',
      at: state.musicStamp && state.musicStamp.at ? state.musicStamp.at : Date.now(),
      version: MUSIC_MESSAGE_VERSION
    };
    try {
      var published = room.localParticipant.publishData(encodeDataMessage(message), {
        reliable: true,
        topic: MUSIC_TOPIC
      });
      if (published && typeof published.catch === 'function') published.catch(function () {});
    } catch (ignored) {
      // Data messages are best-effort; the local audio configuration was still applied.
    }
  }

  function handleMusicModeMessage(payload, participant, topic) {
    if (topic !== MUSIC_TOPIC) return;
    var room = state.room;
    if (!room) return;
    var message = decodeDataMessage(payload);
    if (!message || message.version !== MUSIC_MESSAGE_VERSION || typeof message.enabled !== 'boolean') return;
    var sender = String(message.sender || (participant && participant.identity) || '');
    var ownIdentity = localIdentity();
    if (sender && ownIdentity && sender === ownIdentity) return;
    if (participant && participant.identity && sender && String(participant.identity) !== sender) return;
    // Both peers may announce at once on a simultaneous reconnect. The highest
    // (timestamp, sender) tuple wins, so both converge on one shared state.
    var at = Number(message.at) || 0;
    var previous = state.musicStamp || { at: 0, sender: '' };
    if (at < previous.at || (at === previous.at && sender <= String(previous.sender || ''))) return;
    state.musicStamp = { at: at, sender: sender };
    var enabled = message.enabled === true;
    var changed = state.musicOn !== enabled;
    state.musicOn = enabled;
    state.musicChangedBy = (participant && (participant.name || participant.identity)) || 'شرکت‌کنندهٔ دیگر';
    if (music) music.checked = enabled;
    updateMusicLabel();
    // Receiving a state never re-broadcasts: that is what prevents an echo loop between peers.
    if (changed) reconfigureAudioForMusic();
  }

  function encodeDataMessage(message) {
    var text = JSON.stringify(message);
    if (typeof window.TextEncoder === 'function') return new window.TextEncoder().encode(text);
    var bytes = new Uint8Array(text.length);
    for (var index = 0; index < text.length; index += 1) bytes[index] = text.charCodeAt(index) & 0xff;
    return bytes;
  }

  function decodeDataMessage(payload) {
    if (!payload) return null;
    try {
      var text;
      if (typeof window.TextDecoder === 'function') {
        text = new window.TextDecoder().decode(payload);
      } else {
        text = '';
        for (var index = 0; index < payload.length; index += 1) text += String.fromCharCode(payload[index]);
      }
      var message = JSON.parse(text);
      return message && typeof message === 'object' ? message : null;
    } catch (ignored) {
      return null;
    }
  }

  async function reconfigureAudioForMusic() {
    var room = state.room;
    if (!room || !state.micOn) {
      updateMusicLabel();
      return;
    }
    var token = ++state.musicApplyToken;
    setConnection('در حال به‌روزرسانی تنظیمات صدا…', 'connected');
    try {
      await room.localParticipant.setMicrophoneEnabled(false);
      if (token !== state.musicApplyToken || state.room !== room) return;
      await room.localParticipant.setMicrophoneEnabled(true, captureOptions(), publishOptions());
      if (token !== state.musicApplyToken || state.room !== room) return;
      state.micOn = true;
      clearAlert();
      setConnection('کلاس متصل است.', 'connected');
    } catch (error) {
      if (token !== state.musicApplyToken) return;
      state.micOn = false;
      setConnection('کلاس متصل است.', 'connected');
      showAlert(mediaError(error, 'میکروفن') + ' میکروفن را دوباره روشن کنید؛ حالت موسیقی به‌عنوان درخواست مشترک باقی می‌ماند.');
    }
    updateMicButton();
    updateMusicLabel();
    refreshDevices();
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
   * Layout, rotation, and fullscreen
   * ------------------------------------------------------------------- */

  function rotationLabel(value) {
    if (value === 90) return '۹۰ درجه';
    if (value === 180) return '۱۸۰ درجه';
    if (value === 270) return '۲۷۰ درجه';
    return 'بدون چرخش';
  }

  function syncRotationButton() {
    if (!rotateButton) return;
    var label = rotateButton.querySelector('span');
    if (label) label.textContent = 'چرخش: ' + toPersianDigits(state.rotation) + '°';
    rotateButton.setAttribute('aria-pressed', String(state.rotation !== 0));
    rotateButton.setAttribute('aria-label', 'چرخش نمایش ویدئو: ' + rotationLabel(state.rotation) + ' (فقط نمایش محلی)');
    rotateButton.title = 'چرخش نمایش ویدئو: ' + rotationLabel(state.rotation) + ' — جهت دوربین ارسالی تغییر نمی‌کند';
  }

  function updateRotationGeometry() {
    if (!stage) return;
    var angle = state.rotation || 0;
    // A local presentation transform only. The transmitted camera orientation is untouched.
    stage.style.setProperty('--dzn-rotation', angle + 'deg');
    stage.setAttribute('data-rotation', String(angle));
    if (angle !== 90 && angle !== 270) {
      Array.prototype.forEach.call(stage.querySelectorAll('.dzn-classroom__tile'), function (tile) {
        tile.style.removeProperty('--dzn-rotate-width');
        tile.style.removeProperty('--dzn-rotate-height');
      });
      return;
    }
    Array.prototype.forEach.call(stage.querySelectorAll('.dzn-classroom__tile'), function (tile) {
      var rect = tile.getBoundingClientRect();
      if (rect.width < 1 || rect.height < 1) return;
      tile.style.setProperty('--dzn-rotate-width', rect.height + 'px');
      tile.style.setProperty('--dzn-rotate-height', rect.width + 'px');
    });
  }

  function toggleRotation() {
    if (!stage) return;
    state.rotation = (state.rotation + 90) % 360;
    updateRotationGeometry();
    syncRotationButton();
  }

  function nativeFullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || null;
  }

  function syncFullscreenButton() {
    if (!fullscreenButton || !root) return;
    var active = !!nativeFullscreenElement() || root.classList.contains('is-faux-fullscreen');
    fullscreenButton.setAttribute('aria-pressed', String(active));
    var label = fullscreenButton.querySelector('span');
    if (label) label.textContent = active ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه';
    fullscreenButton.title = active ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه';
  }

  function enterFauxFullscreen() {
    if (!root) return;
    root.classList.add('is-faux-fullscreen');
    document.body.classList.add('dzn-classroom-faux-active');
    syncFullscreenButton();
    window.setTimeout(updateRotationGeometry, 60);
  }

  function exitFauxFullscreen() {
    if (!root) return;
    root.classList.remove('is-faux-fullscreen');
    document.body.classList.remove('dzn-classroom-faux-active');
    syncFullscreenButton();
    updateRotationGeometry();
  }

  function toggleFullscreen() {
    if (!root) return;
    if (nativeFullscreenElement()) {
      var exit = document.exitFullscreen || document.webkitExitFullscreen;
      if (exit) { try { exit.call(document); } catch (ignored) {} }
      return;
    }
    if (root.classList.contains('is-faux-fullscreen')) { exitFauxFullscreen(); return; }
    var request = root.requestFullscreen || root.webkitRequestFullscreen;
    if (!request) { enterFauxFullscreen(); return; }
    try {
      var result = request.call(root);
      if (result && typeof result.catch === 'function') result.catch(function () { enterFauxFullscreen(); });
    } catch (ignored) {
      enterFauxFullscreen();
    }
    window.setTimeout(function () { if (!nativeFullscreenElement() && !root.classList.contains('is-faux-fullscreen')) enterFauxFullscreen(); }, 350);
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
    if (finishButton) finishButton.hidden = !teacherFinishAvailable(session);
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
    var toggle = music.closest('.dzn-classroom__switch');
    var small = toggle ? toggle.querySelector('small') : null;
    if (small) {
      small.textContent = state.musicOn
        ? 'حالت مشترک روشن: درخواست استریو و کیفیت بالاتر بدون حذف نویز؛ دستگاه شما ممکن است همه را نپذیرد.'
        : 'حالت مشترک خاموش: کیفیت گفت‌وگو با حذف نویز و کنترل خودکار صدا.';
    }
    if (!musicStatus) return;
    if (!state.musicOn) {
      musicStatus.hidden = true;
      musicStatus.textContent = '';
      return;
    }
    var who = state.musicChangedBy || 'یکی از شرکت‌کنندگان';
    var verb = who === 'شما' ? 'روشن کردید' : 'روشن کرد';
    musicStatus.hidden = false;
    musicStatus.textContent = who + ' حالت موسیقی را برای هر دو نفر ' + verb +
      '. این یک درخواست است و کیفیت نهایی به مرورگر و دستگاه بستگی دارد؛ ممکن است همهٔ تنظیمات اعمال نشود.';
  }

  function setControlsEnabled(enabled) {
    [micButton, cameraButton, music, leave, rotateButton, fullscreenButton, finishButton].forEach(function (control) {
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

  /* ---------------------------------------------------------------------
   * Leaving, window close, and teacher completion evidence
   * ------------------------------------------------------------------- */

  function teacherFinishAvailable(session) {
    if (!session || session.role !== 'teacher') return false;
    if (!config.finishActionUrl || !config.finishNonce) return false;
    return UID_PATTERN.test(String(session.lesson_uid || ''))
      && UID_PATTERN.test(String(session.schedule_version_uid || ''));
  }

  function portalUrlForRole() {
    return state.session && state.session.role === 'teacher' ? portalUrls.teacher : portalUrls.student;
  }

  function stopAllMedia() {
    var room = state.room;
    if (room && room.localParticipant && room.localParticipant.trackPublications) {
      room.localParticipant.trackPublications.forEach(function (publication) {
        var track = publication && publication.track;
        if (!track) return;
        try { if (typeof track.stop === 'function') track.stop(); } catch (ignored) {}
        try { if (typeof track.detach === 'function') track.detach(); } catch (ignored) {}
      });
    }
    if (localVideo) {
      try { localVideo.pause(); } catch (ignored) {}
      localVideo.srcObject = null;
    }
    clearRemoteMedia();
  }

  function closeOrRedirect(destination) {
    var url = typeof destination === 'string' ? destination : '';
    var redirected = false;
    var go = function () {
      if (redirected) return;
      redirected = true;
      if (!url) return;
      try { window.location.replace(url); } catch (ignored) { window.location.href = url; }
    };
    // Browsers only honour window.close() for script-opened windows. The short fallback timer
    // keeps the portal return reliable on normal tabs and mobile browsers.
    try { window.close(); } catch (ignored) {}
    window.setTimeout(go, 350);
  }

  function exitClassroom() {
    // Student Exit (and the teacher's ordinary leave) is pure cleanup. It deliberately never
    // posts delivery evidence; only the teacher's explicit Finish action can do that.
    endClassroom(portalUrlForRole(), 'از کلاس خارج شدید.');
  }

  async function finishClass() {
    if (!state.session || !teacherFinishAvailable(state.session)) {
      showAlert('شناسه‌های کانونی این کلاس در دسترس نیستند؛ پایان کلاس از این صفحه ثبت نمی‌شود. وضعیت کلاس را از پرتال مدرس بررسی کنید.');
      return;
    }
    if (!config.finishActionUrl || !config.finishNonce) {
      showAlert('ثبت پایان کلاس در این لحظه در دسترس نیست. وضعیت کلاس را از پرتال مدرس بررسی کنید.');
      return;
    }
    if (!state.room) {
      showAlert('برای ثبت پایان کلاس، اتصال کلاس باید برقرار باشد.');
      return;
    }
    if (finishButton) finishButton.disabled = true;
    setConnection('در حال ثبت پایان کلاس…', 'connecting');
    var body = new FormData();
    body.append('_wpnonce', config.finishNonce);
    body.append('lesson_uid', String(state.session.lesson_uid));
    body.append('schedule_version_uid', String(state.session.schedule_version_uid));
    try {
      var response = await fetch(config.finishActionUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: body,
        redirect: 'follow'
      });
      var finalUrl = String(response.url || '');
      var statusMatch = finalUrl.match(/[?&]class_status=([a-z0-9_]+)(?:&|$)/);
      var classStatus = statusMatch ? statusMatch[1] : '';
      // `delivery_recorded` is the pre-confirmation claim status; `class_completed` is the
      // canonical Teacher-confirmation outcome. Both are successful server acknowledgements.
      var recorded = response.ok && (classStatus === 'delivery_recorded' || classStatus === 'class_completed');
      if (!recorded) throw new Error(classStatus || 'finish_not_recorded');
      clearAlert();
      await endClassroom(portalUrls.teacher, 'کلاس به پایان رسید و گزارش برگزاری ثبت شد.');
    } catch (error) {
      if (finishButton) finishButton.disabled = false;
      setConnection('ثبت پایان کلاس انجام نشد.', 'error');
      var refusals = {
        completion_too_early: 'زمان برنامه‌ریزی‌شدهٔ کلاس هنوز به پایان نرسیده است؛ پایان کلاس ثبت نشد.',
        completion_not_allowed: 'این کلاس در وضعیت فعلی قابل تأیید نیست؛ ممکن است حضور آن قبلاً ثبت شده یا نیازمند بررسی مدیر باشد.',
        completion_unavailable: 'ثبت پایان کلاس در این لحظه ممکن نشد؛ اتصال و رسانهٔ کلاس قطع نشد.'
      };
      showAlert(refusals[error && error.message] || 'پایان کلاس ثبت نشد؛ اتصال و رسانهٔ کلاس قطع نشد. لطفاً دوباره تلاش کنید یا از پرتال مدرس وضعیت کلاس را بررسی کنید.');
    }
  }

  async function endClassroom(destination, message) {
    if (state.ending) return;
    state.ending = true;
    state.leaving = true;
    if (state.reconnectTimer) {
      window.clearTimeout(state.reconnectTimer);
      state.reconnectTimer = null;
    }
    setControlsEnabled(false);
    setConnection(message, 'ended');
    stopAllMedia();
    var room = state.room;
    state.room = null;
    if (room) {
      try { await room.disconnect(); } catch (ignored) {}
    }
    showMeetFallback(false);
    closeOrRedirect(destination);
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
  if (rotateButton) rotateButton.addEventListener('click', toggleRotation);
  if (fullscreenButton) fullscreenButton.addEventListener('click', toggleFullscreen);
  if (finishButton) finishButton.addEventListener('click', finishClass);
  if (micDevice) micDevice.addEventListener('change', function () { switchDevice('audioinput', micDevice.value); });
  if (cameraDevice) cameraDevice.addEventListener('change', function () { switchDevice('videoinput', cameraDevice.value); });
  if (leave) {
    leave.addEventListener('click', exitClassroom);
  }
  if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener('devicechange', refreshDevices);
  }
  ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (eventName) {
    document.addEventListener(eventName, function () { syncFullscreenButton(); window.setTimeout(updateRotationGeometry, 80); });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && root && root.classList.contains('is-faux-fullscreen')) exitFauxFullscreen();
  });
  window.addEventListener('resize', updateRotationGeometry);
  if (window.ResizeObserver && stage) {
    state.rotationObserver = new ResizeObserver(function () { updateRotationGeometry(); });
    state.rotationObserver.observe(stage);
  }
  window.addEventListener('pagehide', function () {
    state.leaving = true;
    if (state.room) state.room.disconnect();
  });

  updateMicButton();
  updateCameraButton();
  updateMusicLabel();
  syncRotationButton();
  updateRotationGeometry();
  syncFullscreenButton();
  connect(false);
})();
