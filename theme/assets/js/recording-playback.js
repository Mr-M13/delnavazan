/**
 * Request a fresh, short-lived playback grant and hand it to the OVH gateway in the fragment.
 * No permanent URL, archive path, provider token or lesson identity is constructed here.
 */
(function () {
	'use strict';
	function base64Url(value) {
		return btoa(unescape(encodeURIComponent(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function setStatus(node, text) {
		if (node) { node.textContent = text; }
	}
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-dzn-recording-uid]');
		if (!button || button.disabled) { return; }
		event.preventDefault();
		var status = document.getElementById(button.getAttribute('data-dzn-recording-status') || '');
		button.disabled = true;
		setStatus(status, 'در حال دریافت اجازهٔ پخش…');
		fetch(button.getAttribute('data-dzn-recording-endpoint'), {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': button.getAttribute('data-dzn-recording-nonce') },
			body: JSON.stringify({ recording_uid: button.getAttribute('data-dzn-recording-uid') })
		}).then(function (response) {
			if (!response.ok) { throw new Error('recording_playback_denied'); }
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.grant || !payload.gateway_url) { throw new Error('recording_playback_denied'); }
			var fragment = '#grant=' + base64Url(JSON.stringify(payload.grant));
			var opened = window.open(payload.gateway_url + fragment, '_blank', 'noopener');
			if (!opened) { window.location.href = payload.gateway_url + fragment; }
			setStatus(status, 'اجازهٔ پخش کوتاه‌مدت صادر شد؛ در پنجرهٔ امن ادامه دهید.');
			button.disabled = false;
		}).catch(function () {
			setStatus(status, 'پخش در این لحظه مجاز یا در دسترس نیست.');
			button.disabled = false;
		});
	});
}());
