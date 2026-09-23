<?php

declare(strict_types=1);

namespace App\Billing\Providers;

use App\Billing\BillingProvider;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** No payment provider connected: self-service plan changes are refused with 501 and handled by support. */
final class NullBillingProvider implements BillingProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function connected(): bool
    {
        return false;
    }

    public function checkoutUrl(Workspace $workspace, string $tier, ?int $seats, string $returnUrl): string
    {
        throw new HttpException(501, 'Billing is not connected yet. Plan changes are handled by FlowZapp support for now.');
    }

    public function portalUrl(Subscription $subscription, string $returnUrl): string
    {
        throw new HttpException(501, 'Billing is not connected yet. Plan changes are handled by FlowZapp support for now.');
    }

    public function changePlan(Subscription $subscription, string $tier, ?int $seats): void
    {
        throw new HttpException(501, 'Billing is not connected yet. Plan changes are handled by FlowZapp support for now.');
    }

    public function parseWebhook(Request $request): ?array
    {
        throw new HttpException(404, 'No billing provider is configured.');
    }
}
