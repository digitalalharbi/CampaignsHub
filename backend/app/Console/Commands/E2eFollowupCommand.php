<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Notifications\Engine\Sending\SenderPipeline;
use App\Domains\Notifications\Followup\MorningBriefRunner;
use App\Domains\Notifications\Internal\InternalEvents;
use App\Domains\Notifications\Internal\InternalScan;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NOTIF-V2-INTERNAL-001 / NOTIF-V2-FOLLOWUP-001 — drives the real scan, the real brief runner, the real engine and the real sender
 * for the E2E gate, on a workspace of the demo data. Test support only: it refuses any database not named `*_e2e` and refuses
 * production, like `e2e:notify`.
 *
 *   php artisan e2e:followup agency@campaignshub.io --reset          clean the workspace's follow-up rows and engine notifications
 *   php artisan e2e:followup agency@campaignshub.io --run            work days on every day, brief at 00:00, scan, brief, send
 *   php artisan e2e:followup agency@campaignshub.io --event=sync.failed --count=3
 */
final class E2eFollowupCommand extends Command
{
    protected $signature = 'e2e:followup {email} {--reset} {--run} {--event=} {--count=1}';

    protected $description = 'Run the follow-up scan, the morning brief and engine events for a workspace on the E2E database only.';

    public function handle(InternalScan $scan, MorningBriefRunner $brief, InternalEvents $events, SenderPipeline $pipeline, TenantContext $tenants): int
    {
        $database = Config::string('database.connections.'.Config::string('database.default').'.database');
        if (App::environment('production') || ! str_ends_with($database, E2ePrepareCommand::REQUIRED_SUFFIX)) {
            $this->error('e2e:followup runs only on an E2E database, never in production.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->firstOrFail();
        $membership = Membership::withoutGlobalScopes()->where('user_id', $user->id)->where('status', 'active')->whereIn('portal', ['agency', 'app'])->firstOrFail();
        $tenantId = (string) $membership->tenant_id;
        $out = [];

        if ($this->option('reset')) {
            foreach (['followup_briefs', 'followup_tasks', 'followup_user_settings', 'notification_mutes', 'notification_user_snoozes'] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
            DB::table('app_notifications')->where('tenant_id', $tenantId)->delete();
            DB::table('notification_outbox')->where('tenant_id', $tenantId)->delete();
            $out['reset'] = true;
        }

        if ($this->option('run')) {
            // Every day is a work day and the brief is due at 00:00, so the run does not depend on the weekday it happens on.
            DB::table('workspace_notification_settings')->updateOrInsert(['tenant_id' => $tenantId], [
                'id' => DB::table('workspace_notification_settings')->where('tenant_id', $tenantId)->value('id') ?? (string) Str::uuid(),
                'work_days' => json_encode([0, 1, 2, 3, 4, 5, 6]), 'quiet_hours' => json_encode(['enabled' => false, 'start' => '21:00', 'end' => '08:00']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('followup_user_settings')->updateOrInsert(['tenant_id' => $tenantId, 'user_id' => $user->id], [
                'id' => DB::table('followup_user_settings')->where('tenant_id', $tenantId)->where('user_id', $user->id)->value('id') ?? (string) Str::uuid(),
                'brief_enabled' => true, 'brief_time' => '00:00', 'brief_channels' => json_encode(['in_app']), 'created_at' => now(), 'updated_at' => now(),
            ]);
            app()->forgetScopedInstances();
            $out['scan'] = app(InternalScan::class)->run();
            app()->forgetScopedInstances();
            $out['briefs'] = app(MorningBriefRunner::class)->run();
        }

        if ((string) $this->option('event') !== '') {
            $projectId = (string) DB::table('projects')->where('tenant_id', $tenantId)->whereIn('status', ['active', 'onboarding'])->orderBy('created_at')->value('id');
            $tenants->setTenantId($tenantId);
            for ($i = 0; $i < (int) $this->option('count'); $i++) {
                $events->raise((string) $this->option('event'), $tenantId, $projectId, [], null, 'e2e', 'e2e-'.Str::random(8));
            }
            $out['event'] = $this->option('event');
        }

        // The grouping window holds a burst; the cron would find it due ten minutes on.
        app()->forgetScopedInstances();
        $out['sent'] = app(SenderPipeline::class)->run(Carbon::now()->addMinutes(15));
        $this->line(json_encode($out));

        return self::SUCCESS;
    }
}
