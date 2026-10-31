<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceGoldenHourRepository;

/**
 * The single golden-hour compensation authority.
 *
 * The base amount always comes from the canonical Lesson finance snapshot. The dedicated immutable
 * golden-hour detail table supplements that snapshot with the committed bonus facts; it is not a
 * second rate or statement-total authority. A missing explicit Enrolment service area records base
 * pay with zero bonus when the global policy is disabled; a technical calculation or persistence
 * failure throws so the enclosing snapshot command rolls back and the Lesson remains Finance pending.
 */
final class GoldenHourPayService {
    public function __construct(
        private ?GoldenHourPolicyService $policies = null,
        private ?FinanceGoldenHourRepository $details = null,
        private ?FinanceFacts $facts = null,
        private ?TeacherRateService $rates = null
    ) {
        $this->policies ??= new GoldenHourPolicyService();
        $this->details ??= new FinanceGoldenHourRepository();
        $this->facts ??= new FinanceFacts();
        $this->rates ??= new TeacherRateService();
    }

    /**
     * Capture the detail for one canonical snapshot version inside the snapshot command transaction.
     *
     * @return array<string,mixed>
     */
    public function captureForSnapshot(
        int $lessonId,
        int $snapshotId,
        array $context,
        int $baseAmountMinor,
        string $currency,
        object $rate,
        int $actor,
        string $now,
        string $commandDigest,
        int $correctionId = 0
    ): array {
        $existing = $this->details->bySnapshotVersion($snapshotId, $correctionId);
        if ($existing) return $this->decode($existing);
        $resolved = $this->resolve($context, $baseAmountMinor, $currency);
        $data = array(
            'snapshot_id' => $snapshotId,
            'snapshot_correction_id' => $correctionId,
            'snapshot_version_reference' => $this->versionReference($snapshotId, $correctionId),
            'lesson_id' => $lessonId,
            'policy_setting_id' => $resolved['policy_setting_id'],
            'policy_setting_version' => $resolved['policy_setting_version'],
            'policy_effective_from' => $resolved['policy_effective_from'],
            'policy_effective_until' => $resolved['policy_effective_until'],
            'global_policy_setting_id' => $resolved['global_policy_setting_id'],
            'global_policy_setting_version' => $resolved['global_policy_setting_version'],
            'global_policy_effective_from' => $resolved['global_policy_effective_from'],
            'global_policy_effective_until' => $resolved['global_policy_effective_until'],
            'global_policy_timezone' => $resolved['global_policy_timezone'],
            'service_area_key' => $resolved['area'],
            'policy_timezone' => $resolved['timezone'],
            'lesson_starts_at_utc' => (string) $context['occurrence_starts_at_utc'],
            'lesson_ends_at_utc' => (string) $context['occurrence_ends_at_utc'],
            'base_amount_minor' => $baseAmountMinor,
            'currency' => $currency,
            'eligible_seconds' => (int) $resolved['eligible_seconds'],
            'eligible_minutes' => (int) $resolved['eligible_minutes'],
            'window_breakdown' => wp_json_encode($resolved['breakdown'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'window_definitions' => wp_json_encode($resolved['window_definitions'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'total_bonus_minor' => (int) $resolved['bonus_minor'],
            'committed_total_minor' => (int) $resolved['total_minor'],
            'calculation_version' => GoldenHourRule::RULE_VERSION,
            'calculation_outcome' => (string) $resolved['outcome'],
            'review_reason' => $resolved['review_reason'],
            'created_at' => $now,
            'created_by' => $actor,
        );
        $id = $this->details->insertDetail($data);
        $data['id'] = $id;
        FinanceSupport::audit('finance_golden_hour_details', $id, 'golden_hour_detail_committed', $actor, $commandDigest, null, $now, substr($commandDigest, 0, 190));
        return $this->decode((object) $data);
    }

    /** The effective committed detail of a Lesson, or null when no bonus detail exists. */
    public function details(int $lessonId): ?array {
        $row = $this->details->byLessonEffective($lessonId);
        return $row ? $this->decode($row) : null;
    }

    /** The detail of one canonical snapshot version. */
    public function detailsForVersion(int $snapshotId, int $correctionId = 0): ?array {
        $row = $this->details->bySnapshotVersion($snapshotId, $correctionId);
        return $row ? $this->decode($row) : null;
    }

    /**
     * The final payable amount under the one canonical projection.
     *
     * @return array{total_minor:int,bonus_minor:int,base_minor:int,currency:string,golden:?array}
     */
    public function committedTotal(int $lessonId, int $baseAmountMinor, string $currency, ?int $snapshotId = null, ?int $correctionId = null): array {
        $detail = $snapshotId === null
            ? $this->details($lessonId)
            : $this->detailsForVersion($snapshotId, $correctionId ?? 0);
        if ($detail === null || (int) $detail['lesson_id'] !== $lessonId) {
            return array('total_minor'=>$baseAmountMinor,'bonus_minor'=>0,'base_minor'=>$baseAmountMinor,'currency'=>$currency,'golden'=>null);
        }
        $detailBase = (int) ($detail['base_amount_minor'] ?? -1);
        $total = (int) ($detail['committed_total_minor'] ?? $baseAmountMinor);
        $bonus = (int) ($detail['total_bonus_minor'] ?? 0);
        if ((string) ($detail['currency'] ?? $currency) !== $currency || $detailBase !== $baseAmountMinor || $total !== $baseAmountMinor + $bonus || $total < $baseAmountMinor) {
            // A corrupt or currency/base-mismatched detail never changes the authoritative base pay.
            return array('total_minor'=>$baseAmountMinor,'bonus_minor'=>0,'base_minor'=>$baseAmountMinor,'currency'=>$currency,'golden'=>null);
        }
        return array('total_minor'=>$total,'bonus_minor'=>$bonus,'base_minor'=>$baseAmountMinor,'currency'=>$currency,'golden'=>$detail);
    }

    /**
     * Audited correction: append a new detail for the correction snapshot version. The base detail
     * and every earlier version remain untouched. A pre-feature snapshot without a detail is never
     * backfilled.
     */
    public function correctBase(int $lessonId, int $snapshotId, int $correctionId, int $correctedBaseMinor, string $currency, int $actor, string $now): void {
        if ($correctionId < 1) throw new \InvalidArgumentException('golden_hour_correction_required');
        $existing = $this->details->bySnapshotVersion($snapshotId, $correctionId);
        if ($existing) return;
        $base = $this->details->bySnapshotVersion($snapshotId, 0);
        if (!$base) return;
        $baseData = $this->decode($base);
        if ((string) ($baseData['currency'] ?? '') !== $currency) throw new \RuntimeException('golden_hour_currency_mismatch');
        $calculation = $this->calculateFromRecordedPolicy($baseData, $correctedBaseMinor);
        $data = array(
            'snapshot_id' => $snapshotId,
            'snapshot_correction_id' => $correctionId,
            'snapshot_version_reference' => $this->versionReference($snapshotId, $correctionId),
            'lesson_id' => $lessonId,
            'policy_setting_id' => $baseData['policy_setting_id'] ?? null,
            'policy_setting_version' => (int) ($baseData['policy_setting_version'] ?? 0),
            'policy_effective_from' => $baseData['policy_effective_from'] ?? null,
            'policy_effective_until' => $baseData['policy_effective_until'] ?? null,
            'global_policy_setting_id' => $baseData['global_policy_setting_id'] ?? null,
            'global_policy_setting_version' => (int) ($baseData['global_policy_setting_version'] ?? 0),
            'global_policy_effective_from' => $baseData['global_policy_effective_from'] ?? null,
            'global_policy_effective_until' => $baseData['global_policy_effective_until'] ?? null,
            'global_policy_timezone' => $baseData['global_policy_timezone'] ?? null,
            'service_area_key' => $baseData['service_area_key'] ?? null,
            'policy_timezone' => $baseData['policy_timezone'] ?? null,
            'lesson_starts_at_utc' => (string) $baseData['lesson_starts_at_utc'],
            'lesson_ends_at_utc' => (string) $baseData['lesson_ends_at_utc'],
            'base_amount_minor' => $correctedBaseMinor,
            'currency' => $currency,
            'eligible_seconds' => (int) $calculation['eligible_seconds'],
            'eligible_minutes' => (int) $calculation['eligible_minutes'],
            'window_breakdown' => wp_json_encode($calculation['breakdown'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'window_definitions' => wp_json_encode($calculation['window_definitions'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'total_bonus_minor' => (int) $calculation['bonus_minor'],
            'committed_total_minor' => (int) $calculation['total_minor'],
            'calculation_version' => GoldenHourRule::RULE_VERSION,
            'calculation_outcome' => (string) $calculation['outcome'],
            'review_reason' => $calculation['review_reason'],
            'created_at' => $now,
            'created_by' => $actor,
        );
        $id = $this->details->insertDetail($data);
        FinanceSupport::audit('finance_golden_hour_details', $id, 'golden_hour_detail_corrected', $actor, 'correction:'.$correctionId, null, $now, 'snapshot='.$snapshotId);
    }

    /**
     * Read-only map for a Teacher's own schedule: committed details when compensation is committed,
     * otherwise an explicitly estimated preview from the same calculator and rate authority.
     *
     * @param list<int> $lessonIds
     * @return array<int,array<string,mixed>>
     */
    public function forLessonIds(array $lessonIds, ?string $teacherTimezone = null): array {
        $out = array();
        foreach (array_values(array_unique(array_filter(array_map('intval', $lessonIds), static fn(int $id): bool => $id > 0))) as $lessonId) {
            $committed = $this->details($lessonId);
            if ($committed !== null) {
                $out[$lessonId] = $this->presentation($committed, 'committed', $teacherTimezone);
                continue;
            }
            $estimate = $this->estimate($lessonId);
            if ($estimate !== null) $out[$lessonId] = $this->presentation($estimate, 'estimated', $teacherTimezone);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function estimate(int $lessonId): ?array {
        try {
            $context = $this->facts->context($lessonId);
            if (!empty($context['finalised'])) return null;
            $instant = (string) $context['occurrence_starts_at_utc'];
            if (!$this->policies->anyEnabledAt($instant)) return null;
            $resolution = $this->rates->resolveFor((int) $context['teacher_id'], (int) $context['course_id'], $instant);
            if (empty($resolution['resolved'])) return null;
            $rate = $resolution['rate'];
            $resolved = $this->resolve($context, (int) $rate->amount_minor, (string) $rate->currency);
            return array(
                'lesson_id' => $lessonId,
                'snapshot_id' => null,
                'snapshot_correction_id' => 0,
                'base_amount_minor' => (int) $rate->amount_minor,
                'currency' => (string) $rate->currency,
                'lesson_starts_at_utc' => (string) $context['occurrence_starts_at_utc'],
                'lesson_ends_at_utc' => (string) $context['occurrence_ends_at_utc'],
                'policy_setting_version' => $resolved['policy_setting_version'],
                'policy_effective_from' => $resolved['policy_effective_from'],
                'policy_effective_until' => $resolved['policy_effective_until'],
                'global_policy_setting_id' => $resolved['global_policy_setting_id'],
                'global_policy_setting_version' => $resolved['global_policy_setting_version'],
                'global_policy_effective_from' => $resolved['global_policy_effective_from'],
                'global_policy_effective_until' => $resolved['global_policy_effective_until'],
                'global_policy_timezone' => $resolved['global_policy_timezone'],
                'service_area_key' => $resolved['area'],
                'policy_timezone' => $resolved['timezone'],
                'window_definitions' => $resolved['window_definitions'],
                'eligible_seconds' => $resolved['eligible_seconds'],
                'eligible_minutes' => $resolved['eligible_minutes'],
                'window_breakdown' => $resolved['breakdown'],
                'total_bonus_minor' => $resolved['bonus_minor'],
                'committed_total_minor' => $resolved['total_minor'],
                'calculation_outcome' => $resolved['outcome'],
                'review_reason' => $resolved['review_reason'],
                'base_rate_source' => $this->rateSource($rate),
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function resolve(array $context, int $baseAmountMinor, string $currency): array {
        $occurrence = (string) $context['occurrence_starts_at_utc'];
        $policy = $this->policies->resolveAt(GoldenHourRule::GLOBAL_IRAN_KEY, $occurrence);
        $resolved = array(
            'area' => null,
            'timezone' => null,
            'policy_setting_id' => null,
            'policy_setting_version' => 0,
            'policy_effective_from' => null,
            'policy_effective_until' => null,
            'global_policy_setting_id' => $policy['setting_id'] === null ? null : (int) $policy['setting_id'],
            'global_policy_setting_version' => (int) $policy['setting_version'],
            'global_policy_effective_from' => (string) $policy['effective_from'],
            'global_policy_effective_until' => $policy['effective_until'],
            'global_policy_timezone' => (string) $policy['timezone'],
            'eligible_seconds' => 0,
            'eligible_minutes' => 0,
            'bonus_minor' => 0,
            'total_minor' => $baseAmountMinor,
            'breakdown' => array(),
            'window_definitions' => array(),
            'outcome' => GoldenHourRule::OUTCOME_DISABLED,
            'review_reason' => null,
        );
        if (empty($policy['enabled'])) return $resolved;
        if ((int) $policy['bonus_basis_points'] <= 0) {
            $resolved['outcome'] = GoldenHourRule::OUTCOME_ZERO;
            return $resolved;
        }
        $windows = array();
        foreach ((array) ($policy['periods'] ?? array()) as $period) {
            $windows[] = array(
                'source' => GoldenHourRule::GLOBAL_IRAN_KEY,
                'timezone' => (string) $policy['timezone'],
                'weekdays' => array_values(array_map('intval', (array) $policy['weekdays'])),
                'start' => (string) $period['start'],
                'end' => (string) $period['end'],
                'bonus_basis_points' => (int) $policy['bonus_basis_points'],
            );
        }
        $resolved['window_definitions'] = $windows;
        if ($windows === array()) {
            $resolved['outcome'] = GoldenHourRule::OUTCOME_ZERO;
            return $resolved;
        }
        $calculation = GoldenHourCalculator::calculate($baseAmountMinor, $occurrence, (string) $context['occurrence_ends_at_utc'], $windows, (string) $policy['timezone']);
        $resolved['eligible_seconds'] = (int) $calculation['eligible_seconds'];
        $resolved['eligible_minutes'] = (int) $calculation['eligible_minutes'];
        $resolved['bonus_minor'] = (int) $calculation['bonus_minor'];
        $resolved['total_minor'] = (int) $calculation['total_minor'];
        $resolved['breakdown'] = (array) $calculation['breakdown'];
        $resolved['outcome'] = $resolved['eligible_seconds'] > 0 ? GoldenHourRule::OUTCOME_APPLIED : GoldenHourRule::OUTCOME_NO_OVERLAP;
        return $resolved;
    }

    /** @return array{bonus_minor:int,total_minor:int,eligible_minutes:int,eligible_seconds:int,breakdown:array,window_definitions:array,outcome:string,review_reason:?string} */
    private function calculateFromRecordedPolicy(array $recorded, int $baseAmountMinor): array {
        $windows = json_decode((string) ($recorded['window_definitions'] ?? '[]'), true);
        $windows = is_array($windows) ? $windows : array();
        if ($windows === array()) {
            $breakdown = json_decode((string) ($recorded['window_breakdown'] ?? '[]'), true);
            $breakdown = is_array($breakdown) ? $breakdown : array();
            foreach ($breakdown as $group) {
                $basisPoints = (int) ($group['basis_points'] ?? 0);
                if ($basisPoints <= 0) continue;
                $windows[] = array(
                    'source' => (string) ($recorded['service_area_key'] ?? ''),
                    'timezone' => (string) ($recorded['policy_timezone'] ?? GoldenHourRule::GLOBAL_IRAN_TIMEZONE),
                    'weekdays' => array_values(array_map('intval', (array) ($group['weekdays'] ?? array(1,2,3,4,5,6,7)))),
                    'start' => (string) ($group['window_start'] ?? '00:00'),
                    'end' => (string) ($group['window_end'] ?? '24:00'),
                    'bonus_basis_points' => $basisPoints,
                );
            }
        }
        if ($windows === array()) {
            return array('bonus_minor'=>0,'total_minor'=>$baseAmountMinor,'eligible_minutes'=>0,'eligible_seconds'=>0,'breakdown'=>array(),'window_definitions'=>array(),'outcome'=>(string) ($recorded['calculation_outcome'] ?? GoldenHourRule::OUTCOME_NO_OVERLAP),'review_reason'=>$recorded['review_reason'] ?? null);
        }
        $calculation = GoldenHourCalculator::calculate(
            $baseAmountMinor,
            (string) $recorded['lesson_starts_at_utc'],
            (string) $recorded['lesson_ends_at_utc'],
            $windows,
            (string) ($windows[0]['timezone'] ?? GoldenHourRule::GLOBAL_IRAN_TIMEZONE)
        );
        return array(
            'bonus_minor' => (int) $calculation['bonus_minor'],
            'total_minor' => (int) $calculation['total_minor'],
            'eligible_minutes' => (int) $calculation['eligible_minutes'],
            'eligible_seconds' => (int) $calculation['eligible_seconds'],
            'breakdown' => (array) $calculation['breakdown'],
            'window_definitions' => $windows,
            'outcome' => (int) $calculation['eligible_seconds'] > 0 ? GoldenHourRule::OUTCOME_APPLIED : GoldenHourRule::OUTCOME_NO_OVERLAP,
            'review_reason' => $recorded['review_reason'] ?? null,
        );
    }

    private function decode(object $row): array {
        $breakdown = json_decode((string) ($row->window_breakdown ?? '[]'), true);
        $breakdown = is_array($breakdown) ? $breakdown : array();
        $windowDefinitions = json_decode((string) ($row->window_definitions ?? '[]'), true);
        $windowDefinitions = is_array($windowDefinitions) ? $windowDefinitions : array();
        $highest = 0;
        foreach ($breakdown as $group) $highest = max($highest, (int) ($group['basis_points'] ?? 0));
        return array(
            'id' => isset($row->id) ? (int) $row->id : null,
            'snapshot_id' => (int) ($row->snapshot_id ?? 0),
            'snapshot_correction_id' => (int) ($row->snapshot_correction_id ?? 0),
            'snapshot_version_reference' => (string) ($row->snapshot_version_reference ?? ''),
            'lesson_id' => (int) ($row->lesson_id ?? 0),
            'policy_setting_id' => isset($row->policy_setting_id) && $row->policy_setting_id !== null ? (int) $row->policy_setting_id : null,
            'policy_setting_version' => (int) ($row->policy_setting_version ?? 0),
            'policy_effective_from' => $row->policy_effective_from ?? null,
            'policy_effective_until' => $row->policy_effective_until ?? null,
            'global_policy_setting_id' => isset($row->global_policy_setting_id) && $row->global_policy_setting_id !== null ? (int) $row->global_policy_setting_id : null,
            'global_policy_setting_version' => (int) ($row->global_policy_setting_version ?? 0),
            'global_policy_effective_from' => $row->global_policy_effective_from ?? null,
            'global_policy_effective_until' => $row->global_policy_effective_until ?? null,
            'global_policy_timezone' => $row->global_policy_timezone ?? null,
            'service_area_key' => $row->service_area_key ?? null,
            'policy_timezone' => $row->policy_timezone ?? null,
            'lesson_starts_at_utc' => (string) ($row->lesson_starts_at_utc ?? ''),
            'lesson_ends_at_utc' => (string) ($row->lesson_ends_at_utc ?? ''),
            'base_amount_minor' => (int) ($row->base_amount_minor ?? 0),
            'currency' => (string) ($row->currency ?? ''),
            'eligible_seconds' => (int) ($row->eligible_seconds ?? 0),
            'eligible_minutes' => (int) ($row->eligible_minutes ?? 0),
            'window_breakdown' => $breakdown,
            'window_definitions' => $windowDefinitions,
            'highest_basis_points' => $highest,
            'total_bonus_minor' => (int) ($row->total_bonus_minor ?? 0),
            'committed_total_minor' => (int) ($row->committed_total_minor ?? 0),
            'calculation_version' => (string) ($row->calculation_version ?? ''),
            'calculation_outcome' => (string) ($row->calculation_outcome ?? ''),
            'review_reason' => $row->review_reason ?? null,
            'created_at' => (string) ($row->created_at ?? ''),
            'created_by' => (int) ($row->created_by ?? 0),
        );
    }

    private function presentation(array $data, string $status, ?string $teacherTimezone = null): array {
        $breakdown = (array) ($data['window_breakdown'] ?? array());
        $windowDefinitions = (array) ($data['window_definitions'] ?? array());
        $highest = 0;
        foreach ($breakdown as $group) $highest = max($highest, (int) ($group['basis_points'] ?? 0));
        $duration = 0;
        if (!empty($data['lesson_starts_at_utc']) && !empty($data['lesson_ends_at_utc'])) {
            $duration = max(0, (int) round((strtotime((string) $data['lesson_ends_at_utc'] . ' UTC') - strtotime((string) $data['lesson_starts_at_utc'] . ' UTC')) / 60));
        }
        return array(
            'lesson_id' => (int) ($data['lesson_id'] ?? 0),
            'status' => $status,
            'snapshot_id' => isset($data['snapshot_id']) && $data['snapshot_id'] !== null ? (int) $data['snapshot_id'] : null,
            'snapshot_correction_id' => (int) ($data['snapshot_correction_id'] ?? 0),
            'base_amount_minor' => (int) ($data['base_amount_minor'] ?? 0),
            'bonus_amount_minor' => (int) ($data['total_bonus_minor'] ?? 0),
            'total_amount_minor' => (int) ($data['committed_total_minor'] ?? 0),
            'bonus_basis_points' => $highest,
            'eligible_minutes' => (int) ($data['eligible_minutes'] ?? 0),
            'duration_minutes' => $duration,
            'currency' => (string) ($data['currency'] ?? ''),
            'area' => $data['service_area_key'] ?? null,
            'area_label' => isset($data['service_area_key']) && $data['service_area_key'] !== null
                ? (string) (GoldenHourRule::defaults()[$data['service_area_key']]['label'] ?? $data['service_area_key'])
                : null,
            'timezone' => $data['policy_timezone'] ?? null,
            'window_breakdown' => $breakdown,
            'window_definitions' => $windowDefinitions,
            'teacher_window_display' => $this->teacherWindowDisplay($windowDefinitions, (string) ($data['lesson_starts_at_utc'] ?? ''), $teacherTimezone),
            'global_policy_setting_version' => (int) ($data['global_policy_setting_version'] ?? 0),
            'global_policy_timezone' => $data['global_policy_timezone'] ?? null,
            'review_reason' => $data['review_reason'] ?? null,
            'review_text' => ($data['review_reason'] ?? null) !== null ? GoldenHourRule::REVIEW_TEXT : null,
            'policy_setting_version' => (int) ($data['policy_setting_version'] ?? 0),
            'policy_effective_from' => $data['policy_effective_from'] ?? null,
            'policy_effective_until' => $data['policy_effective_until'] ?? null,
            'base_rate_source' => (string) ($data['base_rate_source'] ?? 'unknown'),
        );
    }

    /** Display the applied policy windows in the teacher's own timezone for their schedule. */
    private function teacherWindowDisplay(array $windows, string $lessonStartsAtUtc, ?string $teacherTimezone): string {
        if ($windows === array() || $lessonStartsAtUtc === '') return '';
        $utc = new \DateTimeZone('UTC');
        $lesson = new \DateTimeImmutable($lessonStartsAtUtc, $utc);
        $teacherZone = null;
        if ($teacherTimezone !== null && in_array($teacherTimezone, timezone_identifiers_list(), true)) {
            $teacherZone = new \DateTimeZone($teacherTimezone);
        }
        $items = array();
        foreach ($windows as $window) {
            $timezone = (string) ($window['timezone'] ?? '');
            if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) continue;
            $zone = new \DateTimeZone($timezone);
            $date = $lesson->setTimezone($zone)->format('Y-m-d');
            $start = new \DateTimeImmutable($date.' '.(string) ($window['start'] ?? '00:00').':00', $zone);
            $endTime = (string) ($window['end'] ?? '24:00');
            $end = $endTime === '24:00'
                ? (new \DateTimeImmutable($date.' 00:00:00', $zone))->modify('+1 day')
                : new \DateTimeImmutable($date.' '.$endTime.':00', $zone);
            if ($end <= $start) $end = $end->modify('+1 day');
            $source = (string) ($window['source'] ?? '');
            $label = $source === GoldenHourRule::GLOBAL_IRAN_KEY
                ? 'Iran global'
                : (string) (GoldenHourRule::defaults()[$source]['label'] ?? $source);
            $text = $label . ' ' . $start->format('H:i') . '–' . $end->format('H:i') . ' ' . $timezone;
            if ($teacherZone !== null) {
                $teacherStart = $start->setTimezone($teacherZone);
                $teacherEnd = $end->setTimezone($teacherZone);
                $text .= ' · your time ' . $teacherStart->format('H:i') . '–' . $teacherEnd->format('H:i') . ' ' . $teacherTimezone;
            }
            $items[] = $text;
        }
        return implode('; ', array_values(array_unique($items)));
    }

    private function versionReference(int $snapshotId, int $correctionId): string {
        return 'S' . $snapshotId . ':C' . $correctionId;
    }

    private function rateSource(object $rate): string {
        $origin = isset($rate->rate_origin) ? (string) $rate->rate_origin : '';
        return in_array($origin, array('global_inherited','explicit'), true) ? $origin : 'legacy_or_unknown';
    }
}
