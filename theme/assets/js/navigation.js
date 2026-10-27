/**
 * Header navigation disclosure.
 *
 * Shared by public and portal documents because both use the site header. The
 * homepage carousel lives in home-carousel.js and is never loaded on portals.
 */
(() => {
  'use strict';

  const button = document.querySelector('.menu-toggle');
  const navigation = document.querySelector('#primary-navigation');

  if (!button || !navigation) {
    return;
  }

  const desktopQuery = window.matchMedia('(min-width: 64rem)');

  const syncNavigation = () => {
    if (desktopQuery.matches) {
      button.hidden = true;
      navigation.hidden = false;
      button.setAttribute('aria-expanded', 'false');
      return;
    }

    button.hidden = false;
    navigation.hidden = button.getAttribute('aria-expanded') !== 'true';
  };

  button.addEventListener('click', () => {
    const isOpen = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', String(!isOpen));
    navigation.hidden = isOpen;

    if (!isOpen) {
      const firstLink = navigation.querySelector('a');
      if (firstLink) {
        firstLink.focus();
      }
    }
  });

  navigation.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || desktopQuery.matches) {
      return;
    }

    button.setAttribute('aria-expanded', 'false');
    navigation.hidden = true;
    button.focus();
  });

  desktopQuery.addEventListener('change', syncNavigation);
  syncNavigation();
})();
