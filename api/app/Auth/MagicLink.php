<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in (FR-101..103): a signed URL with a single-use nonce
 * kept in the cache for its lifetime. The response to a request is identical
 * whether or not the address is registered (FR-102); the user row is created
 * on first successful consume.
 */
final class MagicLink
{
    public const TTL_MINUTES = 15;

    public function send(string $email, ?string $next = null): void
    {
        $email = Str::lower(trim($email));
        $nonce = Str::random(40);
        Cache::put($this->key($nonce), $email, now()->addMinutes(self::TTL_MINUTES));

        $url = URL::temporarySignedRoute(
            'auth.magic.consume',
            now()->addMinutes(self::TTL_MINUTES),
            ['nonce' => $nonce, 'next' => $next],
        );

        Notification::route('mail', $email)->notify(new MagicLinkNotification($url, self::TTL_MINUTES));
    }

    /** Returns the user, or null when the nonce is unknown, expired or already used. */
    public function consume(string $nonce): ?User
    {
        $email = Cache::pull($this->key($nonce)); // pull = single use
        if (! is_string($email)) {
            return null;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => Str::before($email, '@')],
        );
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    private function key(string $nonce): string
    {
        return 'auth:magic:'.hash('sha256', $nonce);
    }
}
