<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * A contact named on TWO of the agency's clients (PORTAL-CLIENT-001).
 *
 * The seeded demo had one contact on one client, so the isolated client space had nothing to isolate
 * — the picker never appeared, and the header that narrows every read was never exercised by anything
 * a reviewer could see. This adds a second brand for the same person, which is the ordinary case the
 * feature exists for: a marketing lead who looks after two brands under one agency.
 *
 * Idempotent; safe to re-run.
 */
final class DemoClientSpacesSeeder extends Seeder
{
    private const CONTACT = 'customer@demo-client.local';

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-agency')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->id);

        $existing = ExternalRequest::query()
            ->where('tenant_id', $tenant->id)
            ->whereRaw('lower(contact_email) = ?', [self::CONTACT])
            ->whereNotNull('client_id')
            ->first();

        // Nothing to extend if the primary demo contact has no space of their own yet.
        if ($existing === null) {
            return;
        }

        $second = ClientWorkspace::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'northwind-demo'],
            [
                'name' => 'Northwind (Second brand) — Demo',
                'mode' => 'managed',
                'status' => 'active',
                'client_status' => 'active',
            ],
        );

        ExternalRequest::firstOrCreate(
            ['tenant_id' => $tenant->id, 'reference' => 'REQ-DEMO-NORTHWIND'],
            [
                'type_id' => $existing->type_id ?? RequestType::query()->firstOrFail()->id,
                'status_id' => $existing->status_id ?? RequestStatus::query()->firstOrFail()->id,
                'contact_name' => $existing->contact_name ?: 'Demo Customer',
                'contact_email' => self::CONTACT,
                'contact_phone' => $existing->contact_phone,
                'client_id' => $second->id,
                'submitted_at' => now(),
            ],
        );

        $this->command?->info('Demo: '.self::CONTACT.' now has two isolated client spaces.');
    }
}
