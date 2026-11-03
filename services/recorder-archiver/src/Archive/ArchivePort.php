<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Archive;

interface ArchivePort {
    /** @return array{mounted:bool,identity:string,free_bytes:int,reason:string} */
    public function identity(): array;
    public function objectKey(string $lessonUid, string $recordingUid, int $contentVersion, string $sha256): string;
    /** @return array{idempotent:bool,bytes:int,sha256:string,receipt:string,temp_key:string} */
    public function transfer(string $sourcePath, string $objectKey, string $expectedSha256, string $temporaryKey): array;
    /** @return array{verified:bool,bytes:int,sha256:string,receipt:string} */
    public function verify(string $objectKey, string $expectedSha256, int $expectedBytes): array;
    public function delete(string $objectKey): array;
}
