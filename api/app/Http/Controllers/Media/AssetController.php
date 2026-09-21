<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Media\MediaStorage;
use App\Models\Document;
use App\Models\MediaAsset;
use App\Models\Recording;
use Illuminate\Http\JsonResponse;

/** Signed URLs for frames and attachments, issued only after the owning document's or recording's policy check (03 §11). */
final class AssetController extends Controller
{
    public function __construct(private readonly MediaStorage $storage) {}

    /** GET /v1/assets/{id}/url */
    public function url(string $id): JsonResponse
    {
        $asset = MediaAsset::query()->findOrFail($id);
        if ($asset->document_id !== null) {
            $this->authorize('view', Document::query()->findOrFail($asset->document_id));
        } elseif ($asset->recording_id !== null) {
            $this->authorize('view', Recording::query()->findOrFail($asset->recording_id));
        } else {
            abort(403, 'Not permitted.');
        }

        return response()->json(['data' => ['url' => $this->storage->signedUrl($asset->storage_key, 900), 'mime_type' => $asset->mime_type, 'expires_in' => 900]]);
    }
}
