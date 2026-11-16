<?php
/**
 * People-directory contact import: CSV parsing, normalisation, country mapping
 * and duplicate-identity collapse, tested without a database or WordPress.
 */
require_once dirname( __DIR__ ) . '/src/Core/Application/PeopleDirectoryImportService.php';

use Delnavazan\Platform\Core\Application\PeopleDirectoryImportService;

$fail = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) throw new RuntimeException( $message );
};

// CSV parsing keys rows by header and tolerates whitespace/blank lines.
$csv = "name,phone,email,city,country,instrument,teacher_name\n"
	. "Alice Example,+98 912 000 1111,alice@example.invalid,Tehran,IR,Piano,Ms. B\n"
	. "\n"
	. "Bob Example,0912 000 2222,bob@example.invalid,Mashhad,Iran,Violin,Mr. C\n";
$rows = PeopleDirectoryImportService::parseCsv( $csv );
$fail( count( $rows ) === 2, 'CSV parsing must return two non-blank rows' );
$fail( ( $rows[0]['name'] ?? '' ) === 'Alice Example', 'First row name must parse' );
$fail( ( $rows[1]['country'] ?? '' ) === 'Iran', 'Second row country must parse' );

// Row normalisation rejects an empty name and a row with no contact identity.
$normalised = PeopleDirectoryImportService::normaliseRow( array( 'name' => 'Alice', 'phone' => '+98 912 000 1111', 'email' => 'Alice@Example.INVALID ' ) );
$fail( $normalised['phone'] === '989120001111', 'Normalised phone must be digits only' );
$fail( $normalised['email'] === 'alice@example.invalid', 'Normalised email must be lowercased/trimmed' );
try { PeopleDirectoryImportService::normaliseRow( array( 'name' => '' ) ); throw new RuntimeException( 'Expected missing-name refusal' ); }
catch ( InvalidArgumentException $error ) { $fail( $error->getMessage() === 'Contact name is required', 'Missing name must be refused' ); }
try { PeopleDirectoryImportService::normaliseRow( array( 'name' => 'X', 'phone' => '', 'email' => '' ) ); throw new RuntimeException( 'Expected missing-contact refusal' ); }
catch ( InvalidArgumentException $error ) { $fail( $error->getMessage() === 'A phone or email is required to match a contact', 'A contact with no phone/email must be refused' ); }

// Country normalisation accepts ISO-2 codes and common full names.
$fail( PeopleDirectoryImportService::normaliseCountry( 'IR' ) === 'IR', 'ISO code must pass through' );
$fail( PeopleDirectoryImportService::normaliseCountry( 'iran' ) === 'IR', 'Full name must map to its code' );
$fail( PeopleDirectoryImportService::normaliseCountry( 'Australia' ) === 'AU', 'Full name must map case-insensitively' );
$fail( PeopleDirectoryImportService::normaliseCountry( '' ) === null, 'Empty country must be null' );
$fail( PeopleDirectoryImportService::normaliseCountry( 'Nowhere' ) === null, 'Unknown country must be null' );

// Duplicate identity collapse: same phone OR same email collapses to one row.
$batch = array(
	array( 'name' => 'Alice', 'phone' => '0912 000 1111', 'email' => 'alice@example.invalid' ),
	array( 'name' => 'Alice Again', 'phone' => '09120001111', 'email' => 'other@example.invalid' ),
	array( 'name' => 'Alice Third', 'phone' => '000', 'email' => 'ALICE@example.invalid' ),
	array( 'name' => 'Bob', 'phone' => '0912 000 2222', 'email' => 'bob@example.invalid' ),
);
$deduped = PeopleDirectoryImportService::dedupeRows( $batch );
$fail( count( $deduped['rows'] ) === 2, 'Duplicate identities must collapse to two rows' );
$fail( $deduped['skipped'] === 2, 'Two duplicate rows must be reported skipped' );
$fail( $deduped['rows'][0]['name'] === 'Alice', 'First occurrence wins the identity' );
$fail( $deduped['rows'][1]['name'] === 'Bob', 'Distinct identity is preserved' );

echo "People directory import unit passed\n";
