<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Idempotent draft provisioning for the five public legal-policy pages.
 *
 * This service never publishes a policy. It creates or updates a WordPress page
 * as an unpublished draft, attaches the Policy presentation template and records
 * the exact source version/digest. Publication remains an explicit human action
 * in WordPress after owner and legal review.
 */
final class LegalPageProvisioner {
    public const META_MARKER = '_dzn_legal_document';
    public const META_VERSION = '_dzn_legal_version';
    public const META_REVIEW = '_dzn_legal_review_status';
    public const META_SOURCE_HASH = '_dzn_legal_source_sha256';
    public const META_LEGACY_SLUG = '_dzn_legal_legacy_slug';
    public const REVIEW_STATUS = 'draft_requires_owner_and_legal_review';
    public const PAGE_TEMPLATE = 'page-templates/content-policy.php';

    /**
     * Canonical document registry. The slugs, labels and titles are mirrored in
     * the Theme footer/pending route; a source contract asserts they agree.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function documents(): array {
        return array(
            'privacy-policy' => array(
                'slug' => 'privacy-policy',
                'legacy' => array(),
                'title' => 'سیاست حفظ حریم خصوصی',
                'description' => 'سیاست حفظ حریم خصوصی دلنوازان دربارهٔ جمع‌آوری، استفاده، نگهداری و حفاظت از اطلاعات هنرجویان، مدرسان و بازدیدکنندگان.',
                'version' => '1.0-draft-2026-10-31',
                'file' => 'privacy-policy.html',
            ),
            'terms-and-conditions' => array(
                'slug' => 'terms-and-conditions',
                'legacy' => array('terms'),
                'title' => 'شرایط و ضوابط استفاده',
                'description' => 'شرایط و ضوابط استفاده از خدمات آموزشی آنلاین دلنوازان، ثبت‌نام، شهریه، حضور، لغو، مالکیت فکری و حقوق مصرف‌کننده.',
                'version' => '1.0-draft-2026-10-31',
                'file' => 'terms-and-conditions.html',
            ),
            'cancellation-and-refunds' => array(
                'slug' => 'cancellation-and-refunds',
                'legacy' => array('refund_returns'),
                'title' => 'سیاست لغو و بازپرداخت',
                'description' => 'سیاست لغو، جلسات جایگزین و بازپرداخت دلنوازان، با حفظ حقوق الزامی مصرف‌کننده تحت قانون حمایت از مصرف‌کنندهٔ استرالیا.',
                'version' => '1.0-draft-2026-10-31',
                'file' => 'cancellation-and-refunds.html',
            ),
            'recording-and-ai-policy' => array(
                'slug' => 'recording-and-ai-policy',
                'legacy' => array(),
                'title' => 'سیاست ضبط کلاس‌ها و استفاده از هوش مصنوعی',
                'description' => 'سیاست دلنوازان دربارهٔ ضبط کلاس، فایل‌های تمرین، دسترسی، نگهداری و پژوهش اختیاری با هوش مصنوعی. ضبط و پژوهش در نسخهٔ فعلی غیرفعال است.',
                'version' => '1.0-draft-2026-10-31',
                'file' => 'recording-and-ai-policy.html',
            ),
            'cookie-policy' => array(
                'slug' => 'cookie-policy',
                'legacy' => array(),
                'title' => 'سیاست کوکی‌ها',
                'description' => 'سیاست کوکی‌های ضروری، نشست، ترجیحات کاربردی و فناوری‌های ارائه‌دهندگان ثالث در وب‌سایت دلنوازان.',
                'version' => '1.0-draft-2026-10-31',
                'file' => 'cookie-policy.html',
            ),
        );
    }

    /**
     * Create or update all unpublished draft pages.
     *
     * @param array{dry_run?:bool,adopt_legacy?:bool} $options
     * @return array{documents:array<int,array<string,mixed>>,created:int,updated:int,skipped:int}
     */
    public static function provisionDrafts( array $options = array() ): array {
        $dryRun = ! empty( $options['dry_run'] );
        $adoptLegacy = ! isset( $options['adopt_legacy'] ) || (bool) $options['adopt_legacy'];
        $results = array( 'documents' => array(), 'created' => 0, 'updated' => 0, 'skipped' => 0 );

        foreach ( self::documents() as $key => $document ) {
            $result = self::provisionOne( $key, $document, $dryRun, $adoptLegacy );
            $results['documents'][] = $result;
            if ( 'created' === $result['action'] ) { $results['created']++; }
            elseif ( 'updated' === $result['action'] ) { $results['updated']++; }
            else { $results['skipped']++; }
        }

        return $results;
    }

    /**
     * Read-only status for the operator screen.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function status(): array {
        $rows = array();
        foreach ( self::documents() as $key => $document ) {
            $page = self::findPage( (string) $document['slug'] );
            $legacyPage = null;
            if ( ! $page ) {
                foreach ( (array) $document['legacy'] as $legacy ) {
                    $candidate = self::findPage( (string) $legacy );
                    if ( $candidate ) { $legacyPage = $candidate; break; }
                }
            }
            $resolved = $page ?: $legacyPage;
            $status = $resolved ? (string) get_post_status( $resolved ) : 'missing';
            $rows[] = array(
                'key' => $key,
                'slug' => (string) $document['slug'],
                'title' => (string) $document['title'],
                'version' => (string) $document['version'],
                'page_id' => $resolved ? (int) $resolved->ID : 0,
                'status' => $status,
                'published' => 'publish' === $status,
                'review_status' => $resolved ? (string) get_post_meta( (int) $resolved->ID, self::META_REVIEW, true ) : '',
                'edit_url' => $resolved ? (string) get_edit_post_link( (int) $resolved->ID, 'raw' ) : '',
            );
        }
        return $rows;
    }

    /**
     * The exact source path for one document.
     *
     * @param array<string,mixed> $document
     * @return string
     */
    private static function sourcePath( array $document ): string {
        return DZN_PLATFORM_DIR . 'content/legal/' . (string) $document['file'];
    }

    /**
     * @param string              $key
     * @param array<string,mixed> $document
     * @param bool                $dryRun
     * @param bool                $adoptLegacy
     * @return array<string,mixed>
     */
    private static function provisionOne( string $key, array $document, bool $dryRun, bool $adoptLegacy ): array {
        $path = self::sourcePath( $document );
        $source = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
        $result = array(
            'key' => $key,
            'slug' => (string) $document['slug'],
            'title' => (string) $document['title'],
            'version' => (string) $document['version'],
            'page_id' => 0,
            'previous_status' => '',
            'action' => 'skipped',
            'reason' => '',
            'source_sha256' => '',
        );

        if ( '' === trim( $source ) ) {
            $result['reason'] = 'source_missing_or_empty';
            return $result;
        }

        $result['source_sha256'] = hash( 'sha256', $source );
        $page = self::findPage( (string) $document['slug'] );
        $legacySlug = '';
        if ( ! $page && $adoptLegacy ) {
            foreach ( (array) $document['legacy'] as $legacy ) {
                $candidate = self::findPage( (string) $legacy );
                if ( $candidate ) { $page = $candidate; $legacySlug = (string) $legacy; break; }
            }
        }

        if ( $page ) {
            $status = (string) get_post_status( $page );
            $marker = (string) get_post_meta( (int) $page->ID, self::META_MARKER, true );
            $result['page_id'] = (int) $page->ID;
            $result['previous_status'] = $status;

            if ( 'publish' === $status ) {
                $result['reason'] = $marker === $key ? 'published_legal_page_requires_manual_update' : 'existing_published_page_not_owned';
                return $result;
            }

            if ( 'trash' === $status ) {
                $result['reason'] = 'trashed_page_requires_manual_decision';
                return $result;
            }

            $currentHash = (string) get_post_meta( (int) $page->ID, self::META_SOURCE_HASH, true );
            $currentVersion = (string) get_post_meta( (int) $page->ID, self::META_VERSION, true );
            if ( $marker === $key && $currentHash === $result['source_sha256'] && $currentVersion === (string) $document['version'] && (string) $page->post_name === (string) $document['slug'] ) {
                $result['reason'] = 'already_current';
                return $result;
            }

            if ( $dryRun ) {
                $result['action'] = 'updated';
                $result['reason'] = 'dry_run';
                return $result;
            }

            $update = array(
                'ID' => (int) $page->ID,
                'post_title' => (string) $document['title'],
                'post_content' => wp_kses_post( $source ),
                'post_status' => in_array( $status, array( 'draft', 'pending', 'private' ), true ) ? $status : 'draft',
                'post_type' => 'page',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            );
            if ( (string) $page->post_name !== (string) $document['slug'] ) {
                $update['post_name'] = (string) $document['slug'];
            }

            $updated = wp_update_post( $update, true );
            if ( is_wp_error( $updated ) ) {
                $result['reason'] = 'wp_update_post_failed:' . $updated->get_error_code();
                return $result;
            }

            self::writeMetadata( (int) $page->ID, $key, $document, $result['source_sha256'] );
            if ( '' !== $legacySlug ) {
                update_post_meta( (int) $page->ID, self::META_LEGACY_SLUG, $legacySlug );
            }
            $result['action'] = 'updated';
            $result['reason'] = '' !== $legacySlug ? 'legacy_draft_consolidated' : 'draft_refreshed';
            return $result;
        }

        if ( $dryRun ) {
            $result['action'] = 'created';
            $result['reason'] = 'dry_run';
            return $result;
        }

        $inserted = wp_insert_post(
            array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => (string) $document['title'],
                'post_name' => (string) $document['slug'],
                'post_content' => wp_kses_post( $source ),
                'post_author' => 0,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ),
            true
        );
        if ( is_wp_error( $inserted ) ) {
            $result['reason'] = 'wp_insert_post_failed:' . $inserted->get_error_code();
            return $result;
        }
        if ( (int) $inserted < 1 ) {
            $result['reason'] = 'wp_insert_post_failed:unknown';
            return $result;
        }

        $result['page_id'] = (int) $inserted;
        self::writeMetadata( (int) $inserted, $key, $document, $result['source_sha256'] );
        $result['action'] = 'created';
        $result['reason'] = 'created_as_draft';
        return $result;
    }

    /**
     * @param int                 $pageId
     * @param string              $key
     * @param array<string,mixed> $document
     * @param string              $sourceHash
     */
    private static function writeMetadata( int $pageId, string $key, array $document, string $sourceHash ): void {
        update_post_meta( $pageId, self::META_MARKER, $key );
        update_post_meta( $pageId, self::META_VERSION, (string) $document['version'] );
        update_post_meta( $pageId, self::META_REVIEW, self::REVIEW_STATUS );
        update_post_meta( $pageId, self::META_SOURCE_HASH, $sourceHash );
        update_post_meta( $pageId, '_wp_page_template', self::PAGE_TEMPLATE );
        // Rank Math is the site's SEO authority. Write only its documented
        // per-page fields; do not install or activate a second SEO plugin.
        update_post_meta( $pageId, 'rank_math_title', (string) $document['title'] . ' | دلنوازان' );
        update_post_meta( $pageId, 'rank_math_description', (string) $document['description'] );
    }

    /**
     * Resolve a page by exact slug in any non-trashed status.
     *
     * @param string $slug
     * @return \WP_Post|null
     */
    private static function findPage( string $slug ): ?\WP_Post {
        if ( '' === $slug ) { return null; }
        $posts = get_posts(
            array(
                'post_type' => 'page',
                'name' => $slug,
                'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
                'posts_per_page' => 1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'suppress_filters' => true,
            )
        );
        return $posts && $posts[0] instanceof \WP_Post ? $posts[0] : null;
    }
}
