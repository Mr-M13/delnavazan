<?php
/**
 * Host-side processor for the Delnavazan article publish workflow.
 *
 * Runs on the production host (uploaded by the workflow and invoked over SSH).
 * It consumes the payload JSON produced by deployment/articles/publish-articles-plan.php
 * and drives WP-CLI to apply the reviewed article bodies and Rank Math SEO meta
 * to the EXISTING posts they describe.
 *
 * It is deliberately narrow and read-only by default:
 *
 *   - locate the post BY SLUG only (wp post list --name=<slug>); refuse when the
 *     slug is not found or resolves to more than one post;
 *   - NEVER create a post, NEVER change the slug, NEVER change the post status;
 *   - dry_run prints a per-article table (slug, post id, old/new content bytes,
 *     SEO-title change) and changes nothing;
 *   - publish first writes a per-article pre-image backup (post_content + the
 *     Rank Math meta + tags, with a UTC stamp) to a host backup directory, then
 *     updates post_content, the Rank Math meta fields and the tags, then reads
 *     the post back and proves the new content hash matches; it stops the whole
 *     run on the first mismatch.
 *
 * WP-CLI is invoked with --skip-plugins --skip-themes so application code can
 * never observe or interfere with the write, and every command is passed as an
 * argv array (never through a shell), so a percent-encoded Persian slug or a
 * multi-word tag can never be split or interpreted.
 *
 * Usage (on the host):
 *   php publish-articles-host.php --payloads payloads.json \
 *     --mode dry_run|publish --result result.json \
 *     [--backup-dir DIR] [--wp-bin wp] [--wp-root PATH]
 */

declare(strict_types=1);

final class HostProcessor
{
    private string $wpBin = 'wp';
    private string $wpRoot;
    private string $mode;
    private string $backupDir;
    private string $resultFile;
    private string $runUtc;
    /** @var array<int,array<string,mixed>> */
    private array $results = [];
    private int $failures = 0;
    /** Admin user ID used to run mutating WP-CLI commands with unfiltered_html. */
    private ?string $user = null;

    /** @param array{wpBin:string,wpRoot:string,mode:string,backupDir:string,resultFile:string} $cfg */
    public function __construct(array $cfg)
    {
        $this->wpBin = $cfg['wpBin'];
        $this->wpRoot = $cfg['wpRoot'];
        $this->mode = $cfg['mode'];
        $this->backupDir = $cfg['backupDir'];
        $this->resultFile = $cfg['resultFile'];
        $this->runUtc = gmdate('Y-m-d\TH:i:s\Z');
        $this->user = $this->resolveAdminUser();
    }

    /**
     * Find one administrator user ID to run the update as. Running wp post update
     * as an administrator gives it unfiltered_html, so the body HTML is stored
     * byte-for-byte (no capability or kses normalization), which is what the
     * read-back content hash proves. Fails closed (null) when none is found.
     */
    private function resolveAdminUser(): ?string
    {
        [$exit, $stdout] = $this->runWp([
            'user', 'list',
            '--role=administrator',
            '--field=ID',
            '--format=json',
        ]);
        if ($exit !== 0) {
            return null;
        }
        $ids = json_decode($stdout, true);
        if (is_array($ids) && $ids !== []) {
            return (string) $ids[0];
        }
        return null;
    }

    /** @return array{int,string,string} [exit, stdout, stderr] */
    private function runWp(array $args): array
    {
        $head = [$this->wpBin, '--path=' . $this->wpRoot, '--skip-plugins', '--skip-themes'];
        if ($this->user !== null) {
            $head[] = '--user=' . $this->user;
        }
        $cmd = array_merge($head, $args);
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            return [1, '', 'failed to spawn WP-CLI'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit, (string) $stdout, (string) $stderr];
    }

    /**
     * Locate a post by slug. Returns ['count' => n, 'id' => ?int, 'post' => ?array].
     * count is the number of posts whose post_name equals the slug: 0 = not
     * found, 1 = exactly one, >1 = ambiguous (refused), -1 = WP-CLI error.
     *
     * @return array{count:int,id:?int,post:?array{post_title:string,post_status:string,post_name:string}}
     */
    private function locateBySlug(string $slug): array
    {
        [$exit, $stdout] = $this->runWp([
            'post', 'list',
            '--post_type=post',
            '--post_status=any',
            '--name=' . $slug,
            '--fields=ID,post_title,post_status,post_name',
            '--format=json',
        ]);
        $rows = json_decode($stdout, true);
        if ($exit !== 0 || !is_array($rows)) {
            return ['count' => -1, 'id' => null, 'post' => null];
        }
        if (count($rows) === 1 && isset($rows[0]['ID'])) {
            return ['count' => 1, 'id' => (int) $rows[0]['ID'], 'post' => [
                'post_title' => (string) ($rows[0]['post_title'] ?? ''),
                'post_status' => (string) ($rows[0]['post_status'] ?? ''),
                'post_name' => (string) ($rows[0]['post_name'] ?? ''),
            ]];
        }
        return ['count' => count($rows), 'id' => null, 'post' => null];
    }

    private function readContent(int $id): ?string
    {
        [$exit, $stdout] = $this->runWp(['post', 'get', (string) $id, '--field=post_content']);
        return $exit === 0 ? $stdout : null;
    }

    private function readMeta(int $id, string $key): string
    {
        [$exit, $stdout] = $this->runWp(['post', 'meta', 'get', (string) $id, $key]);
        return $exit === 0 ? $stdout : '';
    }

    /** @return array<int,string> */
    private function readTags(int $id): array
    {
        [$exit, $stdout] = $this->runWp([
            'post', 'term', 'list', (string) $id, 'post_tag',
            '--fields=name', '--format=json',
        ]);
        if ($exit !== 0) {
            return [];
        }
        $rows = json_decode($stdout, true);
        if (!is_array($rows)) {
            return [];
        }
        $names = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }

    private function writePreimage(int $id, string $slug, array $post, array $payload, string $backupFile): bool
    {
        $preimage = [
            'tool' => 'deployment/articles/publish-articles-host.php',
            'format' => 1,
            'run_utc' => $this->runUtc,
            'slug' => $slug,
            'post_id' => $id,
            'post_name' => $post['post_name'],
            'post_status' => $post['post_status'],
            'post_title' => $post['post_title'],
            'post_content' => $this->readContent($id),
            'rank_math_title' => $this->readMeta($id, 'rank_math_title'),
            'rank_math_description' => $this->readMeta($id, 'rank_math_description'),
            'rank_math_focus_keyword' => $this->readMeta($id, 'rank_math_focus_keyword'),
            'rank_math_facebook_title' => $this->readMeta($id, 'rank_math_facebook_title'),
            'rank_math_facebook_description' => $this->readMeta($id, 'rank_math_facebook_description'),
            'tags' => $this->readTags($id),
        ];
        $json = json_encode($preimage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        if (file_put_contents($backupFile, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        chmod($backupFile, 0600);
        return filesize($backupFile) > 0;
    }

    /** @return array{int,string} [exit, stderr] */
    private function applyUpdate(int $id, array $payload): array
    {
        // 1. content
        $content = $payload['body_html'];
        [$cExit, , $cErr] = $this->runWp(['post', 'update', (string) $id, '--post_content=' . $content]);
        if ($cExit !== 0) {
            return [$cExit, 'post_content update: ' . $cErr];
        }
        // 2. Rank Math meta (title, description, focus keyword)
        $meta = [
            'rank_math_title' => $payload['seo_title'],
            'rank_math_description' => $payload['meta_description'],
            'rank_math_focus_keyword' => $payload['focus_keyword'],
        ];
        foreach ($meta as $key => $value) {
            [$mExit, , $mErr] = $this->runWp(['post', 'meta', 'update', (string) $id, $key, $value]);
            if ($mExit !== 0) {
                return [$mExit, $key . ' update: ' . $mErr];
            }
        }
        // 3. tags (replace the post_tag set, matching by name)
        $tags = $payload['tags'];
        if (is_array($tags) && $tags !== []) {
            $args = ['post', 'term', 'set', (string) $id, 'post_tag', '--by=name'];
            foreach ($tags as $tag) {
                $args[] = (string) $tag;
            }
            [$tExit, , $tErr] = $this->runWp($args);
            if ($tExit !== 0) {
                return [$tExit, 'tags update: ' . $tErr];
            }
        }
        return [0, ''];
    }

    /** @param array<string,mixed> $payload */
    public function process(array $payload): array
    {
        $slug = $payload['slug'];
        $base = [
            'slug' => $slug,
            'file' => $payload['file'] ?? '',
            'post_id' => null,
            'status' => 'error',
            'action' => 'none',
            'old_content_bytes' => null,
            'new_content_bytes' => null,
            'seo_title_changed' => null,
            'content_verified' => null,
            'backup' => null,
        ];

        $located = $this->locateBySlug($slug);
        if ($located['count'] !== 1 || $located['id'] === null || $located['post'] === null) {
            $base['status'] = $located['count'] === 0 ? 'not_found' : 'ambiguous';
            $base['action'] = 'refused';
            $this->failures++;
            return $base;
        }
        $id = $located['id'];
        $post = $located['post'];
        $base['post_id'] = $id;
        $base['post_name'] = $post['post_name'];
        $base['post_status'] = $post['post_status'];

        $oldContent = $this->readContent($id);
        $oldContent = $oldContent === null ? '' : $oldContent;
        $newContent = $payload['body_html'];
        $oldTitle = $this->readMeta($id, 'rank_math_title');

        $base['old_content_bytes'] = strlen($oldContent);
        $base['new_content_bytes'] = strlen($newContent);
        $base['seo_title_changed'] = ($oldTitle !== $payload['seo_title']);

        if ($this->mode === 'dry_run') {
            $base['status'] = 'ok';
            $base['action'] = 'noop_dry_run';
            return $base;
        }

        // publish: pre-image backup first, then update, then verify.
        $backupFile = rtrim($this->backupDir, '/') . '/' . $this->runUtc . '/' . $slug . '.json';
        if (!is_dir(dirname($backupFile)) && !mkdir(dirname($backupFile), 0700, true) && !is_dir(dirname($backupFile))) {
            $base['status'] = 'backup_failed';
            $this->failures++;
            return $base;
        }
        if (!$this->writePreimage($id, $slug, $post, $payload, $backupFile)) {
            $base['status'] = 'backup_failed';
            $this->failures++;
            return $base;
        }
        $base['backup'] = $backupFile;

        [$updExit, $updErr] = $this->applyUpdate($id, $payload);
        if ($updExit !== 0) {
            $base['status'] = 'update_failed';
            $base['error'] = $updErr;
            $this->failures++;
            return $base;
        }

        // Read back and prove the content hash and the meta match.
        $readBack = $this->readContent($id);
        $readBack = $readBack === null ? '' : $readBack;
        $expectedHash = $payload['body_sha256'] ?? '';
        $actualHash = hash('sha256', $readBack);
        $contentOk = ($actualHash === $expectedHash);
        $metaOk = true;
        foreach ([
            'rank_math_title' => $payload['seo_title'],
            'rank_math_description' => $payload['meta_description'],
            'rank_math_focus_keyword' => $payload['focus_keyword'],
        ] as $key => $want) {
            if ($this->readMeta($id, $key) !== $want) {
                $metaOk = false;
            }
        }

        $base['content_sha256'] = $actualHash;
        $base['content_verified'] = $contentOk;
        $base['meta_verified'] = $metaOk;

        if ($contentOk && $metaOk) {
            $base['status'] = 'ok';
            $base['action'] = 'published';
        } else {
            $base['status'] = 'verify_mismatch';
            $base['action'] = 'published_then_mismatch';
            $this->failures++;
        }
        return $base;
    }

    /** @param array<int,array<string,mixed>> $payloads */
    public function run(array $payloads): int
    {
        foreach ($payloads as $payload) {
            $res = $this->process($payload);
            $this->results[] = $res;

            // dry_run reports everything; publish stops on the first failure.
            if ($this->mode === 'publish' && $res['status'] !== 'ok') {
                break;
            }
        }

        $this->writeResult();
        $this->printTable();
        return $this->failures === 0 ? 0 : 1;
    }

    private function summary(): array
    {
        $summary = [
            'total' => count($this->results),
            'located' => 0,
            'not_found' => 0,
            'ambiguous' => 0,
            'published' => 0,
            'noop_dry_run' => 0,
            'failed' => $this->failures,
        ];
        foreach ($this->results as $r) {
            if (($r['post_id'] ?? null) !== null) {
                $summary['located']++;
            }
            if (($r['status'] ?? '') === 'not_found') {
                $summary['not_found']++;
            }
            if (($r['status'] ?? '') === 'ambiguous') {
                $summary['ambiguous']++;
            }
            if (($r['action'] ?? '') === 'published') {
                $summary['published']++;
            }
            if (($r['action'] ?? '') === 'noop_dry_run') {
                $summary['noop_dry_run']++;
            }
        }
        return $summary;
    }

    private function writeResult(): void
    {
        $result = [
            'tool' => 'deployment/articles/publish-articles-host.php',
            'mode' => $this->mode,
            'run_utc' => $this->runUtc,
            'wp_root' => $this->wpRoot,
            'articles' => $this->results,
            'summary' => $this->summary(),
        ];
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            $json = '{"error":"result encode failed"}';
        }
        file_put_contents($this->resultFile, $json . "\n");
    }

    /** Print the human-readable per-article table to stdout. */
    private function printTable(): void
    {
        $summary = $this->summary();
        printf("MODE=%s RUN_UTC=%s TOTAL=%d\n", $this->mode, $this->runUtc, $summary['total']);

        if ($this->mode === 'dry_run') {
            printf("%-6s %-55s %s\n", 'POST', 'SLUG', 'OLD_BYTES NEW_BYTES SEO_TITLE_CHANGED STATUS');
            foreach ($this->results as $r) {
                printf(
                    "%-6s %-55s %d %d %s %s\n",
                    (string) ($r['post_id'] ?? '-'),
                    (string) ($r['slug'] ?? ''),
                    (int) ($r['old_content_bytes'] ?? 0),
                    (int) ($r['new_content_bytes'] ?? 0),
                    (($r['seo_title_changed'] ?? false) ? 'yes' : 'no'),
                    (string) ($r['status'] ?? '')
                );
            }
        } else {
            printf("%-6s %-55s %s\n", 'POST', 'SLUG', 'ACTION CONTENT_VERIFIED STATUS');
            foreach ($this->results as $r) {
                printf(
                    "%-6s %-55s %s %s %s\n",
                    (string) ($r['post_id'] ?? '-'),
                    (string) ($r['slug'] ?? ''),
                    (string) ($r['action'] ?? ''),
                    (($r['content_verified'] ?? null) === true ? 'yes' : (($r['content_verified'] ?? null) === false ? 'no' : '-')),
                    (string) ($r['status'] ?? '')
                );
            }
        }

        printf(
            "SUMMARY located=%d not_found=%d ambiguous=%d published=%d noop_dry_run=%d failed=%d\n",
            $summary['located'],
            $summary['not_found'],
            $summary['ambiguous'],
            $summary['published'],
            $summary['noop_dry_run'],
            $summary['failed']
        );
    }
}

// --------------------------------------------------------------------------- CLI

function dznFail(string $msg): never
{
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
    exit(1);
}

$argv = $GLOBALS['argv'] ?? [];
$payloadsFile = null;
$mode = null;
$resultFile = null;
$backupDir = getenv('HOME') !== false && getenv('HOME') !== ''
    ? getenv('HOME') . '/backups/article-preimages'
    : '/tmp/article-preimages';
$wpBin = 'wp';
$wpRoot = getenv('HOME') !== false && getenv('HOME') !== ''
    ? getenv('HOME') . '/domains/delnavazan.com/public_html'
    : '/tmp';

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--payloads' && isset($argv[$i + 1])) {
        $payloadsFile = $argv[++$i];
    } elseif ($arg === '--mode' && isset($argv[$i + 1])) {
        $mode = $argv[++$i];
    } elseif ($arg === '--result' && isset($argv[$i + 1])) {
        $resultFile = $argv[++$i];
    } elseif ($arg === '--backup-dir' && isset($argv[$i + 1])) {
        $backupDir = $argv[++$i];
    } elseif ($arg === '--wp-bin' && isset($argv[$i + 1])) {
        $wpBin = $argv[++$i];
    } elseif ($arg === '--wp-root' && isset($argv[$i + 1])) {
        $wpRoot = $argv[++$i];
    } else {
        dznFail('unknown argument: ' . $arg);
    }
}

if ($payloadsFile === null || !is_file($payloadsFile)) {
    dznFail('--payloads file is required and must exist');
}
if (!in_array($mode, ['dry_run', 'publish'], true)) {
    dznFail('--mode must be dry_run or publish');
}
if ($resultFile === null) {
    dznFail('--result file is required');
}

$raw = file_get_contents($payloadsFile);
if ($raw === false) {
    dznFail('cannot read payloads file');
}
$payloads = json_decode($raw, true);
if (!is_array($payloads)) {
    dznFail('payloads file is not a JSON array');
}

$processor = new HostProcessor([
    'wpBin' => $wpBin,
    'wpRoot' => $wpRoot,
    'mode' => $mode,
    'backupDir' => $backupDir,
    'resultFile' => $resultFile,
]);

$rc = $processor->run($payloads);
exit($rc);
