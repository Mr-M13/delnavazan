/**
 * Delnavazan teacher Meet entry.
 *
 * A Teacher opens Google Meet in a named popup so this page keeps a handle to it. When the Meet
 * window is closed, the page moves to the canonical finish screen where the private note and
 * attendance confirmation are available. If the browser blocks the popup, the form falls back to
 * its normal target and no automatic transition is attempted.
 */
(function () {
  'use strict';

  var forms = document.querySelectorAll('form[data-dzn-finish-url]');
  if (!forms.length) {
    return;
  }

  // A mobile app handoff is not a browser popup: Android may close or detach the
  // tab when Meet opens. Keep the authenticated POST and redirect in the same tab
  // rather than pre-opening an empty window and incorrectly detecting class end.
  var isAndroid = /Android/i.test((window.navigator && window.navigator.userAgent) || '');

  Array.prototype.forEach.call(forms, function (form) {
    form.addEventListener('submit', function () {
      if (isAndroid) {
        form.setAttribute('target', '_self');
        return;
      }

      var finishUrl = form.getAttribute('data-dzn-finish-url') || '';
      if (!finishUrl) {
        return;
      }

      var windowName = 'dzn-meet-classroom-' + Date.now();
      var meetWindow = window.open('', windowName);
      if (!meetWindow) {
        // Popup blocked: keep the regular target="_blank" behavior.
        return;
      }

      form.setAttribute('target', windowName);

      var timer = window.setInterval(function () {
        if (meetWindow.closed) {
          window.clearInterval(timer);
          window.location.assign(finishUrl);
        }
      }, 1000);
    });
  });
}());
