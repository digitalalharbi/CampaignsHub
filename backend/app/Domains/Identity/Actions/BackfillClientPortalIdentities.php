<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step 1 of PORTAL-AUTH-001: give every existing portal contact a real identity.
 *
 * The client portal authenticates a VERIFIED CONTACT DETAIL — an email or phone matched against
 * `external_requests` — with no `users` row behind it. The rest of the product authenticates users
 * with memberships. This turns the first into the second WITHOUT touching how anyone signs in today:
 * it only creates rows. The OTP engine keeps working until the cutover, which is a later step.
 *
 * Doing this first, and asserting it, is the whole safety of the migration. If a contact's resulting
 * scope does not match what `contactOwnedWorkspaceIds()` computes for them today, then switching the
 * portal to read memberships would silently change what that person can see — and the change would
 * be invisible, because both answers look like a normal portal.
 *
 * Every rule below is fail-closed. A contact this cannot resolve confidently is SKIPPED with a
 * reason, never guessed at: an over-granted client portal shows one customer another's invoices.
 */
final class BackfillClientPortalIdentities
{
    public function __construct(
        private readonly GrantMembership $grants,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * `unchanged` is counted apart from `skipped` on purpose. A re-run leaves everything unchanged,
     * and reporting those as "skipped — nothing was granted" told the operator that 23 contacts had
     * a problem when in fact all 23 were already correct. Skipped means a conflict a human must look
     * at; unchanged means it was right already.
     *
     * @return array{granted: int, updated: int, unchanged: int, skipped: list<array{contact: string, reason: string}>}
     */
    public function execute(bool $dryRun = false): array
    {
        $granted = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = [];

        foreach ($this->contacts() as $contact) {
            $result = $this->one($contact, $dryRun);

            match ($result['outcome']) {
                'granted' => $granted++,
                'updated' => $updated++,
                'unchanged' => $unchanged++,
                default => $skipped[] = ['contact' => $contact['email'] ?? $contact['phone'] ?? '?', 'reason' => $result['outcome']],
            };
        }

        return ['granted' => $granted, 'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }

    /**
     * Every distinct portal contact, with the tenant and client spaces their requests place them in.
     *
     * Grouped by EMAIL where there is one, because email is what the portal session is keyed on and
     * what a person recognises as their identity. A contact with only a phone is grouped by phone.
     *
     * @return list<array{email: ?string, phone: ?string, tenant_id: string, client_ids: list<string>}>
     */
    private function contacts(): array
    {
        $rows = ExternalRequest::query()
            ->withoutGlobalScopes()
            ->whereNotNull('client_id')
            ->where(fn ($q) => $q->whereNotNull('contact_email')->orWhereNotNull('contact_phone'))
            ->get(['tenant_id', 'contact_email', 'contact_phone', 'client_id']);

        $grouped = [];

        foreach ($rows as $row) {
            $email = $row->contact_email === null ? null : Str::lower(trim($row->contact_email));
            $phone = $row->contact_phone === null ? null : trim($row->contact_phone);

            // Keyed by TENANT as well as identity: the same person may be a client of two different
            // agencies, and those are two separate memberships, not one merged reach.
            $key = ($email ?? $phone).'@'.$row->tenant_id;

            $grouped[$key] ??= [
                'email' => $email,
                'phone' => $phone,
                'tenant_id' => (string) $row->tenant_id,
                'client_ids' => [],
            ];

            // Keep the first phone seen for an email-keyed contact; a second one is additional
            // contact data, not a second person.
            $grouped[$key]['phone'] ??= $phone;
            $grouped[$key]['client_ids'][] = (string) $row->client_id;
        }

        return array_values(array_map(
            fn (array $c) => [...$c, 'client_ids' => array_values(array_unique($c['client_ids']))],
            $grouped,
        ));
    }

    /**
     * @param  array{email: ?string, phone: ?string, tenant_id: string, client_ids: list<string>}  $contact
     * @return array{outcome: string}
     */
    private function one(array $contact, bool $dryRun): array
    {
        if ($contact['client_ids'] === []) {
            // Cannot happen given the query, but an empty scope would be a membership that reaches
            // NOTHING — and creating one silently is worse than not creating it.
            return ['outcome' => 'no_client_space'];
        }

        $tenant = Tenant::query()->whereKey($contact['tenant_id'])->first();

        if ($tenant === null) {
            return ['outcome' => 'tenant_missing'];
        }

        // A contact with no email cannot own a `users` row: email is the column the auth engine
        // identifies by, and inventing a placeholder address would create an account nobody can
        // ever sign in to and that collides with the real one when they do give an email.
        if ($contact['email'] === null) {
            return ['outcome' => 'phone_only_no_email'];
        }

        $existing = User::query()->where('email', $contact['email'])->first();

        // The email already belongs to a STAFF account. Granting a client-portal membership to it
        // would give an agency employee a client's view of their own agency — and worse, the reverse
        // is how a client would reach staff surfaces. Never merged; reported for a human to decide.
        if ($existing !== null && $this->isStaff($existing)) {
            return ['outcome' => 'email_belongs_to_staff'];
        }

        if ($dryRun) {
            return ['outcome' => $existing === null ? 'granted' : 'updated'];
        }

        return DB::transaction(function () use ($contact, $tenant, $existing): array {
            $this->tenants->setTenantId((string) $tenant->getKey());

            $user = $existing ?? User::create([
                'name' => $this->nameFor($contact),
                'email' => $contact['email'],
                // No password. These people sign in by one-time code, and a random password nobody
                // holds is not a credential — it is a lock with the key thrown away.
                'password' => Str::random(64),
                'phone' => $contact['phone'],
            ]);

            // Accepting the portal's OTP already proves they hold the address; requiring a second
            // confirmation email would lock out every existing client on the day of the cutover.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $before = $this->currentScope($user, $tenant);

            $this->grants->execute(new MembershipGrant(
                user: $user,
                tenant: $tenant,
                portal: Portal::ClientPortal,
                role: 'client_viewer',
                // Additive by design: `GrantMembership` widens and never replaces, so re-running
                // this over a contact whose spaces have grown adds the new ones and disturbs nothing.
                clientScopeIds: $contact['client_ids'],
            ));

            $after = $this->currentScope($user, $tenant);

            return ['outcome' => $before === [] && $after !== [] ? 'granted' : ($before === $after ? 'unchanged' : 'updated')];
        });
    }

    /**
     * Staff means: they hold a membership in a portal other than the client portal, or they are the
     * platform owner. Checked by MEMBERSHIP rather than by `users.tenant_id`, which is deprecated and
     * says nothing about what a person may reach (ADR 0002).
     */
    private function isStaff(User $user): bool
    {
        return $user->is_platform_admin
            || Membership::query()
                ->where('user_id', $user->getKey())
                ->where('portal', '!=', Portal::ClientPortal->value)
                ->exists();
    }

    /** @return list<string> */
    private function currentScope(User $user, Tenant $tenant): array
    {
        $membership = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->where('portal', Portal::ClientPortal->value)
            ->with('scopes')
            ->first();

        $ids = $membership?->clientScopeIds() ?? [];
        sort($ids);

        return $ids;
    }

    /** @param  array{email: ?string, phone: ?string, tenant_id: string, client_ids: list<string>}  $contact */
    private function nameFor(array $contact): string
    {
        $named = ExternalRequest::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $contact['tenant_id'])
            ->whereRaw('lower(contact_email) = ?', [$contact['email']])
            ->whereNotNull('contact_name')
            ->value('contact_name');

        // Their own name from their own request, or the local part of the address — never a
        // placeholder like "Client", which they would see in their own portal header.
        return $named ?: Str::before((string) $contact['email'], '@');
    }
}
