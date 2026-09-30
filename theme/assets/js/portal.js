(() => {
  'use strict';

  const storagePrefix = 'dzn-portal-announcement:';

  document.querySelectorAll('[data-dzn-announcement]').forEach((announcement) => {
    const id = announcement.dataset.dznAnnouncement;
    let dismissed = false;

    if (id) {
      try {
        dismissed = window.sessionStorage.getItem(`${storagePrefix}${id}`) === 'dismissed';
      } catch (error) {
        dismissed = false;
      }
    }

    if (dismissed) announcement.hidden = true;

    announcement.querySelector('[data-dzn-announcement-dismiss]')?.addEventListener('click', () => {
      announcement.hidden = true;
      if (!id) return;

      try {
        window.sessionStorage.setItem(`${storagePrefix}${id}`, 'dismissed');
      } catch (error) {
        // Dismissal remains useful for this render even when storage is blocked.
      }
    });
  });

  const dialogOpeners = new WeakMap();

  const restoreDialogOpener = (dialog) => {
    const opener = dialogOpeners.get(dialog);
    if (opener && typeof opener.focus === 'function') opener.focus();
    dialogOpeners.delete(dialog);
  };

  const openDialog = (dialog, opener) => {
    if (!dialog) return;
    dialogOpeners.set(dialog, opener);
    if (typeof dialog.showModal === 'function') {
      dialog.showModal();
    } else {
      // An old browser gets an inline, non-modal disclosure. Focus stays on
      // its toggle; this deliberately does not imitate modal focus trapping.
      dialog.dataset.dznDialogFallback = 'disclosure';
      dialog.setAttribute('open', '');
    }
  };

  const closeDialog = (dialog) => {
    if (!dialog) return;
    if (typeof dialog.close === 'function') {
      dialog.close();
    } else {
      dialog.removeAttribute('open');
      restoreDialogOpener(dialog);
    }
  };

  document.querySelectorAll('[data-dzn-dialog-open]').forEach((button) => {
    button.addEventListener('click', () => {
      openDialog(document.getElementById(button.dataset.dznDialogOpen), button);
    });
  });

  document.querySelectorAll('.dzn-portal-dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => restoreDialogOpener(dialog));
    dialog.querySelector('[data-dzn-dialog-close]')?.addEventListener('click', () => closeDialog(dialog));
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDialog(dialog);
    });
  });

  document.querySelectorAll('[data-dzn-presentation-action]').forEach((button) => {
    button.addEventListener('click', () => {
      const statusId = button.getAttribute('aria-describedby');
      const status = statusId ? document.getElementById(statusId) : null;
      if (status) {
        status.textContent = 'این کنترل نمایشی است؛ هیچ اطلاعاتی ثبت یا ارسال نشد.';
      }
    });
  });
})();
