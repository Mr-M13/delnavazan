(() => {
  'use strict';

  const button = document.querySelector('.menu-toggle');
  const navigation = document.querySelector('#primary-navigation');

  if (!button || !navigation) {
    return;
  }

  button.hidden = false;

  const desktopQuery = window.matchMedia('(min-width: 64rem)');

  const syncNavigation = () => {
    if (desktopQuery.matches) {
      navigation.hidden = false;
      button.setAttribute('aria-expanded', 'false');
      return;
    }

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


(() => {
  'use strict';

  const rails = document.querySelectorAll('.dzn-instrument-folio');

  if (!rails.length) {
    return;
  }

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const mobileQuery = window.matchMedia('(max-width: 51.999rem)');

  rails.forEach((rail, railIndex) => {
    const originals = Array.from(rail.querySelectorAll('.dzn-instrument-tile'));

    if (originals.length < 2) {
      return;
    }

    const cloneCard = (card) => {
      const clone = card.cloneNode(true);
      clone.classList.add('is-carousel-clone');
      clone.setAttribute('aria-hidden', 'true');
      clone.removeAttribute('id');
      clone.querySelectorAll('[id]').forEach((element) => element.removeAttribute('id'));
      clone.querySelectorAll('a, button, input, select, textarea, [tabindex]').forEach((element) => {
        element.setAttribute('tabindex', '-1');
      });
      return clone;
    };
    const leading = document.createDocumentFragment();
    const trailing = document.createDocumentFragment();

    originals.forEach((card) => {
      leading.append(cloneCard(card));
      trailing.append(cloneCard(card));
    });

    rail.prepend(leading);
    rail.append(trailing);

    const cards = Array.from(rail.querySelectorAll('.dzn-instrument-tile'));
    const cycleLength = originals.length;
    let activeIndex = cycleLength;
    let cycleSpan = 0;
    let scrollFrame = 0;
    let resizeFrame = 0;
    let settleTimer = 0;
    let normalizationFrame = 0;
    let normalizing = false;
    let interacting = false;
    const controls = document.createElement('div');
    const previous = document.createElement('button');
    const next = document.createElement('button');

   if (!rail.id) {
  rail.id = 'dzn-instrument-carousel-' + (railIndex + 1);
}

rail.tabIndex = 0;
rail.setAttribute('role', 'region');
rail.setAttribute('aria-label', 'دوره‌های سازها، چرخش پیوسته');

    controls.className = 'dzn-instrument-carousel__controls';
    controls.setAttribute('aria-controls', rail.id);

    previous.className = 'dzn-instrument-carousel__button';
    previous.type = 'button';
    previous.textContent = '‹';
    previous.setAttribute('aria-label', 'ساز قبلی');

    next.className = 'dzn-instrument-carousel__button';
    next.type = 'button';
    next.textContent = '›';
    next.setAttribute('aria-label', 'ساز بعدی');

    controls.append(previous, next);
    rail.before(controls);

    const measureCycle = () => {
      cycleSpan = cards[cycleLength * 2].offsetLeft - cards[cycleLength].offsetLeft;
    };

    const updateWheel = () => {
      const railRect = rail.getBoundingClientRect();
      const railCenter = railRect.left + (railRect.width / 2);
      const cardCenters = cards.map((card) => {
        const cardRect = card.getBoundingClientRect();
        return cardRect.left + (cardRect.width / 2);
      });
      const step = cards.length > 1
        ? Math.max(1, Math.abs(cardCenters[1] - cardCenters[0]))
        : Math.max(1, cards[0].getBoundingClientRect().width);
      let closestDistance = Infinity;

      cards.forEach((card, index) => {
        const distance = Math.abs(cardCenters[index] - railCenter) / step;
        const centerLimit = mobileQuery.matches ? .56 : 1.16;
        const nearLimit = mobileQuery.matches ? 1.56 : 2.16;

        card.classList.toggle('is-center', distance <= centerLimit);
        card.classList.toggle('is-near', distance > centerLimit && distance <= nearLimit);
        const edgeLimit = mobileQuery.matches ? 2.35 : 2.65;

        card.classList.toggle(
          'is-far',
          distance > nearLimit && distance <= edgeLimit
        );
        card.classList.toggle('is-edge', distance > edgeLimit);

        if (distance < closestDistance) {
          closestDistance = distance;
          activeIndex = index;
        }
      });

      rail.classList.add('is-wheel-ready');
    };

    const positionCard = (index, behavior = 'smooth') => {
      activeIndex = Math.max(0, Math.min(cards.length - 1, index));
      const card = cards[activeIndex];
      const targetLeft = card.offsetLeft - ((rail.clientWidth - card.offsetWidth) / 2);

      rail.scrollTo({
        left: targetLeft,
        behavior: reducedMotion.matches ? 'auto' : behavior
      });

      window.requestAnimationFrame(updateWheel);
    };

    const normalizeLoop = () => {
      if (normalizing || interacting || !cycleSpan) {
        return false;
      }

      updateWheel();

      let shift = 0;

      if (activeIndex < cycleLength) {
        shift = cycleSpan;
        activeIndex += cycleLength;
      } else if (activeIndex >= cycleLength * 2) {
        shift = -cycleSpan;
        activeIndex -= cycleLength;
      }

      if (!shift) {
        return false;
      }

      normalizing = true;
      rail.classList.add('is-normalizing');
      rail.style.setProperty('scroll-behavior', 'auto', 'important');
      rail.style.setProperty('scroll-snap-type', 'none', 'important');
      rail.scrollLeft += shift;
      updateWheel();

      window.cancelAnimationFrame(normalizationFrame);
      normalizationFrame = window.requestAnimationFrame(() => {
        updateWheel();

        normalizationFrame = window.requestAnimationFrame(() => {
          updateWheel();
          rail.style.removeProperty('scroll-behavior');
          rail.style.removeProperty('scroll-snap-type');
          rail.classList.remove('is-normalizing');
          normalizing = false;
        });
      });

      return true;
    };

    const syncFromScroll = () => {
      if (normalizing) {
        return;
      }

      window.cancelAnimationFrame(scrollFrame);
      scrollFrame = window.requestAnimationFrame(updateWheel);

      if (!('onscrollend' in rail)) {
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(normalizeLoop, 140);
      }
    };

    const restIndex = cycleLength + Math.max(
      0,
      originals.findIndex((card) => card.classList.contains('dzn-instrument-tile--setar'))
    );

    previous.addEventListener('click', () => positionCard(activeIndex - 1));
    next.addEventListener('click', () => positionCard(activeIndex + 1));

    rail.addEventListener('pointerdown', () => {
      interacting = true;
    });

    const releaseInteraction = () => {
      if (!interacting) {
        return;
      }

      interacting = false;

      if (!('onscrollend' in rail)) {
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(normalizeLoop, 140);
      }
    };

    window.addEventListener('pointerup', releaseInteraction, { passive: true });
    window.addEventListener('pointercancel', releaseInteraction, { passive: true });
    rail.addEventListener('scroll', syncFromScroll, { passive: true });

    if ('onscrollend' in rail) {
      rail.addEventListener('scrollend', normalizeLoop);
    }

    rail.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowLeft') {
        event.preventDefault();
        positionCard(activeIndex - 1);
      } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        positionCard(activeIndex + 1);
      }
    });

    const syncAfterResize = () => {
      window.cancelAnimationFrame(resizeFrame);
      resizeFrame = window.requestAnimationFrame(() => {
        measureCycle();
        positionCard(activeIndex, 'auto');
      });
    };

    window.addEventListener('resize', syncAfterResize, { passive: true });
    mobileQuery.addEventListener('change', syncAfterResize);

    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        measureCycle();
        positionCard(restIndex, 'auto');
      });
    });
  });
})();

document.addEventListener('DOMContentLoaded', () => {
  const button = document.querySelector('.dzn-floating-whatsapp');
  const hero = document.querySelector('.dzn-home-hero');
  const footer = document.querySelector('.site-footer');

  if (!button || !hero || !footer) {
    return;
  }

  let heroVisible = false;
  let footerVisible = false;

  const updateButton = () => {
    button.classList.toggle(
      'is-hidden',
      heroVisible || footerVisible
    );
  };

  const heroObserver = new IntersectionObserver(
    ([entry]) => {
      heroVisible = entry.isIntersecting;
      updateButton();
    },
    {
      threshold: 0.15,
    }
  );

  const footerObserver = new IntersectionObserver(
    ([entry]) => {
      footerVisible = entry.isIntersecting;
      updateButton();
    },
    {
      threshold: 0.05,
    }
  );

  heroObserver.observe(hero);
  footerObserver.observe(footer);
});
