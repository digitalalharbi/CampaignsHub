<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CP-REQ-ONE-001 — a client opens a request from INSIDE their portal.
 *
 * It writes the SAME `ExternalRequest` the agency inbox reads: one entity, one reference, one
 * timeline. Nothing the client sends decides where the row lives. The client space comes from the
 * session, the project must belong to that space (and to the user's project reach when they are
 * confined to some), and a related campaign, report or invoice is accepted only when it too belongs
 * to that space — an id from another client resolves to nothing and is refused the same way as an
 * unknown one, so the form cannot be used to probe for it.
 */
final class ClientRequestCreator
{
    /**
     * @param  array<string,mixed>  $data  validated: type, message, priority?, project_id?, related?
     * @param  list<string>|null  $projectReach  null means the whole space
     *
     * @throws \InvalidArgumentException when the project or the related record is not this client's
     */
    public function create(ClientWorkspace $space, ClientPortalToken $token, array $data, ?array $projectReach): ExternalRequest
    {
        $projectId = $data['project_id'] ?? null;
        if ($projectId !== null) {
            $inSpace = Project::query()->where('client_workspace_id', $space->id)->whereKey($projectId)->exists();
            if (! $inSpace || ($projectReach !== null && ! in_array((string) $projectId, $projectReach, true))) {
                throw new \InvalidArgumentException('project');
            }
        } elseif ($projectReach !== null && count($projectReach) === 1) {
            // A user confined to exactly one project is asking about that one.
            $projectId = $projectReach[0];
        }

        [$campaignId, $related] = $this->related($space, $projectId, $projectReach, (array) ($data['related'] ?? []));

        return DB::transaction(function () use ($space, $token, $data, $projectId, $campaignId, $related): ExternalRequest {
            $type = RequestType::where('key', $data['type'])->where('is_active', true)->firstOrFail();
            $status = RequestStatus::where('key', 'new')->firstOrFail();

            $request = new ExternalRequest;
            $request->fill([
                'tenant_id' => $token->tenant_id,
                'reference' => $this->reference(),
                'module' => $type->module,
                'type_id' => $type->id,
                'status_id' => $status->id,
                'priority' => $data['priority'] ?? 'medium',
                'source' => 'client_portal',
                'client_id' => $space->id,
                'project_id' => $projectId,
                'campaign_id' => $campaignId,
                'contact_name' => (string) ($token->contact_email ?: $space->name),
                'contact_email' => (string) ($token->contact_email ?? ''),
                'contact_phone' => $token->contact_phone,
                'company_name' => $space->name,
                'objective' => $data['message'],
                'sla_started_at' => now(),
                'sla_due_at' => now()->addHours((int) config('requests.default_sla_hours', 48)),
                'submitted_at' => now(),
                'last_activity_at' => now(),
                'is_external' => true,
                'is_demo' => false,
                'metadata' => $related === null ? [] : ['related' => $related],
            ]);
            $request->save();

            $request->events()->create([
                'type' => 'created',
                'to_status' => 'new',
                'is_client_visible' => true,
                'message' => 'Request submitted',
                'created_at' => now(),
            ]);

            return $request->fresh(['type', 'status']);
        });
    }

    /**
     * @param  list<string>|null  $projectReach
     * @param  array<string,mixed>  $related  {kind: campaign|report|invoice, id}
     * @return array{0: ?string, 1: ?array{kind: string, id: string}}
     */
    private function related(ClientWorkspace $space, ?string $projectId, ?array $projectReach, array $related): array
    {
        $kind = $related['kind'] ?? null;
        $id = isset($related['id']) ? (string) $related['id'] : null;

        if ($kind === null || $id === null) {
            return [null, null];
        }

        $spaceProjects = Project::query()->where('client_workspace_id', $space->id)
            ->when($projectReach !== null, fn ($q) => $q->whereIn('id', $projectReach))
            ->pluck('id')->map(fn ($p): string => (string) $p)->all();

        $found = match ($kind) {
            'campaign' => UnifiedCampaign::query()->whereIn('project_id', $spaceProjects)->whereKey($id)->exists(),
            'report' => Report::query()->whereIn('project_id', $spaceProjects)->whereKey($id)->exists(),
            // Invoices are client-wide paperwork: a project-confined user has none of their own.
            'invoice' => $projectReach === null && Invoice::query()->where('client_workspace_id', $space->id)->whereKey($id)->exists(),
            default => false,
        };

        if (! $found) {
            throw new \InvalidArgumentException('related');
        }

        return [$kind === 'campaign' ? $id : null, ['kind' => $kind, 'id' => $id]];
    }

    private function reference(): string
    {
        do {
            $ref = 'REQ-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (ExternalRequest::where('reference', $ref)->exists());

        return $ref;
    }
}
