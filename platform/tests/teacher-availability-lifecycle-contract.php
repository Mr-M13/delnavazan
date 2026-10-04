<?php
$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Core/Infrastructure/Repository/TeacherAvailabilityRepository.php' );
if ( false === $source ) throw new RuntimeException( 'Unable to read teacher availability repository' );
if ( strpos( $source, 'teacher_onboarding_states o ON o.teacher_id=t.id' ) === false ) throw new RuntimeException( 'Availability evaluation must join teacher onboarding state' );
if ( strpos( $source, "o.state='active' AND o.readiness_state='ready'" ) === false ) throw new RuntimeException( 'Availability evaluation must require active ready onboarding' );
echo "Teacher availability lifecycle contract passed\n";
