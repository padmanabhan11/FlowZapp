<?php

declare(strict_types=1);

namespace App\Media;

/** In-memory storage for tests and local development without Spaces credentials. */
final class FakeMediaStorage implements MediaStorage
{
    /** @var array<string, int> key => size */
    public array $objects = [];

    /** @var array<string, string> uploadId => key */
    public array $uploads = [];

    public function createMultipartUpload(string $key, string $mimeType, int $sizeBytes): array
    {
        $id = 'upl_'.substr(hash('sha256', $key), 0, 16);
        $this->uploads[$id] = $key;
        $count = max(1, (int) ceil($sizeBytes / SpacesStorage::PART_SIZE));
        $parts = [];
        for ($n = 1; $n <= $count; $n++) {
            $parts[] = ['part_number' => $n, 'url' => "https://fake.spaces.test/$key?partNumber=$n&uploadId=$id"];
        }

        return ['upload_id' => $id, 'parts' => $parts, 'part_size' => SpacesStorage::PART_SIZE];
    }

    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        if (($this->uploads[$uploadId] ?? null) !== $key) {
            throw new \RuntimeException('Unknown upload');
        }
        unset($this->uploads[$uploadId]);
        $this->objects[$key] = count($parts) * SpacesStorage::PART_SIZE;
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        unset($this->uploads[$uploadId]);
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function size(string $key): ?int
    {
        return $this->objects[$key] ?? null;
    }

    public function signedUrl(string $key, int $ttlSeconds = 900): string
    {
        return "https://fake.spaces.test/$key?X-Amz-Expires=".min($ttlSeconds, 900).'&X-Amz-Signature=fake';
    }

    public function deletePrefix(string $prefix): void
    {
        foreach (array_keys($this->objects) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($this->objects[$k]);
            }
        }
    }
}
