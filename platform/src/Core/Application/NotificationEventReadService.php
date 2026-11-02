<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;

/**
 * Small, contact-free projection of the latest delivery evidence for each catalogue event.
 *
 * It reads aggregate identifiers and states only: no recipient, message body, provider reference
 * or credential is returned. A transport hand-off is never promoted to confirmed delivery.
 */
final class NotificationEventReadService {
    /** @return array<string,array{state:string,label:string,at:string,channel:string,subject:string}> */
    public function latestByEvent(int $limit = 300): array {
        $limit = max(1, min(1000, $limit));
        $out = array();
        foreach ($this->canonicalRows($limit) as $row) {
            $event = (string) ($row->intent_key ?? '');
            if ($event === '') continue;
            $category = NotificationDeliveryStatusReadService::categoryFor('canonical', (string) ($row->state ?? ''), isset($row->delivered_at) ? (string) $row->delivered_at : null);
            $out[$event] = $this->row($category, (string) ($row->updated_at ?? ''), 'canonical', (string) ($row->subject_aggregate ?? ''), (int) ($row->subject_aggregate_id ?? 0));
        }
        foreach ($this->portalRows($limit) as $row) {
            $event = (string) ($row->event_key ?? '');
            if ($event === '') continue;
            $category = NotificationDeliveryStatusReadService::categoryFor('portal', 'recorded');
            // Canonical evidence is stronger than a portal copy of the same event.
            if (isset($out[$event]) && $out[$event]['state'] === NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED) continue;
            $out[$event] = $this->row($category, (string) ($row->created_at ?? ''), 'portal', 'portal_notification', (int) ($row->id ?? 0));
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function row(string $category, string $at, string $source, string $aggregate, int $id): array {
        return array(
            'state' => $category,
            'label' => $this->label($category),
            'at' => $at,
            'source' => $source,
            'subject' => $aggregate,
            'subject_id' => $id,
        );
    }

    private function label(string $category): string {
        return match ($category) {
            NotificationDeliveryStatusReadService::QUEUED => 'Queued',
            NotificationDeliveryStatusReadService::HANDED_OFF => 'Handed to provider',
            NotificationDeliveryStatusReadService::DELIVERY_CONFIRMED => 'Delivery confirmed',
            NotificationDeliveryStatusReadService::PORTAL_RECORDED => 'Available in portal',
            NotificationDeliveryStatusReadService::FAILED => 'Failed',
            default => 'Closed',
        };
    }

    /** @return array<int,object> */
    private function canonicalRows(int $limit): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        $table = $wpdb->prefix . 'dzn_notifications';
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT n.intent_key,n.state,n.delivered_at,n.updated_at,n.subject_aggregate,n.subject_aggregate_id
                 FROM {$table} n
                 INNER JOIN (SELECT intent_key,MAX(id) AS id FROM {$table} GROUP BY intent_key) latest ON latest.id=n.id
                 ORDER BY n.id DESC LIMIT %d",
                $limit
            ));
        } catch (\Throwable) { return array(); }
        return is_array($rows) ? $rows : array();
    }

    /** @return array<int,object> */
    private function portalRows(int $limit): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) return array();
        $table = $wpdb->prefix . 'dzn_portal_notifications';
        try {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.event_key,p.created_at,p.id
                 FROM {$table} p
                 INNER JOIN (SELECT event_key,MAX(id) AS id FROM {$table} GROUP BY event_key) latest ON latest.id=p.id
                 ORDER BY p.id DESC LIMIT %d",
                $limit
            ));
        } catch (\Throwable) { return array(); }
        return is_array($rows) ? $rows : array();
    }

    /** @return string[] */
    public static function audiences(string $audience): array {
        return $audience === '' ? array() : array($audience);
    }

    public static function audienceLabel(string $audience): string {
        return match ($audience) {
            'student' => 'Student',
            'teacher' => 'Teacher',
            'admin' => 'Administrator',
            default => ucfirst($audience),
        };
    }

    /** The purpose text is the catalogue description, never a template body. */
    public static function purpose(string $eventKey): string {
        $event = NotificationCatalogue::get($eventKey);
        return (string) ($event['purpose'] ?? $event['label'] ?? $eventKey);
    }
}
