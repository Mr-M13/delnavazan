<?php
declare(strict_types=1);

/** Deterministic financial arithmetic and immutable-snapshot unit proof. */
function wp_salt(string $scheme = 'auth'): string { return 'promotion-application-unit-test-salt-long-enough'; }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }

require_once __DIR__ . '/../src/Core/Application/CommercialMoney.php';
require_once __DIR__ . '/../src/Core/Application/CommercialIdempotency.php';
require_once __DIR__ . '/../src/Core/Application/CommercialRule.php';
require_once __DIR__ . '/../src/Core/Application/CommercialValidator.php';

function promotion_unit_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

use Delnavazan\Platform\Core\Application\CommercialIdempotency;
use Delnavazan\Platform\Core\Application\CommercialMoney;
use Delnavazan\Platform\Core\Application\CommercialRule;
use Delnavazan\Platform\Core\Application\CommercialValidator;

// Exact integer basis-point arithmetic, rounded half-up exactly once.
promotion_unit_assert(CommercialMoney::percentage(10000, 1000) === 1000, '10% of 10000 must be 1000');
promotion_unit_assert(CommercialMoney::percentage(10005, 1000) === 1001, '10% of 10005 must round half-up to 1001');
promotion_unit_assert(CommercialMoney::percentage(10001, 1000) === 1000, '10% of 10001 must round down to 1000');
promotion_unit_assert(CommercialMoney::percentage(25000, 3333) === 8333, '3333 bp of 25000 must be 8333');
promotion_unit_assert(CommercialMoney::percentage(1, 5000) === 1, '50% of one minor unit must round half-up to one');
promotion_unit_assert(CommercialMoney::percentage(0, 10000) === 0, 'A zero amount must never produce a discount');
promotion_unit_assert(CommercialMoney::percentage(25000, 10000) === 25000, 'A 100% promotion must discount the whole payable amount');
promotion_unit_assert(CommercialMoney::subtract(25000, 25000) === 0, 'A 100% promotion must produce an exact zero payable amount');

// A discount is capped by the eligible payable amount and can never create a negative balance.
promotion_unit_assert(CommercialMoney::discount(25000, 30000) === 25000, 'A fixed discount must be capped at the payable amount');
promotion_unit_assert(CommercialMoney::subtract(25000, 30000) === 0, 'A discount can never produce a negative payable amount');
promotion_unit_assert(CommercialMoney::subtract(25000, 2500) === 22500, 'Percentage discount must be exact');
promotion_unit_assert(CommercialMoney::splitTwo(22501) === array(11251, 11250), 'Two-part decomposition must preserve every minor unit');

// Codes are normalised case-insensitively and only their keyed digest is comparable.
promotion_unit_assert(
    hash_equals(CommercialIdempotency::promotionCode(' save10 '), CommercialIdempotency::promotionCode('SAVE10')),
    'Promotion codes must be normalised before digesting'
);

// A superseded offer remains valid immutable history (reads/lineage keep working) while its stored
// amount cannot be rewritten. Offer validity is purely structural.
$obligation = (object) array(
    'obligation_sequence' => 1, 'sessions_from' => 1, 'sessions_to' => 12, 'sessions_covered' => 12,
    'currency' => 'AUD', 'amount_minor' => 22500, 'obligation_reference_digest' => str_repeat('b', 64),
);
$adjustment = (object) array(
    'application_order' => 1, 'currency' => 'AUD', 'source_type' => 'promotion',
    'applied_amount_minor' => 2500, 'snapshot_digest' => str_repeat('c', 64), 'created_at' => '2026-10-28 00:00:00',
);
$offer = (object) array(
    'state' => 'superseded', 'plan_kind' => 'full', 'committed_sessions' => 12, 'currency' => 'AUD',
    'base_amount_minor' => 25000, 'discount_total_minor' => 2500, 'amount_due_minor' => 22500,
    'promotion_id' => 5, 'promotion_amount_minor' => 2500,
    'account_adjustment_id' => null, 'account_adjustment_amount_minor' => null,
    'issued_at' => '2026-10-28 00:00:00', 'evidence_reference_digest' => str_repeat('a', 64), 'offer_version' => 1,
);
promotion_unit_assert(in_array('superseded', CommercialRule::OFFER_STATES, true), 'Superseded must be a recognised offer state');
promotion_unit_assert(CommercialValidator::offerValid($offer, array($obligation), array($adjustment)), 'A superseded offer must remain structurally valid immutable history');

$mutated = clone $offer;
$mutated->amount_due_minor = 20000;
promotion_unit_assert(!CommercialValidator::offerValid($mutated, array($obligation), array($adjustment)), 'A rewritten offer amount must fail structural validation');

$wrongObligation = clone $obligation;
$wrongObligation->amount_minor = 20000;
promotion_unit_assert(!CommercialValidator::offerValid($offer, array($wrongObligation), array($adjustment)), 'Obligations must sum to the amount due');

// A fully discounted offer is structurally valid with a zero payable amount and zero obligations.
$freeOffer = clone $offer;
$freeOffer->discount_total_minor = 25000;
$freeOffer->amount_due_minor = 0;
$freeOffer->promotion_amount_minor = 25000;
$freeObligation = clone $obligation;
$freeObligation->amount_minor = 0;
$freeAdjustment = clone $adjustment;
$freeAdjustment->applied_amount_minor = 25000;
promotion_unit_assert(CommercialValidator::offerValid($freeOffer, array($freeObligation), array($freeAdjustment)), 'A 100% discounted offer must be valid at zero payable');

echo "Promotion application financial unit passed\n";
