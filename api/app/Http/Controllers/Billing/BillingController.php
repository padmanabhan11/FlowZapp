<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\PlanLimits;
use App\Billing\Usage;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

/**
 * S22 — Billing & usage. Counters against plan limits for the shell and the
 * billing screen; the billing portal is provider-hosted (05) and the provider
 * is an open decision, so /billing/portal returns the configured URL or 501.
 */
final class BillingController extends Controller
{
    public function __construct(private readonly Usage $usage, private readonly CurrentWorkspace $current) {}

    /** GET /v1/usage — any member (the shell shows near-limit notices to everyone; S22 itself is admin). */
    public function usage(): JsonResponse
    {
        $ws = Workspace::query()->findOrFail($this->current->require());
        $summary = $this->usage->summary($ws);

        return response()->json(['data' => $summary + [
            'plans' => collect(['free', 'pro', 'team'])->mapWithKeys(fn (string $p) => [$p => PlanLimits::all($p)])->all(),
            'renews_at' => $summary['period_end'],
        ]]);
    }

    /** GET /v1/billing/portal — admin. */
    public function portal(): JsonResponse
    {
        $this->authorize('workspace-admin');
        $url = config('services.billing.portal_url');
        abort_if(! $url, 501, 'Billing is not connected yet. Plan changes are handled by FlowZapp support for now.');

        return response()->json(['data' => ['url' => $url]]);
    }
}
