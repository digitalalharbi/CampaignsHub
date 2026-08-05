<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Remove the accounts and tenants the E2E suite created, and nothing else (E2E-ISO-001).
 *
 * The gate used to run `artisan serve` against the DEVELOPMENT database, so every three-browser run
 * left a full registration journey behind: a user, a tenant, its client space, its project. Over the
 * run history that reached 485 tenants, 791 client spaces, 610 users and 2105 tasks — which does not
 * break the gate but makes live review of any list meaningless, and hid a real permission leak behind
 * a number nobody could interpret.
 *
 * The gate has its own database now (`playwright.config.ts` + `e2e/global-setup.ts`), so this exists
 * for two other reasons: clearing the residue that already accumulated, and staying available for any
 * environment where the two databases were ever shared.
 *
 * ## What makes this safe to run
 *
 * It does NOT pattern-match names. It cannot: the registration specs use the product's own default
 * tenant names, so the database holds 139 tenants called «My Agency» and 136 called «BrandCo» that
 * are indistinguishable from something a person typed. Deleting by name would be a guess.
 *
 * It keys on the EMAIL DOMAIN of the accounts that hold the tenant, and only on domains RFC 2606 and
 * RFC 6761 reserve for testing. Those can never be delivered to and can never belong to a customer,
 * so an address in one is proof of a fixture rather than evidence of one.
 *
 * A tenant is removed only when EVERY member of it is such an account. One real member is enough to
 * keep it — a shared workspace is somebody's work even when a test account was invited into it.
 *
 * It reports before it deletes (`--force` is required to write), refuses to run outside
 * local/testing/e2e, and never touches a `demo-*` tenant even if every one of its members matched:
 * the demo seed is the live-review fixture, and deleting it would silently break the accounts the
 * whole review programme signs in with.
 */
final class PurgeE2eResidueCommand extends Command
{
    protected $signature = 'db:purge-e2e-residue
        {--force : Actually delete. Without it the command only reports what it would remove.}
        {--include-orphans : Also remove tenants that have no members at all (an abandoned half-finished registration).}';

    protected $description = 'Remove tenants and users created by the E2E suite. Keys on reserved test email domains only; never touches real data.';

    /**
     * Domains reserved for documentation and testing by RFC 2606 / RFC 6761.
     *
     * Mail to these is undeliverable by definition, so no customer can hold one. This is the whole
     * safety argument for the command — widening it to a domain that could receive mail would turn
     * the proof into a guess.
     */
    public const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org', 'example.test', 'probe.test', 'test', 'invalid'];

    /** Tenants the live-review programme signs in with. Protected whoever their members are. */
    private const PROTECTED_SLUG_PREFIX = 'demo-';

    public function handle(): int
    {
        if (! App::environment(['local', 'testing', 'e2e'])) {
            $this->error('db:purge-e2e-residue is disabled outside local/testing/e2e.');

            return self::FAILURE;
        }

        $fixtureUserIds = $this->fixtureUserIds();
        $owned = $this->residueTenantIds($fixtureUserIds->all());
        $orphans = $this->option('include-orphans') ? $this->orphanTenantIds() : collect();

        $this->line(sprintf(
            'Fixture accounts: %d · tenants wholly held by them: %d · member-less tenants: %d',
            $fixtureUserIds->count(),
            $owned->count(),
            $orphans->count(),
        ));

        if (! $this->option('force')) {
            $this->warn('Dry run — nothing was deleted. Re-run with --force.');

            return self::SUCCESS;
        }

        // Everything tenant-scoped cascades in the database, so removing the tenant row takes its
        // client spaces, projects, campaigns, tasks, reports and memberships with it.
        $tenants = Tenant::withTrashed()->whereIn('id', $owned->merge($orphans)->unique())->forceDelete();
        $users = User::whereIn('id', $fixtureUserIds)->delete();

        $this->info("Removed {$tenants} tenant(s) and {$users} fixture account(s).");

        return self::SUCCESS;
    }

    private function fixtureUserIds(): Collection
    {
        return User::query()
            ->where(function (Builder $q): void {
                foreach (self::RESERVED_DOMAINS as $domain) {
                    $q->orWhere('email', 'ilike', '%@'.$domain);
                }
            })
            ->pluck('id');
    }

    /**
     * Tenants whose entire membership is fixture accounts.
     *
     * Expressed as «has a fixture member, and has no member outside the fixture set» rather than «all
     * its members are fixtures», because the second phrasing is also true of a tenant with no members
     * at all — a different case with a different risk, handled separately behind its own flag.
     */
    private function residueTenantIds(array $fixtureUserIds): Collection
    {
        if ($fixtureUserIds === []) {
            return collect();
        }

        return $this->unprotected()
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('memberships')
                ->whereColumn('memberships.tenant_id', 'tenants.id')
                ->whereIn('memberships.user_id', $fixtureUserIds))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('memberships')
                ->whereColumn('memberships.tenant_id', 'tenants.id')
                ->whereNotIn('memberships.user_id', $fixtureUserIds))
            ->pluck('id');
    }

    /**
     * Tenants with no members at all — a registration that created the workspace and then stopped.
     *
     * Behind a flag because it is the one rule here that is NOT proof: a tenant mid-provisioning has
     * no membership yet either, and on a live database that is a row in flight rather than a leftover.
     */
    private function orphanTenantIds(): Collection
    {
        return $this->unprotected()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('memberships')
                ->whereColumn('memberships.tenant_id', 'tenants.id'))
            ->pluck('id');
    }

    private function unprotected(): Builder
    {
        return Tenant::withTrashed()->where('slug', 'not like', self::PROTECTED_SLUG_PREFIX.'%');
    }
}
