(() => {
  const slider = document.getElementById('dzn-editorial-slider');
  if (!slider) return;
  const slides = [...slider.querySelectorAll('.dzn-editorial-slider__slide')];
  const previous = document.querySelector('[data-dzn-slide="prev"]');
  const next = document.querySelector('[data-dzn-slide="next"]');
  let current = 0;
  const update = () => {
    if (previous) previous.disabled = current === 0;
    if (next) next.disabled = current === slides.length - 1;
  };
  const go = index => {
    current = Math.max(0, Math.min(slides.length - 1, index));
    slides[current]?.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest', inline: 'start'});
    update();
  };
  previous?.addEventListener('click', () => go(current - 1));
  next?.addEventListener('click', () => go(current + 1));
  slider.addEventListener('keydown', event => {
    if (event.key === 'ArrowRight') { event.preventDefault(); go(current - 1); }
    if (event.key === 'ArrowLeft') { event.preventDefault(); go(current + 1); }
  });
  update();
})();
