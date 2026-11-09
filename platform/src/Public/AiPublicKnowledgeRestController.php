<?php
namespace Delnavazan\Platform\Public;

/**
 * Explicitly curated public AI reference projection.
 * No database, WordPress post, private portal or admin option reads.
 */
final class AiPublicKnowledgeRestController {
    public static function register(): void {
        register_rest_route(
            'delnavazan-platform/v1',
            '/ai-public-knowledge',
            array(
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => array(__CLASS__, 'read'),
            )
        );
    }

    public static function read(): \WP_REST_Response {
        $file = dirname(__DIR__, 2) . '/resources/ai-public-knowledge.json';
        if (!is_readable($file)) {
            return new \WP_REST_Response(array('success' => false, 'code' => 'knowledge_unavailable'), 503);
        }
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || ($payload['schema_version'] ?? null) !== 1 || !is_array($payload['entries'] ?? null)) {
            return new \WP_REST_Response(array('success' => false, 'code' => 'knowledge_invalid'), 503);
        }

        $today = gmdate('Y-m-d');
        $approved = array();
        foreach ($payload['entries'] as $item) {
            if (!is_array($item) || ($item['status'] ?? '') !== 'approved' || empty($item['approved_by'])) {
                continue;
            }
            $source = (string) ($item['source_url'] ?? '');
            if (!preg_match('~^https://(?:www\\.)?delnavazan\\.com(?:/|$)~i', $source)) {
                continue;
            }
            $from = (string) ($item['effective_from'] ?? '');
            $until = (string) ($item['effective_until'] ?? '');
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from) || $from > $today) {
                continue;
            }
            if ($until !== '' && (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $until) || $until < $today)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            $title = (string) ($item['title'] ?? '');
            $text = (string) ($item['text'] ?? '');
            if ($id === '' || $title === '' || $text === '' || strlen($text) > 8000) {
                continue;
            }
            $approved[] = array(
                'id' => $id,
                'title' => $title,
                'text' => $text,
                'source_url' => $source,
                'version' => (int) ($item['version'] ?? 1),
                'effective_from' => $from,
                'effective_until' => $until ?: null,
                'tags' => array_values(array_filter((array) ($item['tags'] ?? array()), 'is_string')),
            );
        }
        $response = new \WP_REST_Response(array('schema_version' => 1, 'entries' => $approved), 200);
        $response->header('Cache-Control', 'public, max-age=300');
        return $response;
    }
}
