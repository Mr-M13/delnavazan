(function () {
    'use strict';

    var classAdminModal = document.querySelector('[data-dzn-class-admin-modal]');
    var classAdminFrame = classAdminModal ? classAdminModal.querySelector('[data-dzn-class-admin-frame]') : null;
    var classAdminOpener = null;
    var classAdminChanged = false;

    function closeClassAdmin(fallbackUrl) {
        if (window.parent !== window && document.body.classList.contains('dzn-class-admin-frame')) {
            window.parent.postMessage({ type: 'dzn-class-admin-close' }, window.location.origin);
            return;
        }
        if (classAdminModal && classAdminModal.open) classAdminModal.close();
        else if (fallbackUrl) window.location.assign(fallbackUrl);
    }

    function addRecordField(form, name, value) {
        if (!value || form.querySelector('[name="' + name + '"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    if (classAdminModal && classAdminFrame) {
        classAdminModal.addEventListener('close', function () {
            classAdminFrame.removeAttribute('src');
            if (classAdminOpener) classAdminOpener.focus();
            if (classAdminChanged) {
                sessionStorage.setItem('dzn-class-admin-scroll', String(window.scrollY));
                window.location.reload();
            }
        });
        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin || event.source !== classAdminFrame.contentWindow || !event.data) return;
            if (event.data.type === 'dzn-class-admin-updated') classAdminChanged = true;
            if (event.data.type === 'dzn-class-admin-close') closeClassAdmin();
        });
        var previousScroll = sessionStorage.getItem('dzn-class-admin-scroll');
        if (previousScroll !== null) {
            sessionStorage.removeItem('dzn-class-admin-scroll');
            window.requestAnimationFrame(function () { window.scrollTo(0, Number(previousScroll) || 0); });
        }
    }

    if (document.body.classList.contains('dzn-class-admin-frame')) {
        var frameParams = new URLSearchParams(window.location.search);
        var frameRecord = frameParams.get('request_id') ? 'request:' + frameParams.get('request_id') : 'class:' + (frameParams.get('class_id') || '');
        var frameStateKey = 'dzn-class-admin-state:' + frameRecord;
        try {
            var savedFrameState = JSON.parse(sessionStorage.getItem(frameStateKey) || 'null');
            if (savedFrameState) {
                document.querySelectorAll('.dzn-record-stage').forEach(function (stage, index) { stage.open = savedFrameState.open.indexOf(index) !== -1; });
                window.requestAnimationFrame(function () { window.scrollTo(0, Number(savedFrameState.scroll) || 0); });
                sessionStorage.removeItem(frameStateKey);
            }
        } catch (ignored) {}
        if (window.parent !== window && (frameParams.has('dzn_workspace_notice') || frameParams.has('dzn_notice'))) {
            window.parent.postMessage({ type: 'dzn-class-admin-updated' }, window.location.origin);
        }
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM' || form.method.toLowerCase() !== 'post') return;
            if (form.target !== '_blank') {
                var openStages = [];
                document.querySelectorAll('.dzn-record-stage').forEach(function (stage, index) { if (stage.open) openStages.push(index); });
                try { sessionStorage.setItem(frameStateKey, JSON.stringify({ open: openStages, scroll: window.scrollY })); } catch (ignored) {}
            }
            var params = new URLSearchParams(window.location.search);
            addRecordField(form, 'request_id', params.get('request_id'));
            addRecordField(form, 'class_id', params.get('class_id'));
            addRecordField(form, 'dzn_modal', '1');
        });
    }

    document.addEventListener('click', function (event) {
        var close = event.target.closest ? event.target.closest('[data-dzn-class-admin-close]') : null;
        if (close) { event.preventDefault(); closeClassAdmin(close.href || ''); return; }
        var opener = event.target.closest ? event.target.closest('[data-dzn-class-admin]') : null;
        if (!opener || !classAdminModal || !classAdminFrame || typeof classAdminModal.showModal !== 'function') return;
        event.preventDefault();
        classAdminOpener = opener;
        classAdminChanged = false;
        classAdminModal.querySelector('[data-dzn-class-admin-title]').textContent = opener.getAttribute('data-dzn-class-admin-label') || 'Class admin';
        var url = new URL(opener.href, window.location.href);
        url.searchParams.set('dzn_modal', '1');
        classAdminFrame.src = url.href;
        classAdminModal.showModal();
    });

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

    function syncStandardTimezone(select) {
        var form = select.closest ? select.closest('[data-dzn-standard-class]') : null;
        if (!form) return;
        var timezone = form.querySelector('[name="schedule_timezone"]');
        var option = select.options[select.selectedIndex];
        var value = option ? option.getAttribute('data-timezone') || '' : '';
        if (!timezone || !value) return;
        if (timezone.querySelector('option[value="' + value.replace(/"/g, '\\"') + '"]')) timezone.value = value;
    }

    document.addEventListener('change', function (event) {
        var select = event.target.closest ? event.target.closest('[data-dzn-standard-enrolment]') : null;
        if (select) syncStandardTimezone(select);
    });

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
