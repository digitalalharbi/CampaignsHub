<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\CRM\Models\Lead;
use App\Domains\Integrations\Leads\IngestProviderLeads;
use App\Domains\Integrations\Leads\ProviderLead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LEAD-INGEST-001 — every route a lead arrives by lands in the same place, exactly once.
 */
final class IngestProviderLeadsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-26 10:00:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-ing-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function providerLead(array $over = []): ProviderLead
    {
        return new ProviderLead(
            provider: $over['provider'] ?? 'meta',
            providerLeadId: $over['providerLeadId'] ?? 'lead-1',
            providerCreatedAt: $over['providerCreatedAt'] ?? Carbon::parse('2026-08-26 09:59:30'),
            // `??` treats an explicit null override as absent, which silently un-did the very case
            // the uncontactable test exists to cover. `array_key_exists` distinguishes «not given»
            // from «given as null», which is the whole point of that test.
            name: array_key_exists('name', $over) ? $over['name'] : 'Buyer',
            email: array_key_exists('email', $over) ? $over['email'] : 'Buyer@Example.TEST',
            phone: array_key_exists('phone', $over) ? $over['phone'] : '٠٥٠١٢٣٤٥٦٧',
            campaignId: 'cmp-1', campaignName: 'Riyadh — Q3',
            adId: 'ad-1', creativeId: 'cr-1', formId: 'form-1',
            answers: ['city' => 'Riyadh'],
        );
    }

    public function test_a_lead_arrives_with_its_provenance_and_a_received_time(): void
    {
        $out = app(IngestProviderLeads::class)->handle((string) $this->tenant->id, [$this->providerLead()]);

        $this->assertSame(['ingested' => 1, 'redelivered' => 0, 'uncontactable' => 0], $out);

        $lead = Lead::first();
        $this->assertSame('meta', $lead->provider);
        $this->assertSame('cmp-1', $lead->external_campaign_id);
        $this->assertSame('cr-1', $lead->external_creative_id);
        // The provider's own timestamp and ours are both kept: the gap between them IS the SLA clock.
        $this->assertSame('2026-08-26 09:59:30', $lead->provider_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-26 10:00:00', $lead->received_at->format('Y-m-d H:i:s'));
    }

    public function test_a_redelivery_is_a_success_and_is_counted_apart(): void
    {
        $svc = app(IngestProviderLeads::class);
        $svc->handle((string) $this->tenant->id, [$this->providerLead()]);

        // The provider retries because our 2xx was lost, or a backfill sees what the webhook saw.
        $out = $svc->handle((string) $this->tenant->id, [$this->providerLead()]);

        $this->assertSame(['ingested' => 0, 'redelivered' => 1, 'uncontactable' => 0], $out);
        $this->assertSame(1, Lead::count(), 'a retry must not create a second lead');
    }

    public function test_one_failed_insert_does_not_poison_the_rest_of_the_batch(): void
    {
        /*
         * The savepoint. Postgres aborts the whole transaction a failed statement was in, so a
         * duplicate mid-batch would otherwise refuse every lead after it — a backfill would silently
         * ingest only up to its first retry.
         */
        $svc = app(IngestProviderLeads::class);
        $svc->handle((string) $this->tenant->id, [$this->providerLead(['providerLeadId' => 'lead-1'])]);

        $out = $svc->handle((string) $this->tenant->id, [
            $this->providerLead(['providerLeadId' => 'lead-1']),
            $this->providerLead(['providerLeadId' => 'lead-2']),
            $this->providerLead(['providerLeadId' => 'lead-3']),
        ]);

        $this->assertSame(2, $out['ingested']);
        $this->assertSame(1, $out['redelivered']);
        $this->assertSame(3, Lead::count());
    }

    public function test_contact_keys_are_normalised_so_two_keyboards_are_one_person(): void
    {
        app(IngestProviderLeads::class)->handle((string) $this->tenant->id, [$this->providerLead()]);

        $lead = Lead::first();
        // An Arabic keyboard produces ٠٥٠…; it is the same number as 050….
        $this->assertSame('+966501234567', $lead->phone_normalized);
        $this->assertSame('buyer@example.test', $lead->email_normalized);
        // The raw values are untouched — normalisation is for matching, not for rewriting the record.
        $this->assertSame('Buyer@Example.TEST', $lead->email);
    }

    public function test_a_lead_with_no_way_to_reach_anyone_is_kept_and_reported(): void
    {
        // Still a real acquisition event the client paid for. Dropping it would hide spend that
        // produced nothing reachable, which is exactly what the client needs to see.
        $out = app(IngestProviderLeads::class)->handle((string) $this->tenant->id, [
            $this->providerLead(['providerLeadId' => 'lead-x', 'email' => null, 'phone' => null]),
        ]);

        $this->assertSame(1, $out['ingested']);
        $this->assertSame(1, $out['uncontactable']);
        $this->assertSame(1, Lead::count());
    }

    public function test_two_providers_may_use_the_same_lead_id(): void
    {
        // The uniqueness is per provider: Meta's «1» and TikTok's «1» are different leads.
        $svc = app(IngestProviderLeads::class);
        $svc->handle((string) $this->tenant->id, [$this->providerLead(['provider' => 'meta', 'providerLeadId' => '1'])]);
        $out = $svc->handle((string) $this->tenant->id, [$this->providerLead(['provider' => 'tiktok', 'providerLeadId' => '1'])]);

        $this->assertSame(1, $out['ingested']);
        $this->assertSame(2, Lead::count());
    }
}
