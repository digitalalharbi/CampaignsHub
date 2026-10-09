<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-BROWSER-PARITY-001 — which ad account ran this creative, on the row.
 *
 * A project reads more than one account, and the library could not answer «which of ours was this
 * on»: two creatives with the same name under two accounts were indistinguishable. It is also the
 * axis ACCOUNT-SCOPE-ISOLATION-001 is about — a reader who cannot see the account cannot check the
 * isolation they are being promised.
 *
 * Two hops, because that is the shape of the data: a creative names its EXTERNAL campaign, and the
 * external campaign names the account. The UNIFIED campaign cannot answer it — it is the
 * cross-platform merge of several external ones and deliberately has no single account.
 */
final class CreativeAdAccountColumnTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-acct-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-acct-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_a_creative_names_the_ad_account_its_campaign_ran_under(): void
    {
        $account = $this->account('act_900', 'RazzahAvenu Self Service');
        $campaign = $this->campaign($account);
        $this->creative('Eid film', $campaign);

        $row = $this->firstRow();

        $this->assertSame('RazzahAvenu Self Service', $row['ad_account']['name']);
        $this->assertSame((string) $account->getKey(), $row['ad_account']['id']);
    }

    /**
     * Two accounts in one project, told apart — the case the column exists for.
     *
     * Same creative name under two accounts is not contrived: an agency duplicates a winning ad
     * across the accounts it runs, and before this the two rows were identical on screen.
     */
    public function test_two_accounts_in_one_project_are_told_apart(): void
    {
        $this->creative('Eid film', $this->campaign($this->account('act_1', 'Account One')));
        $this->creative('Eid film', $this->campaign($this->account('act_2', 'Account Two')));

        $names = collect($this->rows())->pluck('ad_account.name')->sort()->values()->all();

        $this->assertSame(['Account One', 'Account Two'], $names);
    }

    /**
     * A creative with no external campaign has no account, and says so with a null.
     *
     * Real state: a creative can be imported before its campaign is linked. Inventing «unknown
     * account» would be our word for a thing that simply has not happened yet.
     */
    public function test_a_creative_with_no_campaign_carries_no_account(): void
    {
        $this->creative('Orphan', null);

        $this->assertNull($this->firstRow()['ad_account']);
    }

    /**
     * The platform's own name, or the id where it sent none — never a word of ours.
     *
     * An id is what an operator can look up on the platform; «Unnamed account» is not.
     */
    public function test_an_unnamed_account_falls_back_to_its_id_rather_than_to_a_phrase(): void
    {
        $account = $this->account('act_500', '');
        $this->creative('Nameless', $this->campaign($account));

        $this->assertSame((string) $account->getKey(), $this->firstRow()['ad_account']['name']);
    }

    /**
     * Another tenant's campaign row never answers this join.
     *
     * `DB::table()` carries no global scope, so the predicate is written out — the same lesson
     * `CreativeResultAttribution` had to learn. A raw builder joining two tenant-owned tables with
     * neither one stated is a cross-tenant read waiting to happen.
     */
    public function test_another_tenants_campaign_cannot_name_this_creatives_account(): void
    {
        $account = $this->account('act_900', 'Ours');
        $campaign = $this->campaign($account);
        $this->creative('Eid film', $campaign);

        /* The same campaign id, re-filed under another tenant — the shape the predicate stops. */
        DB::table('external_campaigns')->where('id', $campaign->getKey())->update([
            'tenant_id' => Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active'])->id,
        ]);

        $this->assertNull($this->firstRow()['ad_account'], 'another tenant\'s campaign named this account');
    }

    /** @return array<string, mixed> */
    private function firstRow(): array
    {
        return $this->rows()[0];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $creatives = ExternalCreative::query()
            ->where('project_id', $this->project->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return app(CreativeRows::class)->present(
            $creatives,
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-30'),
            withFatigue: false,
        );
    }

    private function account(string $externalId, string $name): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'Meta '.$externalId,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function campaign(ExternalAccount $account): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'provider' => 'meta',
            'external_id' => 'c-'.Str::random(6),
            'name' => 'Campaign',
            'status' => 'active',
        ]);
    }

    private function creative(string $name, ?ExternalCampaign $campaign): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'external_campaign_id' => $campaign?->getKey(),
            'name' => $name,
            'format' => 'image',
            'status' => 'active',
            'last_active_at' => '2026-08-29',
        ]);
    }
}
