<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NOTIF-BELL-001 — raises real bell notifications for the E2E gate, through the real dispatcher.
 *
 * `php artisan e2e:notify agency@campaignshub.io --count=5 --kind=budget_90 --severity=critical`, or `--purge` to empty the
 * workspace's bell first. Runs only against a database named `*_e2e` and never in production (the guards of `e2e:prepare`). The
 * notifications are team-wide (no recipient) on the workspace's first project, exactly what an alert sweep raises, so the
 * bell, the count, the sound and «تعليم الكل» are exercised on the same rows production has.
 */
final class E2eNotifyCommand extends Command
{
    protected $signature = 'e2e:notify {email} {--count=1} {--kind=no_results} {--type=budget_risk} {--severity=warning} {--purge}';

    protected $description = 'Raise bell notifications for a workspace on the E2E database only.';

    public function handle(NotificationDispatcher $dispatcher, TenantContext $tenants): int
    {
        $database = Config::string('database.connections.'.Config::string('database.default').'.database');
        if (App::environment('production') || ! str_ends_with($database, E2ePrepareCommand::REQUIRED_SUFFIX)) {
            $this->error('e2e:notify runs only on an E2E database, never in production.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->firstOrFail();
        $membership = Membership::withoutGlobalScopes()->where('user_id', $user->id)->where('status', 'active')->where('portal', Portal::Agency->value)->firstOrFail();
        $tenantId = (string) $membership->tenant_id;
        $project = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('created_at')->firstOrFail();

        if ($this->option('purge')) {
            DB::table('app_notifications')->where('tenant_id', $tenantId)->delete();
        }

        $tenants->setTenantId($tenantId);
        $severity = (string) $this->option('severity') === 'critical' ? 'error' : (string) $this->option('severity');
        $made = 0;
        for ($i = 0; $i < (int) $this->option('count'); $i++) {
            // Built first, then handed over: this is test support whose types come from an option, not a sender whose types can be classified.
            $notice = [
                'tenant_id' => $tenantId, 'project_id' => (string) $project->id, 'client_workspace_id' => $project->client_workspace_id,
                'type' => (string) $this->option('type'), 'kind' => (string) $this->option('kind'), 'severity' => $severity,
                'title' => 'E2E '.$this->option('kind'), 'message' => 'raised by e2e:notify', 'source' => 'alerts',
                'entity_type' => 'e2e', 'entity_id' => 'e2e-'.Str::random(10), 'upsert_daily' => true,
            ];
            $made += $dispatcher->dispatch($notice) === null ? 0 : 1;
        }

        $this->line((string) $made);

        return self::SUCCESS;
    }
}
