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

    /**
     * Parts already stored for an open multipart upload (C3: resume after a reload).
     *
     * @return list<array{part_number: int, etag: string, size: int}>
     */
    public function listParts(string $key, string $uploadId): array;

    /**
     * Fresh presigned URLs for the given part numbers of an open upload.
     *
     * @param  list<int>  $partNumbers
     * @return list<array{part_number: int, url: string}>
     */
    public function presignParts(string $key, string $uploadId, array $partNumbers): array;

    public function exists(string $key): bool;

    public function size(string $key): ?int;

    /** Write an object (frames, generated assets). */
    public function put(string $key, string $contents, string $mimeType): void;

    /** Presigned single PUT for small objects (editor images and attachments). The browser uploads directly; the API never proxies bytes. */
    public function presignedPut(string $key, string $mimeType, int $ttlSeconds = 900): string;

    /** Signed GET URL; TTL ≤ 15 minutes (03 §4.3). */
    public function signedUrl(string $key, int $ttlSeconds = 900): string;

    /** Delete every object under a prefix ({workspace_id}/{recording_id}/). */
    public function deletePrefix(string $prefix): void;
}
