<?php
/** Pure unit proof for the centralized Google Workspace classroom vocabulary. */
$root = dirname(__DIR__);
require_once $root . '/src/Integrations/GoogleWorkspace/GoogleWorkspaceClassroomRule.php';

$assert = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

use Delnavazan\Platform\Integrations\GoogleWorkspace\GoogleWorkspaceClassroomRule as Rule;

$assert(Rule::ORGANIZER_EMAIL_DEFAULT === 'classroom@delnavazan.com', 'Organizer default changed.');
$assert(str_contains(Rule::SCOPE_SNAPSHOT, 'https://www.googleapis.com/auth/calendar.events'), 'Calendar scope missing.');
$assert(str_contains(Rule::SCOPE_SNAPSHOT, 'https://www.googleapis.com/auth/meetings.space.created'), 'Meet create scope missing.');
$assert(str_contains(Rule::SCOPE_SNAPSHOT, 'https://www.googleapis.com/auth/meetings.space.settings'), 'Meet settings scope missing.');
$assert(str_contains(Rule::SCOPE_SNAPSHOT, 'https://www.googleapis.com/auth/meetings.space.readonly'), 'Meet read scope missing.');
$assert(str_contains(Rule::SCOPE_SNAPSHOT, 'https://www.googleapis.com/auth/drive.readonly'), 'Drive read scope missing.');
$assert(str_contains(Rule::SCOPE_SNAPSHOT, 'openid') && str_contains(Rule::SCOPE_SNAPSHOT, 'email'), 'Organizer identity scope missing.');
$assert(!str_contains(Rule::SCOPE_SNAPSHOT, 'auth/drive '), 'Full Drive scope must not be requested.');
$assert(!str_contains(Rule::SCOPE_SNAPSHOT, 'auth/calendar '), 'Full Calendar scope must not be requested.');

$assert(Rule::scopeSetMatches(Rule::SCOPE_SNAPSHOT), 'Exact granted scope set was refused.');
$assert(Rule::scopeSetMatches('email openid https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/meetings.space.readonly https://www.googleapis.com/auth/meetings.space.settings https://www.googleapis.com/auth/meetings.space.created https://www.googleapis.com/auth/calendar.events'), 'Order-independent exact scope set was refused.');
$assert(!Rule::scopeSetMatches(Rule::SCOPE_SNAPSHOT . ' https://www.googleapis.com/auth/drive'), 'An extra grant must force re-consent.');

$assert(Rule::eventId('lesson:42') === Rule::eventId('lesson:42'), 'Event id is not deterministic.');
$assert(Rule::eventId('lesson:42') !== Rule::eventId('lesson:43'), 'Different lessons collided on one event id.');
$assert(preg_match('/^[0-9a-v]{32}$/D', Rule::eventId('lesson:42')) === 1, 'Event id is not in Google\'s accepted alphabet.');
$assert(Rule::commandDigest('lesson:42', 'create') === Rule::commandDigest('lesson:42', 'create'), 'Command digest is not deterministic.');
$assert(Rule::commandDigest('lesson:42', 'create') !== Rule::commandDigest('lesson:42', 'cancel'), 'Operation was not bound into the command digest.');

$uri = Rule::meetUri('https://meet.google.com/abc-defg-hij');
$assert($uri === 'https://meet.google.com/abc-defg-hij', 'A valid Meet URI was changed.');
$assert(Rule::meetingCode($uri) === 'abc-defg-hij', 'Meeting code extraction failed.');
foreach (array('http://meet.google.com/abc-defg-hij', 'https://evil.example/abc-defg-hij', 'https://meet.google.com/', 'javascript:alert(1)') as $bad) {
    try { Rule::meetUri($bad); throw new RuntimeException('Untrusted Meet URI accepted: ' . $bad); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'meet_join_uri_untrusted', 'Unexpected Meet URI refusal: ' . $error->getMessage()); }
}

$assert(Rule::rfc3339('2026-11-04 18:30:00') === '2026-11-04T18:30:00Z', 'RFC3339 conversion failed.');
$assert(Rule::fromRfc3339('2026-11-04T18:30:00.123Z') === '2026-11-04 18:30:00', 'UTC database conversion failed.');
$assert(Rule::utc('2026-11-04 18:30:00'), 'A valid UTC instant was refused.');
$assert(!Rule::utc('2026-11-04T18:30:00Z'), 'A non-database UTC shape was accepted.');

$assert(Rule::spaceName('spaces/AbC123') === 'spaces/AbC123', 'A valid space name was refused.');
foreach (array('spaces/../etc', 'spaces/', 'AbC123') as $bad) {
    try { Rule::spaceName($bad); throw new RuntimeException('Bad space accepted: ' . $bad); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'meet_space_name_invalid', 'Unexpected space refusal: ' . $error->getMessage()); }
}
$assert(Rule::conferenceRecordName('conferenceRecords/abc_DEF-123') === 'conferenceRecords/abc_DEF-123', 'Conference record refused.');
$assert(Rule::recordingName('conferenceRecords/abc/recordings/xyz') === 'conferenceRecords/abc/recordings/xyz', 'Recording name refused.');
$assert(Rule::driveFileId('1kuceFZohVoCh6FulBHxwy6I15Ogpc4hP') === '1kuceFZohVoCh6FulBHxwy6I15Ogpc4hP', 'Drive file id refused.');
foreach (array('short', '../../etc/passwd', 'file id with spaces') as $bad) {
    try { Rule::driveFileId($bad); throw new RuntimeException('Bad Drive file id accepted: ' . $bad); }
    catch (InvalidArgumentException $error) { $assert($error->getMessage() === 'meet_drive_file_id_invalid', 'Unexpected Drive refusal: ' . $error->getMessage()); }
}

$assert(Rule::accessType() === 'OPEN', 'Absent-organizer access type must default to OPEN.');
$assert(in_array(Rule::accessType(), array('OPEN', 'TRUSTED', 'RESTRICTED'), true), 'Access type is outside the supported set.');

echo "Google Meet classroom rule unit passed\n";
