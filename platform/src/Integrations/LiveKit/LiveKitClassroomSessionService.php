<?php
namespace Delnavazan\Platform\Integrations\LiveKit;

/** Orchestrates an authorised classroom session: canonical re-check, 1:1 room, short token. */
final class LiveKitClassroomSessionService {
    public function __construct(
        private ?LiveKitClassroomAuthorizer $authorizer = null,
        private ?LiveKitConfiguration $configuration = null,
        private ?LiveKitRoomRegistry $registry = null,
        private ?LiveKitRoomServiceClient $rooms = null,
        private ?LiveKitJwtIssuer $issuer = null,
        private ?LiveKitLaunchTicket $tickets = null,
        private $clock = null
    ) {
        $this->authorizer ??= new LiveKitClassroomAuthorizer();
        $this->configuration ??= new LiveKitConfiguration();
        $this->registry ??= new LiveKitRoomRegistry();
        $this->issuer ??= new LiveKitJwtIssuer();
        $this->tickets ??= new LiveKitLaunchTicket();
    }

    public function session(string $ticket): array {
        $claims = $this->tickets->verify($ticket);
        $role = (string)$claims['role'];
        $kind = (string)$claims['kind'];
        $input = $kind === 'booking'
            ? array('kind' => 'booking', 'confirmation_uid' => (string)$claims['confirmation_uid'])
            : array('kind' => 'lesson', 'lesson_uid' => (string)$claims['lesson_uid'], 'schedule_version_uid' => (string)$claims['schedule_version_uid']);
        $grant = $this->authorizer->authorize($role, $input);
        $config = $this->configuration->values();
        $this->rooms ??= new LiveKitRoomServiceClient($config, $this->issuer, null, $this->clock);
        $this->rooms->ensureRoom((string)$grant['room']);
        $this->registry->remember(array(
            'room' => (string)$grant['room'],
            'kind' => (string)$grant['kind'],
            'resource_id' => (int)$grant['resource_id'],
            'resource_uid' => (string)$grant['resource_uid'],
            'schedule_key' => (string)$grant['schedule_key'],
            'teacher_id' => (int)$grant['teacher_id'],
            'student_id' => (int)$grant['student_id'],
            'teacher_identity' => (string)$grant['teacher_identity'],
            'student_identity' => (string)$grant['student_identity'],
            'expires_at' => gmdate('Y-m-d H:i:s', $this->now() + LiveKitRoomRegistry::TTL_SECONDS),
        ));
        $token = $this->issuer->issueParticipant(
            (string)$config['key'],
            (string)$config['secret'],
            (string)$grant['room'],
            (string)$grant['identity'],
            (string)$grant['display_name'],
            $this->now()
        );
        return array(
            'url' => (string)$config['url'],
            'token' => $token,
            'expires_in' => LiveKitJwtIssuer::PARTICIPANT_TTL_SECONDS,
            'role' => (string)$grant['role'],
            'display_name' => (string)$grant['display_name'],
            'peer_name' => (string)$grant['peer_name'],
            'teacher_name' => (string)$grant['teacher_name'],
            'student_name' => (string)$grant['student_name'],
            'course_name' => (string)$grant['course_name'],
            'starts_at_utc' => (string)$grant['starts_at_utc'],
            'ends_at_utc' => (string)$grant['ends_at_utc'],
            'meet_available' => (bool)$grant['meet_available'],
            'max_participants' => 2,
            'is_replacement' => (bool)$grant['is_replacement'],
        );
    }

    private function now(): int {
        return $this->clock !== null ? (int)($this->clock)() : time();
    }
}
