<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Payment provider behind a driver (non-negotiable 10). The provider is an
 * open decision (03 §12), so everything that does not depend on it — the
 * subscription record, plan-change rules, seat handling, scheduled
 * downgrades, the webhook entry point — lives in App\Billing\Plans, and the
 * chosen provider only has to implement this interface. Proration is the
 * provider's: changePlan() tells it the new plan and it bills the difference.
 */
interface BillingProvider
{
    public function name(): string;

    /** True when this driver can take payments (the Null driver cannot). */
    public function connected(): bool;

    /** Hosted checkout for a workspace that has no provider subscription yet. */
    public function checkoutUrl(Workspace $workspace, string $tier, ?int $seats, string $returnUrl): string;

    /** Provider-hosted portal: payment method, invoices, cancellation. */
    public function portalUrl(Subscription $subscription, string $returnUrl): string;

    /** Change the provider-side plan and seat count (prorated by the provider). */
    public function changePlan(Subscription $subscription, string $tier, ?int $seats): void;

    /**
     * Verify and normalise an incoming webhook. Returns null for events the
     * product does not care about; throws on a bad signature.
     *
     * @return array{type: 'subscription.updated'|'subscription.canceled'|'payment.failed', provider_customer_id: ?string, provider_sub_id: ?string, tier: ?string, seats: ?int, status: ?string, current_period_end: ?string, workspace_id: ?string}|null
     */
    public function parseWebhook(Request $request): ?array;
}
