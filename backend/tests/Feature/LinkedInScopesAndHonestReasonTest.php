<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\GrantedScopes;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\IntegrationStatusResolver;
use App\Domains\Integrations\Status\ConnectionFacts;
use App\Domains\Integrations\Support\InsightsAuthorisation;
use App\Domains\Integrations\Support\IntegrationTruth;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LINKEDIN-SCOPES-001 — proven on Production 2026-11-13 (run 43364117427): the consent URL asked only for
 * `r_ads r_ads_reporting`, so all four LinkedIn tokens hold exactly that, and the Posts API answered
 * «Not enough permissions to access: partnerApiPostsExternal.BATCH_GET.20260701» for every sponsored post.
 * Approving products on the LinkedIn app changes nothing for a token that never asked for them.
 *
 * So: ask for the scopes (one list, the catalogue's), read the grant before calling the Posts API and say
 * «reconnect» when it is absent, keep LinkedIn's own sentence when it refuses anyway, read LinkedIn's
 * comma-separated grant correctly, and put the reconnect prompt on the integrations hub row.
 */
final class LinkedInScopesAndHonestReasonTest extends TestCase
{
    use RefreshDatabase;

    private const RECONNECT_AR = 'أعد ربط لينكدإن لمنح صلاحية قراءة المنشورات';

    private Tenant $tenant;

    private ?ProviderConnection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    public function test_the_linkedin_consent_asks_for_the_post_and_media_scopes(): void
    {
        $this->configure();

        $url = app(PlatformOAuth::class)->authorizationUrl(PlatformCredentials::for('linkedin'), 'STATE');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('r_ads r_ads_reporting r_organization_social rw_ads', $query['scope'] ?? null);
    }

    public function test_there_is_one_linkedin_scope_list_not_two(): void
    {
        $this->assertNull(config('ad_platforms.platforms.linkedin.scopes'), 'the catalogue is the one source; a second list in config only misleads');
    }

    public function test_linkedins_comma_separated_grant_is_read_as_separate_scopes(): void
    {
        $this->assertSame(['r_ads', 'r_ads_reporting'], GrantedScopes::parse('r_ads,r_ads_reporting'));
        $this->assertSame(['r_ads', 'r_ads_reporting'], GrantedScopes::parse('r_ads r_ads_reporting'));
        // The shape already stored on Production: one element holding both.
        $this->assertSame(['r_ads', 'r_ads_reporting'], GrantedScopes::parse(['r_ads,r_ads_reporting']));
        $this->assertSame([], GrantedScopes::parse(null));

        $this->configure();
        $c = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'linkedin',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDay(), 'r_ads,r_ads_reporting'),
            connectionName: 'linkedin',
        );

        $this->assertSame(['r_ads', 'r_ads_reporting'], $c->fresh()->scopes);
        $this->assertSame(['r_ads', 'r_ads_reporting', 'r_organization_social', 'rw_ads'], $c->fresh()->requested_scopes);
    }

    public function test_a_consent_that_returned_no_scope_records_the_request_apart_and_the_grant_as_unknown(): void
    {
        $this->configure();
        $tokens = new OAuthTokens('AT', 'RT', Carbon::now()->addDay(), null);
        $c = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'linkedin', tokens: $tokens, connectionName: 'linkedin');

        $this->assertSame([], $c->fresh()->scopes, 'what was asked for is not what was granted');
        $this->assertSame(['r_ads', 'r_ads_reporting', 'r_organization_social', 'rw_ads'], $c->fresh()->requested_scopes);
        $this->assertSame([], GrantedScopes::missing($c->fresh()), 'an unknown grant prompts nobody to reconnect');

        // A re-consent that returns a grant replaces the unknown; one that returns none makes it unknown again.
        $again = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'linkedin',
            tokens: new OAuthTokens('AT2', 'RT', Carbon::now()->addDay(), 'r_ads,r_ads_reporting'), connectionName: 'linkedin');
        $this->assertSame($c->id, $again->id);
        $this->assertSame(['r_organization_social', 'rw_ads'], GrantedScopes::missing($again->fresh()));

        app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'linkedin', tokens: $tokens, connectionName: 'linkedin');
        $this->assertSame([], $c->fresh()->scopes);
    }

    public function test_a_token_without_the_post_scope_is_not_sent_to_the_posts_api_and_says_reconnect(): void
    {
        Http::fake(fn (Request $r) => str_contains($r->url(), '/posts')
            ? Http::response(['status' => 403, 'message' => 'Not enough permissions to access: partnerApiPostsExternal.BATCH_GET.20260701'], 403)
            : Http::response(['elements' => [
                ['id' => 'urn:li:sponsoredCreative:2', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                    'content' => ['reference' => 'urn:li:share:77']],
            ]]));

        $creative = $this->linkedinAds('r_ads,r_ads_reporting')[0]['creative'];

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/posts'));
        $this->assertSame('linkedin_reconnect_for_posts', $creative['raw']['media_reason']);
        $this->assertSame('urn:li:share:77', $creative['raw']['reference']);
        $this->assertArrayNotHasKey('thumbnail_url', $creative);
    }

    public function test_a_refusal_with_the_scope_granted_keeps_linkedins_own_words(): void
    {
        Http::fake(fn (Request $r) => str_contains($r->url(), '/posts')
            ? Http::response(['status' => 403, 'serviceErrorCode' => 100, 'code' => 'ACCESS_DENIED', 'message' => 'Not enough permissions to access: partnerApiPostsExternal.BATCH_GET.20260701'], 403)
            : Http::response(['elements' => [
                ['id' => 'urn:li:sponsoredCreative:2', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                    'content' => ['reference' => 'urn:li:share:77']],
            ]]));

        $creative = $this->linkedinAds('r_ads,r_ads_reporting,r_organization_social,rw_ads')[0]['creative'];

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/posts'));
        $this->assertSame('linkedin_post_scope_missing', $creative['raw']['media_reason']);
        $this->assertSame('Not enough permissions to access: partnerApiPostsExternal.BATCH_GET.20260701', $creative['raw']['provider_message']);
    }

    public function test_an_unknown_grant_still_asks_linkedin_rather_than_guessing(): void
    {
        Http::fake(fn (Request $r) => str_contains($r->url(), '/posts')
            ? Http::response(['results' => ['urn:li:share:77' => ['id' => 'urn:li:share:77', 'commentary' => 'Hello']]])
            : Http::response(['elements' => [
                ['id' => 'urn:li:sponsoredCreative:2', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                    'content' => ['reference' => 'urn:li:share:77']],
            ]]));

        $creative = $this->linkedinAds(null)[0]['creative'];

        $this->assertSame('Hello', $creative['body']);
        $this->assertArrayNotHasKey('media_reason', $creative['raw'] ?? []);
    }

    public function test_the_card_asks_for_a_reconnect_and_repeats_linkedins_refusal(): void
    {
        $presenter = app(CreativePresenter::class);

        $reconnect = $presenter->preview(new ExternalCreative([
            'provider' => 'linkedin', 'name' => 'Creative 2', 'format' => null,
            'raw' => ['media_reason' => 'linkedin_reconnect_for_posts', 'reference' => 'urn:li:share:77'],
        ]));
        $this->assertSame('unavailable', $reconnect['state']);
        $this->assertSame(self::RECONNECT_AR, $reconnect['note_ar']);

        $refused = $presenter->preview(new ExternalCreative([
            'provider' => 'linkedin', 'name' => 'Creative 2', 'format' => null,
            'raw' => ['media_reason' => 'linkedin_post_scope_missing', 'reference' => 'urn:li:share:77',
                'provider_message' => 'Not enough permissions to access: partnerApiPostsExternal.BATCH_GET.20260701'],
        ]));
        $this->assertStringContainsString('partnerApiPostsExternal.BATCH_GET.20260701', (string) $refused['note_ar']);
        $this->assertStringContainsString('partnerApiPostsExternal.BATCH_GET.20260701', (string) $refused['note_en']);
    }

    public function test_the_hub_names_the_missing_linkedin_scopes_so_the_row_asks_for_a_reconnect(): void
    {
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'Admin', 'email' => 'admin@workspace.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        // Exactly the Production shape: LinkedIn returned «r_ads,r_ads_reporting»; the column holds it as one element.
        $old = $this->hubConnection('linkedin', 'r_ads,r_ads_reporting', ['r_ads,r_ads_reporting']);
        $granted = $this->hubConnection('linkedin', 'r_ads,r_ads_reporting,r_organization_social,rw_ads', ['r_ads', 'r_ads_reporting', 'r_organization_social', 'rw_ads']);
        $unknown = $this->hubConnection('linkedin', null, []);
        // A row written before LINKEDIN-SCOPES-001 with no returned grant: its column restates the request. Not proof.
        $legacyFallback = $this->hubConnection('linkedin', null, ['r_ads', 'r_ads_reporting']);
        $other = $this->hubConnection('snapchat', 'snapchat-marketing-api', ['snapchat-marketing-api']);

        $rows = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations/hub')->assertOk()->json('data.connections'))->keyBy('id');

        $this->assertSame(['r_organization_social', 'rw_ads'], $rows[(string) $old->id]['missing_scopes']);
        $this->assertSame([], $rows[(string) $granted->id]['missing_scopes']);
        $this->assertSame([], $rows[(string) $unknown->id]['missing_scopes'], 'an unrecorded grant is not a missing one');
        $this->assertSame([], $rows[(string) $legacyFallback->id]['missing_scopes'], 'a restated request is not a proven grant');
        $this->assertSame([], $rows[(string) $other->id]['missing_scopes']);
    }

    public function test_an_unknown_meta_grant_still_answers_from_what_was_requested(): void
    {
        // Meta returns no scope: the grant is unknown, and a request that named no read scope still cannot read insights.
        $never = new ProviderConnection(['provider' => 'meta', 'scopes' => [], 'requested_scopes' => ['business_management']]);
        $asked = new ProviderConnection(['provider' => 'meta', 'scopes' => [], 'requested_scopes' => ['ads_read', 'business_management']]);

        $this->assertFalse(InsightsAuthorisation::grantedBy('meta', ConnectionFacts::fromModel($never)->scopes));
        $this->assertTrue(InsightsAuthorisation::grantedBy('meta', ConnectionFacts::fromModel($asked)->scopes));
    }

    /**
     * SyncSweepCharacterisationTest records `scopes = []` for Meta and Snapchat now that the vault no longer restates the
     * request as a grant. An UNKNOWN grant must not read as a missing one: every status these connections show stays as it was.
     */
    public function test_meta_and_snapchat_with_no_returned_scope_still_read_as_connected_with_nothing_missing(): void
    {
        foreach (['meta', 'snapchat'] as $provider) {
            foreach (PlatformCredentials::for($provider)->requires() as $key) {
                config()->set("ad_platforms.platforms.{$provider}.{$key}", "test-{$key}");
            }
            $connection = app(TokenVault::class)->open(
                tenantId: $this->tenant->id,
                provider: $provider,
                tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDays(30), null),
                connectionName: $provider,
            );

            $this->assertSame([], $connection->scopes, "{$provider}: no grant returned is recorded as unknown");
            $this->assertNotSame([], $connection->requested_scopes, "{$provider}: the request is kept apart");
            $this->assertSame(
                IntegrationTruth::CONNECTED,
                IntegrationStatusResolver::connectionTruth(ConnectionFacts::fromModel($connection)),
                "{$provider}: an unknown grant must not read as «reconnect»",
            );
            $this->assertSame([], GrantedScopes::missing($connection), "{$provider}: no reconnect prompt");
        }
    }

    /** @return list<array<string,mixed>> */
    private function linkedinAds(?string $grantedScope): array
    {
        $this->configure();
        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'linkedin',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay(), $grantedScope),
            connectionName: 'linkedin',
        );
        /** @var ApiAdvertisingConnector $c */
        $c = app(AdvertisingConnectorRegistry::class)->get('linkedin')->withConnection($this->connection);

        return (new \ReflectionMethod($c, 'fetchAds'))->invoke($c, app(TokenVault::class)->stored($this->connection), '123');
    }

    private function configure(): void
    {
        foreach (PlatformCredentials::for('linkedin')->requires() as $key) {
            config()->set("ad_platforms.platforms.linkedin.{$key}", "test-{$key}");
        }
    }

    /** @param list<string> $scopes */
    private function hubConnection(string $provider, ?string $tokenScope, array $scopes): ProviderConnection
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'tenant_shared', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->forceFill(['tenant_id' => $this->tenant->id]);
        $credential->setPayload(json_encode(['access_token' => 'tok', 'refresh_token' => null, 'expires_at' => null, 'scope' => $tokenScope, 'raw' => []]));
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => ucfirst($provider).uniqid(), 'scope' => 'tenant_shared', 'status' => 'connected', 'scopes' => $scopes,
        ]);
    }
}
