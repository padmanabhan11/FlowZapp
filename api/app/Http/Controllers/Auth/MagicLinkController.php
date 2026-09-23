<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\MagicLink;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class MagicLinkController extends Controller
{
    public function __construct(private readonly MagicLink $magicLink) {}

    /** POST /v1/auth/magic-link — same response for known and unknown addresses (FR-102). */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190'],
            'next' => ['nullable', 'string', 'max:500', 'regex:#^/#'],
        ]);

        $this->magicLink->send($data['email'], $data['next'] ?? null);

        return response()->json(['message' => 'If that address can sign in, a link is on its way. Check your email.'], 202);
    }

    /** GET /v1/auth/magic-link/{nonce} — signed, single use (FR-103). Redirects to the SPA. */
    public function consume(Request $request, string $nonce): RedirectResponse
    {
        $frontend = rtrim((string) config('flowzapp.frontend_url'), '/');
        $user = $this->magicLink->consume($nonce);

        if ($user === null) {
            return redirect()->away("$frontend/auth?error=link_expired");
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $next = (string) $request->query('next', '/');
        $next = str_starts_with($next, '/') ? $next : '/';

        return redirect()->away($frontend.$next);
    }

    /** POST /v1/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    /** GET /v1/auth/me */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user?->only(['id', 'name', 'email', 'locale', 'avatar_path']),
            'workspaces' => $user?->workspaces()->get(['workspaces.id', 'name', 'slug', 'plan'])
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'plan' => $w->plan, 'role' => data_get($w, 'pivot.role')]),
        ]);
    }
}
