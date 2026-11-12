<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Status\LinkHealth;
use App\Domains\Notifications\Support\OperationalDigestModel;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LINK-HEALTH-YESTERDAY-001 — `docs/OWNER_NOTIFICATIONS_RULES_2026-11-12.md` §7أ, the decisive criterion: a link alert
 * only when yesterday's data did not arrive; one item per connection; the open false items close silently; and «no data»
 * is three states with three texts. The Recommendations page («تتبع وبيانات») reads `GET /alerts/events?status=open`.
 */
final class LinkHealthYesterdayTest extends TestCase
{
    use RefreshDatabase;

    /** 08:00 in Riyadh on Thursday 12 November 2026 — yesterday is the 11th. */
    private const NOW_UTC = '2026-11-12 05:00:00';

    private Tenant $tenant;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-lh-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-lh-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@lh.test', 'password' => 'secret123']);
        Membership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active']);
        $this->user->assignRole($role);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Coffee', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => '1KCOFFEE', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A working, auto-renewing connection whose ACCESS token expires within the hour (Google's every hour). */
    private function connection(string $provider): ProviderConnection
    {
        $c = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addHour()), connectionName: $provider.uniqid(),
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $c->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => 'acc-'.uniqid(), 'name' => $provider, 'currency' => 'SAR', 'status' => 'active',
        ]);
        ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'purpose' => 'ads', 'is_active' => true,
        ]);

        return $c;
    }

    /** A sync run finished at `$finishedUtc`, its window ending that (Riyadh) day. */
    private function syncRun(ProviderConnection $c, string $finishedUtc, string $status = 'success'): void
    {
        $at = Carbon::parse($finishedUtc, 'UTC');
        $local = $at->copy()->setTimezone('Asia/Riyadh');
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'connection_id' => $c->id, 'provider' => $c->provider, 'status' => $status,
            'window_start' => $local->copy()->subDays(3)->toDateString(), 'window_end' => $local->toDateString(),
            'error' => $status === 'failed' ? 'timeout' : null, 'started_at' => $at->copy()->subMinute(), 'finished_at' => $at,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function rule(string $type, ?string $projectId = null): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $projectId, 'type' => $type, 'name' => $type, 'cooldown_minutes' => 720,
            'channels' => ['in_app'], 'severity' => 'warning', 'active' => true, 'threshold' => $type === 'token_expiry' ? ['days' => 7] : [],
        ]);
    }

    /** @return list<array<string,mixed>> what the Recommendations page reads */
    private function openForProject(): array
    {
        app(TenantContext::class)->forget();

        return (array) $this->actingAs($this->user, 'sanctum')->withHeader('X-Tenant-ID', (string) $this->tenant->id)
            ->getJson('/api/v1/alerts/events?status=open&project='.$this->project->id)->assertOk()->json('data');
    }

    private function sweep(array $rules): void
    {
        foreach ($rules as $rule) {
            app(TenantContext::class)->setTenantId($this->tenant->id);
            app(AlertEvaluator::class)->evaluateRule($rule);
        }
    }

    public function test_healthy_auto_renewing_connections_whose_yesterday_arrived_show_no_link_item_and_the_false_cards_close_silently(): void
    {
        $google = $this->connection('google');
        $snap = $this->connection('snapchat');
        $this->syncRun($google, '2026-11-12 04:00:00');    // 07:00 Riyadh today: the 11th is complete
        $this->syncRun($snap, '2026-11-12 04:30:00');
        $this->syncRun($snap, '2026-11-12 04:40:00', 'failed');   // a later failure the earlier run already made good
        $rules = [$this->rule('token_expiry'), $this->rule('token_expiry', (string) $this->project->id), $this->rule('sync_failure'), $this->rule('sync_outage', (string) $this->project->id)];

        // The cards open before this change: «صلاحية الربط تنتهي قريبًا» per connection and per rule, and a failed-run card.
        foreach ([$google, $snap] as $c) {
            foreach ([$rules[0], $rules[1]] as $r) {
                AlertEvent::create(['tenant_id' => $this->tenant->id, 'rule_id' => $r->id, 'type' => 'token_expiry', 'entity_type' => ProviderConnection::class,
                    'entity_id' => (string) $c->id, 'dedup_key' => Str::random(16), 'severity' => 'warning', 'status' => 'open',
                    'context' => ['provider' => $c->provider, 'expires_at' => '2026-11-12T06:00:00+00:00'], 'last_triggered_at' => now()]);
            }
        }
        AlertEvent::create(['tenant_id' => $this->tenant->id, 'rule_id' => $rules[2]->id, 'type' => 'sync_failure', 'entity_type' => ProviderConnection::class,
            'entity_id' => (string) $snap->id, 'dedup_key' => Str::random(16), 'severity' => 'warning', 'status' => 'open',
            'context' => ['provider' => 'snapchat', 'error' => 'timeout'], 'last_triggered_at' => now()]);
        $notices = DB::table('app_notifications')->count();

        $this->sweep($rules);

        $this->assertSame([], $this->openForProject(), 'no tracking/data item at all');
        $this->assertSame($notices, DB::table('app_notifications')->count(), 'closed without a «recovered» notice');
        $this->assertSame(0, AlertEvent::withoutGlobalScopes()->whereIn('status', ['open', 'snoozed'])->count());
        $this->assertEqualsCanonicalizing(['renews_itself', 'yesterday_arrived'],
            AlertEvent::withoutGlobalScopes()->get()->map(fn (AlertEvent $e): string => (string) $e->context['closed_reason'])->unique()->values()->all());
    }

    public function test_when_yesterday_did_not_arrive_there_is_exactly_one_item_per_connection_saying_so(): void
    {
        $google = $this->connection('google');
        $snap = $this->connection('snapchat');
        $this->syncRun($google, '2026-11-11 04:00:00');          // covers the 10th only
        $this->syncRun($google, '2026-11-12 04:00:00', 'failed');
        $this->syncRun($snap, '2026-11-11 05:00:00');            // covers the 10th; no run since — it simply stopped
        $rules = [$this->rule('token_expiry'), $this->rule('token_expiry', (string) $this->project->id), $this->rule('sync_failure'), $this->rule('sync_failure', (string) $this->project->id), $this->rule('sync_outage', (string) $this->project->id)];

        $this->sweep($rules);
        $this->sweep($rules);   // a second sweep repeats nothing

        $link = array_values(array_filter($this->openForProject(), static fn (array $e): bool => $e['type'] !== 'sync_outage'));
        $this->assertCount(2, $link, 'one item per connection');
        $this->assertEqualsCanonicalizing([(string) $google->id, (string) $snap->id], array_column($link, 'entity_id'));
        foreach ($link as $e) {
            $this->assertSame('sync_failure', $e['type'], 'never «expiring» for an auto-renewing token');
            $this->assertSame('not_synced', $e['context']['link_state']);
            $this->assertSame('2026-11-11', $e['context']['required_day']);
            $this->assertSame('2026-11-10', $e['context']['last_day']);
            $this->assertSame(LinkHealth::text('not_synced', true), $e['context']['title_ar']);
        }
    }

    public function test_a_refused_grant_whose_yesterday_did_not_arrive_is_one_card_not_two(): void
    {
        $google = $this->connection('google');
        $google->forceFill(['status' => 'error', 'last_error' => 'invalid_grant'])->save();
        $this->syncRun($google, '2026-11-11 04:00:00');

        $this->sweep([$this->rule('token_expiry'), $this->rule('sync_failure')]);

        $open = AlertEvent::withoutGlobalScopes()->where('status', 'open')->get();
        $this->assertCount(1, $open);
        $this->assertSame('token_expiry', $open->sole()->type);
    }

    public function test_a_missing_day_escalates_once_it_persists_and_the_morning_grace_is_honoured(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-10 04:00:00');          // covers the 9th: the 10th and the 11th are missing
        $rule = $this->rule('sync_failure');
        $this->sweep([$rule]);
        $this->assertSame('critical', AlertEvent::withoutGlobalScopes()->sole()->severity);

        // At 02:00 Riyadh the day that must have arrived is the day BEFORE yesterday.
        Carbon::setTestNow(Carbon::parse('2026-11-11 23:00:00', 'UTC'));
        $this->assertSame('2026-11-10', LinkHealth::requiredDay(Carbon::now())->toDateString());
        Carbon::setTestNow(Carbon::parse('2026-11-12 03:00:00', 'UTC'));
        $this->assertSame('2026-11-11', LinkHealth::requiredDay(Carbon::now())->toDateString());
    }

    public function test_no_data_is_three_states_with_three_texts(): void
    {
        $google = $this->connection('google');
        $health = app(LinkHealth::class);
        $day = Carbon::parse('2026-11-11', 'Asia/Riyadh');
        $tenant = (string) $this->tenant->id;
        $project = (string) $this->project->id;

        $this->syncRun($google, '2026-11-11 04:00:00');
        $this->assertSame('not_synced', $health->noDataState($tenant, $project, $day, Carbon::now()), 'yesterday did not arrive');

        $this->syncRun($google, '2026-11-12 04:00:00');
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'C', 'status' => 'paused',
            'objective' => 'sales', 'platforms' => ['google'], 'total_budget' => 0, 'budget_currency' => 'SAR']);
        $this->assertSame('not_running', $health->noDataState($tenant, $project, $day, Carbon::now()), 'ended or paused');

        $campaign->forceFill(['status' => 'active'])->save();
        $this->assertSame('no_spend', $health->noDataState($tenant, $project, $day, Carbon::now()), 'healthy, active, spent nothing');

        $texts = array_map(static fn (string $s): string => OperationalDigestModel::noDataReason(['no_data_state' => $s], true), ['not_synced', 'not_running', 'no_spend']);
        $this->assertCount(3, array_unique($texts), 'three different texts, never mixed');
        $this->assertSame(LinkHealth::text('no_spend', true), $texts[2]);
        $this->assertStringContainsString('ما وصلت', $texts[0]);
        $this->assertStringContainsString('منتهية أو موقوفة', $texts[1]);
    }

    // ── independent review fixes ─────────────────────────────────────────────

    /** A campaign-STRUCTURE run reads names and ids, never a day's figures: it does not prove yesterday arrived. */
    public function test_a_structure_run_never_counts_as_yesterday_arriving(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-11 04:00:00');
        DB::table('integration_sync_runs')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider_connection_id' => $google->id, 'type' => 'structure', 'status' => 'success', 'started_at' => now(), 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertFalse(app(LinkHealth::class)->forConnection((string) $google->id, Carbon::now())['arrived']);
    }

    /** A website binding beside a healthy ad platform does not turn «campaigns ended» into «yesterday did not arrive». */
    public function test_a_website_binding_does_not_make_every_no_data_day_a_link_problem(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-12 04:00:00');
        $account = ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $google->id, 'provider' => 'ga4',
            'account_type' => 'property', 'external_id' => 'ga-'.uniqid(), 'name' => 'site', 'status' => 'active']);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'tracking', 'is_active' => true]);

        $this->assertSame('not_running', app(LinkHealth::class)->noDataState((string) $this->tenant->id, (string) $this->project->id, Carbon::parse('2026-11-11', 'Asia/Riyadh'), Carbon::now()));
        $this->assertSame(['google'], array_keys(app(LinkHealth::class)->projectReadings((string) $this->tenant->id, (string) $this->project->id, Carbon::now())));
    }

    /** A real outage stays open when a rule merely stops proposing it; it closes only on proof (yesterday arrived). */
    public function test_an_unproven_link_event_stays_open_and_closes_only_when_yesterday_arrives(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-11 04:00:00');
        $rule = $this->rule('sync_failure');
        $this->sweep([$rule]);
        $event = AlertEvent::withoutGlobalScopes()->sole();

        // Three weeks on with no run at all (the old fortnight window dropped the candidate and closed the event silently):
        // still open — nothing proves the data arrived.
        Carbon::setTestNow(Carbon::parse('2026-11-30 05:00:00', 'UTC'));
        $this->sweep([$rule]);
        $this->assertSame('open', $event->refresh()->status);

        $this->syncRun($google, '2026-11-30 04:00:00');
        $this->sweep([$rule]);
        $this->assertSame('resolved', $event->refresh()->status);
        $this->assertSame('yesterday_arrived', $event->context['closed_reason']);
    }

    /** An outage open under the old per-rule key is adopted on the first sweep after deploy: no second event, no second notice. */
    public function test_a_legacy_keyed_open_outage_is_adopted_not_announced_again(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-11 04:00:00');
        $rule = $this->rule('sync_failure');
        $old = AlertEvent::create(['tenant_id' => $this->tenant->id, 'rule_id' => $rule->id, 'type' => 'sync_failure', 'entity_type' => ProviderConnection::class,
            'entity_id' => (string) $google->id, 'dedup_key' => hash('sha256', $rule->id.'|legacy'), 'severity' => 'warning', 'status' => 'open',
            'context' => ['provider' => 'google'], 'last_triggered_at' => now()->subMinutes(10)]);
        $notices = DB::table('app_notifications')->count();

        $this->sweep([$rule]);

        $this->assertSame(1, AlertEvent::withoutGlobalScopes()->whereIn('status', ['open', 'snoozed'])->count());
        $this->assertSame('open', $old->refresh()->status, 'the same event, rekeyed');
        $this->assertSame($notices, DB::table('app_notifications')->count(), 'not announced a second time');
    }

    /** An escalation reaches the stored event (the bell and the Recommendations page read it), not only the mail. */
    public function test_an_escalation_is_stored_on_the_open_event(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-11 04:00:00');            // the 11th missing: one day
        $rule = $this->rule('sync_failure');
        $rule->forceFill(['cooldown_minutes' => 60])->save();
        $this->sweep([$rule]);
        $event = AlertEvent::withoutGlobalScopes()->sole();
        $this->assertSame('warning', $event->severity);

        Carbon::setTestNow(Carbon::parse('2026-11-13 05:00:00', 'UTC'));   // the 12th missing too: two days
        $this->sweep([$rule]);
        $this->assertSame('critical', $event->refresh()->severity);
    }

    /** Where each connection is watched, the project-level outage adds no second item to the mail or the bell. */
    public function test_a_project_outage_is_not_a_second_instant_item_where_connections_are_watched(): void
    {
        $google = $this->connection('google');
        $this->syncRun($google, '2026-11-08 04:00:00');
        // Figures last read four days ago: the freshness engine calls the project's source stale.
        $account = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $google->id)->firstOrFail();
        DB::table('daily_metrics')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => (string) Str::uuid(), 'provider' => 'google', 'metric_key' => 'spend',
            'metric_date' => '2026-11-08', 'value' => 10, 'project_currency' => 'SAR', 'data_freshness_at' => '2026-11-08 04:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->sweep([$this->rule('sync_failure'), $this->rule('sync_outage', (string) $this->project->id)]);

        $open = AlertEvent::withoutGlobalScopes()->where('status', 'open')->get()->keyBy('type');
        $this->assertTrue($open->has('sync_failure'));
        // The project-level outage is kept for «ملخص الربط» only: no second instant notice in the bell or the mail.
        $this->assertTrue((bool) ($open->get('sync_outage')?->context['digest_only'] ?? false));
        $this->assertSame(1, DB::table('app_notifications')->where('type', 'sync_failed')->count());
    }

    /** «ملخص الربط» lists a platform only when its yesterday did not arrive, in the rule's words. */
    public function test_the_digest_linking_section_follows_the_one_rule(): void
    {
        $google = $this->connection('google');
        $snap = $this->connection('snapchat');
        $this->syncRun($google, '2026-11-12 04:00:00');
        $this->syncRun($snap, '2026-11-11 04:00:00');
        $block = ['project_id' => (string) $this->project->id, 'link_health' => app(LinkHealth::class)->projectReadings((string) $this->tenant->id, (string) $this->project->id, Carbon::now()),
            'freshness' => ['state' => 'stale', 'sync_failed' => true]];

        $m = new \ReflectionMethod(OperationalDigestModel::class, 'linking');
        $items = $m->invoke(null, $block, [['provider' => 'google', 'name' => 'Google', 'link' => 'failed']], true, (string) $this->project->id);

        $this->assertSame(['snapchat'], array_column($items, 'provider'), 'only the platform whose yesterday is missing; the seven-day freshness is not a link verdict');
        $this->assertSame(LinkHealth::text('not_synced', true), $items[0]['label']);
    }
}
