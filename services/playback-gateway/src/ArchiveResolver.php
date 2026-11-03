<?php
declare(strict_types=1);

namespace Delnavazan\Gateway;

/**
 * Resolve an archived object only inside the verified M-Box mount. A missing mount is an accurate
 * temporary-unavailability condition, never a fallback to the PixelBox root filesystem.
 */
final class ArchiveResolver {
    public function __construct(private Config $config) {}

    public function state(): array {
        $root = rtrim((string) $this->config->get('archive_root'), '/');
        $marker = (string) $this->config->get('archive_mount_marker');
        if (!is_dir($root) || !is_file($marker)) return ['available' => false, 'identity' => '', 'reason' => 'archive_mount_absent'];
        $identity = trim((string) file_get_contents($marker));
        if ($identity === '') return ['available' => false, 'identity' => '', 'reason' => 'archive_identity_unverified'];
        return ['available' => true, 'identity' => $identity, 'reason' => 'ok'];
    }

    public function resolve(string $objectKey): array {
        if ($objectKey === '' || str_starts_with($objectKey, '/') || str_contains($objectKey, "\0") || preg_match('#(^|/)\.\.(/|$)#', $objectKey) === 1) throw new \RuntimeException('archive_object_invalid');
        if (preg_match('#^[0-9A-HJKMNP-TV-Z]{26}/[0-9A-HJKMNP-TV-Z]{26}/[0-9]+/[a-f0-9]{64}\.mp4$#D', $objectKey) !== 1) throw new \RuntimeException('archive_object_invalid');
        $state = $this->state();
        if (!$state['available']) throw new \RuntimeException('archive_temporarily_unavailable');
        $root = realpath((string) $this->config->get('archive_root'));
        $path = realpath($root . '/' . $objectKey);
        if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path) || is_link($path)) throw new \RuntimeException('archive_temporarily_unavailable');
        $size = filesize($path);
        if (!is_int($size) || $size < 1) throw new \RuntimeException('archive_temporarily_unavailable');
        $etag = substr(basename($objectKey), 0, 64);
        return ['path' => $path, 'size' => $size, 'etag' => $etag, 'identity' => $state['identity']];
    }
}
