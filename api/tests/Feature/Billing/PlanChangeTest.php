<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Providers\FakeBillingProvider;
use App\Models\Space;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActsInWorkspace;
use Tests\TestCase;

/**
 * K2 — plan changes, seats and scheduled downgrades, provider-independent,
 * with the fake payment driver; and the 501 path with no provider at all.
 */
final class PlanChangeTest extends TestCase
{
    use ActsInWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['flowzapp.billing_driver' => 'fake', 'services.billing.webhook_secret' => 's3cret']);
        FakeBillingProvider::$changes = [];
    }

    private function sub(Workspace $ws): Subscription
    {
        return app(CurrentWorkspace::class)->runAs($ws->id, fn () => Subscription::query()->firstOrFail());
    }

    private function webhook(array $event, string $secret = 's3cret'): TestResponse
    {
        return $this->withHeaders(['X-Billing-Signature' => $secret])->postJson('/api/v1/billing/webhook', $event);
    }

    public function test_first_upgrade_goes_through_checkout_and_the_webhook_lands_the_subscription(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);
        $this->assertSame('free', $this->actingAs($admin)->getJson('/api/v1/billing', $h)->assertOk()->json('data.tier'));
        $this->assertSame('fake', $this->actingAs($admin)->getJson('/api/v1/usage', $h)->json('data.billing.provider'));

        $r = $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'team'], $h)->assertOk();
        $this->assertStringStartsWith('https://billing.example.test/checkout?workspace='.$ws->id.'&tier=team&seats=10', $r->json('data.checkout_url'));
        $this->assertSame('free', $r->json('data.tier'), 'nothing changes until the provider confirms payment');
        $this->assertSame([], FakeBillingProvider::$changes);

        $this->webhook(['type' => 'subscription.updated', 'workspace_id' => $ws->id, 'provider_customer_id' => 'cus_1', 'provider_sub_id' => 'sub_1', 'tier' => 'team', 'seats' => 10, 'status' => 'active', 'current_period_end' => now()->addMonth()->toIso8601String()])->assertOk()->assertJsonPath('data.handled', true);

        $this->assertSame('team', $ws->refresh()->plan);
        $state = $this->actingAs($admin)->getJson('/api/v1/billing', $h)->json('data');
        $this->assertSame(['team', 10, 'active', true], [$state['tier'], $state['seats'], $state['status'], $state['has_payment_method']]);
        $this->assertSame(10, $this->actingAs($admin)->getJson('/api/v1/usage', $h)->json('data.counters.seats.max'));
        $this->assertStringContainsString('/portal/cus_1', $this->actingAs($admin)->getJson('/api/v1/billing/portal', $h)->assertOk()->json('data.url'));

        // A bad signature is rejected; a member cannot touch billing.
        $this->webhook(['type' => 'subscription.canceled', 'provider_sub_id' => 'sub_1'], 'wrong')->assertStatus(400);
        $this->assertSame('team', $ws->refresh()->plan);
        $editor = $this->addMember($ws, 'ed@example.test', 'editor');
        $this->actingAs($editor)->postJson('/api/v1/billing/change', ['tier' => 'pro'], $h)->assertStatus(403);
    }

    public function test_upgrades_apply_at_once_and_downgrades_wait_for_the_period_end(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme', 'pro');
        $h = $this->wsHeaders($ws);
        $end = now()->addDays(10)->startOfSecond();
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => Subscription::create(['tier' => 'pro', 'seats' => null, 'provider' => 'fake', 'provider_customer_id' => 'cus_2', 'provider_sub_id' => 'sub_2', 'current_period_end' => $end]));

        // Pro → Team: up, immediate, prorated by the provider.
        $r = $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'team', 'seats' => 8], $h)->assertOk();
        $this->assertSame(['team', 8, null], [$r->json('data.tier'), $r->json('data.seats'), $r->json('data.checkout_url')]);
        $this->assertSame('team', $ws->refresh()->plan);
        $this->assertSame([['subscription_id' => $this->sub($ws)->id, 'tier' => 'team', 'seats' => 8]], FakeBillingProvider::$changes);

        // More seats: immediate. More than ten: refused (open decision, not decided in code).
        $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'team', 'seats' => 10], $h)->assertOk()->assertJsonPath('data.seats', 10);
        $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'team', 'seats' => 11], $h)->assertStatus(422);

        // Team → Pro: down, scheduled for the period end; the plan stays Team until then.
        $r = $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'pro'], $h)->assertOk();
        $this->assertSame('team', $r->json('data.tier'));
        $this->assertSame(['tier' => 'pro', 'seats' => null, 'at' => $end->toIso8601String()], $r->json('data.scheduled'));
        $this->assertSame('team', $ws->refresh()->plan);

        // Changed their mind: keep Team.
        $this->actingAs($admin)->deleteJson('/api/v1/billing/scheduled', [], $h)->assertOk()->assertJsonPath('data.scheduled', null);
        $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'pro'], $h)->assertOk();

        // The scheduler applies it once the period has ended.
        $this->artisan('billing:apply-scheduled')->expectsOutputToContain('0 scheduled plan change(s) applied');
        $this->travelTo($end->copy()->addMinute());
        $this->artisan('billing:apply-scheduled')->expectsOutputToContain('1 scheduled plan change(s) applied');
        $this->assertSame('pro', $ws->refresh()->plan);
        $this->assertNull($this->sub($ws)->scheduled_tier);
    }

    public function test_a_downgrade_that_would_strand_members_or_documents_is_refused_with_the_numbers(): void
    {
        [$ws, $admin] = $this->makeWorkspace('acme', 'team');
        $h = $this->wsHeaders($ws);
        app(CurrentWorkspace::class)->runAs($ws->id, fn () => Subscription::create(['tier' => 'team', 'seats' => 10, 'provider' => 'fake', 'provider_sub_id' => 'sub_3', 'current_period_end' => now()->addDays(3)]));
        foreach (range(1, 4) as $i) {
            $this->addMember($ws, "m{$i}@example.test", 'reader');
        }
        $sid = app(CurrentWorkspace::class)->runAs($ws->id, fn () => Space::query()->firstOrFail()->id);
        foreach (range(1, 51) as $i) {
            $this->actingAs($admin)->postJson('/api/v1/documents', ['space_id' => $sid, 'title' => "Doc {$i}"], $h)->assertStatus(201);
        }

        $r = $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'free'], $h)->assertStatus(422);
        $this->assertStringContainsString('5 members', $r->json('error.message'));
        $r = $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'team', 'seats' => 4], $h)->assertStatus(422);
        $this->assertStringContainsString('5 members', $r->json('error.message'));
        $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'pro'], $h)->assertOk()->assertJsonPath('data.scheduled.tier', 'pro');

        // The provider cancelling the subscription is applied regardless (the workspace is then over Free's caps and the limits say so).
        $this->webhook(['type' => 'subscription.canceled', 'provider_sub_id' => 'sub_3'])->assertOk()->assertJsonPath('data.handled', true);
        $this->assertSame('free', $ws->refresh()->plan);
        $this->assertTrue($this->actingAs($admin)->getJson('/api/v1/usage', $h)->json('data.counters.documents.over'));
    }

    public function test_without_a_provider_self_service_billing_answers_501_and_support_handles_it(): void
    {
        config(['flowzapp.billing_driver' => 'null']);
        [$ws, $admin] = $this->makeWorkspace('acme');
        $h = $this->wsHeaders($ws);
        $this->assertNull($this->actingAs($admin)->getJson('/api/v1/billing', $h)->assertOk()->json('data.provider'));
        $this->actingAs($admin)->postJson('/api/v1/billing/change', ['tier' => 'pro'], $h)->assertStatus(501);
        $this->actingAs($admin)->getJson('/api/v1/billing/portal', $h)->assertStatus(501);
        $this->postJson('/api/v1/billing/webhook', ['type' => 'subscription.updated'])->assertStatus(404);
        $this->assertSame('free', $ws->refresh()->plan);
    }
}
