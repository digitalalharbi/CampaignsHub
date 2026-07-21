<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Audit\Listeners\RecordAuthAudit;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared per-request tenant context — the authority on "current tenant".
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Audit authentication lifecycle events.
        Event::listen(Login::class, [RecordAuthAudit::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAuthAudit::class, 'handleLogout']);
    }
}
