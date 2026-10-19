<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Services\AgencyProvisioner;
use Illuminate\Console\Command;

/**
 * AGENCY-PROVISION-001 — create an agency tenant and invite its owner. Idempotent; prints no secret.
 *
 *   php artisan agency:provision "Smart Code" digital@smartcode.sa
 *
 * The owner is invited, never granted: nothing is accessible until the person who controls the
 * address accepts the emailed link. The invitation token is never printed here — it exists only in
 * the email (and in the response of a non-production environment).
 */
final class ProvisionAgencyCommand extends Command
{
    protected $signature = 'agency:provision {name : The agency name} {owner_email : Who will own it} {--pretend : Say what would happen and change nothing}';

    protected $description = 'Create an isolated agency tenant and invite its owner through a verified invitation';

    public function handle(AgencyProvisioner $agencies): int
    {
        $name = (string) $this->argument('name');
        $email = (string) $this->argument('owner_email');

        if ($this->option('pretend')) {
            $this->line("Would ensure an agency tenant «{$name}» and invite {$email} as its owner.");

            return self::SUCCESS;
        }

        try {
            $result = $agencies->provision($name, $email);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($result['created'] ? 'created' : 'already existed').": tenant {$result['tenant']->id} ({$result['tenant']->slug})");
        $this->line($result['owner_has_access']
            ? 'owner already has access; no invitation issued'
            : ($result['invitation'] !== null
                ? "invitation issued; delivery: {$result['invitation']['delivery_status']}"
                : 'an unexpired invitation is already pending; none re-issued'));

        return self::SUCCESS;
    }
}
