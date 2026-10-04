<?php
$root = dirname( __DIR__ );
$validation = file_get_contents( $root . '/src/Core/Application/BookingRequestValidationService.php' );
$submission = file_get_contents( $root . '/src/Core/Application/BookingRequestSubmissionService.php' );
if ( false === $validation || false === $submission ) throw new RuntimeException( 'Unable to read booking services' );
if ( strpos( $validation, 'usort( $times' ) !== false ) throw new RuntimeException( 'Requested booking times must preserve submitted preference order' );
if ( strpos( $validation, '$times[] = $item;' ) === false ) throw new RuntimeException( 'Validated times must retain input append order' );
if ( strpos( $submission, 'foreach ( $data[\'times\'] as $sequence => $time ) $this->repo->createTime( $id, $sequence + 1' ) === false ) throw new RuntimeException( 'Persisted sequence must derive from retained submitted order' );
echo "Booking preference order contract passed\n";
