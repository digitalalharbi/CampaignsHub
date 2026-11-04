<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Launch\LaunchPlan;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Forms\Models\LeadForm;
use App\Domains\Forms\Models\LeadFormVersion;
use App\Domains\Forms\Support\FormDefinition;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Jobs\FetchPeriodReachJob;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Notifications\Mail\AlertBundleMail;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\ConfiguredAlertSweepEmailProvider;

/**
 * PERF-RECOVERY-001 — one deterministic world every alert type fires in.
 *
 * The live-report world underneath supplies the accounts that must stay invisible (one deselected, one bound elsewhere). On top of it sit the campaigns
 * whose single-campaign reads were the awkward ones: withheld money, demo rows beside live ones, rows of a deselected account, rows filed under another
 * project, a soft-deleted campaign, a campaign with no rows at all. A second tenant holds the same rule types over its own campaigns.
 */
trait BuildsAlertSweepWorld
{
    use BuildsLiveReportWorld;

    protected Tenant $otherTenant;

    protected Project $otherTenantProject;

    /** @var array<string, string> label => unified campaign id */
    protected array $named = [];

    /** @var array<string, AlertRule> label => rule */
    protected array $rules = [];

    protected function buildAlertWorld(int $campaignsPerAccount = 4): void
    {
        /*
         * The sweep reads these with no ORDER BY, so it walks them in physical order, and in a database earlier tests have written to and autovacuum has swept, a row lands wherever
         * space was freed: the same inserts come back in another order, and with them every id written after. TRUNCATE is transactional in Postgres and starts each table on a new file,
         * so physical order is insertion order here whatever ran before, and the rollback puts everything back.
         */
        DB::statement('TRUNCATE unified_campaigns, external_campaigns, daily_metrics, alert_rules, alert_events, metric_sync_runs, integration_sync_runs, provider_connections, external_accounts, integration_credentials, project_integration_bindings, spend_limits, spend_limit_events, leads, lead_forms, launch_plans, notification_preferences, app_notifications, notification_deliveries, tasks, digest_sends CASCADE');

        $this->seed(PermissionSeeder::class);
        $this->buildWorld($campaignsPerAccount);

        $this->tenant->forceFill(['enabled_modules' => ['paid_media', 'advanced_tracking']])->save();
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => 'tenant', 'scope_key' => $this->tenant->id, 'module' => 'advanced_tracking'],
            ['scope_id' => null, 'enabled' => true],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);

        $this->recipients();
        $this->awkwardCampaigns();
        $this->otherSignals();
        $this->tenantRules();
        $this->secondTenant();

        // A sweep runs with no project open, and the tenant it reads is the one it sets.
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** One reader of each kind the mailer tells apart: Arabic, English, and email switched off. */
    private function recipients(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-alert-world']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        foreach ([['ar', 'ar@alerts.test', true], ['en', 'en@alerts.test', true], ['ar', 'off@alerts.test', false]] as [$locale, $email, $wantsMail]) {
            $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
            $this->grantMembership($user, $this->tenant);
            $user->assignRole($role);
            DB::table('notification_preferences')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => (string) $this->tenant->id, 'user_id' => $user->id,
                'channels' => json_encode(['in_app' => true, 'email' => $wantsMail]), 'categories' => json_encode([]), 'frequency' => 'realtime', 'locale' => $locale,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * A campaign and its rows.
     *
     * @param  array<int, array<string, float|null>>  $days  days ago => metric key => value (null = withheld, with the original kept)
     * @param  array<string, mixed>  $over  campaign columns
     * @param  array<string, mixed>  $rowOver  metric columns (`is_demo`, `project_id`)
     */
    protected function alertCampaign(string $label, ExternalAccount $account, array $days, array $over = [], array $rowOver = [], ?string $reuse = null): string
    {
        $now = Carbon::now();
        $id = $reuse ?? (string) Str::uuid();
        $external = (string) Str::uuid();
        $tenantId = $over['tenant_id'] ?? $this->tenant->id;
        $projectId = $over['project_id'] ?? $this->project->id;
        $rowProject = $rowOver['project_id'] ?? $projectId;

        if ($reuse === null) {
            DB::table('unified_campaigns')->insert($over + [
                'id' => $id, 'tenant_id' => $tenantId, 'project_id' => $projectId, 'name' => $label, 'objective' => 'sales', 'status' => 'active',
                'platforms' => json_encode([$account->provider]), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->named[$label] = $id;
        }

        DB::table('external_campaigns')->insert([
            'id' => $external, 'tenant_id' => $tenantId, 'project_id' => $rowProject, 'external_account_id' => $account->id, 'unified_campaign_id' => $id,
            'provider' => $account->provider, 'external_id' => 'x-'.$external, 'name' => $label, 'status' => 'active', 'objective' => 'sales',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($days as $ago => $figures) {
            foreach ($figures as $key => $value) {
                DB::table('daily_metrics')->insert($rowOver + [
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'project_id' => $rowProject, 'external_account_id' => $account->id,
                    'external_campaign_id' => $external, 'unified_campaign_id' => $id, 'provider' => $account->provider, 'metric_key' => $key,
                    'metric_date' => $now->copy()->subDays($ago)->toDateString(), 'value' => $value, 'project_currency' => 'SAR',
                    'original_currency' => $value === null ? 'EUR' : 'SAR', 'original_amount' => $value ?? 100, 'data_freshness_at' => $now->copy()->subHours(3),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        return $id;
    }

    /** @return array<int, array<string, float|null>> `$figures` on each of the days ago in [$from, $to] */
    protected function alertDays(int $from, int $to, array $figures): array
    {
        $out = [];
        for ($d = $from; $d <= $to; $d++) {
            $out[$d] = $figures;
        }

        return $out;
    }

    private function awkwardCampaigns(): void
    {
        $a = $this->boundAccounts[0];
        $b = $this->boundAccounts[2];

        // Breaches, one per handler.
        $this->alertCampaign('no-results', $a, $this->alertDays(1, 12, ['spend' => 100.0, 'conversions' => 0.0]));
        $this->alertCampaign('roas-drop', $a, $this->alertDays(8, 14, ['spend' => 100.0, 'revenue' => 1000.0, 'conversions' => 5.0]) + $this->alertDays(1, 7, ['spend' => 100.0, 'revenue' => 100.0, 'conversions' => 5.0]));
        $this->alertCampaign('cost-rise', $b, $this->alertDays(8, 14, ['spend' => 100.0, 'conversions' => 10.0, 'leads' => 10.0, 'clicks' => 20.0]) + $this->alertDays(1, 7, ['spend' => 100.0, 'conversions' => 2.0, 'leads' => 2.0, 'clicks' => 20.0]));
        $this->alertCampaign('budget-risk', $a, $this->alertDays(1, 10, ['spend' => 95.0, 'conversions' => 3.0]), ['total_budget' => 1000, 'budget_currency' => 'SAR']);
        $this->alertCampaign('budget-under', $a, $this->alertDays(1, 10, ['spend' => 5.0, 'conversions' => 3.0]), ['total_budget' => 1000, 'budget_currency' => 'SAR']);
        $this->alertCampaign('budget-other-unit', $a, $this->alertDays(1, 10, ['spend' => 95.0, 'conversions' => 3.0]), ['total_budget' => 1000, 'budget_currency' => 'USD']);
        $this->alertCampaign('budget-unspent', $a, [], ['total_budget' => 500, 'budget_currency' => 'SAR']);

        // Money the contract withheld: no verdict on a cost, and a budget that cannot be watched.
        $this->alertCampaign('withheld-now', $b, $this->alertDays(8, 14, ['spend' => 100.0, 'conversions' => 10.0, 'revenue' => 500.0]) + $this->alertDays(1, 7, ['spend' => null, 'conversions' => 1.0, 'revenue' => 10.0]));
        $this->alertCampaign('withheld-before', $b, $this->alertDays(8, 14, ['spend' => null, 'conversions' => 10.0]) + $this->alertDays(1, 7, ['spend' => 100.0, 'conversions' => 1.0]));
        $this->alertCampaign('withheld-budget', $a, $this->alertDays(1, 10, ['spend' => null, 'conversions' => 3.0]), ['total_budget' => 1000, 'budget_currency' => 'SAR']);
        $this->alertCampaign('withheld-partly', $a, $this->alertDays(1, 3, ['spend' => 100.0, 'conversions' => 0.0]) + $this->alertDays(2, 2, ['revenue' => null]));

        // The demo policy is per campaign: rows of a campaign holding ONLY demo rows are the answer, and demo rows beside any live row are not.
        $this->alertCampaign('demo-only', $a, $this->alertDays(1, 14, ['spend' => 50.0, 'conversions' => 0.0, 'revenue' => 5.0]), [], ['is_demo' => true]);
        $mixed = $this->alertCampaign('demo-beside-old-live', $a, [60 => ['spend' => 1.0]]);
        $this->alertCampaign('', $a, $this->alertDays(1, 14, ['spend' => 500.0, 'conversions' => 0.0]), [], ['is_demo' => true], $mixed);

        // Rows the project may not show, on their own and beside rows it may.
        $this->alertCampaign('only-deselected', $this->deselectedAccount, $this->alertDays(1, 14, ['spend' => 100.0, 'conversions' => 0.0]));
        $half = $this->alertCampaign('half-deselected', $a, $this->alertDays(1, 14, ['spend' => 10.0, 'conversions' => 1.0]));
        $this->alertCampaign('', $this->deselectedAccount, $this->alertDays(1, 14, ['spend' => 900.0, 'conversions' => 0.0]), [], [], $half);
        $this->alertCampaign('only-account-elsewhere', $this->elsewhereAccount, $this->alertDays(1, 14, ['spend' => 100.0, 'conversions' => 0.0]));

        // A campaign of this project whose rows are filed under the project its account is bound to: visible by the row's own project.
        $this->alertCampaign('rows-filed-elsewhere', $this->elsewhereAccount, $this->alertDays(1, 14, ['spend' => 70.0, 'conversions' => 0.0]), [], ['project_id' => $this->otherProject->id]);
        // The other project's own campaigns, which a project-bound rule must see and no other project's rule may.
        $this->alertCampaign('other-project-no-results', $this->elsewhereAccount, $this->alertDays(1, 14, ['spend' => 70.0, 'conversions' => 0.0]), ['project_id' => $this->otherProject->id]);
        $this->alertCampaign('other-project-budget', $this->elsewhereAccount, $this->alertDays(1, 14, ['spend' => 70.0, 'conversions' => 1.0]), ['project_id' => $this->otherProject->id, 'total_budget' => 100, 'budget_currency' => 'SAR']);

        // Nothing to say, and not allowed to say anything.
        $this->alertCampaign('no-rows', $a, []);
        $this->alertCampaign('soft-deleted', $a, $this->alertDays(1, 14, ['spend' => 100.0, 'conversions' => 0.0]), ['deleted_at' => Carbon::now()]);

        // Anomalies: a recent spike, a paused one, an old one, one too short to judge, one on a withheld day.
        $calm = ['spend' => 100.0, 'conversions' => 5.0, 'clicks' => 50.0];
        $wobble = static fn (int $d): array => ['spend' => 100.0 + $d % 5, 'conversions' => 5.0 + $d % 3, 'clicks' => 50.0 + $d % 4];
        $series = [];
        for ($d = 2; $d <= 20; $d++) {
            $series[$d] = $wobble($d);
        }
        $this->alertCampaign('spike-recent', $b, [1 => ['spend' => 2000.0, 'conversions' => 5.0, 'clicks' => 50.0]] + $series);
        $this->alertCampaign('spike-paused', $b, [1 => ['spend' => 2000.0, 'conversions' => 5.0, 'clicks' => 50.0]] + $series, ['status' => 'paused']);
        $old = $series;
        $old[10] = ['spend' => 2000.0, 'conversions' => 5.0, 'clicks' => 50.0];
        $this->alertCampaign('spike-old', $b, $old);
        $this->alertCampaign('spike-too-short', $b, [1 => ['spend' => 2000.0]] + $this->alertDays(2, 4, $calm));
        $this->alertCampaign('spike-withheld', $b, [1 => ['spend' => null, 'conversions' => 40.0, 'clicks' => 50.0]] + $series);
        $this->alertCampaign('spike-other-project', $this->elsewhereAccount, [1 => ['spend' => 2000.0, 'conversions' => 5.0, 'clicks' => 50.0]] + $series, ['project_id' => $this->otherProject->id]);

        // A row hanging off no unified campaign is delivery too, and is nobody's campaign.
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $a->id,
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => null, 'provider' => $a->provider, 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(), 'value' => 33, 'project_currency' => 'SAR', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function otherSignals(): void
    {
        $now = Carbon::now();
        $this->project->refresh();

        // `sync_failure` fires on a provider nothing is expected from, so the tenant's coverage stays complete until a later step changes that.
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'connection_id' => (string) Str::uuid(),
            'provider' => 'linkedin', 'status' => 'failed', 'window_start' => $now->copy()->subDay()->toDateString(), 'window_end' => $now->toDateString(),
            'error' => 'token expired', 'started_at' => $now->copy()->subMinutes(5), 'created_at' => $now, 'updated_at' => $now,
        ]);

        // `sync_outage`: the other project's account, whose only run failed.
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->otherProject->id, 'connection_id' => (string) Str::uuid(),
            'external_account_id' => $this->elsewhereAccount->id, 'provider' => 'google', 'status' => 'failed', 'window_start' => $now->copy()->subDay()->toDateString(),
            'window_end' => $now->toDateString(), 'error' => 'quota', 'started_at' => $now->copy()->subMinutes(9), 'finished_at' => $now->copy()->subMinutes(8),
            'created_at' => $now->copy()->subMinutes(9), 'updated_at' => $now->copy()->subMinutes(8),
        ]);

        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->otherProject->id, 'connection_id' => (string) Str::uuid(),
            'external_account_id' => $this->elsewhereAccount->id, 'provider' => 'google', 'status' => 'success', 'window_start' => $now->copy()->subDays(9)->toDateString(),
            'window_end' => $now->copy()->subDays(8)->toDateString(), 'started_at' => $now->copy()->subDays(8), 'finished_at' => $now->copy()->subDays(8),
            'created_at' => $now->copy()->subDays(8), 'updated_at' => $now->copy()->subDays(8),
        ]);

        // `measurement_outage`.
        $ga4 = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', $now->copy()->addDays(30)), connectionName: 'ga4-conn');
        IntegrationSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider_connection_id' => $ga4->id, 'type' => 'measurement', 'status' => 'failed',
            'error' => 'quota exceeded', 'started_at' => $now->copy()->subMinutes(20), 'finished_at' => $now->copy()->subMinutes(19),
        ]);

        // `token_expiry`: one already refused.
        $refused = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'linkedin', tokens: new OAuthTokens('AT', 'RT', $now->copy()->addDays(2)), connectionName: 'linkedin-conn');
        $refused->forceFill(['status' => 'error', 'last_error' => 'refused'])->save();
        $soon = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'google', tokens: new OAuthTokens('AT', 'RT', $now->copy()->addDays(2)), connectionName: 'google-conn');
        unset($soon);

        // The three lead promises.
        foreach ([
            ['owner_id' => null, 'received_at' => $now->copy()->subHours(4)],
            ['owner_id' => null, 'received_at' => $now->copy()->subHours(5), 'project_id' => $this->otherProject->id],
            ['owner_id' => 1, 'first_attempt_at' => null, 'received_at' => $now->copy()->subHours(9)],
            ['owner_id' => 1, 'first_attempt_at' => $now->copy()->subHour(), 'next_follow_up_at' => $now->copy()->subDays(3), 'received_at' => $now->copy()->subDays(9)],
        ] as $i => $over) {
            if (($over['owner_id'] ?? null) === 1) {
                $over['owner_id'] = User::query()->where('email', 'ar@alerts.test')->value('id');
            }
            (new Lead)->forceFill($over + [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Buyer', 'phone' => '+96650000000'.$i,
                'source' => 'provider', 'status' => LeadStage::New->value,
            ])->save();
        }

        // A published form with views and no submission: Tracking Health calls it broken.
        $form = LeadForm::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Villa', 'status' => 'published']);
        $version = LeadFormVersion::create([
            'tenant_id' => $this->tenant->id, 'lead_form_id' => $form->id, 'version' => 1, 'published_at' => $now,
            'definition' => FormDefinition::normalise([
                'steps' => [['id' => 's1', 'fields' => [
                    ['id' => 'full_name', 'type' => 'text', 'label' => 'Name', 'maps_to' => 'name'],
                    ['id' => 'mobile', 'type' => 'phone', 'label' => 'Mobile', 'maps_to' => 'phone'],
                ]]],
                'consent' => ['required' => true, 'text' => ['en' => 'Contact me.']],
            ]),
            'checksum' => str_repeat('a', 64),
        ]);
        $form->forceFill(['published_version_id' => $version->id])->save();
        $views = [];
        for ($i = 1; $i <= 22; $i++) {
            $views[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $form->project_id, 'event_type' => 'form_view',
                'subject_type' => 'lead_form', 'subject_id' => $form->id, 'source' => 'first_party_form', 'occurred_at' => $now, 'recorded_at' => $now,
                'consent_state' => 'unknown', 'visitor_confidence' => 'none', 'is_bot' => false, 'session_id' => 'ses-'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('tracking_events')->insert($views);

        // A launched plan whose account has since earned a better stage.
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }
        $meta = $this->boundAccounts[0];
        LaunchPlan::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Spring', 'objective' => 'OUTCOME_SALES', 'status' => LaunchPlan::PUBLISHED,
            'idempotency_key' => 'alert-world-plan', 'published_at' => $now->copy()->subDays(20),
            'spec' => ['journey' => ['goal' => 'sales', 'stages' => [(string) $meta->id => 'measurement_first']]],
        ]);
        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $meta->id,
            'provider' => 'meta', 'entity_type' => 'ad', 'entity_id' => (string) Str::uuid(), 'external_entity_id' => 'purchases-ad',
            'metric_date' => $now->copy()->subDays(2)->toDateString(), 'purchases' => 90, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // The workspace's own limit: crossed once, announced once. On the other project, whose money all converted, so the limit has a figure to read.
        SpendLimit::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->otherProject->id, 'scope' => 'project', 'scope_id' => null, 'amount' => 1000,
            'currency' => 'SAR', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'thresholds' => null, 'active' => true,
        ]);
    }

    /** @param array<string, mixed> $over */
    private function alertRule(string $label, string $type, array $over = [], ?Tenant $tenant = null): void
    {
        $this->rules[$label] = AlertRule::create($over + [
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'type' => $type, 'name' => $label, 'cooldown_minutes' => 720,
            'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ]);
    }

    private function tenantRules(): void
    {
        $this->alertRule('no-results-3d', 'no_results', ['threshold' => ['days' => 3]]);
        $this->alertRule('no-results-7d-task', 'no_results', ['threshold' => ['days' => 7], 'create_task' => true, 'cooldown_minutes' => 30, 'severity' => 'critical']);
        $this->alertRule('roas', 'roas_drop', ['threshold' => ['pct' => 25, 'days' => 7]]);
        $this->alertRule('cpa', 'cpa_increase', ['threshold' => ['pct' => 25, 'days' => 7]]);
        $this->alertRule('cpl', 'cpl_increase', ['threshold' => ['pct' => 25, 'days' => 7], 'severity' => 'info']);
        $this->alertRule('budget', 'budget_risk', ['threshold' => ['ratio' => 0.9]]);
        $this->alertRule('anomaly', 'metric_anomaly');
        $this->alertRule('anomaly-short-window', 'metric_anomaly', ['threshold' => ['days' => 3]]);
        $this->alertRule('sync-failure', 'sync_failure');
        $this->alertRule('sync-outage', 'sync_outage');
        $this->alertRule('measurement', 'measurement_outage');
        $this->alertRule('token', 'token_expiry', ['threshold' => ['days' => 7]]);
        $this->alertRule('lead-unassigned', 'lead_unassigned');
        $this->alertRule('lead-no-contact', 'lead_no_contact');
        $this->alertRule('lead-overdue', 'lead_follow_up_overdue');
        $this->alertRule('tracking', 'tracking_health');
        $this->alertRule('launch', 'launch_advance');
        // Rules no sweep raises, and one switched off.
        $this->alertRule('never-raised', 'sla_warning');
        $this->alertRule('event-driven', 'report_failed');
        $this->alertRule('inactive', 'no_results', ['active' => false]);

        // The same questions narrowed to one project, over the campaigns that project holds.
        foreach ([['no_results', ['days' => 3]], ['roas_drop', ['pct' => 25, 'days' => 7]], ['cpa_increase', ['pct' => 25, 'days' => 7]], ['metric_anomaly', []], ['budget_risk', ['ratio' => 0.5]], ['sync_outage', []]] as [$type, $threshold]) {
            $this->alertRule('other-project-'.$type, $type, ['project_id' => $this->otherProject->id, 'threshold' => $threshold]);
        }
        $this->alertRule('this-project-no-results', 'no_results', ['project_id' => $this->project->id, 'threshold' => ['days' => 3]]);
    }

    /** A tenant of its own: same rule types, its own campaigns, and a stale provider that withdraws its cost verdicts. */
    private function secondTenant(): void
    {
        $now = Carbon::now();
        $this->otherTenant = Tenant::create(['name' => 'Second', 'slug' => 'second-alert-world', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->otherTenant->id);
        $workspace = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->otherTenant->id, 'name' => 'B', 'slug' => 'b-alert-world', 'mode' => 'managed', 'status' => 'active']);
        $this->otherTenantProject = Project::create(['tenant_id' => $this->otherTenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'Second project', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(tenantId: (string) $this->otherTenant->id, provider: 'meta', tokens: new OAuthTokens('AT', 'RT', $now->copy()->addDays(30)), connectionName: 'second-conn');
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->otherTenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'second-act', 'name' => 'second-act', 'currency' => 'SAR', 'status' => 'active', 'last_synced_at' => $now->copy()->subHours(3),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->otherTenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $this->otherTenantProject->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'reporting', 'is_active' => true,
        ]);

        $own = ['tenant_id' => $this->otherTenant->id, 'project_id' => $this->otherTenantProject->id];
        $this->alertCampaign('second-no-results', $account, $this->alertDays(1, 12, ['spend' => 40.0, 'conversions' => 0.0]), $own);
        $this->alertCampaign('second-cost-rise', $account, $this->alertDays(8, 14, ['spend' => 100.0, 'conversions' => 10.0]) + $this->alertDays(1, 7, ['spend' => 100.0, 'conversions' => 2.0]), $own);

        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->otherTenant->id, 'project_id' => $this->otherTenantProject->id, 'connection_id' => (string) Str::uuid(),
            'external_account_id' => $account->id, 'provider' => 'meta', 'status' => 'success', 'window_start' => $now->copy()->subDays(9)->toDateString(),
            'window_end' => $now->copy()->subDays(4)->toDateString(), 'started_at' => $now->copy()->subDays(4), 'finished_at' => $now->copy()->subDays(4),
            'created_at' => $now->copy()->subDays(4), 'updated_at' => $now->copy()->subDays(4),
        ]);

        foreach ([['no_results', ['days' => 3]], ['cpa_increase', ['pct' => 25, 'days' => 7]], ['metric_anomaly', []], ['budget_risk', ['ratio' => 0.9]]] as [$type, $threshold]) {
            $this->alertRule('second-'.$type, $type, ['threshold' => $threshold], $this->otherTenant);
        }
    }

    /**
     * Five sweeps over 25 hours: first breach, a repeat inside the cooldown, a recovery with a snooze and a resolve, a re-notification after the cooldown,
     * and a provider going stale. What each left behind is the characterisation.
     *
     * @return array<string, mixed>
     */
    protected function runAlertSweeps(): array
    {
        config()->set('providers.channels.email', ConfiguredAlertSweepEmailProvider::class);
        Mail::fake();
        // A world's tokens are not real and a test does not call a provider: the fetch a totals read queues on a miss stays a recorded dispatch.
        Bus::fake([FetchPeriodReachJob::class]);
        Http::preventStrayRequests();

        $evaluator = app(AlertEvaluator::class);
        $start = Carbon::now()->copy();
        $out = [];

        $out['1_first'] = $this->alertSweep($evaluator, $start->copy());
        $out['2_inside_cooldown'] = $this->alertSweep($evaluator, $start->copy()->addMinutes(15));

        $this->inTenant(function (): void {
            $event = fn (string $rule, string $campaign) => AlertEvent::query()->where('rule_id', $this->rules[$rule]->id)->where('entity_id', $this->named[$campaign])->firstOrFail();
            app(AlertEvaluator::class)->snooze($event('roas', 'roas-drop'), Carbon::now()->addDays(5));
            app(AlertEvaluator::class)->resolve($event('budget', 'budget-risk'));
            // A conversion arrives for one campaign: its no-results alert recovers.
            $this->alertCampaign('', $this->boundAccounts[0], [1 => ['conversions' => 3.0]], [], [], $this->named['no-results']);
        });
        $out['3_recovery'] = $this->alertSweep($evaluator, $start->copy()->addMinutes(45));

        $out['4_after_cooldown'] = $this->alertSweep($evaluator, $start->copy()->addHours(13));

        $this->inTenant(function (): void {
            // Snapchat stops short of the window: the tenant's coverage goes partial and every cost verdict is withdrawn, as a recovery.
            DB::table('metric_sync_runs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'connection_id' => (string) Str::uuid(),
                'external_account_id' => $this->boundAccounts[2]->id, 'provider' => 'snapchat', 'status' => 'success', 'window_start' => '2026-10-01',
                'window_end' => '2026-10-10', 'started_at' => Carbon::now(), 'finished_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
        });
        $out['5_provider_stale'] = $this->alertSweep($evaluator, $start->copy()->addHours(25));

        $out['mail'] = Mail::sent(AlertBundleMail::class)->map(fn (AlertBundleMail $m): array => [
            'to' => array_column($m->to, 'address'), 'lang' => $m->lang, 'name' => $m->recipientName, 'subject' => $m->envelope()->subject, 'items' => $m->items,
        ])->values()->all();

        Carbon::setTestNow($start);

        return $out;
    }

    private function inTenant(\Closure $work): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $work();
        app(TenantContext::class)->forget();
    }

    /** @return array<string, mixed> */
    private function alertSweep(AlertEvaluator $evaluator, Carbon $at): array
    {
        Carbon::setTestNow($at);
        $raised = $evaluator->evaluateAll();

        $emails = DB::table('users')->pluck('email', 'id');
        $state = ['raised' => $raised];
        foreach (['alert_events', 'app_notifications', 'notification_deliveries', 'tasks', 'spend_limit_events', 'digest_sends'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(static function (object $row) use ($emails): array {
                $row = (array) $row;
                // Bigint keys come from sequences a rolled-back test does not reset; a person is named by their address.
                foreach (['user_id', 'owner_id', 'created_by', 'assignee_id'] as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = $emails[$row[$column]] ?? 'unknown';
                    }
                }

                return $row;
            })->all();
        }

        return $state;
    }
}
