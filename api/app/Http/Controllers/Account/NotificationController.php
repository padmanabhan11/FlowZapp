<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** In-app notifications for the shell (doc 11 triggers). The notifications table is global (a person spans workspaces). */
final class NotificationController extends Controller
{
    /** GET /v1/notifications?unread=1 — newest first, 50 max. */
    public function index(Request $request): JsonResponse
    {
        $q = $request->user()->notifications()->limit(50);
        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        }

        return response()->json(['data' => $q->get()->map(fn (DatabaseNotification $n) => [
            'id' => $n->id, 'data' => $n->data, 'read_at' => $n->read_at, 'created_at' => $n->created_at,
        ]), 'unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    /** POST /v1/notifications/read  body { ids?: [] } — marks the given ones (or all) read. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:200'], 'ids.*' => ['string']]);
        $q = $request->user()->unreadNotifications();
        if (! empty($data['ids'])) {
            $q->whereIn('id', $data['ids']);
        }
        $q->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }
}
