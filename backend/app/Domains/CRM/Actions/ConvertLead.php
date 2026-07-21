<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\CRM\Models\Company;
use App\Domains\CRM\Models\Contact;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Converts a qualified lead into a Company + Contact + Opportunity (default pipeline, first stage).
 * Idempotency: a lead can only be converted once.
 */
final class ConvertLead
{
    public function __construct(
        private readonly EnsureDefaultPipeline $ensureDefaultPipeline,
        private readonly RecordActivity $recordActivity,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Lead $lead, ?string $opportunityName = null): Opportunity
    {
        if ($lead->isConverted()) {
            throw new RuntimeException('This lead has already been converted.');
        }

        return DB::transaction(function () use ($lead, $opportunityName): Opportunity {
            $pipeline = $this->ensureDefaultPipeline->execute();
            $firstStage = $pipeline->stages()->orderBy('sort')->firstOrFail();

            $company = $lead->company_id !== null
                ? Company::findOrFail($lead->company_id)
                : Company::create(['name' => $lead->name]);

            $contact = $lead->contact_id !== null
                ? Contact::find($lead->contact_id)
                : Contact::create([
                    'company_id' => $company->id,
                    'name' => $lead->name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                ]);

            $opportunity = Opportunity::create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $firstStage->id,
                'company_id' => $company->id,
                'contact_id' => $contact?->id,
                'lead_id' => $lead->id,
                'owner_id' => $lead->owner_id,
                'name' => $opportunityName ?? $lead->name,
                'amount' => $lead->estimated_value,
                'currency' => $lead->currency,
                'probability' => $firstStage->probability,
                'status' => 'open',
            ]);

            $lead->update([
                'status' => 'qualified',
                'company_id' => $company->id,
                'contact_id' => $contact?->id,
                'converted_opportunity_id' => $opportunity->id,
                'converted_at' => now(),
            ]);

            $this->recordActivity->execute($lead, 'status_change', 'Converted to opportunity', [
                'opportunity_id' => $opportunity->id,
            ]);
            $this->audit->log(
                action: 'lead.converted',
                entityType: Lead::class,
                entityId: (string) $lead->id,
                after: ['opportunity_id' => $opportunity->id, 'company_id' => $company->id],
            );

            return $opportunity;
        });
    }
}
