<?php
namespace Delnavazan\Platform\Core\Application\Port;

/**
 * Calendar projection boundary.
 *
 * A projection mirrors one exact applicable canonical schedule version. The port may create, update
 * or delete a provider calendar event for an already-approved command — it may never create, revise,
 * release or reschedule canonical schedule authority, and it never decides capacity.
 *
 * A port that can perform the provider write returns the acknowledged opaque provider reference. A
 * port that only translates (no transport, no credential) returns the exact provider request it
 * would send plus its fact digest and no reference: the mapping is then recorded as `pending` and is
 * only ever marked verified after a separate acknowledged provider result.
 */
interface ProviderCalendarPort {
    /**
     * @param array{provider_code:string,operation:string,lesson_id:int,schedule_version_id:int,starts_at_utc:string,ends_at_utc:string,schedule_timezone:string,local_wall_date:string,local_wall_time:string,teacher_subject_reference:?string,projection_reference:?string} $command
     * @return array{provider_object_reference?:string,provider_request?:array<string,mixed>,provider_facts_digest?:string,provider_occurred_at_utc:string}
     */
    public function project(array $command):array;
}
