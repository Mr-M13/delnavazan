<?php
$root = dirname(__DIR__);
$theme = $root . '/../theme';
$script = (string) file_get_contents($theme . '/assets/js/navigation.js') . "\n" . (string) file_get_contents($theme . '/assets/js/home-carousel.js');
$assets = (string) file_get_contents($theme . '/inc/assets.php');
$home = (string) file_get_contents($theme . '/patterns/homepage-editorial.php');
$booking = (string) file_get_contents($theme . '/inc/booking.php');
$helpers = (string) file_get_contents($theme . '/inc/template-tags.php');
$pricing = (string) file_get_contents($theme . '/assets/js/pricing-region.js');
foreach (array(
    'automatic movement keeps a fractional position accumulator' => 'let autoScrollLeft = 0',
    'automatic movement advances the fractional accumulator slowly' => 'autoScrollLeft += elapsed * 9',
    'automatic movement writes the fractional accumulator to the rail' => 'rail.scrollLeft = autoScrollLeft',
    'automatic movement resynchronises after positioning changes' => 'autoScrollLeft = rail.scrollLeft',
    'motion preference disables automatic movement' => '!reducedMotion.matches',
    'cloned groups provide a repeated sequence' => 'cloneCard(card)',
    'loop boundary is normalized by one cycle without smooth scrolling' => 'autoScrollLeft -= cycleSpan',
    'reverse loop boundary is normalized by one cycle without smooth scrolling' => 'autoScrollLeft += cycleSpan',
    'manual movement temporarily pauses the automatic rail' => 'pauseAutoUntil = performance.now() + 1800',
    'automatic movement disables smooth-scroll and snap so small frame steps are not cancelled' => 'rail.classList.add(\'is-auto-scrolling\')',
    'manual movement removes automatic scrolling mode before snapping to a card' => "rail.classList.remove('is-auto-scrolling')",
    'booking link consumes the canonical tile enrol URL' => "card.getAttribute('data-enrol-url')",
    'canonical instrument lookup helper exists' => 'function dzn_theme_booking_enrol_url',
    'currency options use ISO codes' => 'item.currency || code',
    'booking instrument order remains canonical' => "'تار', 'تمبک', 'سه‌تار', 'پیانو', 'سنتور', 'کمانچه', 'دف', 'آواز', 'تنبور', 'نی', 'ویولن', 'گیتار'",
) as $description => $needle) {
    $source = str_contains($description, 'booking instrument order') || str_contains($description, 'canonical instrument lookup') ? $booking : (str_contains($description, 'currency options') ? $pricing : $script);
    if (!str_contains($source, $needle)) throw new RuntimeException('Homepage instrument belt contract failed: ' . $description);
}
if (12 !== substr_count($home, 'data-enrol-url=')) {
    throw new RuntimeException('All twelve homepage instrument tiles must carry a canonical enrol URL.');
}
if (!str_contains($helpers, "function dzn_theme_instrument_image") || !str_contains($helpers, "'سهتار'") || !str_contains($helpers, "'تمبک'") || !str_contains($helpers, "'تنبک'")) {
    throw new RuntimeException('Instrument artwork lookup must normalise Persian catalogue spellings.');
}
if (!str_contains($booking, 'dzn_theme_instrument_image( (string) $option[\'name_fa\'] )')) {
    throw new RuntimeException('Booking instrument tiles must resolve artwork through the shared lookup.');
}
if (!str_contains($home, '<option value="AU">AUD</option>') || !str_contains($home, '<option value="NZ">NZD</option>')) {
    throw new RuntimeException('The pricing selector must expose ISO currency codes.');
}
foreach (array('منطقهٔ قیمت‌گذاری', 'این پیشنهاد تقریبی است', 'این منطقه فقط بر اساس موقعیت تقریبی') as $removed) {
    if (str_contains($home, $removed) || str_contains($pricing, $removed)) {
        throw new RuntimeException('Removed pricing copy returned: ' . $removed);
    }
}
if (!str_contains($assets, 'if ( ! is_front_page() )')) {
    throw new RuntimeException('Homepage carousel and pricing scripts must be gated to the front page.');
}
$style = (string) file_get_contents($theme . '/assets/css/theme.css');
if (!preg_match('/\.dzn-instrument-folio\.is-auto-scrolling\s*\{[^}]*scroll-snap-type:\s*none !important/s', $style)) {
    throw new RuntimeException('Automatic belt motion must disable CSS snapping while it advances.');
}
$slugs = array('tar','tombak','setar','piano','santur','kamancheh','daf','avaz','tanboor','ney','violin','guitar');
$positions = array_map(static fn($slug) => strpos($home, 'dzn-instrument-tile--' . $slug), $slugs);
$ordered = !in_array(false, $positions, true) && count(array_unique($positions)) === count($positions);
for ($i = 1; $ordered && $i < count($positions); $i++) $ordered = $positions[$i] > $positions[$i - 1];
if (!$ordered) {
    throw new RuntimeException('Homepage tiles must follow the same canonical instrument order as booking.');
}
foreach (array('C-Setar.webp','C-Tar.webp','C-Piano.webp','C-santour.webp','C-Tombak.webp','C-Kamancheh.webp','C-Daf.webp','C-Avaz.webp','C-Tanboor.webp','C-Ney.webp','C-Violin.webp','C-Guitar.webp') as $asset) {
    if (!str_contains($home, $asset)) throw new RuntimeException('Homepage instrument asset missing: ' . $asset);
}
echo "homepage-instrument-belt-contract: pass\n";
