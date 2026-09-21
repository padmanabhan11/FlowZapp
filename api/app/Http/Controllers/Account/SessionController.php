<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\UserSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** S24 "Active sessions": device, last seen, sign out (05: GET/DELETE /auth/sessions). */
final class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->hasSession() ? $request->session()->getId() : null;
        $rows = UserSession::query()->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get();

        return response()->json(['data' => $rows->map(fn (UserSession $s) => [
            'id' => $s->id, 'ip' => $s->ip_address, 'device' => self::device((string) $s->user_agent),
            'last_seen_at' => now()->setTimestamp((int) $s->last_activity)->toIso8601String(), 'current' => $s->id === $currentId,
        ])]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = UserSession::query()->where('user_id', $request->user()->id)->whereKey($id)->delete();
        abort_if($deleted === 0, 404, 'No such session.');
        Audit::record('auth.session_revoked', 'user', $request->user()->id, ['session' => substr($id, 0, 8)]);

        return response()->json(['data' => ['revoked' => $id]]);
    }

    /** A short human label from the user agent; never the raw string. */
    private static function device(string $ua): string
    {
        $os = preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'macOS' : (preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Unknown OS'))));
        $browser = preg_match('/Edg\//i', $ua) ? 'Edge' : (preg_match('/Chrome\//i', $ua) ? 'Chrome' : (preg_match('/Firefox\//i', $ua) ? 'Firefox' : (preg_match('/Safari\//i', $ua) ? 'Safari' : 'Browser')));

        return "{$browser} on {$os}";
    }
}
