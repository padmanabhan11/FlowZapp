# Runbook — payment failed

**Alert:** `billing.payment_failed` (warning) on the `alerts` channel, with `workspace_id` and `provider_sub_id`. The subscription is marked `past_due`; the workspace keeps its plan and limits.

1. Check the provider dashboard for the customer: card declined, expired, or a dispute.
2. The provider retries on its own schedule (dunning). Nothing changes in FlowZapp until it sends `subscription.updated` (recovered → `active`) or `subscription.canceled` (gave up → the workspace drops to Free at once; if it is then over Free's caps, the limits screen says so and creating more is refused, nothing is deleted).
3. If the customer contacts support before the provider gives up, point them at Billing → "Update payment method" (the provider portal).

Manual override (support-approved only): `php artisan tinker` → `app(App\Billing\Plans::class)->applyWebhook([...])` with a `subscription.updated` event, and note it in the audit log comment. Never edit `workspaces.plan` directly.
