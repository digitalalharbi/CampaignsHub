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
 * The test used is the quarantine's own, exactly: `raw->sandbox = true` on a campaign under an
 * account whose provider is NOT `sandbox`. A real sandbox account's own fixtures stay its own —
 * that is the distinction the quarantine already draws, and it is drawn the same way here.
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

    /** The defect: a fixture row makes a client's report say their money is only partly counted. */
    public function test_a_sandbox_row_under_a_live_account_is_not_an_expected_contributor(): void
    {
        $this->campaign('real-1', 'snapchat', ['id' => 'real-1']);
        $this->campaign('sbx-cmp-1', 'sandbox', ['sandbox' => true]);

        $coverage = $this->coverage();

        $this->assertNotContains(
            'sandbox',
            $coverage['expected_contributors'],
            'a contaminated fixture row was expected to report figures, so the report reads partial for ever',
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
     * And a sandbox ACCOUNT's own sandbox campaigns stay its own — the same line the quarantine
     * draws. This is a demo tenant looking at its demo data, and nothing about it is contamination.
     */
    public function test_a_sandbox_accounts_own_rows_are_still_its_contributors(): void
    {
        /*
         * The account is built directly rather than through `TokenVault::open()`: `sandbox` is not a
         * configured OAuth platform, so opening a connection for it throws «No platform configuration
         * for 'sandbox'». What this test needs is an ACCOUNT whose provider is sandbox, and the
         * coverage query reads that column and never the connection behind it.
         */
        $sandboxAccount = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->live->provider_connection_id,
            'provider' => 'sandbox',
            'account_type' => 'ad_account',
            'external_id' => 'act_sandbox',
            'name' => 'Sandbox',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);

        $this->campaign('sbx-own-1', 'sandbox', ['sandbox' => true], $sandboxAccount);

        $this->assertContains(
            'sandbox',
            $this->coverage()['expected_contributors'],
            'a sandbox account stopped being able to report its own figures',
        );
    }
}
