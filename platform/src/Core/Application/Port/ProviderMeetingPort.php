<?php
namespace Delnavazan\Platform\Core\Application\Port;

/**
 * Meeting/Conference projection boundary.
 *
 * A conference is a provider reference bound to one exact occurrence. A join URI is a reference: its
 * existence, its presence in a payload, or a click on it never proves attendance or delivery, and the
 * port may never write any attendance, delivery or completion storage. Like the calendar port, a
 * translation-only port returns the exact provider request plus its fact digest and no reference, and
 * the mapping stays `pending` until a separate acknowledged provider result is recorded.
 */
interface ProviderMeetingPort {
    /**
     * @param array{provider_code:string,operation:string,lesson_id:int,schedule_version_id:int,starts_at_utc:string,ends_at_utc:string,teacher_subject_reference:?string,projection_reference:?string} $command
     * @return array{provider_object_reference?:string,join_uri_reference?:string,provider_request?:array<string,mixed>,provider_facts_digest?:string,provider_occurred_at_utc:string}
     */
    public function project(array $command):array;
}
