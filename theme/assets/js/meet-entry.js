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

  Array.prototype.forEach.call(forms, function (form) {
    form.addEventListener('submit', function () {
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
