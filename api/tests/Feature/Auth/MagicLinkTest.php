<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class MagicLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_response_is_identical_for_unknown_and_known_addresses(): void
    {
        Notification::fake();
        User::create(['name' => 'Ana', 'email' => 'ana@example.test']);

        $known = $this->postJson('/api/v1/auth/magic-link', ['email' => 'ana@example.test']);
        $unknown = $this->postJson('/api/v1/auth/magic-link', ['email' => 'nobody@example.test']);

        $known->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTimes(MagicLinkNotification::class, 2);
    }

    public function test_link_signs_in_creates_the_user_on_first_use_and_is_single_use(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/magic-link', ['email' => 'new@example.test', 'next' => '/onboarding/workspace'])->assertStatus(202);

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, MagicLinkNotification::class, function (MagicLinkNotification $n, array $channels, AnonymousNotifiable $to) use (&$url): bool {
            $url = $n->toMail($to)->actionUrl;

            return $to->routes['mail'] === 'new@example.test';
        });
        $this->assertNotNull($url);

        $this->get($url)->assertRedirect(config('flowzapp.frontend_url').'/onboarding/workspace');
        $this->assertAuthenticated('web');
        $this->assertNotNull(User::query()->where('email', 'new@example.test')->first()?->email_verified_at);

        // Second use fails closed.
        $this->post('/api/v1/auth/logout');
        $this->get($url)->assertRedirect(config('flowzapp.frontend_url').'/auth?error=link_expired');
        $this->assertGuest('web');
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $this->get('/api/v1/auth/magic-link/abc?signature=nope&expires=9999999999')->assertStatus(403);
    }

    public function test_requests_are_rate_limited(): void
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/magic-link', ['email' => 'a@example.test'])->assertStatus(202);
        }
        $this->postJson('/api/v1/auth/magic-link', ['email' => 'a@example.test'])->assertStatus(429);
    }
}
