<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\CRM\Actions\UpdateLead;
use App\Domains\CRM\DTOs\LeadData;
use App\Domains\CRM\Models\Lead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LEAD-PROVENANCE-001 — a lead remembers which ad produced it, and an edit cannot change that.
 *
 * The CRM already modelled a lead well enough for somebody typing one in. What it could not record
 * is the only thing a paid lead-generation client is buying: which campaign, which ad, which
 * creative. `source` was an enum whose widest answer was «paid», and «paid» cannot be acted on.
 *
 * Two claims are load-bearing here and both are enforced by the database or by code, not convention:
 * provenance survives editing, and the same provider lead cannot be ingested twice.
 */
final class LeadProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-lead-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** @param array<string,mixed> $over */
    private function lead(array $over = []): Lead
    {
        return Lead::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '+966500000001',
            'source' => 'paid', 'status' => 'new',
            'provider' => 'meta',
            'provider_lead_id' => 'meta-lead-'.uniqid(),
            'provider_created_at' => Carbon::parse('2026-08-20 09:00:00'),
            'received_at' => Carbon::parse('2026-08-20 09:00:04'),
            'external_campaign_id' => 'cmp-1', 'campaign_name' => 'Riyadh — Q3',
            'external_adset_id' => 'set-1', 'adset_name' => 'Riyadh 25-45',
            'external_ad_id' => 'ad-1', 'ad_name' => 'Villa carousel',
            'external_creative_id' => 'cr-1', 'creative_name' => 'Villa 3BR',
            'form_id' => 'form-1', 'form_name' => 'Register interest',
            'utm_source' => 'meta', 'utm_campaign' => 'q3-riyadh',
            'form_answers' => ['city' => 'Riyadh', 'budget' => '1.5-2M'],
        ], $over));
    }

    public function test_a_lead_records_the_whole_hierarchy_that_produced_it(): void
    {
        $lead = $this->lead()->fresh();

        $this->assertSame('meta', $lead->provider);
        $this->assertSame('cmp-1', $lead->external_campaign_id);
        $this->assertSame('set-1', $lead->external_adset_id);
        $this->assertSame('ad-1', $lead->external_ad_id);
        $this->assertSame('cr-1', $lead->external_creative_id);
        $this->assertSame('form-1', $lead->form_id);
        // The form's own answers stay structured, so a real-estate question does not need a column.
        $this->assertSame('Riyadh', $lead->form_answers['city']);
    }

    public function test_a_direct_mass_assignment_cannot_rewrite_provenance(): void
    {
        /*
         * The real exposure, and the one the first version of this test missed.
         *
         * Provenance must be fillable so ingestion writes it in one insert — and that same
         * fillability is what any `$lead->update($request->all())` elsewhere would ride in on. The
         * guard lives on the model for that reason, so this asserts the thing that can actually
         * happen rather than a path that happens to drop the fields upstream.
         */
        $lead = $this->lead();

        $lead->update([
            'name' => 'Edited',
            'external_campaign_id' => 'cmp-999',
            'external_creative_id' => 'cr-999',
            'provider' => 'tiktok',
            'utm_campaign' => 'someone-elses',
        ]);

        $lead->refresh();

        $this->assertSame('Edited', $lead->name, 'the editable part must still be editable');
        $this->assertSame('cmp-1', $lead->external_campaign_id);
        $this->assertSame('cr-1', $lead->external_creative_id);
        $this->assertSame('meta', $lead->provider);
        $this->assertSame('q3-riyadh', $lead->utm_campaign);
    }

    public function test_the_crm_edit_path_leaves_provenance_alone_too(): void
    {
        $lead = $this->lead();

        app(UpdateLead::class)->execute($lead, LeadData::fromArray([
            'name' => 'Buyer, corrected',
            'email' => 'buyer@example.test',
            'phone' => '+966500000001',
            'source' => 'paid',
            'status' => 'contacted',
            // A caller trying to move the provenance along with the edit — which must not work.
            'external_campaign_id' => 'cmp-999',
            'external_creative_id' => 'cr-999',
            'provider' => 'tiktok',
        ]));

        $lead->refresh();

        $this->assertSame('Buyer, corrected', $lead->name, 'the editable part must still be editable');
        $this->assertSame('contacted', $lead->status);
        // The part that must not move.
        $this->assertSame('cmp-1', $lead->external_campaign_id);
        $this->assertSame('cr-1', $lead->external_creative_id);
        $this->assertSame('meta', $lead->provider);
    }

    public function test_the_same_provider_lead_cannot_be_ingested_twice(): void
    {
        // Providers retry deliveries, and a webhook and a backfill both see the same lead. The
        // database refuses the second copy rather than the code asking «does this exist?» and racing
        // itself between the two.
        $first = $this->lead(['provider_lead_id' => 'meta-lead-fixed']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->lead(['provider_lead_id' => 'meta-lead-fixed', 'name' => 'Same person again']);
    }

    public function test_two_campaigns_reaching_one_person_stay_two_acquisition_events(): void
    {
        /*
         * The distinction the whole dedup design rests on. One person, two ads, two clicks — that is
         * one contact and TWO acquisition events, and the second campaign paid for its one. Merging
         * them into a single row would erase the attribution the client is paying to measure.
         */
        $first = $this->lead(['external_campaign_id' => 'cmp-1']);
        $second = $this->lead([
            'provider_lead_id' => 'meta-lead-second',
            'external_campaign_id' => 'cmp-2',
            'campaign_name' => 'Jeddah — Q3',
            'canonical_lead_id' => $first->id,
            'duplicate_reason' => 'phone',
        ]);

        $this->assertTrue($second->fresh()->isDuplicate());
        $this->assertSame($first->id, $second->fresh()->canonical->id);
        $this->assertCount(1, $first->fresh()->duplicates);
        // Each event keeps its own campaign — that is the point.
        $this->assertSame('cmp-1', $first->fresh()->external_campaign_id);
        $this->assertSame('cmp-2', $second->fresh()->external_campaign_id);
    }
}
