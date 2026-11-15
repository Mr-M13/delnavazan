<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingTeacherWorkloadRepository;

/**
 * Weighted, cross-instrument Teacher workload for introductory allocation.
 *
 * This is the approved allocation policy's second ordering key. It runs only
 * after the hard filters in BookingAllocationService::candidates() have already
 * enforced course eligibility, Teacher availability, Student preference and
 * booking safety, so it can never make an ineligible Teacher attractive.
 *
 * Weights (documented here because they are policy, not incidental constants):
 *
 * | component            | weight | why                                              |
 * |----------------------|--------|--------------------------------------------------|
 * | pending offer        | 0.25   | provisional: the Teacher has not committed yet     |
 * | accepted booking     | 1.00   | a real commitment on the Teacher's calendar        |
 * | confirmed lesson     | 1.00   | an enrolled lesson that is still expected          |
 * | completed lesson     | 1.00   | teaching already delivered in the rolling window   |
 *
 * A pending offer is therefore worth a quarter of a commitment: it breaks a tie
 * in favour of the Teacher who has not just been offered work, without letting an
 * unanswered offer hold a Teacher out of rotation as strongly as a confirmed
 * class. Every weight is a multiple of 0.25, so the comparison is exact in
 * binary floating point and does not need an epsilon.
 *
 * The 30-day rolling window is applied to past work only (see the repository), so
 * future commitments are never aged out.
 *
 * Transactional consistency: BookingAllocationService reads this inside the same
 * transaction that already holds the per-Teacher root locks taken by
 * lockEligibleTeacherRoots(), so a concurrent allocation cannot change the score
 * between the read and the delivery/offer insert. No global lock is taken.
 */
final class BookingTeacherWorkloadService {
    public const PENDING_OFFER_WEIGHT = 0.25;
    public const ACCEPTED_BOOKING_WEIGHT = 1.0;
    public const CONFIRMED_LESSON_WEIGHT = 1.0;
    public const COMPLETED_LESSON_WEIGHT = 1.0;
    public const COMPLETED_WINDOW_DAYS = 30;

    /** Canonical ordering of the weight components, used by the audit record. */
    public const WEIGHTS = array(
        'pending' => self::PENDING_OFFER_WEIGHT,
        'accepted' => self::ACCEPTED_BOOKING_WEIGHT,
        'confirmed' => self::CONFIRMED_LESSON_WEIGHT,
        'completed' => self::COMPLETED_LESSON_WEIGHT,
    );

    public function __construct(private ?BookingTeacherWorkloadRepository $repository = null) {
        $this->repository ??= new BookingTeacherWorkloadRepository();
    }

    /**
     * Weighted total workload per Teacher, across every instrument.
     *
     * @param list<int> $teacherIds
     * @return array<int,array{weighted:float,pending:int,accepted:int,confirmed:int,completed:int}>
     */
    public function scores(array $teacherIds, string $now): array {
        $components = $this->repository->components($teacherIds, $now, self::COMPLETED_WINDOW_DAYS);
        $scores = array();
        foreach ($components as $teacherId => $counts) {
            $scores[(int) $teacherId] = array(
                'weighted' => self::weightedTotal($counts),
                'pending' => (int) ($counts['pending'] ?? 0),
                'accepted' => (int) ($counts['accepted'] ?? 0),
                'confirmed' => (int) ($counts['confirmed'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
            );
        }
        return $scores;
    }

    /** @param array<string,int> $counts */
    public static function weightedTotal(array $counts): float {
        $total = 0.0;
        foreach (self::WEIGHTS as $component => $weight) $total += $weight * (int) ($counts[$component] ?? 0);
        return $total;
    }
}
