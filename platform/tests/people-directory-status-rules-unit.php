<?php
/**
 * People directory derived-status rules, tested without a database.
 *
 * The pure derivation helpers are the single source of truth for the status a
 * directory row carries. This unit proves the active/former split for Students
 * and Teachers, the fixed lead status, the enrolment/term summary, the contact
 * normalisation and the import-match identity rule.
 */
require_once dirname( __DIR__ ) . '/src/Core/Application/PeopleDirectoryReadService.php';

use Delnavazan\Platform\Core\Application\PeopleDirectoryReadService;

$fail = static function ( bool $ok, string $message ): void {
	if ( ! $ok ) throw new RuntimeException( $message );
};

// Student status is active exactly when a current operational Enrolment exists.
$fail( PeopleDirectoryReadService::studentStatus( true ) === 'active', 'A student with a current enrolment must be active' );
$fail( PeopleDirectoryReadService::studentStatus( false ) === 'former', 'A student without a current enrolment must be former' );

// Teacher status is active only when onboarding is complete and principal-linked.
$fail( PeopleDirectoryReadService::teacherStatus( true ) === 'active', 'An onboarded, ready Teacher must be active' );
$fail( PeopleDirectoryReadService::teacherStatus( false ) === 'former', 'A non-ready Teacher must be former' );

// A lead is a lead; there is no active/former split for a prospective person.
$fail( PeopleDirectoryReadService::leadStatus() === 'lead', 'A lead status must be lead' );

// The enrolment/term summary covers the two scope examples exactly.
$fail( PeopleDirectoryReadService::termCountLabel( 2 ) === 'enrolled 2 terms', 'Two terms must read "enrolled 2 terms"' );
$fail( PeopleDirectoryReadService::termCountLabel( 1 ) === 'enrolled 1 term', 'One term must read "enrolled 1 term"' );
$fail( PeopleDirectoryReadService::termCountLabel( 0 ) === '0 enrolment', 'Zero terms must read "0 enrolment" (went through intro, never enrolled)' );

// Normalisation makes practical phone/email matching possible and stable.
$fail( PeopleDirectoryReadService::normalisePhone( '+98 (912) 345-6789' ) === '989123456789', 'Phone normalisation must strip non-digits' );
$fail( PeopleDirectoryReadService::normalisePhone( '0912 345 6789' ) === '09123456789', 'Phone normalisation must strip spaces' );
$fail( PeopleDirectoryReadService::normaliseEmail( '  Person@Example.COM ' ) === 'person@example.com', 'Email normalisation must lowercase and trim' );

// Import match identity: a matched id means the human is already represented.
$fail( PeopleDirectoryReadService::matched( 12, null ) === true, 'A matched student id marks the contact as matched' );
$fail( PeopleDirectoryReadService::matched( null, 7 ) === true, 'A matched teacher id marks the contact as matched' );
$fail( PeopleDirectoryReadService::matched( null, null ) === false, 'An unmatched contact is not matched' );
$fail( PeopleDirectoryReadService::matched( 0, 0 ) === false, 'Zero ids are not a match' );

// The group vocabulary the follow-up notification PR consumes is fixed and complete.
$groups = PeopleDirectoryReadService::GROUPS;
foreach ( array( 'active_students', 'former_students', 'active_teachers', 'former_teachers', 'leads', 'imported_contacts' ) as $required ) {
	$fail( in_array( $required, $groups, true ), 'Missing People group: ' . $required );
}

// Derived status vocabulary is exactly active / former / lead.
$fail( PeopleDirectoryReadService::STATUSES === array( 'active', 'former', 'lead' ), 'Status vocabulary must be active/former/lead' );

echo "People directory status rules unit passed\n";
