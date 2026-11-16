<?php
/**
 * Runner-side parser for the Delnavazan article publish workflow.
 *
 * Reads the reviewed Markdown article files under docs/articles/improved and
 * turns each one into a machine-readable payload for the host-side processor:
 *
 *   slug             exact last URL path segment (percent-encoded Persian slugs
 *                    are preserved byte-for-byte, never decoded)
 *   seo_title        the "عنوان سئو" field, with the trailing "(N نویسه)" note removed
 *   meta_description the "متا" field, with the trailing "(N نویسه)" note removed
 *   focus_keyword    the "عبارت کانونی" field
 *   tags             the "برچسب‌ها" field split on "·"
 *   body_html        the Markdown body converted to HTML
 *   body_sha256      SHA-256 of body_html (used for the host-side read-back proof)
 *
 * It is static and offline: it never contacts GitHub or production. It is the
 * single place that decides which bytes the host is allowed to write, and the
 * source contract proves it parses all 58 files with the honesty note and the
 * SEO block excluded.
 *
 * Usage:
 *   php deployment/articles/publish-articles-plan.php              # validate all + report count
 *   php deployment/articles/publish-articles-plan.php --slugs a,b  # validate the named slugs only
 *   php deployment/articles/publish-articles-plan.php --out out.json [--slugs a,b]
 *
 * Exit 0 when every selected file parses to a complete payload; non-zero when
 * any file is missing a slug or an SEO field.
 *
 * The body must never leak the header metadata (URL/Status/Wave/WORDS), the
 * SEO block, or the honesty note. Those markers are asserted absent from the
 * emitted HTML below.
 */

declare(strict_types=1);

const DZN_BODY_HEADING = '## متن مقاله (فارسی)';
const DZN_SEO_HEADING = '## بلاک سئو';
const DZN_HONESTY_HEADING = '## یادداشت صداقت';

final class MarkdownConverter
{
    /** @var array<int,string> */
    private array $ph = [];

    private function escapeHtml(string $s): string
    {
        // Text nodes: escape &, <, > only. Quotes are left untouched so a raw
        // apostrophe round-trips byte-for-byte through WordPress's post save.
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $s): string
    {
        // Attribute values: also escape the quote characters.
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function place(string $html): string
    {
        $this->ph[] = $html;
        return "\x00" . (count($this->ph) - 1) . "\x00";
    }

    /**
     * Convert inline Markdown to inline HTML. Handles `code`, [links](url),
     * **bold**, *italic* and HTML-escaping of the remainder. Placeholders are
     * used so that the final escape pass never re-escapes already-generated
     * tags. Ordering (code -> link -> bold -> italic) keeps the corpus's
     * non-nested formatting unambiguous.
     */
    public function inline(string $s): string
    {
        $s = preg_replace_callback('/`([^`]+)`/u', function (array $m): string {
            return $this->place('<code>' . $this->escapeHtml($m[1]) . '</code>');
        }, $s);

        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function (array $m): string {
            $text = $this->inline($m[1]);
            $href = $this->escapeAttr($m[2]);
            return $this->place('<a href="' . $href . '">' . $text . '</a>');
        }, $s);

        $s = preg_replace_callback('/\*\*([^*]+)\*\*/u', function (array $m): string {
            return $this->place('<strong>' . $this->inline($m[1]) . '</strong>');
        }, $s);

        $s = preg_replace_callback('/(?<!\*)\*([^*\n]+)\*(?!\*)/u', function (array $m): string {
            return $this->place('<em>' . $this->inline($m[1]) . '</em>');
        }, $s);

        $s = $this->escapeHtml($s);

        return preg_replace_callback('/\x00([0-9]+)\x00/u', function (array $m): string {
            return $this->ph[(int) $m[1]];
        }, $s);
    }

    /**
     * Convert a Markdown body (array of lines, no trailing blank lines) to HTML.
     * Blocks: headings, blockquotes, tables, ordered/unordered lists, paragraphs.
     */
    public function toHtml(array $lines): string
    {
        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            // Table: consecutive rows starting with "|".
            if (preg_match('/^\s*\|/', $line) === 1) {
                $rows = [];
                while ($i < $n && preg_match('/^\s*\|/', $lines[$i]) === 1) {
                    $rows[] = $this->splitTableRow($lines[$i]);
                    $i++;
                }
                $out[] = $this->renderTable($rows);
                continue;
            }

            // Blockquote: consecutive lines starting with ">".
            if (preg_match('/^\s*>\s?/', $line) === 1) {
                $quote = [];
                while ($i < $n && preg_match('/^\s*>\s?/', $lines[$i]) === 1) {
                    $quote[] = preg_replace('/^\s*>\s?/', '', $lines[$i]);
                    $i++;
                }
                $html = $this->inline(implode('<br />', array_map('trim', $quote)));
                $out[] = '<blockquote><p>' . $html . "</p></blockquote>";
                continue;
            }

            // Heading.
            if (preg_match('/^\s*(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $level = strlen($m[1]);
                $out[] = '<h' . $level . '>' . $this->inline(trim($m[2])) . '</h' . $level . '>';
                $i++;
                continue;
            }

            // Unordered list.
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m) === 1) {
                $items = [];
                while ($i < $n && preg_match('/^\s*[-*+]\s+(.*)$/', $lines[$i], $m) === 1) {
                    $items[] = '<li>' . $this->inline(trim($m[1])) . '</li>';
                    $i++;
                }
                $out[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            // Ordered list (ASCII or Persian digits).
            if (preg_match('/^\s*([0-9۰-۹]+)[.)]\s+(.*)$/u', $line, $m) === 1) {
                $items = [];
                while ($i < $n && preg_match('/^\s*([0-9۰-۹]+)[.)]\s+(.*)$/u', $lines[$i], $m) === 1) {
                    $items[] = '<li>' . $this->inline(trim($m[2])) . '</li>';
                    $i++;
                }
                $out[] = '<ol>' . implode('', $items) . '</ol>';
                continue;
            }

            // Paragraph: collect until a blank line or a block boundary.
            $para = [];
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '' || $this->isBlockStart($l)) {
                    break;
                }
                $para[] = trim($l);
                $i++;
            }
            if ($para !== []) {
                $out[] = '<p>' . $this->inline(implode(' ', $para)) . '</p>';
            }
        }

        return implode("\n\n", $out);
    }

    private function isBlockStart(string $line): bool
    {
        if (preg_match('/^\s*\|/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\s*>\s?/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\s*#{1,6}\s+/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\s*[-*+]\s+/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\s*[0-9۰-۹]+[.)]\s+/u', $line) === 1) {
            return true;
        }
        return false;
    }

    /** @return array<int,string> */
    private function splitTableRow(string $line): array
    {
        $cells = explode('|', trim($line, "| \t"));
        return array_map('trim', $cells);
    }

    /** @param array<int,array<int,string>> $rows */
    private function renderTable(array $rows): string
    {
        // Drop the separator row (the one whose cells are all dashes/colons).
        $filtered = [];
        foreach ($rows as $row) {
            $isSeparator = $row !== [] && array_reduce(
                $row,
                fn (bool $acc, string $c) => $acc && preg_match('/^:?-{2,}:?$/', $c) === 1,
                true
            );
            if ($isSeparator) {
                continue;
            }
            $filtered[] = $row;
        }

        $html = '<table>';
        if ($filtered !== []) {
            $html .= '<thead><tr>';
            foreach ($filtered[0] as $cell) {
                $html .= '<th>' . $this->inline($cell) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            for ($r = 1; $r < count($filtered); $r++) {
                $html .= '<tr>';
                foreach ($filtered[$r] as $cell) {
                    $html .= '<td>' . $this->inline($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';
        }
        $html .= '</table>';
        return $html;
    }
}

final class ArticleParser
{
    /**
     * @return array{
     *   slug:string, seo_title:string, meta_description:string,
     *   focus_keyword:string, tags:array<int,string>, body_html:string
     * }|null  payload, or null with $error set when the file is incomplete
     */
    public static function parse(string $filePath, ?string &$error = null): ?array
    {
        $error = null;
        $raw = @file_get_contents($filePath);
        if ($raw === false) {
            $error = 'unreadable';
            return null;
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        // --- header: the URL line ---
        $url = null;
        foreach ($lines as $line) {
            if (preg_match('/^\s*-\s*\*\*URL:\*\*\s*(.+?)\s*$/', $line, $m) === 1) {
                $url = trim($m[1]);
                break;
            }
        }
        if ($url === null || $url === '') {
            $error = 'missing URL';
            return null;
        }
        $slug = self::slugFromUrl($url);
        if ($slug === '' || preg_match('/^[A-Za-z0-9%_-]+$/', $slug) !== 1) {
            $error = 'invalid slug in URL: ' . $url;
            return null;
        }

        // --- body: between the Persian body heading and the SEO heading ---
        $bodyStart = self::indexOf($lines, DZN_BODY_HEADING);
        $seoStart = self::indexOf($lines, DZN_SEO_HEADING);
        if ($bodyStart === null) {
            $error = 'missing body heading';
            return null;
        }
        if ($seoStart === null || $seoStart <= $bodyStart) {
            $error = 'missing SEO heading';
            return null;
        }

        $bodyLines = array_slice($lines, $bodyStart + 1, $seoStart - $bodyStart - 1);
        // Drop leading/trailing blank lines and any trailing "---" separators.
        while ($bodyLines !== [] && trim($bodyLines[0]) === '') {
            array_shift($bodyLines);
        }
        while ($bodyLines !== [] && (trim(end($bodyLines)) === '' || trim(end($bodyLines)) === '---')) {
            array_pop($bodyLines);
        }
        if ($bodyLines === []) {
            $error = 'empty body';
            return null;
        }

        // --- SEO block: between the SEO heading and the honesty heading ---
        $honestyStart = self::indexOf($lines, DZN_HONESTY_HEADING);
        if ($honestyStart === null || $honestyStart <= $seoStart) {
            $error = 'missing honesty heading';
            return null;
        }
        $seo = self::parseSeoBlock(array_slice($lines, $seoStart + 1, $honestyStart - $seoStart - 1));
        if ($seo === null) {
            $error = 'incomplete SEO block';
            return null;
        }

        $converter = new MarkdownConverter();
        $bodyHtml = $converter->toHtml($bodyLines);
        $bodyHtml = trim($bodyHtml);

        // The three excluded regions must never leak into the published HTML.
        foreach (['URL:', 'Status:', 'Wave:', 'WORDS:', 'یادداشت صداقت', 'بلاک سئو', 'عبارت کانونی', 'عنوان سئو'] as $marker) {
            if (strpos($bodyHtml, $marker) !== false) {
                $error = 'body leaks excluded marker: ' . $marker;
                return null;
            }
        }

        return [
            'slug' => $slug,
            'seo_title' => $seo['seo_title'],
            'meta_description' => $seo['meta_description'],
            'focus_keyword' => $seo['focus_keyword'],
            'tags' => $seo['tags'],
            'body_html' => $bodyHtml,
        ];
    }

    /** @param array<int,string> $lines */
    private static function indexOf(array $lines, string $needle): ?int
    {
        foreach ($lines as $i => $line) {
            if (strpos($line, $needle) === 0) {
                return $i;
            }
        }
        return null;
    }

    private static function slugFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        $path = rtrim($path, '/');
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        if ($segments === []) {
            return '';
        }
        $slug = end($segments);
        return is_string($slug) ? $slug : '';
    }

    /**
     * @param array<int,string> $lines
     * @return array{seo_title:string, meta_description:string, focus_keyword:string, tags:array<int,string>}|null
     */
    private static function parseSeoBlock(array $lines): ?array
    {
        $seoTitle = null;
        $meta = null;
        $focus = null;
        $tags = null;

        foreach ($lines as $line) {
            // Field format: "- **label:** value" (the label and its colon are
            // both inside the bold run), e.g. "- **عنوان سئو:** ...".
            if (preg_match('/^\s*-\s*\*\*([^*]+):\*\*\s*(.*)$/u', $line, $m) !== 1) {
                continue;
            }
            $key = trim($m[1]);
            $value = trim($m[2]);

            if (strpos($key, 'عنوان سئو') !== false) {
                $seoTitle = self::stripCountNote($value);
            } elseif (strpos($key, 'متا') !== false) {
                $meta = self::stripCountNote($value);
            } elseif (strpos($key, 'عبارت کانونی') !== false) {
                $focus = $value;
            } elseif (strpos($key, 'برچسب') !== false) {
                $parts = array_map('trim', explode('·', $value));
                $tags = array_values(array_filter($parts, fn ($t) => $t !== ''));
            }
            // "FAQ" is a note, not a publishable field; ignored.
        }

        if ($seoTitle === null || $seoTitle === '' ||
            $meta === null || $meta === '' ||
            $focus === null || $focus === '' ||
            $tags === null || $tags === []) {
            return null;
        }

        return [
            'seo_title' => $seoTitle,
            'meta_description' => $meta,
            'focus_keyword' => $focus,
            'tags' => $tags,
        ];
    }

    /** Remove a trailing "(N نویسه)" character-count note. */
    private static function stripCountNote(string $value): string
    {
        return trim(preg_replace('/\s*\([^()]*نویسه\)\s*$/u', '', $value));
    }
}

// --------------------------------------------------------------------------- CLI

function dznFail(string $msg): never
{
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$dir = $root . '/docs/articles/improved';
$outFile = null;
$slugsFilter = [];

$argv = $GLOBALS['argv'] ?? [];
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--out' && isset($argv[$i + 1])) {
        $outFile = $argv[++$i];
    } elseif ($arg === '--slugs' && isset($argv[$i + 1])) {
        $raw = trim($argv[++$i]);
        if ($raw !== '') {
            $slugsFilter = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
        }
    } elseif ($arg === '--dir' && isset($argv[$i + 1])) {
        $dir = $argv[++$i];
    } else {
        dznFail('unknown argument: ' . $arg);
    }
}

if (!is_dir($dir)) {
    dznFail('article directory missing: ' . $dir);
}

$files = glob($dir . '/*.md');
if ($files === false || $files === []) {
    dznFail('no article files under ' . $dir);
}
sort($files, SORT_STRING);

$payloads = [];
$missing = 0;
$filteredSlugs = $slugsFilter !== [] ? array_fill_keys($slugsFilter, true) : [];

foreach ($files as $file) {
    $error = null;
    $payload = ArticleParser::parse($file, $error);
    if ($payload === null) {
        $missing++;
        fwrite(STDERR, 'FAIL  ' . basename($file) . ': ' . $error . "\n");
        continue;
    }
    if ($filteredSlugs !== [] && !isset($filteredSlugs[$payload['slug']])) {
        continue;
    }
    $payload['file'] = 'docs/articles/improved/' . basename($file);
    $payload['body_sha256'] = hash('sha256', $payload['body_html']);
    $payloads[] = $payload;
    printf("ARTICLE ok slug=%s file=%s\n", $payload['slug'], $payload['file']);
}

// A requested slug that matches no file is a hard failure.
if ($slugsFilter !== []) {
    $found = [];
    foreach ($payloads as $p) {
        $found[$p['slug']] = true;
    }
    foreach ($slugsFilter as $requested) {
        if (!isset($found[$requested])) {
            dznFail('requested slug not found in any article: ' . $requested);
        }
    }
}

printf("TOTAL=%d\n", count($payloads));
printf("MISSING=%d\n", $missing);

if ($missing > 0) {
    dznFail($missing . ' article(s) are missing a slug or SEO field');
}

if ($outFile !== null) {
    $json = json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        dznFail('failed to encode payload JSON: ' . json_last_error_msg());
    }
    if (file_put_contents($outFile, $json . "\n") === false) {
        dznFail('failed to write payload JSON: ' . $outFile);
    }
    printf("WROTE=%s\n", $outFile);
}
