<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Database\Seeders\TaxonomyEngineSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * PROD-PROVISION-001 — the reference data a production install cannot work without.
 *
 * ## The defect this exists to close
 *
 * `scripts/deploy-production.sh` ran `migrate --force` and nothing else, because the deployment
 * checklist says — correctly — never to seed a production database. But "seed" had come to mean two
 * different things wearing one command:
 *
 * - **Demo data**: demo tenants, demo logins, ten months of invented metrics. Must never reach
 *   production, and `DemoPortalLoginsSeeder::shouldRun()` already refuses there.
 * - **Reference data**: the permission catalogue, the request service types, the SUBSCRIPTION PLANS,
 *   the paid-media service taxonomy, the metric definitions. Structural facts the product is built
 *   out of, with no tenant, no customer and nothing invented in them.
 *
 * `DatabaseSeeder` runs the second group unconditionally, in every environment — but a production
 * deploy never invoked any seeder at all, so the second group never arrived. The live site was
 * migrated, healthy, and empty of the things it sells:
 *
 * ```
 * GET /api/v1/plans                                → {"plans": []}
 * GET /api/v1/public/catalog/paid-media-services   → {"categories": [], "services": []}
 * ```
 *
 * Which is why the homepage showed no services, `/services` said «لا توجد خدمة مطابقة», and sign-up
 * died at plan selection with «تعذّر»: there was nothing to choose. Not a bug in any of those
 * screens — every one of them was faithfully rendering an empty catalogue.
 *
 * ## Why a command and not `db:seed`
 *
 * `db:seed` runs `DatabaseSeeder`, which knows about the demo chain. Even though that chain is
 * environment-guarded, telling an operator to run the command whose name means "fill the database
 * with demo data" on production is the kind of instruction that goes wrong once and cannot be undone.
 * This one names exactly what it does and CANNOT reach a demo seeder — the list below is the whole
 * command.
 *
 * ## Safe to run on every deploy
 *
 * Every seeder here is idempotent — `updateOrCreate`/`upsert` against a stable key — so running it
 * on each deploy is how the catalogue stays current when a plan or a service is added, rather than a
 * one-off somebody has to remember. It creates no tenant, no user and no customer-visible record.
 */
final class ProvisionPlatformCommand extends Command
{
    protected $signature = 'platform:provision {--pretend : List what would run and change nothing}';

    protected $description = 'Provision the reference data a production install needs. Never touches demo data.';

    /**
     * The reference seeders, in dependency order.
     *
     * Permissions first because roles reference them; the rest are independent. Deliberately a
     * literal list rather than a scan of the seeders directory: a new demo seeder appearing in that
     * directory must never start running in production because somebody named it well.
     *
     * @var list<class-string>
     */
    private const REFERENCE_SEEDERS = [
        PermissionSeeder::class,
        RequestCatalogSeeder::class,
        SubscriptionPlanSeeder::class,
        TaxonomyEngineSeeder::class,
        MetricDefinitionSeeder::class,
    ];

    public function handle(): int
    {
        $this->components->info('Provisioning platform reference data.');

        foreach (self::REFERENCE_SEEDERS as $seeder) {
            $name = class_basename($seeder);

            if ($this->option('pretend')) {
                $this->components->twoColumnDetail($name, '<fg=yellow>would run</>');

                continue;
            }

            $started = microtime(true);

            /*
             * Unguarded, exactly as `db:seed` runs a seeder.
             *
             * `SeedCommand::handle()` wraps seeders in `Model::unguarded()`, and these were written
             * against that. Running them guarded here would silently drop non-fillable attributes and
             * provision subtly different rows in production than in every environment they were
             * tested in — the worst kind of difference, because both look like they worked.
             */
            Model::unguarded(fn () => $this->getLaravel()->make($seeder)->setCommand($this)->run());

            $this->components->twoColumnDetail($name, sprintf('<fg=green>done</> %.0fms', (microtime(true) - $started) * 1000));
        }

        $this->newLine();
        $this->components->info('Reference data is in place. No demo data was created.');

        return self::SUCCESS;
    }
}
