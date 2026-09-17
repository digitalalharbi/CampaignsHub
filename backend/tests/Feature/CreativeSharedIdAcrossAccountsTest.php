<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\ImportExternalStructure;
use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 / CREATIVE-ACCOUNT-IDENTITY-001 — a creative id shared by two accounts
 * in one project never carries one account's figures onto the other's row.
 *
 * `external_creatives` is unique on `(project_id, provider, external_creative_id)` and holds no
 * account column, so two accounts in one project that both run a creative with the same provider id
 * collapse into ONE row, and each account's structure import flips that row's campaign to its own.
 * The stats upsert resolves a creative THROUGH the syncing account's campaigns and refuses an id that
 * resolves outside them — so the account whose import came second owns the row and the other
 * account's figures for that id are SKIPPED and counted, never written across.
 *
 * That is fail-closed, and it is also a real loss: the first account's creative figures for that id
 * are not stored anywhere. Closing the loss needs the account on the creative's identity — a schema
 * change with a backfill — and this test pins the boundary until that lands.
 */
final class CreativeSharedIdAcrossAccountsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $first;

    private ExternalAccount $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->first = $this->account($connection, 'act-first');
        $this->second = $this->account($connection, 'act-second');

        foreach ([$this->first, $this->second] as $account) {
            ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
                'provider' => 'snapchat', 'external_id' => 'cmp-'.$account->external_id, 'name' => 'C', 'status' => 'active',
            ]);
        }
    }

    public function test_two_accounts_reporting_one_creative_id_keep_two_creatives_and_their_own_figures(): void
    {
        // CREATIVE-ACCOUNT-IDENTITY-001 — the key includes the account, so nothing collapses and nothing is skipped.
        foreach ([$this->first, $this->second] as $account) {
            app(ImportExternalStructure::class)->execute($account, [], [[
                'external_id' => 'ad-'.$account->external_id, 'name' => 'Ad', 'status' => 'active',
                'campaign_external_id' => 'cmp-'.$account->external_id,
                'creative' => ['external_id' => 'cr-shared', 'name' => 'Shared', 'format' => 'image'],
            ]]);
        }

        $rows = ExternalCreative::withoutGlobalScopes()->where('external_creative_id', 'cr-shared')->get();
        $this->assertCount(2, $rows, 'two accounts collapsed into one creative row');
        $this->assertEqualsCanonicalizing([$this->first->id, $this->second->id], $rows->pluck('external_account_id')->all());

        $first = app(UpsertCreativeDailyMetrics::class)->execute($this->first, [['campaign_id' => 'cr-shared', 'date' => '2026-08-01', 'spend' => 10.0]]);
        $second = app(UpsertCreativeDailyMetrics::class)->execute($this->second, [['campaign_id' => 'cr-shared', 'date' => '2026-08-01', 'spend' => 999.0]]);

        $this->assertSame(1, $first['upserted']);
        $this->assertSame(1, $second['upserted']);

        foreach ([[$this->first, 10.0], [$this->second, 999.0]] as [$account, $spend]) {
            $creativeId = $rows->firstWhere('external_account_id', $account->id)->id;
            $stored = DB::table('creative_daily_metrics')->where('creative_id', $creativeId)->get();
            $this->assertCount(1, $stored);
            $this->assertEqualsWithDelta($spend, (float) ($stored[0]->spend ?? $stored[0]->spend_original), 0.001, 'a figure landed on the other account\'s creative');
        }
    }

    public function test_a_creative_stored_before_it_carried_an_account_is_still_reached_through_its_campaign(): void
    {
        $campaign = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $this->first->id)->firstOrFail();
        $legacy = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'snapchat',
            'external_campaign_id' => $campaign->id, 'external_creative_id' => 'cr-legacy', 'name' => 'Legacy',
        ]);

        $this->assertNull($legacy->external_account_id);
        $this->assertSame(1, app(UpsertCreativeDailyMetrics::class)->execute($this->first, [['campaign_id' => 'cr-legacy', 'date' => '2026-08-01', 'spend' => 5.0]])['upserted']);
        $this->assertSame(0, app(UpsertCreativeDailyMetrics::class)->execute($this->second, [['campaign_id' => 'cr-legacy', 'date' => '2026-08-02', 'spend' => 7.0]])['upserted'], 'another account reached a legacy creative');
    }

    private function account(ProviderConnection $connection, string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
            'currency' => 'SAR', 'discovered_at' => Carbon::now(),
        ]);
    }
}
