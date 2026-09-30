<?php
require dirname( __DIR__ ) . '/src/Core/Application/AvailabilityLocalTime.php';
use Delnavazan\Platform\Core\Application\AvailabilityLocalTime;
function availability_expect_rejection( callable $fn, string $label ): void { try { $fn(); } catch ( InvalidArgumentException ) { return; } throw new RuntimeException( 'Expected rejection: ' . $label ); }
$brisbane = AvailabilityLocalTime::interval( '2026-01-15', '09:00:00', '10:00:00', 'Australia/Brisbane' );
if ( $brisbane['ends_at_utc'] <= $brisbane['starts_at_utc'] ) throw new RuntimeException( 'Brisbane interval invalid' );
$kathmandu = AvailabilityLocalTime::interval( '2026-01-15', '10:00:00', '11:00:00', 'Asia/Kathmandu' );
if ( $kathmandu['starts_at_utc'] !== '2026-01-15 04:15:00' ) throw new RuntimeException( 'Fractional offset conversion failed' );
$tehran = AvailabilityLocalTime::interval( '2026-01-15', '10:00:00', '11:00:00', 'Asia/Tehran' );
if ( $tehran['ends_at_utc'] <= $tehran['starts_at_utc'] ) throw new RuntimeException( 'Tehran conversion invalid' );
$crossing = AvailabilityLocalTime::interval( '2026-01-15', '00:30:00', '01:30:00', 'Australia/Brisbane' );
if ( substr( $crossing['starts_at_utc'], 0, 10 ) !== '2026-01-14' ) throw new RuntimeException( 'UTC date-boundary conversion failed' );
availability_expect_rejection( static fn() => AvailabilityLocalTime::interval( '2026-10-04', '02:30:00', '03:30:00', 'Australia/Sydney' ), 'Sydney spring-forward nonexistent time' );
availability_expect_rejection( static fn() => AvailabilityLocalTime::interval( '2026-04-05', '02:30:00', '03:30:00', 'Australia/Sydney' ), 'Sydney fall-back ambiguous time' );
availability_expect_rejection( static fn() => AvailabilityLocalTime::interval( '2026-03-08', '02:30:00', '03:30:00', 'America/New_York' ), 'New York spring-forward nonexistent time' );
availability_expect_rejection( static fn() => AvailabilityLocalTime::interval( '2026-11-01', '01:30:00', '02:30:00', 'America/New_York' ), 'New York fall-back ambiguous time' );
availability_expect_rejection( static fn() => AvailabilityLocalTime::interval( '2026-01-15', '10:00:00', '10:00:00', 'Australia/Brisbane' ), 'zero interval' );
echo "Phase 2A.1-B local-time contract passed\n";
