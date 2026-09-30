<?php
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return $value; } }
require dirname( __DIR__ ) . '/src/Core/Application/Normalizer.php';
require dirname( __DIR__ ) . '/src/Core/Application/AvailabilityLocalTime.php';
require dirname( __DIR__ ) . '/src/Core/Application/RequestedTimeNormalizer.php';
use Delnavazan\Platform\Core\Application\RequestedTimeNormalizer;
function p2a1c_reject( callable $fn, string $label ): void { try { $fn(); } catch ( InvalidArgumentException ) { return; } throw new RuntimeException( 'Expected rejection: ' . $label ); }
$brisbane = RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-01-15', 'local_start_time' => '00:30:00', 'timezone' => 'Australia/Brisbane' ), 30, 15 );
if ( $brisbane['starts_at_utc'] !== '2026-01-14 14:30:00' || $brisbane['instructional_ends_at_utc'] !== '2026-01-14 15:00:00' || $brisbane['occupied_ends_at_utc'] !== '2026-01-14 15:15:00' ) throw new RuntimeException( 'Brisbane interval facts invalid' );
$minuteInput = RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-01-15', 'local_start_time' => '00:30', 'timezone' => 'Australia/Brisbane' ), 30, 15 ); if ( $minuteInput['local_start_time'] !== '00:30:00' ) throw new RuntimeException( 'Minute-form time normalization invalid' );
$kathmandu = RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-01-15', 'local_start_time' => '10:00:00', 'timezone' => 'Asia/Kathmandu' ), 30, 15 ); if ( $kathmandu['starts_at_utc'] !== '2026-01-15 04:15:00' ) throw new RuntimeException( 'Kathmandu conversion invalid' );
p2a1c_reject( static fn() => RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-10-04', 'local_start_time' => '02:30:00', 'timezone' => 'Australia/Sydney' ), 30, 15 ), 'Sydney gap' );
p2a1c_reject( static fn() => RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-04-05', 'local_start_time' => '02:30:00', 'timezone' => 'Australia/Sydney' ), 30, 15 ), 'Sydney fold' );
p2a1c_reject( static fn() => RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-11-01', 'local_start_time' => '01:30:00', 'timezone' => 'America/New_York' ), 30, 15 ), 'New York fold' );
p2a1c_reject( static fn() => RequestedTimeNormalizer::normalize( array( 'local_date' => '2026-01-15', 'local_start_time' => '10:00:00', 'timezone' => 'Asia/Tehran' ), 0, 15 ), 'zero duration' );
echo "Phase 2A.1-C requested-time contract passed\n";
