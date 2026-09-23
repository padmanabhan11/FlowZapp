<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Media\MediaStorage;
use App\Models\Document;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * B1-T3 — images and attachments in the editor. Two calls: register (returns a
 * presigned PUT the browser uploads to directly) and complete (verifies the
 * object landed). Blocks reference the asset by ID, never by URL or base64;
 * readers get short-lived signed URLs from GET /assets/{id}/url after a
 * policy check on the owning document (non-negotiable 9).
 */
final class DocumentAssetController extends Controller
{
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public const FILE_TYPES = [
        'application/pdf', 'text/plain', 'text/csv', 'application/zip',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly MediaStorage $storage) {}

    /** POST /v1/documents/{id}/assets  body { filename, mime_type, size_bytes } → 201 { asset_id, kind, upload_url, headers } */
    public function store(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', Rule::in([...self::IMAGE_TYPES, ...self::FILE_TYPES])],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ]);
        $isImage = in_array($data['mime_type'], self::IMAGE_TYPES, true);
        $max = $isImage ? self::MAX_IMAGE_BYTES : self::MAX_FILE_BYTES;
        abort_if($data['size_bytes'] > $max, 422, ($isImage ? 'Images' : 'Files').' can be up to '.($max / 1024 / 1024).' MB.');

        $assetId = (string) Str::ulid();
        $ext = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION)) ?: 'bin';
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $key = "{$doc->workspace_id}/documents/{$doc->id}/{$assetId}.{$ext}";

        $asset = new MediaAsset(['document_id' => $doc->id, 'kind' => $isImage ? 'image' : 'attachment', 'storage_key' => $key, 'mime_type' => $data['mime_type'], 'size_bytes' => $data['size_bytes']]);
        $asset->id = $assetId;
        $asset->save();

        return response()->json(['data' => [
            'asset_id' => $asset->id, 'kind' => $asset->kind, 'filename' => $data['filename'],
            'upload_url' => $this->storage->presignedPut($key, $data['mime_type']), 'method' => 'PUT', 'headers' => ['Content-Type' => $data['mime_type']],
        ]], 201);
    }

    /** POST /v1/documents/{id}/assets/{asset_id}/complete — 409 if the object is not in storage yet. */
    public function complete(string $id, string $assetId): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $asset = MediaAsset::query()->where('document_id', $doc->id)->findOrFail($assetId);
        abort_unless($this->storage->exists($asset->storage_key), 409, 'The upload has not finished. Try again.');
        $size = $this->storage->size($asset->storage_key);
        if ($size !== null) {
            $asset->forceFill(['size_bytes' => $size])->save();
        }
        Audit::record('document.asset_uploaded', 'document', $doc->id, ['asset_id' => $asset->id, 'kind' => $asset->kind, 'size_bytes' => $asset->size_bytes]);

        return response()->json(['data' => ['asset_id' => $asset->id, 'kind' => $asset->kind, 'mime_type' => $asset->mime_type, 'url' => $this->storage->signedUrl($asset->storage_key, 900)]]);
    }
}
