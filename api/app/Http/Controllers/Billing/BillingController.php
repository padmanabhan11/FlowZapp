<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Billing\BillingProvider;
use App\Billing\PlanLimits;
use App\Billing\Plans;
use App\Billing\Usage;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * S22 — Billing & usage. Counters against plan limits for the shell and the
 * billing screen. Plan changes go through App\Billing\Plans; the payment
 * provider sits behind BillingProvider and is an open decision, so with the
 * Null driver self-service billing answers 501 and support handles it.
 */
final class BillingController extends Controller
{
    public function __construct(private readonly Usage $usage, private readonly CurrentWorkspace $current, private readonly Plans $plans, private readonly BillingProvider $provider) {}

    /** GET /v1/usage — any member (the shell shows near-limit notices to everyone; S22 itself is admin). */
    public function usage(): JsonResponse
    {
        $ws = Workspace::query()->findOrFail($this->current->require());
        $summary = $this->usage->summary($ws);

        return response()->json(['data' => $summary + [
            'billing' => $this->plans->state($ws),
            'plans' => collect(['free', 'pro', 'team'])->mapWithKeys(fn (string $p) => [$p => PlanLimits::all($p)])->all(),
            'renews_at' => $summary['period_end'],
        ]]);
    }

    /** GET /v1/billing — admin: the subscription state (K2). */
    public function show(): JsonResponse
    {
        $this->authorize('workspace-admin');

        return response()->json(['data' => $this->plans->state(Workspace::query()->findOrFail($this->current->require()))]);
    }

    /**
     * POST /v1/billing/change  body { tier, seats? } — admin. Upgrades apply at once,
     * downgrades at the period end; a workspace that has never paid gets a checkout URL.
     */
    public function change(Request $request): JsonResponse
    {
        $this->authorize('workspace-admin');
        $data = $request->validate(['tier' => ['required', Rule::in(Subscription::TIERS)], 'seats' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $ws = Workspace::query()->findOrFail($this->current->require());
        $r = $this->plans->change($ws, $data['tier'], isset($data['seats']) ? (int) $data['seats'] : null, $request->user(), $this->returnUrl());

        return response()->json(['data' => $r['state'] + ['checkout_url' => $r['checkout_url']]]);
    }

    /** DELETE /v1/billing/scheduled — admin: keep the current plan after all. */
    public function cancelScheduled(Request $request): JsonResponse
    {
        $this->authorize('workspace-admin');
        $ws = Workspace::query()->findOrFail($this->current->require());
        $this->plans->cancelScheduled($ws, $request->user());

        return response()->json(['data' => $this->plans->state($ws)]);
    }

    /** GET /v1/billing/portal — admin. Provider-hosted; 501 until a provider is connected. */
    public function portal(): JsonResponse
    {
        $this->authorize('workspace-admin');
        $ws = Workspace::query()->findOrFail($this->current->require());
        $sub = $this->plans->current($ws);
        if (! $this->provider->connected()) {
            $url = config('services.billing.portal_url');
            abort_if(! $url, 501, 'Billing is not connected yet. Plan changes are handled by FlowZapp support for now.');

            return response()->json(['data' => ['url' => $url]]);
        }
        abort_if($sub->provider_sub_id === null, 409, 'This workspace has no paid subscription yet. Choose a plan first.');

        return response()->json(['data' => ['url' => $this->provider->portalUrl($sub, $this->returnUrl())]]);
    }

    /** POST /v1/billing/webhook — unauthenticated; the driver verifies the signature. */
    public function webhook(Request $request): JsonResponse
    {
        $event = $this->provider->parseWebhook($request);
        if ($event === null) {
            return response()->json(['data' => ['handled' => false]]);
        }
        $sub = $this->plans->applyWebhook($event);

        return response()->json(['data' => ['handled' => $sub !== null]]);
    }

    private function returnUrl(): string
    {
        return rtrim((string) config('flowzapp.frontend_url'), '/').'/admin/billing';
    }
}
