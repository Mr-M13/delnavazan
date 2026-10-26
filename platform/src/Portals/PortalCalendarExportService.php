<?php
namespace Delnavazan\Platform\Portals;

/** Build one portable calendar event from an already authorised portal read. */
final class PortalCalendarExportService {
    public static function nonceAction(string $role, string $source, string $eventId): string {
        if (!in_array($role, array('student', 'teacher'), true)
            || !in_array($source, array('lesson', 'booking'), true)
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $eventId) !== 1) {
            throw new \InvalidArgumentException('calendar_export_invalid_request');
        }
        return 'dzn_portal_calendar_export:' . $role . ':' . $source . ':' . $eventId;
    }

    /** @return array{content:string,filename:string} */
    public function export(array $read, string $role, string $source, string $eventId, ?string $nowUtc = null): array {
        self::nonceAction($role, $source, $eventId);
        $collection = $source === 'booking' ? 'booking_classes' : 'lessons';
        $identity = $source === 'booking' ? 'confirmation_uid' : 'lesson_uid';
        $expectedVersion = $source === 'booking' ? $role . '_booking_class_v1' : 'portal_lesson_v1';
        $event = null;
        foreach ((array) ($read[$collection] ?? array()) as $candidate) {
            if (!is_array($candidate)
                || (string) ($candidate[$identity] ?? '') !== $eventId
                || (string) ($candidate['version'] ?? '') !== $expectedVersion) continue;
            $event = $candidate;
            break;
        }
        if (!$event) throw new \InvalidArgumentException('calendar_export_not_available');
        if ((string) ($event['lifecycle_state'] ?? '') === 'cancelled'
            || (string) ($event['confirmation_state'] ?? '') === 'cancelled') {
            throw new \InvalidArgumentException('calendar_export_cancelled');
        }

        $start = $this->utc((string) ($event['starts_at_utc'] ?? ''));
        $end = $this->utc((string) ($event['ends_at_utc'] ?? ''));
        if ($end <= $start) throw new \InvalidArgumentException('calendar_export_invalid_interval');
        $course = trim((string) ($event['course_name'] ?? ''));
        if ($course === '') $course = trim((string) ($event['course_reference'] ?? ''));
        if ($course === '') throw new \InvalidArgumentException('calendar_export_not_available');
        $participant = trim((string) ($event[$role === 'teacher' ? 'student_display_reference' : 'teacher_display_reference'] ?? ''));
        $summary = $participant === '' ? $course : $course . ' · ' . $participant;
        $timezone = trim((string) ($event['schedule_timezone'] ?? 'UTC')) ?: 'UTC';
        $stamp = $this->utc($nowUtc ?? gmdate('Y-m-d H:i:s'));
        $uid = hash('sha256', $source . ':' . $eventId) . '@calendar.delnavazan.com';
        $description = 'Delnavazan class · Timezone: ' . $timezone;
        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Delnavazan//Portal Calendar Export//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'CLASS:PRIVATE',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $stamp->format('Ymd\\THis\\Z'),
            'DTSTART:' . $start->format('Ymd\\THis\\Z'),
            'DTEND:' . $end->format('Ymd\\THis\\Z'),
            'SUMMARY:' . $this->text($summary),
            'DESCRIPTION:' . $this->text($description),
            'END:VEVENT',
            'END:VCALENDAR',
        );
        return array('content' => implode("\r\n", array_map(array($this, 'fold'), $lines)) . "\r\n", 'filename' => 'delnavazan-calendar.ics');
    }

    private function utc(string $value): \DateTimeImmutable {
        $zone = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), $zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== trim($value)) {
            throw new \InvalidArgumentException('calendar_export_invalid_time');
        }
        return $date;
    }

    private function text(string $value): string {
        // RFC 5545 text must not retain C0/C1 controls. CR/LF are represented as an escaped newline.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $value) ?? '';
        return str_replace(array('\\', ';', ',', "\r", "\n"), array('\\\\', '\\;', '\\,', '', '\\n'), $value);
    }

    /** RFC 5545 content lines are folded at 75 octets without splitting UTF-8 sequences. */
    private function fold(string $line): string {
        if (strlen($line) <= 75) return $line;
        $folded = '';
        $first = true;
        while ($line !== '') {
            $limit = $first ? 75 : 74;
            $cut = min($limit, strlen($line));
            if ($cut < strlen($line)) {
                while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;
            }
            if ($cut < 1) $cut = min($limit, strlen($line));
            $folded .= ($first ? '' : "\r\n ") . substr($line, 0, $cut);
            $line = substr($line, $cut);
            $first = false;
        }
        return $folded;
    }
}
