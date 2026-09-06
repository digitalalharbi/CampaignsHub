<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\ImportExternalStructure;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONTENT-PREVIEW-SHAPES-001 — «بعض الصور» had no preview because they were never images.
 *
 * `external_creatives.format` was `NOT NULL DEFAULT 'image'` and the importer wrote
 * `(string) ($creative['format'] ?? 'image')`. A connector that could not map a platform's creative
 * type deliberately emits nothing — and BOTH layers turned that silence into a claim. The ad became
 * an image, `CreativePresenter::kind()` looked for a still, found none, and told the reader «This ad
 * was fetched from the platform, and the platform exposed no asset for it».
 *
 * Every word of that is wrong. The platform described a shape; we overwrote it with a default and
 * then blamed the platform for the consequences.
 *
 * ## Why this is asserted at the importer and not only at the connector
 *
 * The connector was fixed to keep the platform's word — that is `SnapchatCreativeAssetsTest`. But a
 * connector cannot be the only guard: five other adapters write through the same importer, and the
 * default fired for every one of them. The rule belongs where the write happens.
 */
final class CreativeFormatIsNotInventedTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'F', 'slug' => 'f-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /** A creative whose connector stated no format is stored as «we do not know». */
    public function test_an_unstated_format_is_stored_as_null(): void
    {
        $creative = $this->import(['external_id' => 'cr-1', 'name' => 'Unmapped shape']);

        $this->assertNull($creative->format, 'A format nobody stated must not be asserted by us.');
    }

    /**
     * ...and it does NOT read as an image on the surface.
     *
     * The row being null is only half the fix: what the reader is told is the other half, and the
     * presenter must reach an honest answer from it rather than falling into the still-image path.
     */
    public function test_an_unstated_format_does_not_present_as_an_image(): void
    {
        $preview = app(CreativePresenter::class)->preview($this->import(['external_id' => 'cr-2', 'name' => 'Unmapped shape']));

        $this->assertNotSame('image', $preview['kind'], 'An ad nobody called an image must not be presented as one.');
        $this->assertSame('other', $preview['kind']);
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — when the label and the asset disagree, the asset wins.
     *
     * The live first-page census found «[snapchat/image] available  image=no thumb=no video=yes»: a
     * row the platform labelled an image, on which the only thing that ever resolved is a film.
     * `format` won outright, so the reading was `image`, the card looked for a still, found none,
     * and drew «the platform sent no file for it» over a video that had arrived and would play.
     *
     * That is one of the owner's blank rectangles, and it is the weaker evidence winning: a label the
     * platform wrote ABOUT the ad, beating a file it actually handed over.
     */
    public function test_a_film_is_not_called_an_image_because_the_label_said_so(): void
    {
        $creative = $this->import([
            'external_id' => 'cr-film', 'name' => 'Labelled an image, delivered as a film',
            'format' => 'image', 'video_url' => 'https://cdn.example/a.mp4',
        ]);

        $preview = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('video', $preview['kind'], 'The only asset on this row is a film.');
        $this->assertSame('available', $preview['state']);
    }

    /**
     * ...and the label still wins where it is the only tie-breaker there is.
     *
     * An image ad with a preview clip has both, and calling every one of those a video would trade
     * one wrong reading for another. The rule above is deliberately narrow: it fires only when
     * nothing still-shaped resolved at all.
     */
    public function test_an_image_that_also_has_a_clip_is_still_an_image(): void
    {
        $creative = $this->import([
            'external_id' => 'cr-both', 'name' => 'An image with a clip',
            'format' => 'image',
            'asset_url' => 'https://cdn.example/a.png',
            'video_url' => 'https://cdn.example/a.mp4',
        ]);

        $this->assertSame('image', app(CreativePresenter::class)->preview($creative)['kind']);
    }

    /** A format the connector DID state is written exactly, and never rounded to a known word. */
    public function test_a_stated_format_is_written_verbatim(): void
    {
        $creative = $this->import(['external_id' => 'cr-3', 'name' => 'A story', 'format' => 'composite']);

        $this->assertSame('composite', $creative->format);
    }

    /**
     * Driven through the private writer by reflection, deliberately.
     *
     * `execute()` needs an account, a campaign row and an ads list to reach the creative writer, and
     * three tables of scaffolding around a one-column claim would test the scaffolding. What is being
     * held here is one rule at one line: an unstated format is not written as an image.
     *
     * @param  array<string,mixed>  $creative
     */
    private function import(array $creative): ExternalCreative
    {
        /*
         * The scaffolding is what the schema insists on, no more: a credential, a connection and an
         * account so a campaign row can exist, because `creativeFor` reads the account's provider and
         * the campaign's ids. What is being HELD is one rule at one line — an unstated format is not
         * written as an image.
         */
        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider' => 'snapchat',
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snapchat — '.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'act-'.uniqid(), 'name' => 'An account', 'status' => 'active',
        ]);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat',
            'external_id' => 'cmp-'.uniqid(),
            'name' => 'A campaign',
            'status' => 'active',
        ]);

        $counts = ['ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'skipped' => 0];

        $method = new \ReflectionMethod(ImportExternalStructure::class, 'creativeFor');
        $method->setAccessible(true);
        $method->invokeArgs(app(ImportExternalStructure::class), [$account, $campaign, $creative, 'ad-1', &$counts]);

        return ExternalCreative::withoutGlobalScopes()
            ->where('external_creative_id', $creative['external_id'])
            ->firstOrFail();
    }
}
