<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Install;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Enums\AccountType;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\PendingStoreInstall;
use App\Domains\Commerce\Services\StoreAssignment;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SALLA-ONBOARD-001 — a merchant installs from Salla and finds a working dashboard.
 *
 * ## What it does, in order
 *
 * The install has already been exchanged and kept as a pending install (`SallaInstalls::fromCode`). From the
 * store and from who authorised it, as Salla states both:
 *
 *  - **A store already connected** (a reinstall, or the «Open app» link): the tokens are renewed in place for
 *    the tenant that already owns it, and the authorising person is signed in if — and only if — they are a
 *    member of that tenant.
 *  - **A new store, a new person:** a workspace (an advertiser account on the `salla-trial` plan), its owner
 *    (the Salla email, marked verified because Salla verified it), a project named for the store, the store
 *    connected and assigned to that project, and the first sync queued.
 *  - **A new store, a person who already has an account:** the store is attached to THEIR workspace in a new
 *    project named for the store; the project can be changed on the stores page.
 *
 * Anything it cannot decide safely — no email from Salla, an employee rather than the owner, an account with
 * no workspace to attach to, a store already owned by a different tenant — leaves the pending install for the
 * ordinary claim page. Falling back is always correct; guessing is not.
 *
 * ## What it refuses to be
 *
 * It never signs in anybody Salla did not just prove, and never signs in an existing account unless
 * `commerce_platforms.onboarding.trust_email` is on (the default), because that trust is the one assumption here: that Salla's
 * email belongs to the person who completed Salla's authorisation.
 *
 * A store belongs to exactly one tenant: a merchant already connected elsewhere is never connected a second time.
 */
final class SallaOnboarding
{
    public const PLAN = 'salla-trial';

    public function __construct(
        private readonly SallaInstalls $installs,
        private readonly SallaIdentity $identity,
        private readonly StoreAssignment $assignment,
        private readonly TransitionAccountState $transitions,
        private readonly GrantMembership $grants,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{outcome: 'provisioned'|'attached'|'entered'|'claim', user: ?User, store: ?string}
     */
    public function handle(PendingStoreInstall $install, string $claimCode): array
    {
        $tokens = $this->tokensOf($install);
        $merchant = (string) $install->merchant_id;
        $who = $this->identity->fetch($tokens);

        // 1. Already connected: entry for the owning tenant only.
        $existing = $this->installs->connectionForMerchant($merchant);
        if ($existing !== null) {
            $this->installs->renew($existing, $tokens);
            $user = $who === null ? null : $this->memberOf((string) $existing->tenant_id, $who['email']);

            return ['outcome' => 'entered', 'user' => $user, 'store' => $merchant];
        }

        if ($who === null || ! $who['is_owner']) {
            return ['outcome' => 'claim', 'user' => null, 'store' => $merchant];
        }

        $user = User::query()->whereRaw('lower(email) = ?', [$who['email']])->first();

        if ($user === null) {
            [$tenant, $user] = $this->provisionWorkspace($who, $this->storeName($install));
            $this->connect($install, $claimCode, $tenant, $user);

            return ['outcome' => 'provisioned', 'user' => $user, 'store' => $merchant];
        }

        $tenant = $this->tenantOf($user);
        if ($tenant === null) {
            return ['outcome' => 'claim', 'user' => null, 'store' => $merchant];
        }

        // SEC-3 — a client is never picked for the merchant: with several (or an agency's one) the install
        // waits on the claim page for a person with integrations.connect to place it.
        $this->context->setTenantId((string) $tenant->id);
        if (! $this->mayPlaceWithoutAsking($tenant)) {
            return ['outcome' => 'claim', 'user' => null, 'store' => $merchant];
        }

        $this->connect($install, $claimCode, $tenant, $user);

        return ['outcome' => 'attached', 'user' => $this->mayEnter($user) ? $user : null, 'store' => $merchant];
    }

    /** Whether an EXISTING account may be signed in on Salla's word alone. */
    public function mayEnter(User $user): bool
    {
        return (bool) config('commerce_platforms.onboarding.trust_email', true) && $user->disabled_at === null;
    }

    /**
     * @param  array{id: string, email: string, name: string, mobile: ?string, is_owner: bool}  $who
     * @return array{0: Tenant, 1: User}
     */
    private function provisionWorkspace(array $who, string $storeName): array
    {
        return DB::transaction(function () use ($who, $storeName): array {
            $tenant = Tenant::create([
                'name' => $storeName,
                'slug' => $this->uniqueSlug($storeName),
                'status' => 'inactive',
                'account_type' => 'self_serve_company',
                'subscription_plan' => self::PLAN,
                'enabled_modules' => ['paid_media'],
                'onboarding_step' => 'done',
                'onboarding_completed_at' => now(),
            ]);
            $this->context->setTenantId((string) $tenant->id);

            Workspace::create(['tenant_id' => $tenant->id, 'name' => 'Default Workspace', 'slug' => 'default']);

            $user = User::create([
                'name' => $who['name'],
                // Nobody knows this password, including us: entry is by Salla or by «forgot password».
                'password' => Hash::make(Str::random(48)),
                'email' => $who['email'],
                'phone' => $who['mobile'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $role = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'],
                ['name' => 'Tenant Owner', 'is_system' => true],
            );
            $role->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($role);

            $this->grants->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::forAccountType('self_serve_company'), role: 'owner'));

            $this->transitions->provision($tenant, AccountState::Active, 'Provisioned from a Salla store install: the merchant authorised the app and Salla vouched for the email.');

            return [$tenant->refresh(), $user];
        });
    }

    /** Connect the store into the tenant, in a new project named for it, and queue the first sync. */
    private function connect(PendingStoreInstall $install, string $claimCode, Tenant $tenant, User $user): void
    {
        $this->context->setTenantId((string) $tenant->id);

        // At most one client exists here (see `mayPlaceWithoutAsking`): the only one, or a fresh one.
        $client = ClientWorkspace::query()->first() ?? ClientWorkspace::create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => Str::slug($tenant->name.'-'.Str::random(4)),
            'mode' => 'managed',
            'status' => 'active',
            'client_status' => 'active',
            'owner_id' => $user->id,
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'client_workspace_id' => $client->id,
            'name' => $this->storeName($install),
            'status' => 'active',
        ]);

        $this->installs->claim($claimCode, (string) $tenant->id, (int) $user->getKey(), (string) $client->id);

        $store = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('provider', 'salla')
            ->where('account_type', 'store')
            ->where('external_id', (string) $install->merchant_id)
            ->first();

        if ($store !== null) {
            // The assignment is what queues the first backfill (products, orders, carts, store profile).
            $this->assignment->assign($store, $project);
        }
    }

    /**
     * Whether a new store may be filed without asking where: the tenant has no client yet (one is made
     * for it) or is an advertiser with exactly one. Several clients, or an agency's, is a human choice —
     * guessing the oldest client filed one merchant's orders and customers under an unrelated client.
     */
    private function mayPlaceWithoutAsking(Tenant $tenant): bool
    {
        $clients = ClientWorkspace::query()->limit(2)->count();

        if ($clients === 0) {
            return true;
        }

        return $clients === 1 && $tenant->account_type !== AccountType::Agency->value;
    }

    private function memberOf(string $tenantId, string $email): ?User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $user !== null && $this->mayEnter($user)
            && Membership::query()->forUser($user->getKey())->where('tenant_id', $tenantId)->active()->exists()
            ? $user
            : null;
    }

    /** The workspace an existing account's store goes into: their default advertiser or agency membership. */
    private function tenantOf(User $user): ?Tenant
    {
        $membership = Membership::query()->forUser($user->getKey())->active()
            ->whereIn('portal', [Portal::App->value, Portal::Agency->value])
            ->orderByDesc('is_default')->orderBy('created_at')->first();

        $tenant = $membership?->tenant;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private function storeName(PendingStoreInstall $install): string
    {
        foreach ([$install->store_name, $install->store_domain] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return mb_substr(trim($name), 0, 120);
            }
        }

        return 'Salla store '.$install->merchant_id;
    }

    private function tokensOf(PendingStoreInstall $install): OAuthTokens
    {
        $t = (array) $install->tokens;

        return new OAuthTokens(
            (string) ($t['access_token'] ?? ''),
            isset($t['refresh_token']) ? (string) $t['refresh_token'] : null,
            isset($t['expires_at']) ? Carbon::parse((string) $t['expires_at']) : null,
            isset($t['scope']) ? (string) $t['scope'] : null,
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'salla-store';
        $slug = $base;
        $i = 1;
        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
