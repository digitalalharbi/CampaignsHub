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

            /*
             * LEAD-PROVENANCE-001 — an edit may never rewrite the acquisition event.
             *
             * The team corrects names, fixes phone numbers and moves statuses all day. None of that
             * changes which creative produced the click. If an update could reach these columns, a
             * campaign's measured performance would drift every time somebody tidied a record, and
             * the report would stop being evidence of anything.
             *
             * Stripped here rather than guarded at the request layer, because this action is what
             * both the API and any future importer go through, and a rule enforced at one entrance
             * is a rule with another way in.
             */
            $lead->update(collect($data->toAttributes())->except(Lead::PROVENANCE)->all());

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
