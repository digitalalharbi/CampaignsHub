<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Accounts\Enums\AccountType;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROJECT-CREATE-WORKSPACE-001 — the single answer to «which container does this project belong to?»
 *
 * ## The question the product had no answer for
 *
 * `projects.client_workspace_id` is NOT NULL, and a `client_workspaces` row means an AGENCY'S
 * CLIENT. An advertiser running their own campaigns has no clients, so the product had nowhere to
 * put their project — and the connection wizard papered over it with `workspaces.data?.[0]?.id`,
 * which returns nothing when there are none and the wrong client when there are several. That single
 * expression is both halves of the live production failure: an advertiser could not create a project
 * at all, and an agency would have had one filed under whichever client sorted first.
 *
 * ## The rule, and why it differs by account type
 *
 * An advertiser has ONE container by definition — their own work — so it can be resolved without
 * asking, provided it is resolved the same way every time. An agency's containers are the thing they
 * are paying us to keep apart, so there the answer is never derived: they name it. «One client
 * today» is not a rule about tomorrow, and a convenience that quietly files a project under the only
 * existing client is the same defect as `[0]`, merely harder to notice.
 *
 * So:
 *
 *  - `personal` account types (freelancer, agency, in-house team) → `null`. Ask.
 *  - `company` account types (brand, self-serve) → the canonical container, adopted or created.
 *  - account type not yet answered → decided by SHAPE, and only where the shape is unambiguous:
 *    zero or one workspace has exactly one possible answer; two or more has none, so it asks. This
 *    matters because onboarding can be abandoned half way, and a tenant that never reached the
 *    question must still be able to use the product.
 *
 * Nothing here ever picks among several. That is the whole point.
 */
final class CanonicalWorkspace
{
    /**
     * The advertiser's own container, creating it once if it does not exist yet.
     *
     * Returns null when the tenant must be asked instead — the caller turns that into a validation
     * error naming `client_workspace_id`, rather than inventing a container for an agency.
     */
    public function ensureFor(Tenant $tenant): ?ClientWorkspace
    {
        if (! $this->resolvesWithoutAsking($tenant)) {
            return null;
        }

        /*
         * Serialised on the tenant row, for the same reason the ad-account quota is: two requests
         * that both find no container would otherwise both create one, and a tenant with two
         * canonical containers has no canonical container. The partial unique index behind this is
         * the backstop, not the mechanism.
         */
        return DB::transaction(function () use ($tenant): ClientWorkspace {
            DB::table('tenants')->where('id', $tenant->getKey())->lockForUpdate()->first();

            $canonical = ClientWorkspace::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->where('is_canonical', true)
                ->first();

            if ($canonical !== null) {
                return $canonical;
            }

            $existing = ClientWorkspace::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->get();

            // Adopt rather than duplicate: an advertiser provisioned through registration already
            // has the container this would otherwise create, holding whatever they have done so far.
            if ($existing->count() === 1) {
                $only = $existing->first();
                $only->forceFill(['is_canonical' => true])->save();

                return $only;
            }

            return ClientWorkspace::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->getKey(),
                // Named after the account, because that is what it holds. Not «Default», which reads
                // as a placeholder somebody is expected to replace.
                'name' => (string) $tenant->name,
                'slug' => $this->uniqueSlug($tenant),
                'mode' => 'managed',
                'status' => 'active',
                'client_status' => 'active',
                'is_canonical' => true,
            ]);
        });
    }

    /** Whether this tenant's container can be resolved at all, without creating anything. */
    public function resolvesWithoutAsking(Tenant $tenant): bool
    {
        $kind = AccountType::tryFrom((string) $tenant->account_type)?->workspaceKind();

        if ($kind === 'personal') {
            return false;   // an agency names its client. Always.
        }

        if ($kind === 'company') {
            return true;
        }

        /*
         * The account type was never answered. Decide on shape, and only where the shape leaves one
         * possible answer — a tenant holding two or more workspaces is agency-shaped whatever the
         * column says, and gets asked.
         */
        return ClientWorkspace::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->count() <= 1;
    }

    /** Unique within the tenant, which is where the slug's uniqueness constraint lives. */
    private function uniqueSlug(Tenant $tenant): string
    {
        $base = Str::slug((string) $tenant->name) ?: 'workspace';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $slug = $attempt === 0 ? $base : $base.'-'.Str::lower(Str::random(4));

            $taken = ClientWorkspace::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->where('slug', $slug)
                ->exists();

            if (! $taken) {
                return $slug;
            }
        }

        return $base.'-'.Str::lower(Str::random(8));
    }
}
