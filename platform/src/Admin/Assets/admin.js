(function () {
    'use strict';

    if (document.body.classList.contains('dzn-class-admin-frame') && window.parent === window) {
        var standaloneUrl = new URL(window.location.href);
        standaloneUrl.searchParams.delete('dzn_modal');
        window.location.replace(standaloneUrl.href);
        return;
    }

    var classAdminModal = document.querySelector('[data-dzn-class-admin-modal]');
    var classAdminFrame = classAdminModal ? classAdminModal.querySelector('[data-dzn-class-admin-frame]') : null;
    var classAdminOpener = null;
    var classAdminChanged = false;
    var listStateKey = 'dzn-class-admin-list:' + window.location.pathname + window.location.search;

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
                var historyPanel = document.querySelector('[data-dzn-history]');
                var openers = document.querySelectorAll('[data-dzn-class-admin]');
                var openerIndex = Array.prototype.indexOf.call(openers, classAdminOpener);
                try { sessionStorage.setItem(listStateKey, JSON.stringify({ scroll: window.scrollY, historyOpen: historyPanel ? historyPanel.open : null, openerIndex: openerIndex })); } catch (ignored) {}
                window.location.reload();
            }
        });
        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin || event.source !== classAdminFrame.contentWindow || !event.data) return;
            if (event.data.type === 'dzn-class-admin-updated') classAdminChanged = true;
            if (event.data.type === 'dzn-class-admin-external') classAdminChanged = true;
            if (event.data.type === 'dzn-class-admin-close') closeClassAdmin();
        });
        try {
            var listState = JSON.parse(sessionStorage.getItem(listStateKey) || 'null');
            if (listState) {
                sessionStorage.removeItem(listStateKey);
                var historyPanel = document.querySelector('[data-dzn-history]');
                if (historyPanel && listState.historyOpen !== null) historyPanel.open = !!listState.historyOpen;
                window.requestAnimationFrame(function () {
                    window.scrollTo(0, Number(listState.scroll) || 0);
                    var openers = document.querySelectorAll('[data-dzn-class-admin]');
                    if (openers[listState.openerIndex]) openers[listState.openerIndex].focus({ preventScroll: true });
                });
            }
        } catch (ignored) {}
    }

    function revealFrameAnchor(hash) {
        if (!hash) return false;
        var target = document.getElementById(decodeURIComponent(hash.slice(1)));
        if (!target) return false;
        var parent = target;
        while (parent) {
            if (parent.tagName === 'DETAILS') parent.open = true;
            parent = parent.parentElement;
        }
        window.requestAnimationFrame(function () { target.scrollIntoView({ block: 'start' }); });
        return true;
    }

    if (document.body.classList.contains('dzn-class-admin-frame')) {
        var frameParams = new URLSearchParams(window.location.search);
        var frameRecord = frameParams.get('request_id') ? 'request:' + frameParams.get('request_id') : 'class:' + (frameParams.get('class_id') || '');
        var frameStateKey = 'dzn-class-admin-state:' + frameRecord;
        try {
            var savedFrameState = JSON.parse(sessionStorage.getItem(frameStateKey) || 'null');
            if (savedFrameState) {
                document.querySelectorAll('[data-dzn-stage]').forEach(function (stage) {
                    var name = stage.getAttribute('data-dzn-stage');
                    if (Object.prototype.hasOwnProperty.call(savedFrameState.stages || {}, name)) stage.open = !!savedFrameState.stages[name];
                });
                window.requestAnimationFrame(function () { window.scrollTo(0, Number(savedFrameState.scroll) || 0); });
                sessionStorage.removeItem(frameStateKey);
            }
        } catch (ignored) {}
        revealFrameAnchor(window.location.hash);
        window.addEventListener('hashchange', function () { revealFrameAnchor(window.location.hash); });
        if (window.parent !== window && (frameParams.has('dzn_workspace_notice') || frameParams.has('dzn_notice'))) {
            window.parent.postMessage({ type: 'dzn-class-admin-updated' }, window.location.origin);
        }
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM' || form.method.toLowerCase() !== 'post') return;
            if (form.target !== '_blank') {
                var stages = {};
                document.querySelectorAll('[data-dzn-stage]').forEach(function (stage) { stages[stage.getAttribute('data-dzn-stage')] = stage.open; });
                try { sessionStorage.setItem(frameStateKey, JSON.stringify({ stages: stages, scroll: window.scrollY })); } catch (ignored) {}
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
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        classAdminOpener = opener;
        classAdminChanged = false;
        classAdminModal.querySelector('[data-dzn-class-admin-title]').textContent = opener.getAttribute('data-dzn-class-admin-label') || 'Class admin';
        var url = new URL(opener.href, window.location.href);
        url.searchParams.set('dzn_modal', '1');
        classAdminFrame.src = url.href;
        classAdminModal.showModal();
    });

    if (document.body.classList.contains('dzn-class-admin-frame')) {
        document.addEventListener('click', function (event) {
            var link = event.target.closest ? event.target.closest('a[href]') : null;
            if (!link || link.hasAttribute('data-dzn-class-admin-close')) return;
            var target = new URL(link.href, window.location.href);
            if (target.origin !== window.location.origin) return;
            if (target.pathname === window.location.pathname && target.search === window.location.search && target.hash && revealFrameAnchor(target.hash)) {
                event.preventDefault();
                window.history.pushState(null, '', target.hash);
                return;
            }
            if (target.pathname.endsWith('/admin.php') && target.searchParams.get('page') && target.searchParams.get('page') !== new URLSearchParams(window.location.search).get('page')) {
                target.searchParams.delete('dzn_modal');
                link.href = target.href;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                if (window.parent !== window) window.parent.postMessage({ type: 'dzn-class-admin-external' }, window.location.origin);
            }
        });
    }

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
