<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * META-SAAS-APP-001 — the ONE workspace where the new SaaS Meta app is exercised end to end before Meta approves it.
 *
 * A brand-new tenant («Meta SaaS Test»), owned by a brand-new user whose address the Owner supplies. It holds no customer, no
 * connection and no account until the Owner connects a test ad account through the SaaS app. Nothing is copied into it: no token, no
 * app profile, no binding from any other tenant.
 *
 * Until the Owner records Meta's approval, this is the only kind of tenant where an operator may choose the SaaS app at all
 * (`MetaAppRouting::saasEligibilityFor`). Every customer tenant keeps connecting through Live, so testing the new app cannot open a
 * second connection beside a customer's, re-discover their accounts or touch their sync.
 *
 * The user gets an unusable random password and a reset link by email, so no password is ever chosen here, shown or sent through chat.
 */
final class MetaSaasTestWorkspace
{
    public const SLUG = 'meta-saas-test';

    public const SETTINGS_KEY = 'meta_saas_test';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TransitionAccountState $transitions,
        private readonly GrantMembership $grants,
        private readonly PasswordResetService $resets,
    ) {}

    public static function isTestTenant(?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }
        $settings = Tenant::query()->whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;

        return (($settings ?? [])[self::SETTINGS_KEY]['active'] ?? false) === true;
    }

    public function tenant(): ?Tenant
    {
        return Tenant::query()->where('slug', self::SLUG)->first();
    }

    /** @return array{tenant: Tenant, user: User} */
    public function provision(string $email, ?int $actorId): array
    {
        $email = Str::lower(trim($email));
        if ($this->tenant() !== null) {
            throw new InvalidArgumentException('test_workspace_exists');
        }
        // A NEW identity only: an existing user would carry their customer workspaces into the test one.
        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('email_already_registered');
        }

        $previous = $this->context->tenantId();
        try {
            $tenant = Tenant::create([
                'name' => 'Meta SaaS Test',
                'slug' => self::SLUG,
                'status' => 'inactive',
                'account_type' => 'self_serve_company',
                'enabled_modules' => ['paid_media'],
                'onboarding_step' => 'done',
                'onboarding_completed_at' => now(),
            ]);
            $this->transitions->provision($tenant, AccountState::Active, 'Provisioned as the isolated Meta SaaS app test workspace.');
            $tenant->forceFill(['settings' => array_merge((array) ($tenant->settings ?? []), [
                self::SETTINGS_KEY => ['active' => true, 'created_at' => now()->toIso8601String(), 'created_by' => $actorId],
            ])])->save();
            $this->context->setTenantId((string) $tenant->id);

            Workspace::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'default'], ['name' => 'Default Workspace']);

            $user = new User(['email' => $email, 'name' => 'Meta SaaS Tester']);
            $user->password = Hash::make(Str::random(64));
            $user->save();
            $user->forceFill(['email_verified_at' => now()])->save();

            $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'], ['name' => 'Tenant Owner', 'is_system' => true]);
            $role->givePermissionTo(...Permission::pluck('key')->all());
            $user->assignRole($role);
            $this->grants->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::forAccountType('self_serve_company'), role: 'owner'));

            $client = ClientWorkspace::firstOrNew(['tenant_id' => $tenant->id, 'slug' => 'meta-saas-test-store']);
            $client->fill(['name' => 'Meta SaaS Test', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active', 'owner_id' => $user->id, 'branding' => ['brand_name' => 'Meta SaaS Test']]);
            $client->save();
            Project::firstOrCreate(['client_workspace_id' => $client->id, 'name' => 'Meta SaaS Test — Project'], ['status' => 'active', 'setup_completion' => 100]);
        } finally {
            $this->context->setTenantId($previous);
        }

        // The Owner sets the password from their own inbox; nothing here knows it.
        $this->resets->request($email);

        return ['tenant' => $tenant->refresh(), 'user' => $user];
    }

    /** Close the workspace to the SaaS app (it keeps its data); the next test needs it re-opened by an admin. */
    public function deactivate(): void
    {
        $tenant = $this->tenant();
        if ($tenant === null) {
            return;
        }
        $settings = (array) ($tenant->settings ?? []);
        $settings[self::SETTINGS_KEY] = [...(array) ($settings[self::SETTINGS_KEY] ?? []), 'active' => false];
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /**
     * The journey as it really happened in the test workspace — each step read from rows, never from a flag.
     *
     * @return array{exists: bool, active: bool, tenant_id: string|null, login_email: string|null, steps: array<string, bool>}
     */
    public function journey(): array
    {
        $tenant = $this->tenant();
        if ($tenant === null) {
            return ['exists' => false, 'active' => false, 'tenant_id' => null, 'login_email' => null, 'steps' => []];
        }
        $tid = (string) $tenant->id;
        $connections = ProviderConnection::withoutGlobalScopes()->where('tenant_id', $tid)->where('provider', 'meta')
            ->where('app_profile', MetaCredentialProfile::Saas->value);
        $connectionIds = (clone $connections)->pluck('id');
        $accounts = ExternalAccount::withoutGlobalScopes()->whereIn('provider_connection_id', $connectionIds);
        $accountIds = (clone $accounts)->pluck('id');
        $owner = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tid))->value('email');

        return [
            'exists' => true,
            'active' => self::isTestTenant($tid),
            'tenant_id' => $tid,
            'login_email' => $owner,
            'steps' => [
                'oauth' => (clone $connections)->exists(),
                'discovery' => $accountIds->isNotEmpty(),
                'binding' => $accountIds->isNotEmpty() && ProjectIntegrationBinding::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->exists(),
                'sync' => (clone $accounts)->whereNotNull('last_synced_at')->exists(),
                'metrics' => $accountIds->isNotEmpty() && DailyMetric::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->exists(),
                'reports' => Report::withoutGlobalScopes()->where('tenant_id', $tid)->exists(),
                'disconnect' => (clone $connections)->where('status', 'revoked')->exists(),
            ],
        ];
    }
}
