<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

final class RecordingConsentRepository extends BaseRepository {
    protected string $table;
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'dzn_recording_consent_grants';
    }
}
