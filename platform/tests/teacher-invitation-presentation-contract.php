<?php
function sanitize_text_field($v){return trim(strip_tags($v));}
function sanitize_textarea_field($v){return strip_tags($v);}
require_once dirname(__DIR__).'/src/Integrations/Notifications/TeacherInvitationPresentation.php';
use Delnavazan\Platform\Integrations\Notifications\TeacherInvitationPresentation as Copy;
$copy=Copy::validate(Copy::SUBJECT,Copy::BODY);
if(!str_contains($copy['body'],'{{claim_url}}')||!str_contains($copy['body'],'{{invitation_code}}'))throw new RuntimeException('The safe fallback must preserve required claim instructions');
$failed=false;try{Copy::validate('Subject','{{claim_url}} {{invitation_code}} {{raw_token}}');}catch(InvalidArgumentException){$failed=true;}if(!$failed)throw new RuntimeException('Unallowlisted placeholders must be refused');
$failed=false;try{Copy::validate('Subject','{{claim_url}} only');}catch(InvalidArgumentException){$failed=true;}if(!$failed)throw new RuntimeException('Mandatory one-time code fallback must not be removable');
$failed=false;try{Copy::validate('Subject','https://example.test/{{invitation_code}} {{claim_url}}');}catch(InvalidArgumentException){$failed=true;}if(!$failed)throw new RuntimeException('Invitation secret must not be placed in a URL by edited copy');
$rendered=Copy::render($copy['body'],'https://staging.example.test/teacher-invitation/','secret-test-value');
if(!str_contains($rendered,'https://staging.example.test/teacher-invitation/')||!str_contains($rendered,'secret-test-value'))throw new RuntimeException('Allowlisted values must render into the delivery only');
echo "Teacher invitation presentation contract passed\n";
