(function () {
    'use strict';

    function statusFor(button) {
        var panel = button.closest ? button.closest('.dzn-class-actions__panel') : null;
        return panel ? panel.querySelector('.dzn-class-actions__status') : null;
    }

    function setStatus(button, message, error) {
        var status = statusFor(button);
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', !!error);
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            var field = document.createElement('textarea');
            field.value = text;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (ignored) { ok = false; }
            document.body.removeChild(field);
            ok ? resolve() : reject(new Error('copy_failed'));
        });
    }

    function participantCopy(button) {
        var text = button.getAttribute('data-dzn-copy') || '';
        if (!text) return;
        copyText(text).then(function () {
            setStatus(button, 'Participant portal link copied. It still requires sign-in and applies the normal joining window.', false);
        }).catch(function () {
            window.prompt('Copy this participant portal link', text);
        });
    }

    function meetCopy(button) {
        var endpoint = button.getAttribute('data-endpoint') || '';
        var nonce = button.getAttribute('data-nonce') || '';
        var kind = button.getAttribute('data-kind') || 'lesson';
        if (!endpoint || !nonce) return;
        button.disabled = true;
        setStatus(button, 'Preparing the backup Meet link…', false);
        fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body: JSON.stringify(kind === 'booking'
                ? { kind: 'booking', confirmation_uid: button.getAttribute('data-confirmation-uid') || '' }
                : { kind: 'lesson', lesson_id: button.getAttribute('data-lesson-id') || '' })
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (body) {
                if (!response.ok || !body.url) throw new Error(body.code || 'meet_unavailable');
                return body.url;
            });
        }).then(function (url) {
            return copyText(url).then(function () {
                setStatus(button, 'Backup Meet link copied. Opening the link is recorded in the classroom audit trail.', false);
            }, function () {
                window.prompt('Copy this backup Meet link', url);
                setStatus(button, 'Backup Meet link ready.', false);
            });
        }).catch(function () {
            setStatus(button, 'The backup Meet link is not provisioned or could not be read. No link was invented.', true);
        }).finally(function () {
            button.disabled = false;
        });
    }

    document.addEventListener('click', function (event) {
        var copy = event.target.closest ? event.target.closest('[data-dzn-copy]') : null;
        if (copy) {
            event.preventDefault();
            participantCopy(copy);
            return;
        }
        var meet = event.target.closest ? event.target.closest('[data-dzn-meet-copy]') : null;
        if (meet) {
            event.preventDefault();
            meetCopy(meet);
        }
    });
})();
