<?php

declare(strict_types=1);

namespace App\Billing\Providers;

use App\Billing\BillingProvider;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Test and local-development provider (BILLING_DRIVER=fake). Checkout and
 * portal are fake URLs; plan changes are recorded; the webhook is the
 * normalised event as JSON, signed with a shared secret header.
 */
final class FakeBillingProvider implements BillingProvider
{
    /** @var list<array{subscription_id: string, tier: string, seats: ?int}> */
    public static array $changes = [];

    public function __construct(private readonly string $secret) {}

    public function name(): string
    {
        return 'fake';
    }

    public function connected(): bool
    {
        return true;
    }

    public function checkoutUrl(Workspace $workspace, string $tier, ?int $seats, string $returnUrl): string
    {
        return "https://billing.example.test/checkout?workspace={$workspace->id}&tier={$tier}&seats=".($seats ?? '').'&return='.urlencode($returnUrl);
    }

    public function portalUrl(Subscription $subscription, string $returnUrl): string
    {
        return "https://billing.example.test/portal/{$subscription->provider_customer_id}?return=".urlencode($returnUrl);
    }

    public function changePlan(Subscription $subscription, string $tier, ?int $seats): void
    {
        self::$changes[] = ['subscription_id' => $subscription->id, 'tier' => $tier, 'seats' => $seats];
    }

    public function parseWebhook(Request $request): ?array
    {
        if (! hash_equals($this->secret, (string) $request->header('X-Billing-Signature'))) {
            throw new HttpException(400, 'Invalid webhook signature.');
        }
        $e = $request->json()->all();
        if (! in_array($e['type'] ?? null, ['subscription.updated', 'subscription.canceled', 'payment.failed'], true)) {
            return null;
        }

        return [
            'type' => $e['type'], 'provider_customer_id' => $e['provider_customer_id'] ?? null, 'provider_sub_id' => $e['provider_sub_id'] ?? null,
            'tier' => $e['tier'] ?? null, 'seats' => isset($e['seats']) ? (int) $e['seats'] : null, 'status' => $e['status'] ?? null,
            'current_period_end' => $e['current_period_end'] ?? null, 'workspace_id' => $e['workspace_id'] ?? null,
        ];
    }
}
