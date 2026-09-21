<?php

declare(strict_types=1);

namespace App\Media;

use Aws\S3\S3Client;

/** DigitalOcean Spaces via the S3 API (config/filesystems.php "spaces" disk). */
final class SpacesStorage implements MediaStorage
{
    public const PART_SIZE = 16 * 1024 * 1024;   // 16 MB parts → ≤ 128 parts for a 2 GB recording

    private readonly S3Client $client;

    private readonly string $bucket;

    public function __construct()
    {
        $cfg = config('filesystems.disks.spaces');
        $this->bucket = (string) $cfg['bucket'];
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $cfg['region'],
            'endpoint' => $cfg['endpoint'],
            'use_path_style_endpoint' => false,
            'credentials' => ['key' => $cfg['key'], 'secret' => $cfg['secret']],
        ]);
    }

    public function createMultipartUpload(string $key, string $mimeType, int $sizeBytes): array
    {
        $r = $this->client->createMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'ContentType' => $mimeType, 'ACL' => 'private']);
        $uploadId = (string) $r['UploadId'];
        $count = max(1, (int) ceil($sizeBytes / self::PART_SIZE));
        $parts = [];
        for ($n = 1; $n <= $count; $n++) {
            $cmd = $this->client->getCommand('UploadPart', ['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId, 'PartNumber' => $n]);
            $parts[] = ['part_number' => $n, 'url' => (string) $this->client->createPresignedRequest($cmd, '+6 hours')->getUri()];
        }

        return ['upload_id' => $uploadId, 'parts' => $parts, 'part_size' => self::PART_SIZE];
    }

    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(fn ($p) => ['PartNumber' => $p['part_number'], 'ETag' => $p['etag']], $parts)],
        ]);
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId]);
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    public function size(string $key): ?int
    {
        $r = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);

        return isset($r['ContentLength']) ? (int) $r['ContentLength'] : null;
    }

    public function signedUrl(string $key, int $ttlSeconds = 900): string
    {
        $ttl = min($ttlSeconds, 900);
        $cmd = $this->client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);

        return (string) $this->client->createPresignedRequest($cmd, "+{$ttl} seconds")->getUri();
    }

    public function deletePrefix(string $prefix): void
    {
        $this->client->deleteMatchingObjects($this->bucket, $prefix);
    }
}
