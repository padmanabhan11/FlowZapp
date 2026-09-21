<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceMember;
use App\Notifications\Support\NotificationPrefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** S24 — the user's own settings: profile, per-workspace notification preferences. */
final class AccountController extends Controller
{
    /** PATCH /v1/account  body { name?, locale? } — email is read-only (changing it needs re-verification, out of scope). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'min:1', 'max:120'], 'locale' => ['sometimes', 'string', Rule::in(['en', 'de', 'fr', 'es', 'pt', 'nl', 'it'])]]);
        $user = $request->user();
        $user->fill($data)->save();

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'locale', 'avatar_path'])]);
    }

    /** GET /v1/account/notifications — preferences for the current workspace (X-Workspace-Id). */
    public function notifications(Request $request): JsonResponse
    {
        return response()->json(['data' => NotificationPrefs::for($request->user())]);
    }

    /** PATCH /v1/account/notifications  body { review_requested?, …, weekly_digest? } */
    public function updateNotifications(Request $request): JsonResponse
    {
        $rules = [];
        foreach (NotificationPrefs::KEYS as $k) {
            $rules[$k] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $m = WorkspaceMember::query()->where('user_id', $request->user()->id)->firstOrFail();
        $current = NotificationPrefs::for($request->user());
        foreach ($current['locked'] as $k) {
            unset($data[$k]);   // cannot be disabled while assigned to acknowledge (S24 rule)
        }
        $m->forceFill(['notification_prefs' => array_merge($m->notification_prefs ?? [], $data)])->save();
        Audit::record('account.notifications_updated', 'user', $request->user()->id, $data);

        return response()->json(['data' => NotificationPrefs::for($request->user())]);
    }
}
