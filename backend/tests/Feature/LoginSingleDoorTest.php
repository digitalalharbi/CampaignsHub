<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Models\OAuthIdentity;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Requests\Services\RequestConversionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Models\User;
use App\Support\Frontend;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LOGIN-SINGLE-DOOR-001 — one sign-in, and the server decides where it leads.
 *
 * Every case signs in over HTTP through `/auth/login` and asks `/auth/memberships`, the two requests the browser makes.
 */
final class LoginSingleDoorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    /** @var array<string, string> */
    private array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
    }

    private function tenant(string $status = 'active', bool $onboarded = true): Tenant
    {
        $tenant = Tenant::create(['name' => 'T '.Str::random(5), 'slug' => 't-'.Str::lower(Str::random(8)), 'status' => $status]);
        $tenant->forceFill(['onboarding_completed_at' => $onboarded ? now() : null])->save();

        return $tenant;
    }

    private function person(string $email, array $attributes = []): User
    {
        return User::factory()->create(['email' => $email, 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(), ...$attributes]);
    }

    private function holds(User $user, Portal $portal, ?Tenant $tenant = null, bool $default = true, ?string $role = null): Membership
    {
        return Membership::query()->create([
            'tenant_id' => ($tenant ?? $this->tenant())->id, 'user_id' => $user->id, 'portal' => $portal->value,
            'status' => 'active', 'is_default' => $default, 'role' => $role ?? 'owner',
        ]);
    }

    private function clientOf(Tenant $tenant): ClientWorkspace
    {
        return ClientWorkspace::query()->withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'name' => 'Client', 'slug' => 'c-'.Str::lower(Str::random(8))]);
    }

    private function nameOnARequest(string $email, Tenant $tenant, ?ClientWorkspace $client = null): ExternalRequest
    {
        return ExternalRequest::create([
            'tenant_id' => $tenant->id, 'reference' => 'REQ-'.Str::upper(Str::random(8)),
            'type_id' => RequestType::query()->firstOrFail()->id,
            'status_id' => RequestStatus::query()->where('key', 'new')->firstOrFail()->id,
            'client_id' => $client?->id,
            'contact_name' => 'A Contact', 'contact_email' => $email, 'contact_phone' => '+966500000000', 'submitted_at' => now(),
        ]);
    }

    private function application(string $email, AccountState $state): RegistrationRequest
    {
        $application = new RegistrationRequest;
        $application->forceFill([
            'email' => $email, 'name' => 'A', 'tenant_name' => 'Applied Co', 'account_type' => 'brand',
            'requested_portal' => 'app', 'plan_code' => 'growth', 'password' => Hash::make(self::PASSWORD),
            'state' => $state->value, 'email_verified_at' => now(),
        ])->save();

        return $application;
    }

    private function signIn(string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    private function destination(?string $intended = null): string
    {
        $query = $intended === null ? '' : '?intended='.rawurlencode($intended);
        $response = $this->withHeaders($this->spa)->getJson('/api/v1/auth/memberships'.$query);
        $response->assertOk();

        return (string) $response->json('data.destination');
    }

    private function issuedLink(string $email): string
    {
        // The secret is only ever in the mail; the test swaps in one it knows, on the row the real service wrote.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertOk();
        $secret = Str::random(64);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $email)->update(['token' => hash('sha256', $secret)]));

        return $secret;
    }

    private function spend(string $email, string $secret, string $password = self::PASSWORD): TestResponse
    {
        return $this->withHeaders($this->spa)->postJson('/api/v1/auth/reset-password', [
            'email' => $email, 'token' => $secret, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_a_platform_admin_lands_in_the_console(): void
    {
        $this->person('admin@door.test', ['is_platform_admin' => true]);

        $this->signIn('admin@door.test')->assertOk();

        $this->assertSame('/admin', $this->destination());
    }

    public function test_an_advertiser_lands_in_the_app_portal(): void
    {
        $this->holds($this->person('adv@door.test'), Portal::App);

        $this->signIn('adv@door.test')->assertOk();

        $this->assertSame(Portal::App->landingPath(), $this->destination());
    }

    public function test_an_agency_operator_lands_in_the_agency_portal(): void
    {
        $this->holds($this->person('ag@door.test'), Portal::Agency);

        $this->signIn('ag@door.test')->assertOk();

        $this->assertSame(Portal::Agency->landingPath(), $this->destination());
    }

    public function test_a_client_portal_member_lands_in_the_portal_with_their_access_unchanged(): void
    {
        $tenant = $this->tenant();
        $client = $this->clientOf($tenant);
        $user = $this->person('member@door.test');
        $membership = $this->holds($user, Portal::ClientPortal, $tenant, role: 'client_viewer');
        MembershipScope::query()->create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_CLIENT, 'scope_id' => (string) $client->id]);

        $this->signIn('member@door.test')->assertOk();

        $this->assertSame('/portal', $this->destination());
        $this->withHeaders($this->spa)->getJson('/api/v1/client/session')->assertOk();

        $after = $membership->fresh()?->load('scopes');
        $this->assertSame('client_viewer', $after?->role);
        $this->assertSame([(string) $client->id], $after?->clientScopeIds());
    }

    /** The agency's own unfinished setup is not a step for its client: they were being sent to a wizard. */
    public function test_a_client_portal_member_is_not_sent_to_the_wizard_of_an_agency_not_yet_set_up(): void
    {
        $this->holds($this->person('m2@door.test'), Portal::ClientPortal, $this->tenant(onboarded: false), role: 'client_viewer');

        $this->signIn('m2@door.test')->assertOk();

        $this->assertSame('/portal', $this->destination());
    }

    public function test_an_operator_of_an_unfinished_workspace_is_still_sent_to_the_wizard(): void
    {
        $this->holds($this->person('new@door.test'), Portal::Agency, $this->tenant(onboarded: false));

        $this->signIn('new@door.test')->assertOk();

        $this->assertSame('/onboarding', $this->destination());
    }

    public function test_only_a_person_holding_several_workspaces_goes_to_the_switcher(): void
    {
        $two = $this->person('two@door.test');
        $this->holds($two, Portal::App);
        $this->holds($two, Portal::Agency, default: false);
        $this->signIn('two@door.test')->assertOk();
        $this->assertSame('/switch', $this->destination());
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->assertJsonPath('data.needs_switcher', true);

        Auth::guard('web')->logout();
        $this->app['auth']->forgetGuards();

        $this->holds($this->person('one@door.test'), Portal::App);
        $this->signIn('one@door.test')->assertOk();
        $this->assertSame(Portal::App->landingPath(), $this->destination());
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->assertJsonPath('data.needs_switcher', false);
    }

    public function test_signing_in_moves_no_workspace_and_a_held_portal_named_in_a_link_leaves_the_session_where_it_is(): void
    {
        $two = $this->person('stay@door.test');
        $home = $this->holds($two, Portal::App);
        $other = $this->holds($two, Portal::Agency, default: false);
        $this->signIn('stay@door.test')->assertOk();

        // Honoured, because they hold it: the portal's guard then asks before anything moves (#945).
        $this->assertSame('/agency/clients', $this->destination('/agency/clients'));

        $state = $this->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->json('data');
        $this->assertSame((string) $home->id, $state['current']['id'] ?? null);
        $this->assertNotSame((string) $other->id, $state['current']['id'] ?? null);
    }

    /** @return array<string, array{0: AccountState}> */
    public static function waitingStates(): array
    {
        return [
            'pending approval' => [AccountState::PendingApproval],
            'awaiting payment' => [AccountState::ApprovedAwaitingPayment],
            'payment pending' => [AccountState::PaymentPending],
            'mobile not proved' => [AccountState::MobileVerificationRequired],
        ];
    }

    #[DataProvider('waitingStates')]
    public function test_an_application_still_waiting_goes_to_its_status_whatever_the_link_says(AccountState $state): void
    {
        $this->person('applicant@door.test');
        $application = $this->application('applicant@door.test', $state);

        $this->signIn('applicant@door.test')->assertOk();

        $expected = '/signup/status?request='.$application->getKey();
        $this->assertSame($expected, $this->destination());
        $this->assertSame($expected, $this->destination('/app/dashboard'));
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $this->holds($this->person('off@door.test', ['disabled_at' => now()]), Portal::App);

        $this->signIn('off@door.test')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_an_account_whose_only_workspace_is_suspended_cannot_sign_in(): void
    {
        $this->holds($this->person('susp@door.test'), Portal::App, $this->tenant('suspended'));

        $this->signIn('susp@door.test')->assertForbidden();
        $this->assertGuest('web');
    }

    public function test_a_suspended_workspace_is_never_the_destination_of_someone_who_holds_another(): void
    {
        $user = $this->person('mixed@door.test');
        $this->holds($user, Portal::Agency, $this->tenant('suspended'));
        $this->holds($user, Portal::App, default: false);

        $this->signIn('mixed@door.test')->assertOk();

        $this->assertSame(Portal::App->landingPath(), $this->destination());
        $this->assertSame(Portal::App->landingPath(), $this->destination('/agency/clients'));
    }

    public function test_with_no_session_the_server_says_so_rather_than_naming_a_destination(): void
    {
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/memberships?intended=%2Fapp%2Fdashboard')->assertUnauthorized();
    }

    public function test_the_very_next_request_after_signing_in_is_not_refused(): void
    {
        $this->holds($this->person('next@door.test'), Portal::App);

        $this->signIn('next@door.test')->assertOk();

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->assertOk();
    }

    /** @return array<string, array{0: string}> */
    public static function refusedDestinations(): array
    {
        return [
            'absolute' => ['https://evil.example/x'],
            'protocol relative' => ['//evil.example/x'],
            'encoded protocol relative' => ['%2F%2Fevil.example'],
            'backslash' => ['/\\evil.example'],
            'encoded backslash' => ['/%5Cevil.example'],
            'new line' => ["/app\r\nLocation: https://evil.example"],
            'encoded new line' => ['/app%0d%0aLocation:%20https://evil.example'],
            'javascript' => ['javascript:alert(1)'],
            'no leading slash' => ['app/dashboard'],
            'empty' => [''],
            'login' => ['/login'],
            'login with a query' => ['/login?redirect=%2Flogin'],
            'old door' => ['/portal/login'],
            'older door' => ['/client/login'],
            'admin door' => ['/admin/login'],
            'register' => ['/register'],
            'forgot password' => ['/forgot-password'],
            'reset password' => ['/reset-password?token=x'],
            'console' => ['/admin'],
            'console page' => ['/admin/tenants'],
            'portal not held' => ['/agency/clients'],
            'client portal not held' => ['/portal/requests'],
            'client portal by its older address' => ['/client/requests'],
            'upper case portal' => ['/AGENCY/clients'],
            'withdrawn portal' => ['/influencers'],
        ];
    }

    #[DataProvider('refusedDestinations')]
    public function test_a_destination_this_person_may_not_have_leads_to_their_own_landing(string $intended): void
    {
        $this->holds($this->person('adv2@door.test'), Portal::App);
        $this->signIn('adv2@door.test')->assertOk();

        $this->assertSame(Portal::App->landingPath(), $this->destination($intended));
    }

    /** @return array<string, array{0: string}> */
    public static function allowedDestinations(): array
    {
        return [
            'own portal' => ['/app/campaigns'],
            'own portal with a query' => ['/app/campaigns?tab=active#row'],
            'outside every portal' => ['/account/security'],
            'the switcher' => ['/switch'],
        ];
    }

    #[DataProvider('allowedDestinations')]
    public function test_a_destination_this_person_may_enter_is_kept(string $intended): void
    {
        $this->holds($this->person('adv3@door.test'), Portal::App);
        $this->signIn('adv3@door.test')->assertOk();

        $this->assertSame($intended, $this->destination($intended));
    }

    public function test_a_portal_destination_is_kept_for_a_member_and_refused_for_the_console(): void
    {
        $this->holds($this->person('m3@door.test'), Portal::ClientPortal, role: 'client_viewer');
        $this->signIn('m3@door.test')->assertOk();

        $this->assertSame('/portal/requests', $this->destination('/portal/requests'));
        $this->assertSame('/portal', $this->destination('/admin'));
    }

    public function test_the_console_keeps_its_own_pages_for_its_owner_and_no_tenant_portal(): void
    {
        $this->person('owner@door.test', ['is_platform_admin' => true]);
        $this->signIn('owner@door.test')->assertOk();

        $this->assertSame('/admin/tenants', $this->destination('/admin/tenants'));
        $this->assertSame('/admin', $this->destination('/agency/clients'));
    }

    /** No destination the server gives may be a sign-in page: that is the whole of the redirect loop. */
    public function test_the_landing_of_every_role_is_never_a_sign_in_page(): void
    {
        foreach ([Portal::App, Portal::Agency, Portal::ClientPortal] as $portal) {
            $this->assertNotNull(PortalResolver::safeInAppPath($portal->landingPath()), $portal->value);
        }
        $this->assertNotNull(PortalResolver::safeInAppPath(Portal::Admin->landingPath()));
    }

    /** @return array<string, array{0: Portal}> */
    public static function everyPortal(): array
    {
        return ['app' => [Portal::App], 'agency' => [Portal::Agency], 'client portal' => [Portal::ClientPortal]];
    }

    #[DataProvider('everyPortal')]
    public function test_signing_out_from_any_portal_ends_the_session(Portal $portal): void
    {
        $this->holds($this->person('out@door.test'), $portal, role: $portal === Portal::ClientPortal ? 'client_viewer' : null);
        $this->signIn('out@door.test')->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    #[DataProvider('everyPortal')]
    public function test_recovery_works_from_any_portal_and_leads_back_to_the_same_landing(Portal $portal): void
    {
        Mail::fake();
        $user = $this->person('rec@door.test');
        $this->holds($user, $portal, role: $portal === Portal::ClientPortal ? 'client_viewer' : null);

        $this->spend('rec@door.test', $this->issuedLink('rec@door.test'), 'a-brand-new-password')->assertOk();

        $this->signIn('rec@door.test', self::PASSWORD)->assertStatus(422);
        $this->signIn('rec@door.test', 'a-brand-new-password')->assertOk();
        $this->assertSame($portal->landingPath(), $this->destination());
    }

    public function test_a_member_who_never_held_a_usable_password_recovers_and_keeps_exactly_their_access(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $client = $this->clientOf($tenant);
        $user = User::factory()->create(['email' => 'nopass@door.test', 'password' => Str::random(64), 'email_verified_at' => now()]);
        $membership = $this->holds($user, Portal::ClientPortal, $tenant, role: 'client_viewer');
        MembershipScope::query()->create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_CLIENT, 'scope_id' => (string) $client->id]);
        MembershipScope::query()->create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_PROJECT, 'scope_id' => (string) Str::uuid()]);
        $before = DB::table('membership_scopes')->where('membership_id', $membership->id)->orderBy('scope_id')->get(['scope_type', 'scope_id'])->toArray();

        $this->spend('nopass@door.test', $this->issuedLink('nopass@door.test'))->assertOk();
        $this->signIn('nopass@door.test')->assertOk();

        $this->assertSame('/portal', $this->destination());
        $this->assertEquals($before, DB::table('membership_scopes')->where('membership_id', $membership->id)->orderBy('scope_id')->get(['scope_type', 'scope_id'])->toArray());
        $this->assertSame('client_viewer', $membership->fresh()?->role);
        $this->assertSame(1, Membership::query()->where('user_id', $user->id)->count());
    }

    public function test_asking_for_recovery_for_a_contact_creates_nothing_until_the_link_is_spent(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'services.notifications.email_enabled' => true]);
        $tenant = $this->tenant();
        $this->nameOnARequest('contact@door.test', $tenant, $this->clientOf($tenant));

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/forgot-password', ['email' => 'contact@door.test'])->assertOk();

        $this->assertNull(User::query()->where('email', 'contact@door.test')->first(), 'an unproved address opened an account');
        $this->assertSame('contact_setup', DB::table('password_reset_tokens')->where('email', 'contact@door.test')->value('purpose'));
        $this->assertNotNull(DB::table('mail_deliveries')->where('recipient', 'contact@door.test')->first(), 'nothing tried to reach the contact');
    }

    public function test_a_contact_spends_the_link_signs_in_at_the_one_door_and_sees_what_their_code_showed(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $client = $this->clientOf($tenant);
        $mine = $this->nameOnARequest('contact2@door.test', $tenant, $client);
        $theirs = $this->nameOnARequest('stranger@door.test', $tenant, $this->clientOf($tenant));

        $this->spend('contact2@door.test', $this->issuedLink('contact2@door.test'))->assertOk();
        $this->signIn('contact2@door.test')->assertOk();

        $this->assertSame('/portal', $this->destination());
        $references = collect($this->withHeaders($this->spa)->getJson('/api/v1/client/requests')->assertOk()->json('data.requests'))->pluck('reference');
        $this->assertContains($mine->reference, $references);
        $this->assertNotContains($theirs->reference, $references);

        $user = User::query()->where('email', 'contact2@door.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([(string) $client->id], $membership->load('scopes')->clientScopeIds());
        $this->assertNull($membership->invited_by, 'no agency chose a restricted role here, so the session reads as their code session did');
    }

    /**
     * PLATFORM-SERVICE-INBOX-001 — the same address also ordered a service from the platform itself.
     *
     * That request belongs to no workspace (`tenant_id` null). It must not become a group of its own when the account is set
     * up: there is no tenant to grant, and asking the database for a tenant with an empty id failed the whole setup with a 500.
     */
    public function test_a_contact_who_also_ordered_from_the_platform_finishes_setup_with_only_the_agency_access(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $client = $this->clientOf($tenant);
        $mine = $this->nameOnARequest('mixed@door.test', $tenant, $client);
        $platform = $this->nameOnARequest('mixed@door.test', $tenant);
        $platform->forceFill(['tenant_id' => null, 'owner_scope' => ExternalRequest::OWNER_PLATFORM])->save();

        $this->spend('mixed@door.test', $this->issuedLink('mixed@door.test'))->assertOk();
        $this->signIn('mixed@door.test')->assertOk();

        $user = User::query()->where('email', 'mixed@door.test')->firstOrFail();
        $memberships = Membership::query()->withoutGlobalScopes()->where('user_id', $user->id)->get();
        $this->assertSame([(string) $tenant->id], $memberships->pluck('tenant_id')->map(fn ($id): string => (string) $id)->all());
        $this->assertSame([(string) $client->id], $memberships->first()->load('scopes')->clientScopeIds());
        $this->assertSame(0, DB::table('portal_identity_conflicts')->whereNull('tenant_id')->count());

        $references = collect($this->withHeaders($this->spa)->getJson('/api/v1/client/requests')->assertOk()->json('data.requests'))->pluck('reference');
        $this->assertContains($mine->reference, $references);
        $this->assertNotContains($platform->reference, $references, 'a platform order is not part of any agency portal');
    }

    public function test_a_contact_of_two_agencies_gets_each_and_is_asked_which(): void
    {
        Mail::fake();
        $one = $this->tenant();
        $two = $this->tenant();
        $this->nameOnARequest('both@door.test', $one, $this->clientOf($one));
        $this->nameOnARequest('both@door.test', $two, $this->clientOf($two));

        $this->spend('both@door.test', $this->issuedLink('both@door.test'))->assertOk();
        $this->signIn('both@door.test')->assertOk();

        $this->assertSame('/switch', $this->destination());
    }

    public function test_a_contact_with_no_client_yet_sees_only_their_own_unconverted_request_and_no_client(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $otherClient = $this->clientOf($tenant);
        $mine = $this->nameOnARequest('early@door.test', $tenant);
        $notMine = $this->nameOnARequest('someone@door.test', $tenant, $otherClient);

        $this->spend('early@door.test', $this->issuedLink('early@door.test'))->assertOk();
        $this->signIn('early@door.test')->assertOk();

        $this->assertSame('/portal', $this->destination());
        $references = collect($this->withHeaders($this->spa)->getJson('/api/v1/client/requests')->assertOk()->json('data.requests'))->pluck('reference')->all();
        $this->assertSame([$mine->reference], $references);
        $this->assertNotContains($notMine->reference, $references);
        // A membership with no scope reaches no client: an empty list here must never read as «all of them».
        $this->assertSame([], $this->withHeaders($this->spa)->getJson('/api/v1/client/spaces')->assertOk()->json('data.spaces') ?? []);
        $this->withHeaders($this->spa)->getJson('/api/v1/client-workspaces')->assertStatus(403);
    }

    public function test_a_contact_with_no_client_yet_keeps_sight_of_their_request_when_it_is_converted(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $client = $this->clientOf($tenant);
        $request = $this->nameOnARequest('later@door.test', $tenant);
        $this->spend('later@door.test', $this->issuedLink('later@door.test'))->assertOk();

        app(TenantContext::class)->setTenantId((string) $tenant->id);
        app(RequestConversionService::class)->convert($request, ['client_id' => (string) $client->id], $this->person('op@door.test'));

        $membership = Membership::query()->whereIn('user_id', User::query()->where('email', 'later@door.test')->select('id'))->firstOrFail();
        $this->assertSame([(string) $client->id], $membership->load('scopes')->clientScopeIds());
    }

    /** Nobody's existing reach widens: only the no-scope state above is carried, and no agency chose that one. */
    public function test_converting_a_request_never_widens_a_member_who_already_has_a_client(): void
    {
        $tenant = $this->tenant();
        $held = $this->clientOf($tenant);
        $other = $this->clientOf($tenant);
        $user = $this->person('scoped@door.test');
        $membership = $this->holds($user, Portal::ClientPortal, $tenant, role: 'client_viewer');
        MembershipScope::query()->create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_CLIENT, 'scope_id' => (string) $held->id]);
        $request = $this->nameOnARequest('scoped@door.test', $tenant);

        app(TenantContext::class)->setTenantId((string) $tenant->id);
        app(RequestConversionService::class)->convert($request, ['client_id' => (string) $other->id], $this->person('op2@door.test'));

        $this->assertSame([(string) $held->id], $membership->fresh()?->load('scopes')->clientScopeIds());
    }

    public function test_recovery_for_nobody_answers_the_same_and_sends_nothing(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'services.notifications.email_enabled' => true]);
        $tenant = $this->tenant();
        $this->nameOnARequest('known@door.test', $tenant);

        $known = $this->withHeaders($this->spa)->postJson('/api/v1/auth/forgot-password', ['email' => 'known@door.test'])->assertOk();
        $unknown = $this->withHeaders($this->spa)->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@door.test'])->assertOk();

        // Everything but the per-request id, which differs for any two requests.
        $this->assertSame(array_diff_key((array) $known->json(), ['meta' => 1]), array_diff_key((array) $unknown->json(), ['meta' => 1]));
        $this->assertNull(DB::table('password_reset_tokens')->where('email', 'nobody@door.test')->first());
        $this->assertNull(DB::table('mail_deliveries')->where('recipient', 'nobody@door.test')->first());
    }

    public function test_a_wrong_or_spent_link_opens_no_account_and_a_link_is_single_use(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $this->nameOnARequest('once@door.test', $tenant);
        $secret = $this->issuedLink('once@door.test');

        $this->spend('once@door.test', Str::random(64))->assertStatus(422);
        $this->assertNull(User::query()->where('email', 'once@door.test')->first());

        $this->spend('once@door.test', $secret)->assertOk();
        $this->spend('once@door.test', $secret)->assertStatus(422);
    }

    public function test_an_ordinary_reset_never_creates_an_account_even_for_an_address_on_a_request(): void
    {
        Mail::fake();
        $this->nameOnARequest('gone@door.test', $this->tenant());
        DB::table('password_reset_tokens')->insert([
            'email' => 'gone@door.test', 'token' => hash('sha256', 'stale-secret'), 'created_at' => now(), 'expires_at' => now()->addHour(), 'purpose' => 'password_reset',
        ]);

        $this->spend('gone@door.test', 'stale-secret')->assertStatus(422);
        $this->assertNull(User::query()->where('email', 'gone@door.test')->first());
    }

    public function test_a_staff_address_on_a_request_keeps_its_account_and_receives_no_client_view(): void
    {
        Mail::fake();
        $tenant = $this->tenant();
        $staff = $this->person('staff@door.test');
        $this->holds($staff, Portal::Agency, $tenant);
        $this->nameOnARequest('staff@door.test', $tenant, $this->clientOf($tenant));

        $this->spend('staff@door.test', $this->issuedLink('staff@door.test'))->assertOk();

        $this->assertSame(0, Membership::query()->where('user_id', $staff->id)->where('portal', Portal::ClientPortal->value)->count());
    }

    /** A real callback: PKCE start, a signed token, and the same destination rule as the password path. */
    private function googleSignIn(User $user, ?string $redirect): TestResponse
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
        OAuthIdentity::create([
            'user_id' => $user->getKey(), 'provider' => 'google', 'provider_user_id' => 'sub-'.$user->getKey(),
            'email' => $user->email, 'email_verified' => true, 'linked_at' => now(),
        ]);

        $url = (string) $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/google/start', ['redirect' => $redirect])->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'kid' => 'door-key'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iss' => 'https://accounts.google.com', 'aud' => 'test-id', 'sub' => 'sub-'.$user->getKey(), 'nonce' => $query['nonce'],
            'iat' => time(), 'exp' => time() + 300, 'email' => $user->email, 'email_verified' => true,
        ], JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $header.'.'.$payload.'.'.$encode($signature)]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [['kid' => 'door-key', 'kty' => 'RSA', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]]]),
        ]);

        return $this->get('/api/v1/auth/oauth/google/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]));
    }

    public function test_google_lands_a_member_by_the_same_rule_as_a_password(): void
    {
        $user = $this->person('g1@door.test');
        $this->holds($user, Portal::ClientPortal, role: 'client_viewer');

        $this->googleSignIn($user, null)->assertRedirect(Frontend::url('/portal'));
    }

    public function test_google_keeps_a_destination_the_person_may_enter_and_refuses_one_they_may_not(): void
    {
        $user = $this->person('g2@door.test');
        $this->holds($user, Portal::App);

        $this->googleSignIn($user, '/app/campaigns')->assertRedirect(Frontend::url('/app/campaigns'));
    }

    public function test_google_refuses_a_destination_the_person_may_not_enter(): void
    {
        $user = $this->person('g3@door.test');
        $this->holds($user, Portal::App);

        $this->googleSignIn($user, '/agency/clients')->assertRedirect(Frontend::url(Portal::App->landingPath()));
    }

    public function test_google_refuses_an_open_redirect(): void
    {
        $user = $this->person('g4@door.test');
        $this->holds($user, Portal::App);

        $this->googleSignIn($user, '//evil.example')->assertRedirect(Frontend::url(Portal::App->landingPath()));
    }

    public function test_google_sends_a_waiting_application_to_its_status(): void
    {
        $user = $this->person('g5@door.test');
        $application = $this->application('g5@door.test', AccountState::ApprovedAwaitingPayment);

        $this->googleSignIn($user, null)->assertRedirect(Frontend::url('/signup/status?request='.$application->getKey()));
    }

    public function test_google_refuses_a_disabled_account_with_no_session(): void
    {
        $user = $this->person('g6@door.test', ['disabled_at' => now()]);
        $this->holds($user, Portal::App);

        $this->googleSignIn($user, null)->assertRedirect(Frontend::url('/login?oauth=unavailable'));
        $this->assertGuest('web');
    }

    public function test_the_code_is_off_by_default_and_its_routes_answer_nothing_while_off(): void
    {
        $this->assertStringContainsString("env('PORTAL_CODE_ON_LOGIN', false)", (string) file_get_contents(config_path('requests.php')));
        config(['requests.verification.portal_code_on_login' => false]);

        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'a@door.test'])->assertNotFound();
        $this->withHeaders($this->spa)->postJson('/api/v1/client/login/verify', ['verification_id' => 'x', 'code' => '000000'])->assertNotFound();
        $this->getJson('/api/v1/auth/oauth/providers')->assertOk()->assertJsonPath('data.email_code', false);
    }

    public function test_when_on_the_page_is_told_and_a_code_for_a_contact_lands_inside_the_portal_only(): void
    {
        config(['requests.verification.portal_code_on_login' => true]);
        $this->getJson('/api/v1/auth/oauth/providers')->assertOk()->assertJsonPath('data.email_code', true);

        $tenant = $this->tenant();
        $this->nameOnARequest('coded@door.test', $tenant, $this->clientOf($tenant));

        foreach (['/portal/requests' => '/portal/requests', 'https://evil.example' => '/portal', '/admin' => '/portal', '/portal/login' => '/portal'] as $redirect => $landing) {
            ContactVerification::query()->update(['last_sent_at' => now()->subMinutes(5)]);
            $start = $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'coded@door.test'])->assertCreated();
            $this->withHeaders($this->spa)->postJson('/api/v1/client/login/verify', [
                'verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code'), 'redirect' => $redirect,
            ])->assertOk()->assertJsonPath('data.destination', $landing);
        }
    }

    public function test_a_session_opened_before_the_code_was_turned_off_keeps_working(): void
    {
        config(['requests.verification.portal_code_on_login' => true]);
        $tenant = $this->tenant();
        $this->nameOnARequest('live@door.test', $tenant, $this->clientOf($tenant));
        $start = $this->withHeaders($this->spa)->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => 'live@door.test'])->assertCreated();
        $token = (string) $this->withHeaders($this->spa)->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'), 'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');

        config(['requests.verification.portal_code_on_login' => false]);

        $this->withHeaders(['X-Client-Token' => $token])->getJson('/api/v1/client/session')->assertOk();
    }
}
