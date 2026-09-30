<?php
namespace Delnavazan\Platform\Core\Application;

/** Normalizes public facts before locking, then resolves catalogue authority only while locked. */
final class BookingRequestValidationService {
    public const MAX_REQUESTED_TIMES = 8;

    public function normalizePublic(array $input): array {
        $allowed = array( 'requested_instrument_id', 'selected_intro_course_id', 'full_name', 'email', 'mobile', 'country', 'city', 'timezone', 'communication_language', 'whatsapp_same_as_mobile', 'whatsapp_number', 'privacy_notice_accepted', 'privacy_notice_version', 'requested_times' );
        if ( array_diff( array_keys( $input ), $allowed ) ) throw new \InvalidArgumentException( 'Unsupported public intake field' );
        $this->assertScalarFields( $input, array( 'requested_instrument_id', 'selected_intro_course_id', 'full_name', 'email', 'mobile', 'country', 'city', 'timezone', 'communication_language', 'whatsapp_same_as_mobile', 'whatsapp_number', 'privacy_notice_accepted', 'privacy_notice_version' ) );
        $times = $input['requested_times'] ?? null; if ( ! is_array( $times ) || ! $times ) throw new \InvalidArgumentException( 'At least one requested time required' ); if ( count( $times ) > self::MAX_REQUESTED_TIMES ) throw new \InvalidArgumentException( 'Too many requested times' );
        $canonicalTimes = array(); foreach ( $times as $time ) {
            if ( ! is_array( $time ) || array_diff( array_keys( $time ), array( 'local_date', 'local_start_time', 'timezone' ) ) ) throw new \InvalidArgumentException( 'Malformed requested time' );
            $this->assertScalarFields( $time, array( 'local_date', 'local_start_time', 'timezone' ) );
            $canonicalTimes[] = $this->canonicalRequestedTime( $time );
        }
        $instrument = Normalizer::id( $input['requested_instrument_id'] ?? null ); $course = Normalizer::id( $input['selected_intro_course_id'] ?? null, false );
        $full = Normalizer::text( $input['full_name'] ?? null, 191, true ); $email = Normalizer::email( $input['email'] ?? null ); $email = $email ? strtolower($email) : null; $mobile = $this->contactPhone( Normalizer::phone( $input['mobile'] ?? null ) ); $country = Normalizer::country( $input['country'] ?? null ); $city = Normalizer::text( $input['city'] ?? null, 191, true ); $timezone = Normalizer::timezone( $input['timezone'] ?? null ); $language = Normalizer::one( $input['communication_language'] ?? null, array( 'fa', 'en' ), 'communication language' );
        if ( ! $email || ! $this->phone( $mobile ) || ! $country || ! $city || ! $timezone ) throw new \InvalidArgumentException( 'Valid contact, country, city, and IANA timezone required' );
        $same = filter_var( $input['whatsapp_same_as_mobile'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ); if ( $same === null ) throw new \InvalidArgumentException( 'WhatsApp mobile relationship required' );
        $whatsapp = $same ? $mobile : $this->contactPhone( Normalizer::phone( $input['whatsapp_number'] ?? null ) ); if ( ! $this->phone( $whatsapp ) ) throw new \InvalidArgumentException( 'Valid WhatsApp number required' );
        if ( ($input['privacy_notice_accepted'] ?? null) !== true ) throw new \InvalidArgumentException( 'Privacy notice acknowledgement required' ); if ( isset( $input['privacy_notice_version'] ) && (string) $input['privacy_notice_version'] !== '2026-09-05' ) throw new \InvalidArgumentException( 'Unsupported privacy notice version' );
        return array( 'instrument_id' => $instrument, 'course_id' => $course, 'contact' => array( 'full_name' => $full, 'email' => $email, 'mobile' => $mobile, 'country' => $country, 'city' => $city, 'timezone' => $timezone, 'communication_language' => $language, 'whatsapp_same_as_mobile' => $same ? 1 : 0, 'whatsapp_number' => $whatsapp ), 'requested_times' => $canonicalTimes );
    }

    /** Resolves one coherent, locked catalogue state for every requested-time child. */
    public function resolveLockedCatalogue(array $data, ?object $instrument, ?object $course): array {
        if ( ! $instrument || $instrument->status !== 'active' || $instrument->archived_at !== null ) throw new \InvalidArgumentException( 'Active Instrument required' );
        $duration = 30; $buffer = 15;
        if ( $data['course_id'] ) { if ( ! $course || $course->status !== 'active' || $course->archived_at !== null || $course->course_type !== 'introductory' || (int) $course->instrument_id !== (int) $instrument->id ) throw new \InvalidArgumentException( 'Valid active Intro Course for Instrument required' ); $duration = Normalizer::count( $course->default_duration_minutes, 5, 480 ); $buffer = Normalizer::count( $course->default_buffer_minutes, 0, 240 ); }
        $times = array(); $seen = array(); foreach ( $data['requested_times'] as $time ) { $item = RequestedTimeNormalizer::normalize( $time, $duration, $buffer ); $key = implode( '|', array( $item['local_date'], $item['local_start_time'], $item['timezone'], $duration, $buffer ) ); if ( isset( $seen[$key] ) ) throw new \InvalidArgumentException( 'Duplicate requested time' ); $seen[$key] = true; $times[] = $item; }
        usort( $times, static fn( array $a, array $b ): int => array( $a['starts_at_utc'], $a['local_date'], $a['local_start_time'], $a['timezone'] ) <=> array( $b['starts_at_utc'], $b['local_date'], $b['local_start_time'], $b['timezone'] ) );
        $data['times'] = $times; unset( $data['requested_times'] ); return $data;
    }
    /** Reject compound public values before a normalizer or PHP cast can inspect them. */
    private function assertScalarFields(array $input, array $fields): void {
        foreach ( $fields as $field ) {
            if ( ! array_key_exists( $field, $input ) || $input[$field] === null ) continue;
            if ( ! is_scalar( $input[$field] ) ) throw new \InvalidArgumentException( 'Malformed public intake field' );
        }
    }
    private function phone(?string $value): bool { return is_string( $value ) && preg_match( '/^\\+?[0-9][0-9 () .-]{5,31}$/', $value ) === 1; }
    /** Contact-only canonical representation for exact duplicate signals; no fuzzy matching. */
    private function contactPhone(?string $value): ?string { if($value===null)return null; $digits=preg_replace('/[^0-9+]/','',$value); return is_string($digits)?$digits:null; }
    /** Catalogue-independent time normalization feeds the idempotency digest; list order is retained. */
    private function canonicalRequestedTime(array $time): array { $date=AvailabilityLocalTime::date((string)$time['local_date']);$raw=(string)$time['local_start_time'];if(preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$raw))$raw.=':00';$local=Normalizer::time($raw);$zone=Normalizer::timezone($time['timezone']);if(!$local||!$zone)throw new \InvalidArgumentException('Requested local date, time, and IANA timezone required');AvailabilityLocalTime::wall($date,$local,$zone);return array('local_date'=>$date,'local_start_time'=>$local,'timezone'=>$zone); }
}
