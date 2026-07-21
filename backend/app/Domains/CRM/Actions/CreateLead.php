<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\CRM\DTOs\LeadData;
use App\Domains\CRM\Models\Lead;
use Illuminate\Support\Facades\DB;

final class CreateLead
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function execute(LeadData $data): Lead
    {
        return DB::transaction(function () use ($data): Lead {
            $lead = Lead::create($data->toAttributes());

            $this->recordActivity->execute($lead, 'status_change', 'Lead created', ['status' => $lead->status]);
            $this->audit->log(
                action: 'lead.created',
                entityType: Lead::class,
                entityId: (string) $lead->id,
                after: $lead->only(['name', 'email', 'source', 'status', 'estimated_value']),
            );

            return $lead;
        });
    }
}
