<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Private object storage behind an interface (03 §8: "The Laravel API never
 * proxies media bytes"). Recordings upload straight from the browser to
 * presigned multipart URLs; reads are short-TTL signed URLs issued only after
 * a Policy check. SpacesStorage is the DigitalOcean implementation; the test
 * suite binds FakeMediaStorage.
 */
interface MediaStorage
{
    /** @return array{upload_id: string, parts: list<array{part_number: int, url: string}>, part_size: int} */
    public function createMultipartUpload(string $key, string $mimeType, int $sizeBytes): array;

    /** @param list<array{part_number: int, etag: string}> $parts */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void;

    public function abortMultipartUpload(string $key, string $uploadId): void;

    public function exists(string $key): bool;

    public function size(string $key): ?int;

    /** Write an object (frames, generated assets). */
    public function put(string $key, string $contents, string $mimeType): void;

    /** Signed GET URL; TTL ≤ 15 minutes (03 §4.3). */
    public function signedUrl(string $key, int $ttlSeconds = 900): string;

    /** Delete every object under a prefix ({workspace_id}/{recording_id}/). */
    public function deletePrefix(string $prefix): void;
}
