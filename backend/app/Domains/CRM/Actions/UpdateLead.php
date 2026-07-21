<?php

declare(strict_types=1);

namespace App\Domains\CRM\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\CRM\DTOs\LeadData;
use App\Domains\CRM\Models\Lead;
use Illuminate\Support\Facades\DB;

final class UpdateLead
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecordActivity $recordActivity,
    ) {}

    public function execute(Lead $lead, LeadData $data): Lead
    {
        return DB::transaction(function () use ($lead, $data): Lead {
            $before = $lead->only(['name', 'email', 'source', 'status', 'estimated_value']);
            $previousStatus = $lead->status;

            $lead->update($data->toAttributes());

            if ($previousStatus !== $lead->status) {
                $this->recordActivity->execute(
                    $lead,
                    'status_change',
                    "Status changed from {$previousStatus} to {$lead->status}",
                    ['from' => $previousStatus, 'to' => $lead->status],
                );
            }

            $this->audit->log(
                action: 'lead.updated',
                entityType: Lead::class,
                entityId: (string) $lead->id,
                before: $before,
                after: $lead->only(['name', 'email', 'source', 'status', 'estimated_value']),
            );

            return $lead->refresh();
        });
    }
}
