<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Workspace-level gates. The role comes from ResolveWorkspace, which stores it
 * on the request after verifying membership, so a gate never has to query.
 * Space- and folder-level access is a Policy per model (03 §4.1), added with
 * the document model in Epic B.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('workspace-admin', fn (User $user): bool => request()->attributes->get('workspace_role') === 'admin');
        Gate::define('workspace-approver', fn (User $user): bool => in_array(request()->attributes->get('workspace_role'), ['admin', 'approver'], true));
        Gate::define('workspace-editor', fn (User $user): bool => in_array(request()->attributes->get('workspace_role'), ['admin', 'approver', 'editor'], true));
    }
}
