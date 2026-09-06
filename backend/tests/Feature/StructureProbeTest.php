<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-ACCOUNT-CHOICE-001 — «does this ad account hold the business's campaigns?»
 *
 * ## The question the stored diagnosis cannot answer
 *
 * `act_1500383245036671` was bound to Project 1 and returned nothing, while three other discovered
 * Meta accounts sat unbound. `integrations:diagnose` could say nothing about those three — an account
 * that has never been bound has never been synced, so the database holds no answer about it at all.
 * Only the provider does, and nothing here was allowed to ask.
 *
 * The insights probe was the wrong instrument for it: it answers «what did this account spend in a
 * window», and an account silent for thirty days looks exactly like an empty one through a window
 * while holding four years of history behind it.
 *
 * `--structure` asks the campaigns edge and reports identity, counts, statuses and the date range.
 * Read-only, like the rest of this command: the campaigns come back, are counted, and are thrown
 * away. Nothing is imported and no binding is created — choosing an account is the owner's decision,
 * and this exists to inform it rather than to make it.
 */
final class StructureProbeTest extends TestCase
{
    use RefreshDatabase;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $tenant = Tenant::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        Project::create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider' => 'meta',
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'Meta', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_374140991630974', 'name' => 'razzahavenu', 'status' => 'active',
            'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
        ]);
    }

    /** An account with history reports its counts, its statuses and how far back it goes. */
    public function test_it_reports_the_campaign_census_and_date_range(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'Ramadan Sales', 'status' => 'PAUSED', 'start_time' => '2025-03-01T00:00:00+0300', 'stop_time' => '2025-04-01T00:00:00+0300'],
            ['id' => '2', 'name' => 'Always-On', 'status' => 'ACTIVE', 'start_time' => '2026-08-01T00:00:00+0300', 'stop_time' => '2026-09-01T00:00:00+0300'],
        ]], 200)]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--structure' => true])
            ->expectsOutputToContain('STRUCTURE PROBE — nothing is stored by this command.')
            ->expectsOutputToContain('campaigns returned : 2')
            ->expectsOutputToContain('earliest start     : 2025-03-01')
            ->expectsOutputToContain('latest end/updated : 2026-09-01')
            ->expectsOutputToContain('Ramadan Sales')
            ->assertSuccessful();
    }

    /**
     * An empty account says it is empty, in those words.
     *
     * «The provider answered and listed no campaigns» and «the request failed» are different findings
     * with different next steps, and the whole value of this probe is that it keeps them apart — the
     * bound Meta account's `no_data` was the first of those and read for weeks like the second.
     */
    public function test_an_empty_account_is_named_as_empty_rather_than_as_a_failure(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--structure' => true])
            ->expectsOutputToContain('campaigns returned : 0')
            ->expectsOutputToContain('This account is empty')
            ->assertSuccessful();
    }

    /**
     * AD-MEDIA-RECOVERY-001 — «a drawable URL exists» is not acceptance, so `--media` fetches it.
     *
     * The first-page census reports what the PRESENTER would hand the browser — 21 of 24 on the live
     * estate — and the owner still saw blanks, so everything left is downstream of the payload: the
     * request is refused, or the bytes are not an image. Neither can be settled by reading a column.
     *
     * The decisive check is the DECODE, not the status. A CDN whose signature has expired commonly
     * answers 200 with an HTML error page, and a status check calls that healthy while the card stays
     * blank. `getimagesizefromstring` reads the real header, so those bytes report «did not decode».
     */
    public function test_media_that_is_not_an_image_is_reported_as_unusable(): void
    {
        $this->creativeWith('https://cdn.example/a.jpg');

        // 200, and an HTML error page — the shape an expired CDN grant actually returns.
        Http::fake(['cdn.example/*' => Http::response('<html>Access denied</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--media' => true])
            /*
             * One expectation per LINE: `expectsOutputToContain` consumes a write call, so two
             * substrings from the same row silently starve the second. The row line is proven by
             * «did not decode»; the verdict is proven by the counts beneath it.
             */
            ->expectsOutputToContain('did not decode')
            ->expectsOutputToContain('usable stills    : 0')
            ->expectsOutputToContain('unusable         : 1')
            ->assertSuccessful();
    }

    /** ...and real image bytes report their decoded dimensions and count as usable. */
    public function test_real_image_bytes_are_reported_with_their_decoded_size(): void
    {
        $this->creativeWith('https://cdn.example/a.png');

        // A one-pixel PNG: the smallest thing that is genuinely an image.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        Http::fake(['cdn.example/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--media' => true])
            ->expectsOutputToContain('1x1')
            ->expectsOutputToContain('usable stills    : 1')
            ->assertSuccessful();
    }

    /** A refused request is named as refused rather than counted as media. */
    public function test_a_refused_asset_is_counted_as_unusable(): void
    {
        $this->creativeWith('https://cdn.example/a.jpg');

        Http::fake(['cdn.example/*' => Http::response('', 403, ['Content-Type' => 'text/plain'])]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--media' => true])
            ->expectsOutputToContain('403')
            ->expectsOutputToContain('usable stills    : 0')
            ->assertSuccessful();
    }

    /** And it stores nothing — the whole premise of a probe. */
    public function test_the_probe_imports_nothing(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'Ramadan Sales', 'status' => 'PAUSED', 'start_time' => '2025-03-01T00:00:00+0300'],
        ]], 200)]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--structure' => true])->assertSuccessful();

        $this->assertDatabaseCount('external_campaigns', 0);
    }

    /**
     * The probe answers for ONE provider, because the project holds several.
     *
     * The first cut scoped only by «the project this binding points at». Project 1 holds both the
     * Meta account and the Snapchat one, so probing either returned the same twelve rows — identical
     * names, identical byte counts, identical object ids, in identical order. It read like a finding
     * about both providers and was one query answering one question twice. Two accounts, two
     * answers, or the output is worthless.
     */
    public function test_it_probes_only_the_provider_of_the_account_it_was_given(): void
    {
        $this->creativeWith('https://cdn.example/meta.png');
        $this->creativeWith('https://cdn.example/snap.png', provider: 'snapchat', id: 'cr-snap-1', name: 'A Snapchat creative');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['cdn.example/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--media' => true])
            ->doesntExpectOutputToContain('A Snapchat creative')
            ->expectsOutputToContain('usable stills    : 1')
            ->assertSuccessful();
    }

    /**
     * AD-PREVIEW-001 — «usable» means the still the CARD draws, not any byte stream on the row.
     *
     * The probe used to fetch `image_url ?? thumbnail_url ?? video_url`, which is a question no
     * surface asks. A creative whose kind is an image while only a film resolved has no still, so the
     * card draws an absence — and the old probe fetched the mp4, saw 200 and `video/mp4`, and called
     * it USABLE. The probe then reported health for precisely the card the owner was staring at
     * blank, which makes it worse than no probe at all.
     *
     * Injecting the old rule — accepting `video/` as media — fails this case.
     */
    public function test_a_film_is_never_counted_as_the_still_a_card_draws(): void
    {
        $project = Project::withoutGlobalScopes()->firstOrFail();
        $this->bind();

        ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->account->tenant_id,
            'project_id' => $project->id,
            'provider' => 'meta',
            'external_creative_id' => 'cr-film-1',
            'name' => 'A film with no cover',
            /* The platform's label says image; the only thing it handed over is a film. */
            'format' => 'image',
            'video_url' => 'https://cdn.example/a.mp4',
            'source_type' => 'api',
        ]);

        Http::fake(['cdn.example/*' => Http::response('....', 200, ['Content-Type' => 'video/mp4'])]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--media' => true])
            /*
             * A film with no cover is NOT counted as a card that draws nothing: the library grid
             * mounts a player for exactly this row, and every other surface has a written sentence
             * for it. What is asserted is that the probe never fetches the mp4 as if it were a still.
             */
            ->expectsOutputToContain('no cover — the surface plays the film instead')
            ->expectsOutputToContain('usable stills    : 0')
            ->expectsOutputToContain('no still         : 0')
            ->assertSuccessful();
    }

    /** A video's POSTER is fetched — an `<img>` cannot draw an mp4, so the poster is the still. */
    public function test_a_video_with_a_cover_has_its_poster_fetched_and_not_its_film(): void
    {
        $project = Project::withoutGlobalScopes()->firstOrFail();
        $this->bind();

        ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->account->tenant_id,
            'project_id' => $project->id,
            'provider' => 'meta',
            'external_creative_id' => 'cr-film-2',
            'name' => 'A film with a cover',
            'format' => 'video',
            'video_url' => 'https://cdn.example/a.mp4',
            'thumbnail_url' => 'https://cdn.example/cover.png',
            'source_type' => 'api',
        ]);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        Http::fake([
            'cdn.example/cover.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'cdn.example/a.mp4' => Http::response('....', 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->artisan('integrations:probe', ['account' => 'act_374140991630974', '--media' => true])
            ->expectsOutputToContain('cover.png')
            ->expectsOutputToContain('usable stills    : 1')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'a.mp4'));
    }

    private function creativeWith(string $asset, string $provider = 'meta', string $id = 'cr-media-1', string $name = 'A creative'): void
    {
        $this->bind();

        ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->account->tenant_id,
            'project_id' => Project::withoutGlobalScopes()->firstOrFail()->id,
            'provider' => $provider,
            'external_creative_id' => $id,
            'name' => $name,
            'format' => 'image',
            'asset_url' => $asset,
            'source_type' => 'api',
        ]);
    }

    /** The binding is what makes the account's project findable; created once, idempotently. */
    private function bind(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->firstOrCreate([
            'tenant_id' => $this->account->tenant_id,
            'project_id' => Project::withoutGlobalScopes()->firstOrFail()->id,
            'external_account_id' => $this->account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
        ], ['is_active' => true]);
    }
}
