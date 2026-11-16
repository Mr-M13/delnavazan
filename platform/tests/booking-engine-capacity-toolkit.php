<?php
/**
 * Pure capacity-interval authority for the intro-booking stress audit.
 *
 * Deliberately free of WordPress and $wpdb: the fixture feeds it database rows,
 * and platform/tests/booking-engine-capacity-unit.php feeds it synthetic rows on
 * a plain PHP host. That keeps the overlap rules — including the course buffer —
 * provable without a disposable MariaDB stack, which is where the original
 * audit could report success while capacity was over-committed.
 *
 * Interval convention: every interval is half-open, [start, end). Two intervals
 * overlap only when `a.start < b.end && b.start < a.end`, so an adjacent lesson
 * that begins exactly when the previous one ends is never a violation, while a
 * lesson that begins inside the previous lesson's buffer is.
 *
 * The occupied end is authoritative: it already includes the course buffer, so
 * callers must pass `occupied_ends_at_utc` (from the requested-time row), never
 * a confirmation's instructional `ends_at_utc`.
 */

/**
 * Normalise raw rows into comparable intervals.
 *
 * @param list<array<string,mixed>|object> $rows
 * @return list<array{id:int,teacher_id:int,request_id:int,starts_at_utc:string,occupied_ends_at_utc:string,kind:string}>
 */
function dzn_capacity_intervals(array $rows, string $kind): array {
    $intervals = array();
    foreach ($rows as $row) {
        $row = (array) $row;
        $start = isset($row['starts_at_utc']) ? trim((string) $row['starts_at_utc']) : '';
        $end = isset($row['occupied_ends_at_utc']) ? trim((string) $row['occupied_ends_at_utc']) : '';
        if ($start === '' || $end === '') {
            // An occupancy row without a usable interval is itself evidence of a
            // defect; keep it so the caller can count it rather than silently drop it.
            $intervals[] = array(
                'id' => (int) ($row['id'] ?? 0), 'teacher_id' => (int) ($row['teacher_id'] ?? 0),
                'request_id' => (int) ($row['booking_request_id'] ?? 0),
                'starts_at_utc' => $start, 'occupied_ends_at_utc' => $end, 'kind' => $kind, 'malformed' => true,
            );
            continue;
        }
        $intervals[] = array(
            'id' => (int) ($row['id'] ?? 0), 'teacher_id' => (int) ($row['teacher_id'] ?? 0),
            'request_id' => (int) ($row['booking_request_id'] ?? 0),
            'starts_at_utc' => $start, 'occupied_ends_at_utc' => $end, 'kind' => $kind,
        );
    }
    return $intervals;
}

function dzn_capacity_valid(array $interval): bool {
    return empty($interval['malformed'])
        && $interval['starts_at_utc'] !== ''
        && $interval['occupied_ends_at_utc'] > $interval['starts_at_utc'];
}

/** True when two half-open intervals share any instant. */
function dzn_capacity_overlaps(array $a, array $b): bool {
    return $a['starts_at_utc'] < $b['occupied_ends_at_utc']
        && $b['starts_at_utc'] < $a['occupied_ends_at_utc'];
}

/**
 * True when two rows describe the same booking rather than two bookings.
 *
 * A lifecycle transition can legitimately leave an offer row beside the
 * confirmation it produced. Counting both would report a phantom conflict, so
 * the pair is collapsed whenever the request and the occupied window are equal.
 */
function dzn_capacity_same_booking(array $a, array $b): bool {
    return $a['request_id'] > 0
        && $a['request_id'] === $b['request_id']
        && $a['starts_at_utc'] === $b['starts_at_utc']
        && $a['occupied_ends_at_utc'] === $b['occupied_ends_at_utc'];
}

/**
 * Overlap pairs inside one interval list.
 *
 * Two occupancy rows describing the exact same booking (the same request and
 * window) are collapsed first, so a lifecycle transition that leaves both an
 * offer and a confirmation cannot masquerade as a conflict.
 *
 * @param list<array> $intervals
 * @return list<array{a:array,b:array}>
 */
function dzn_capacity_pairs(array $intervals): array {
    $deduped = array();
    foreach ($intervals as $interval) {
        if (!dzn_capacity_valid($interval)) continue;
        $key = $interval['request_id'] . '|' . $interval['starts_at_utc'] . '|' . $interval['occupied_ends_at_utc'];
        if (!isset($deduped[$key])) $deduped[$key] = $interval;
    }
    $list = array_values($deduped);
    usort($list, static function (array $a, array $b): int {
        return array($a['teacher_id'], $a['starts_at_utc'], $a['occupied_ends_at_utc'])
            <=> array($b['teacher_id'], $b['starts_at_utc'], $b['occupied_ends_at_utc']);
    });
    $pairs = array();
    for ($i = 0, $n = count($list); $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if ($list[$i]['teacher_id'] !== $list[$j]['teacher_id']) continue;
            // Sorted by start: once a later interval starts after this one ends,
            // no later interval can overlap it either.
            if ($list[$j]['starts_at_utc'] >= $list[$i]['occupied_ends_at_utc']) break;
            if (dzn_capacity_overlaps($list[$i], $list[$j])) $pairs[] = array('a' => $list[$i], 'b' => $list[$j]);
        }
    }
    return $pairs;
}

/**
 * Classify capacity evidence for one Teacher population.
 *
 * Hard violations (must be zero):
 *  - confirmed_overlaps: two confirmed occupancies share an instant, i.e. a
 *    genuine double booking including a buffer collision.
 *  - pending_over_confirmed: a live offer still holds capacity that a confirmed
 *    booking already occupies; the offer should have been retired.
 *  - pending_overlaps: two live offers over-commit one Teacher's capacity.
 *
 * Informational only (never a failure):
 *  - competing_pending: live offers that hold the *same student slot* open at
 *    different Teachers. That is the intended sequential-offer design: the
 *    offers compete for one request, not for one Teacher's capacity.
 *  - orphan_pending: live offers with no usable requested-time interval, which
 *    cannot be reasoned about and are reported instead of being ignored.
 *
 * @param list<array|object> $confirmedRows
 * @param list<array|object> $pendingRows
 * @return array{confirmed_overlaps:list<array>,pending_over_confirmed:list<array>,pending_overlaps:list<array>,competing_pending:list<array>,orphan_pending:int,malformed_confirmed:int,hard_count:int}
 */
function dzn_capacity_violations(array $confirmedRows, array $pendingRows): array {
    $confirmed = dzn_capacity_intervals($confirmedRows, 'confirmed');
    $pending = dzn_capacity_intervals($pendingRows, 'pending');

    $malformedConfirmed = count(array_filter($confirmed, static fn(array $i): bool => !dzn_capacity_valid($i)));
    $orphanPending = count(array_filter($pending, static fn(array $i): bool => !dzn_capacity_valid($i)));

    $confirmedOverlaps = dzn_capacity_pairs($confirmed);
    $pendingOverlaps = dzn_capacity_pairs($pending);

    $pendingOverConfirmed = array();
    foreach ($pending as $offer) {
        if (!dzn_capacity_valid($offer)) continue;
        foreach ($confirmed as $booking) {
            if (!dzn_capacity_valid($booking) || $booking['teacher_id'] !== $offer['teacher_id']) continue;
            if (dzn_capacity_same_booking($offer, $booking)) continue;
            if (dzn_capacity_overlaps($offer, $booking)) $pendingOverConfirmed[] = array('a' => $offer, 'b' => $booking);
        }
    }

    // Same student slot held open at more than one Teacher: legitimate.
    $competing = array();
    $slotTeachers = array();
    foreach ($pending as $offer) {
        if (!dzn_capacity_valid($offer) || $offer['request_id'] < 1) continue;
        $slot = $offer['request_id'] . '|' . $offer['starts_at_utc'];
        $slotTeachers[$slot][$offer['teacher_id']] = true;
    }
    foreach ($slotTeachers as $slot => $teachers) {
        if (count($teachers) > 1) $competing[] = array('slot' => $slot, 'teachers' => array_keys($teachers));
    }

    return array(
        'confirmed_overlaps' => $confirmedOverlaps,
        'pending_over_confirmed' => $pendingOverConfirmed,
        'pending_overlaps' => $pendingOverlaps,
        'competing_pending' => $competing,
        'orphan_pending' => $orphanPending,
        'malformed_confirmed' => $malformedConfirmed,
        'hard_count' => count($confirmedOverlaps) + count($pendingOverConfirmed) + count($pendingOverlaps) + $orphanPending + $malformedConfirmed,
    );
}

/** Compact, privacy-safe rendering of one violation pair for audit output. */
function dzn_capacity_describe(array $pair): array {
    return array(
        'teacher_id' => (int) $pair['a']['teacher_id'],
        'left' => array('kind' => $pair['a']['kind'], 'start' => $pair['a']['starts_at_utc'], 'occupied_end' => $pair['a']['occupied_ends_at_utc'], 'request_id' => $pair['a']['request_id']),
        'right' => array('kind' => $pair['b']['kind'], 'start' => $pair['b']['starts_at_utc'], 'occupied_end' => $pair['b']['occupied_ends_at_utc'], 'request_id' => $pair['b']['request_id']),
    );
}
