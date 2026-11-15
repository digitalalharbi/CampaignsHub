<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformCreatedAt;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONS-I-AUTOMATION — the automation indicators read what they say they read: the daily budget consumption from the
 * platform's daily budget (converted to the display currency), the campaign's age from its CREATION on the platform,
 * and a dry run that speaks Arabic and gives the exact reason for «بيانات غير كافية».
 */
final class AutomationIndicatorsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private ExternalAccount $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = $this->member('o@a.test', $role);
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->meta = $this->account($this->project, 'meta', 'act_1');

    }

    private function rule(array $conditions, array $over = []): string
    {
        return (string) $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules", array_replace([
            'name' => 'R', 'enabled' => true, 'mode' => 'recommend', 'level' => 'campaign', 'logic' => 'all',
            'conditions' => $conditions,
            'action' => ['type' => 'notify'], 'schedule' => ['frequency' => 'hourly', 'timezone' => 'Asia/Riyadh'], 'cooldown_hours' => 24,
        ], $over))->assertCreated()->json('data.id');
    }

    /** @return array<string, array<string, mixed>> object name => dry-run result */
    private function dry(string $id): array
    {
        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/automation/rules/{$id}/dry-run")->assertOk();

        return collect($res->json('data.results'))->keyBy('name')->all();
    }

    /** @param array<string, mixed> $attrs */
    private function campaign(string $name, array $attrs = [], float $spendToday = 0.0): ExternalCampaign
    {
        $c = ExternalCampaign::create(array_replace([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->meta->id, 'provider' => 'meta',
            'external_id' => (string) random_int(100000, 999999), 'name' => $name, 'status' => 'active', 'currency' => 'USD',
        ], $attrs));
        if ($spendToday > 0) {
            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->meta->id,
                'external_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => Carbon::now('Asia/Riyadh')->toDateString(), 'value' => $spendToday,
            ]);
        }

        return $c;
    }

    public function test_daily_budget_consumption_is_computed_from_the_platform_budget_and_todays_spend(): void
    {
        $this->campaign('Budgeted', ['daily_budget' => 100], 30);
        $id = $this->rule([['metric' => 'budget_utilization', 'operator' => 'gt', 'value' => 20, 'window_days' => 1]]);

        $r = $this->dry($id)['Budgeted'];
        $this->assertTrue($r['matched']);
        $this->assertEqualsWithDelta(30.0, $r['readings'][0]['value'], 0.01);
        $this->assertSame('30.0%', $r['readings'][0]['text_ar']);
    }

    public function test_the_budget_is_converted_to_the_display_currency_before_the_division(): void
    {
        // 375 SAR a day is 100 USD at the 3.75 peg; 50 USD spent today is 50% — not 13%.
        $this->campaign('Riyals', ['daily_budget' => 375, 'currency' => 'SAR'], 50);
        $id = $this->rule([['metric' => 'budget_utilization', 'operator' => 'gt', 'value' => 40, 'window_days' => 1]]);

        $r = $this->dry($id)['Riyals'];
        $this->assertTrue($r['matched']);
        $this->assertEqualsWithDelta(50.0, $r['readings'][0]['value'], 0.01);
    }

    public function test_no_platform_budget_reads_as_not_available_and_the_rule_is_skipped_with_the_reason(): void
    {
        $this->campaign('Budgetless', [], 30);
        $id = $this->rule([['metric' => 'budget_utilization', 'operator' => 'gt', 'value' => 80, 'window_days' => 1]], ['action' => ['type' => 'budget_increase', 'percent' => 20]]);

        $r = $this->dry($id)['Budgetless'];
        $this->assertFalse($r['matched']);
        $this->assertSame('skipped_no_figure', $r['status']);
        $this->assertSame('غير متوفرة', $r['readings'][0]['text_ar']);
        $this->assertSame('no_daily_budget', $r['readings'][0]['reason_code']);
        $this->assertStringContainsString('ميزانية يومية', (string) $r['reason']['ar']);
        $this->assertStringContainsString('بيانات غير كافية', (string) $r['reason']['ar']);
    }

    public function test_a_campaign_created_in_january_shows_its_true_age_not_the_first_sync(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Riyadh'));
        // Synced for the first time just now (created_at = now) but created on the platform in January.
        $this->campaign('January', ['platform_created_at' => '2026-01-10 08:00:00'], 5);
        $id = $this->rule([['metric' => 'age_days', 'operator' => 'gt', 'value' => 100, 'window_days' => 1]]);

        $r = $this->dry($id)['January'];
        $this->assertTrue($r['matched']);
        $this->assertEqualsWithDelta(309.0, $r['readings'][0]['value'], 1.0);
    }

    public function test_an_older_row_reads_the_creation_date_the_platform_sent_and_otherwise_says_unknown(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Riyadh'));
        $this->campaign('FromRaw', ['raw' => ['external_id' => 'x', 'raw' => ['created_time' => '2026-09-01T10:00:00+0000']]], 5);
        $this->campaign('Unknown', [], 5);
        $id = $this->rule([['metric' => 'age_days', 'operator' => 'gt', 'value' => 10, 'window_days' => 1]]);

        $by = $this->dry($id);
        $this->assertEqualsWithDelta(75.0, $by['FromRaw']['readings'][0]['value'], 1.0);
        $this->assertNull($by['Unknown']['readings'][0]['value']);
        $this->assertSame('no_creation_date', $by['Unknown']['readings'][0]['reason_code']);
        $this->assertSame('skipped_no_figure', $by['Unknown']['status']);
    }

    public function test_a_dry_run_speaks_arabic_names_and_money_in_the_display_currency_and_never_the_technical_key(): void
    {
        $c = $this->campaign('Spender', [], 0);
        foreach (['spend' => 200, 'conversions' => 10] as $k => $v) {
            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->meta->id,
                'external_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => $k, 'metric_date' => Carbon::now('Asia/Riyadh')->subDay()->toDateString(), 'value' => $v,
            ]);
        }
        $id = $this->rule([['metric' => 'cpa', 'operator' => 'gt', 'value' => 5, 'window_days' => 7]]);

        $reading = $this->dry($id)['Spender']['readings'][0];
        $this->assertSame('تكلفة النتيجة (آخر 7 أيام)', $reading['name_ar']);
        $this->assertSame('20.00 $', $reading['text_ar']);
        $this->assertSame('USD', $reading['currency']);
        $this->assertStringNotContainsString('cpa@', json_encode($reading, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_minimum_spend_reason_states_the_spend_and_the_minimum(): void
    {
        $this->campaign('Small', [], 3);
        $id = $this->rule([['metric' => 'spend', 'operator' => 'gt', 'value' => 1, 'window_days' => 1]], ['min_spend' => 50]);

        $r = $this->dry($id)['Small'];
        $this->assertSame('skipped_insufficient_data', $r['status']);
        $this->assertSame('below_min_spend', $r['reason']['code']);
        $this->assertStringContainsString('بيانات غير كافية', $r['reason']['ar']);
        $this->assertStringContainsString('3.00 $', $r['reason']['ar']);
        $this->assertStringContainsString('50.00 $', $r['reason']['ar']);
    }

    public function test_platform_creation_stamps_are_normalised_and_nonsense_is_not_an_age(): void
    {
        $this->assertSame('2026-01-10T08:00:00+00:00', PlatformCreatedAt::from('2026-01-10 08:00:00'));
        $this->assertSame('2026-01-10T08:00:00+00:00', PlatformCreatedAt::from(Carbon::parse('2026-01-10 08:00:00', 'UTC')->getTimestamp() * 1000));
        $this->assertSame('2026-01-10T08:00:00+00:00', PlatformCreatedAt::from('2026-01-10T11:00:00+03:00'));
        $this->assertNull(PlatformCreatedAt::from(null));
        $this->assertNull(PlatformCreatedAt::from('0'));
        $this->assertNull(PlatformCreatedAt::from('not a date'));
        $this->assertNull(PlatformCreatedAt::from('1970-01-01 00:00:00'));
    }

    private function member(string $email, Role $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function account(Project $project, string $provider, string $externalId): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay()), connectionName: $provider.uniqid());
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active', 'currency' => 'USD']);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true]);

        return $account;
    }
}
