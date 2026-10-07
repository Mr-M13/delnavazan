<?php
$root = dirname(__DIR__);
$theme = $root . '/../theme';
$script = (string) file_get_contents($theme . '/assets/js/navigation.js');
$home = (string) file_get_contents($theme . '/patterns/homepage-editorial.php');
$booking = (string) file_get_contents($theme . '/inc/booking.php');
foreach (array(
    'automatic movement is deliberately slow' => 'elapsed * 9',
    'motion preference disables automatic movement' => '!reducedMotion.matches',
    'cloned groups provide a repeated sequence' => 'cloneCard(card)',
    'loop boundary is normalized by one cycle without smooth scrolling' => 'rail.scrollLeft -= cycleSpan',
    'manual movement temporarily pauses the automatic rail' => 'pauseAutoUntil = performance.now() + 1800',
    'automatic movement disables smooth-scroll and snap so small frame steps are not cancelled' => 'rail.classList.add(\'is-auto-scrolling\')',
    'manual movement removes automatic scrolling mode before snapping to a card' => "rail.classList.remove('is-auto-scrolling')",
    'booking instrument order remains canonical' => "'سه‌تار', 'تار', 'پیانو', 'سنتور', 'تنبک', 'کمانچه', 'دف', 'آواز', 'تنبور', 'نی', 'ویولن', 'گیتار'",
) as $description => $needle) {
    $source = str_contains($description, 'booking instrument order') ? $booking : $script;
    if (!str_contains($source, $needle)) throw new RuntimeException('Homepage instrument belt contract failed: ' . $description);
}
$style = (string) file_get_contents($theme . '/assets/css/theme.css');
if (!preg_match('/\.dzn-instrument-folio\.is-auto-scrolling\s*\{[^}]*scroll-snap-type:\s*none !important/s', $style)) {
    throw new RuntimeException('Automatic belt motion must disable CSS snapping while it advances.');
}
$slugs = array('setar','tar','piano','santur','tombak','kamancheh','daf','avaz','tanboor','ney','violin','guitar');
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
