<?php
/**
 * Guard every Admin PHP source file against unqualified class references that
 * would resolve under Delnavazan\Platform\Admin\... and fatal at request time.
 *
 * This is intentionally narrower than a PHP parser: it inspects the class
 * positions that can fail before any application code runs (instantiation,
 * static access, catch, instanceof, extends and implements) and resolves them
 * against the file's use imports, the classes declared in the same namespace,
 * and a small list of PHP/WordPress globals. A synthetic case proves the check
 * still detects a missing import rather than passing vacuously.
 */
declare(strict_types=1);

/**
 * @param list<string> $files
 * @return array<string, array<string, true>>
 */
function dzn_admin_declarations(array $files): array
{
    $declarations = array();
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $match)) {
            $namespace = trim($match[1]);
        }
        foreach (array('class', 'interface', 'trait', 'enum') as $keyword) {
            if (preg_match_all('/\b' . $keyword . '\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches)) {
                foreach ($matches[1] as $name) $declarations[$namespace][$name] = true;
            }
        }
    }
    return $declarations;
}

/**
 * @return array<string, true>
 */
function dzn_admin_imports(string $source): array
{
    $imports = array();
    preg_match_all('/^\s*use\s+([^;]+);/m', $source, $matches);
    foreach ($matches[1] as $statement) {
        $statement = trim($statement);
        if (str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) continue;
        if (preg_match('/^([^{]+)\{(.*)\}$/s', $statement, $group)) {
            foreach (explode(',', $group[2]) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                if (preg_match('/\bas\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $part, $alias)) {
                    $imports[$alias[1]] = true;
                } else {
                    $segments = explode('\\', trim($part, " \t\n\r\0\x0B\\"));
                    $imports[(string) end($segments)] = true;
                }
            }
            continue;
        }
        if (preg_match('/\bas\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $statement, $alias)) {
            $imports[$alias[1]] = true;
            continue;
        }
        $segments = explode('\\', trim($statement, " \t\n\r\0\x0B\\"));
        $imports[(string) end($segments)] = true;
    }
    return $imports;
}

/**
 * @param array<string, true> $known
 * @return list<array{line:int,name:string,kind:string}>
 */
function dzn_admin_unresolved_class_references(string $source, array $known): array
{
    $tokens = token_get_all($source);
    $issues = array();
    $significant = static function (int $index) use ($tokens): ?int {
        $count = count($tokens);
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
            return $cursor;
        }
        return null;
    };
    $record = static function (int $index, string $kind) use (&$issues, $tokens, $known): void {
        $token = $tokens[$index];
        $name = is_array($token) ? (string) $token[1] : '';
        if ($name === '' || isset($known[$name]) || in_array($name, array('self', 'static', 'parent'), true)) return;
        $issues[] = array(
            'line' => is_array($token) ? (int) $token[2] : 0,
            'name' => $name,
            'kind' => $kind,
        );
    };

    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token)) continue;
        if ($token[0] === T_NEW) {
            $next = $significant($index);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) $record($next, 'new');
            continue;
        }
        if ($token[0] === T_CATCH) {
            $next = $significant($index);
            if ($next !== null && $tokens[$next] === '(') $next = $significant($next);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) $record($next, 'catch');
            continue;
        }
        if (in_array($token[0], array(T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS), true)) {
            $next = $significant($index);
            if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) $record($next, strtolower($token[1]));
            continue;
        }
        if ($token[0] === T_DOUBLE_COLON) {
            $previous = null;
            for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && in_array($candidate[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) continue;
                $previous = $cursor;
                break;
            }
            if ($previous !== null && is_array($tokens[$previous]) && $tokens[$previous][0] === T_STRING) $record($previous, 'static');
        }
    }
    return $issues;
}

$builtins = array(
    'DateTime' => true, 'DateTimeImmutable' => true, 'DateTimeZone' => true, 'DateInterval' => true,
    'PDO' => true, 'PDOException' => true, 'Exception' => true, 'RuntimeException' => true,
    'InvalidArgumentException' => true, 'Throwable' => true, 'ArrayObject' => true, 'stdClass' => true,
    'ReflectionMethod' => true, 'ReflectionClass' => true, 'ReflectionProperty' => true,
    'Closure' => true, 'Generator' => true, 'LogicException' => true, 'UnexpectedValueException' => true,
    'DomainException' => true, 'LengthException' => true, 'OutOfRangeException' => true,
    'OutOfBoundsException' => true, 'BadMethodCallException' => true, 'JsonException' => true,
    'WeakMap' => true, 'WeakReference' => true, 'Iterator' => true, 'IteratorAggregate' => true,
    'Countable' => true, 'ArrayAccess' => true, 'Traversable' => true, 'Serializable' => true,
    'Stringable' => true, 'mysqli' => true, 'mysqli_result' => true,
    'WP_Error' => true, 'WP_User' => true, 'WP_Post' => true, 'WP_Query' => true,
    'WP_REST_Request' => true, 'WP_REST_Response' => true, 'WP_CLI' => true, 'wpdb' => true,
);

// Prove the guard fails when the exact production regression is reintroduced.
$syntheticRoot = <<<'PHP'
<?php
namespace Delnavazan\Platform\Admin\Controller;
final class SyntheticScreen {
    public function run(): void {
        new CanonicalLessonScheduleReadService();
    }
}
PHP;
$syntheticKnown = array('SyntheticScreen' => true);
$syntheticIssues = dzn_admin_unresolved_class_references($syntheticRoot, $syntheticKnown);
if (count($syntheticIssues) !== 1 || $syntheticIssues[0]['name'] !== 'CanonicalLessonScheduleReadService') {
    throw new RuntimeException('Admin class-resolution guard did not detect a missing import in the synthetic regression case.');
}
$importedRoot = "<?php\nnamespace Delnavazan\\Platform\\Admin\\Controller;\nuse Delnavazan\\Platform\\Core\\Application\\CanonicalLessonScheduleReadService;\n"
    . "final class SyntheticScreen {\n    public function run(): void {\n        new CanonicalLessonScheduleReadService();\n    }\n}\n";
$importedIssues = dzn_admin_unresolved_class_references($importedRoot, $syntheticKnown + dzn_admin_imports($importedRoot));
if ($importedIssues !== array()) {
    throw new RuntimeException('Admin class-resolution guard rejected a correctly imported class.');
}

$sourceRoot = dirname(__DIR__) . '/src/Admin';
$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') $files[] = $file->getPathname();
}
sort($files);
if ($files === array()) throw new RuntimeException('Admin class-resolution guard found no Admin PHP sources.');

$declarations = dzn_admin_declarations($files);
$failures = array();
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    $namespace = '';
    if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $match)) $namespace = trim($match[1]);
    $known = $builtins + dzn_admin_imports($source) + ($declarations[$namespace] ?? array());
    foreach (dzn_admin_unresolved_class_references($source, $known) as $issue) {
        $failures[] = sprintf('%s:%d unresolved %s %s (namespace %s)', $file, $issue['line'], $issue['kind'], $issue['name'], $namespace);
    }
}
if ($failures !== array()) {
    throw new RuntimeException("Unresolved Admin class references:\n" . implode("\n", $failures));
}

echo 'admin-class-resolution-unit: OK (' . count($files) . " Admin files)\n";
