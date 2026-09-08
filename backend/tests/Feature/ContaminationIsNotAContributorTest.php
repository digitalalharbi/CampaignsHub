<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Coverage\ContributorCoverage;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AGGREGATION-TRUTH-001 / SANDBOX-PROD-001 — a fixture row is not a contributor whose silence counts.
 *
 * ## What the owner's client is being told
 *
 * The live client report declares its spend coverage `partial`, and the only thing making it partial
 * is a provider called `sandbox`:
 *
 *     expected  [linkedin, meta, snapchat, sandbox]
 *     included  [linkedin, meta, snapchat]
 *     excluded  [sandbox]
 *     reasons   sandbox => "The last sync failed: No connector is registered for provider 'sandbox'."
 *
 * There is no such platform. Two rows — `sbx-cmp-1 Sandbox Awareness` and `sbx-cmp-2 Sandbox
 * Conversions` — were written into the live Snapchat account by the binding defect SANDBOX-PROD-001
 * closed at the write path, and `expectedProviders()` reads `external_campaigns.provider`, so it
 * expects a platform this install has no connector for and can never hear from. A paying client is
 * therefore told their figures are incomplete because of an artefact of ours.
 *
 * ## Why this is fixed here and not only by deleting the rows
 *
 * Removing them is authorised and needs a shell, and it should still happen. But a report that
 * becomes true only after somebody remembers to run a command is not a truthful report, and the same
 * falsehood returns the moment any contaminated row exists again. Contamination is not evidence about
 * a client's money.
 *
 * ## Production's printed shape, project-wide (2026-09-08)
 *
 *     snapchat  89   2 flagged `raw->sandbox`
 *     linkedin  24   0 flagged
 *     meta       4   2 flagged
 *     sandbox    2   2 flagged      <- the whole of it
 *
 * ## The shape is Production's, not an inference
 *
 * A first repair filtered rows flagged `raw->sandbox` under a live account. It deployed and
 * Production did not change, because those flagged rows claim `provider = 'snapchat'` — the
 * account's provider distribution reads «snapchat 89, 2 flagged». The flag was never the handle,
 * and the fixture that «proved» it was written to match the guess rather than the data.
 *
 * The fact this rests on instead is Production-proven: `integrations:diagnose --provider=sandbox`
 * answers «No external account matches that filter». There is no account for that platform, so it
 * cannot sync, so its silence is not a gap in a client's figures. These fixtures reproduce THAT:
 * a campaign claiming a provider the tenant holds no account for.
 */
final class ContaminationIsNotAContributorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $live;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->live = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act_live',
            'name' => 'Live',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    /** @param array<string,mixed> $raw */
    private function campaign(string $externalId, string $provider, array $raw, ?ExternalAccount $account = null): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => ($account ?? $this->live)->id,
            'provider' => $provider,
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'raw' => $raw,
        ]);
    }

    private function coverage(): array
    {
        return (new ContributorCoverage)->forWindow(
            tenantId: (string) $this->tenant->id,
            projectId: (string) $this->project->id,
            from: Carbon::now()->subDays(7)->startOfDay(),
            to: Carbon::yesterday()->startOfDay(),
        )->toArray();
    }

    /**
     * The defect, in Production's own shape: a campaign claims a platform this tenant holds no
     * account for, and the client's report is told their money is only partly counted because of it.
     */
    public function test_a_provider_with_no_account_is_not_an_expected_contributor(): void
    {
        $this->campaign('real-1', 'snapchat', ['id' => 'real-1']);
        $this->campaign('sbx-cmp-1', 'sandbox', ['sandbox' => true]);

        $coverage = $this->coverage();

        $this->assertNotContains(
            'sandbox',
            $coverage['expected_contributors'],
            'a platform with no connected account was expected to report figures, so the report reads partial for ever',
        );
    }

    /**
     * The vacuity check, and the half that keeps this honest: a REAL provider that is genuinely
     * missing must still make the total partial. A filter that quietly dropped any absent
     * contributor would pass the test above and publish an incomplete total as a whole one, which is
     * the failure AGGREGATION-TRUTH-001 exists to prevent.
     */
    public function test_a_real_provider_that_reported_nothing_still_degrades_the_total(): void
    {
        $this->campaign('real-1', 'snapchat', ['id' => 'real-1']);

        $coverage = $this->coverage();

        $this->assertContains('snapchat', $coverage['expected_contributors']);
        $this->assertNotSame('complete', $coverage['state'],
            'a provider that was expected and sent nothing was treated as if it had reported');
    }

    /**
     * The belief this test used to hold was WRONG, and the data said so.
     *
     * It asserted that a flagged row under a sandbox account IS an expected contributor — «a sandbox
     * account's own fixtures stay its own». That assumption is exactly what let the two live rows
     * through: both are flagged, and preserving them is what kept the owner's report `partial`.
     *
     * A row the sandbox connector wrote is synthetic wherever it is filed. It never carried real
     * money, so it can never owe a figure. A tenant whose rows are ALL synthetic now reports
     * `complete` instead of expecting a platform to answer for invented data.
     */
    public function test_a_flagged_fixture_row_is_never_a_contributor_wherever_it_is_filed(): void
    {
        $this->campaign('real-1', 'snapchat', ['id' => 'real-1']);
        $this->campaign('sbx-under-live', 'sandbox', ['sandbox' => true]);

        $coverage = $this->coverage();

        $this->assertNotContains('sandbox', $coverage['expected_contributors']);
        $this->assertContains('snapchat', $coverage['expected_contributors'],
            'the real platform stopped being expected too — this filter is too wide');
    }

    /**
     * The surgical check, in Production's own proportions: a platform with SOME flagged rows keeps
     * its unflagged ones and stays a contributor. Live data has snapchat at 89 with 2 flagged and
     * meta at 4 with 2 flagged — if this filter removed a platform because any row of it was
     * flagged, it would erase two real contributors from a client's report.
     */
    public function test_a_platform_with_some_flagged_rows_keeps_its_real_ones(): void
    {
        $this->campaign('meta-real', 'meta', ['id' => 'meta-real']);
        $this->campaign('meta-fixture', 'meta', ['sandbox' => true]);

        $this->assertContains(
            'meta',
            $this->coverage()['expected_contributors'],
            'a platform lost its place because one of its rows was a fixture',
        );
    }
}
