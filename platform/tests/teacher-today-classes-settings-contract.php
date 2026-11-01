<?php
/**
 * Source contract for the Teacher Today/classes completion gate and the Settings troubleshooting copy.
 *
 * Run: php platform/tests/teacher-today-classes-settings-contract.php
 */
$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$read = static function (string $path): string {
	$value = file_get_contents($path);
	if (false === $value) { throw new RuntimeException('Unreadable ' . $path); }
	return $value;
};
$assert = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };

$notifications = $read($theme . '/template-parts/teacher-portal/notifications.php');
$classes = $read($theme . '/template-parts/teacher-portal/classes.php');
$calendar = $read($theme . '/template-parts/teacher-portal/calendar.php');
$help = $read($theme . '/template-parts/teacher-portal/help.php');
$account = $read($theme . '/template-parts/teacher-portal/account.php');
$bridge = $read($theme . '/inc/platform-bridge.php');
$shell = $read($theme . '/template-parts/teacher-portal/shell.php');
$actions = $read($theme . '/template-parts/components/classroom-actions.php');

// Today notifications: one heading, a white panel, the honest empty state and no explanatory caption.
$assert(!str_contains($notifications, 'پیام‌های حساب شما'), 'The notification kicker must be removed.');
$assert(str_contains($notifications, '>اعلان‌ها') && str_contains($notifications, 'اعلان تازه‌ای وجود ندارد.'), 'The notifications heading and empty state must remain.');
$assert(str_contains($notifications, 'dzn-tp-section dzn-notifications'), 'Notifications must keep the white panel.');
$assert(!str_contains($notifications, 'dzn-tp-note'), 'The notification explanatory caption must be removed.');

// Today classes: no duplicate upcoming list; completed work is collapsed history.
$assert(!str_contains($shell, "'upcoming_items'") && !str_contains($shell, '$model[\'upcoming_classes\']'), 'The classes screen must not render the redundant upcoming list.');
$assert(str_contains($classes, 'dzn-tp-class-history') && str_contains($classes, '<summary>کلاس‌های گذشته'), 'Completed classes must be available in a collapsed history section.');
$assert(str_contains($classes, "isset( \$args['past_items'] )") && str_contains($shell, "'past_items'"), 'History must come from the model, never from a date guess.');
$assert(str_contains($bridge, "array( 'not_recorded', 'presumed_completed', 'review_required'"), 'History must exclude presumed completion and unresolved review that have no recorded delivery outcome.');
$assert(str_contains($bridge, "\$past_classes[] = \$past_row") && str_contains($bridge, "'past_classes' => \$past_classes"), 'Completed today rows must leave the actionable list and enter history.');

// Expanded details: no repeated identity/time, LTR WhatsApp, condensed Persian golden hour and term progress.
$assert(!str_contains($classes, 'student_summary') && !str_contains($classes, '<h4>زمان‌بندی</h4>'), 'Expanded details must not repeat the student name or class time.');
$assert(str_contains($classes, 'class="dzn-tp-student-contact"><span>واتس‌اپ:</span>') && str_contains($classes, 'dir="ltr"') && str_contains($classes, "'https://wa.me/'"), 'WhatsApp must remain LTR with a wa.me link.');
$assert(str_contains($classes, 'golden_hour_label') && !str_contains($classes, 'Golden hour') && !str_contains($classes, ' · estimated'), 'Golden hour must be condensed into Persian without English labels.');
$assert(str_contains($classes, 'term_progress_label') && !str_contains($classes, 'تکمیل‌شده:'), 'Term progress must use the concise Persian label.');
$assert(str_contains($bridge, "'term_progress_label' =>") && str_contains($bridge, "جلسه باقی‌مانده"), 'The view model must supply the term progress label.');
$assert(str_contains($classes, "replacement_label"), 'The replacement balance may only render when the model supplies it.');
$assert(!str_contains($bridge, "replacement_available' => 0"), 'The view model must not invent a replacement balance.');

// Completion gate: ended + actionable + not completed/claimed, and the form disappears afterwards.
$assert(str_contains($bridge, "'completion_available' => \$completion_available"), 'The view model must expose completion_available.');
$assert(str_contains($bridge, "&& \$ended && ! \$completion_completed && ! \$completion_claimed"), 'Completion must require an ended, unclaimed occurrence.');
$assert(str_contains($classes, "true === ( \$item['completion_available'] ?? false )"), 'The completion form must render only when the gated flag is true.');
$assert(str_contains($classes, 'completion_action_url') && str_contains($classes, 'completion_nonce'), 'The completion form must use backend-issued completion fields when supplied.');
$assert(str_contains($classes, 'completion_message'), 'A non-actionable class must explain itself instead of showing the form.');
$assert(str_contains($bridge, "\$completion_completed = array_key_exists( 'completion_completed', \$lesson )"), 'The canonical completion flag must take precedence when present.');
$assert(str_contains($bridge, "if ( \$class['completion_completed'] ) {") && str_contains($bridge, 'never an actionable Today row'), 'Completed rows must be filtered from today_classes.');
$assert(str_contains($bridge, "&& ! \$completion_completed\n\t\t\t&& ! \$completion_claimed"), 'reschedule_available must be false for completed or claimed lessons.');

// Actions: Delnavazan classroom is primary; Meet is the explicit backup label.
$assert(str_contains($actions, "'meet_label'") && str_contains($classes, 'ورود با گوگل میت (پشتیبان)'), 'Meet must be labelled as the backup.');
$assert(str_contains($classes, "'primary_class' => 'dzn-button dzn-tp-btn-join'"), 'The Delnavazan classroom must remain the primary action.');

// Calendar: compact expandable cards with only canonical fields.
$assert(str_contains($calendar, '<details class="dzn-tp-calendar__item">'), 'Calendar entries must be compact expandable cards.');
$assert(str_contains($calendar, 'dzn-tp-calendar-action--link') && str_contains($calendar, 'افزودن به تقویم'), 'Calendar export must be a small secondary link.');
$assert(str_contains($calendar, "item['instrument']") && str_contains($calendar, "item['student']") && str_contains($calendar, "item['term_reference']") && str_contains($calendar, "item['golden_hour_label']"), 'Calendar cards must use the canonical instrument, student, term and golden-hour fields.');
$assert(str_contains($bridge, "'instrument' => \$course_label") && str_contains($bridge, "'golden_hour_label' => \$golden_summary"), 'The view model must supply those calendar fields.');

// Settings: one availability/capacity card per instrument, not one per course row.
$assert(str_contains($account, '$groups[(int)$row->instrument_id][]') && str_contains($account, '$capacity_groups[(int)$course_row->instrument_id][]'), 'Both the instrument picker and capacity summary must group by instrument id.');
$assert(str_contains($account, 'foreach($capacity_groups as $capacity_rows):'), 'The capacity summary must render once per instrument group.');

// Troubleshooting: exactly two concise topics; no classroom-method explanation, no invented Studio setting.
$assert(str_contains($help, 'نمی‌توانم کلاس را شروع کنم') && str_contains($help, 'صدای ساز به‌خوبی شنیده نمی‌شود'), 'The two required troubleshooting topics must remain.');
$assert(2 === preg_match_all('/<details>/', $help), 'Exactly two troubleshooting topics are allowed.');
$assert(str_contains($help, 'حالت موسیقی') && str_contains($help, 'پردازش صدا') && str_contains($help, 'حذف نویز'), 'The audio topic must name music mode and audio processing/noise suppression.');
$assert(!str_contains($help, 'Studio') && !str_contains($help, 'روش اضطراری کلاس') && !str_contains($help, 'راهنمای «تنظیم Google Meet برای موسیقی»'), 'Removed topics and the classroom-method explanation must not return.');
$assert(!str_contains($help, 'data-dzn-tp-presentation'), 'Help must not keep a presentation-only classroom method control.');

echo "teacher-today-classes-settings-contract: OK\n";
