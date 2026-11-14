<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Models\ContentApproval;
use App\Domains\Projects\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CLIENT-CONTENT-APPROVAL-001 — the one place a content approval changes state.
 *
 * The same shape as a quote: the agency raises it (`pending`), the client answers once (`approved` or
 * `changes_requested`, a note required for the second), the agency may withdraw an unanswered ask. Every
 * transition is audited with who did it. Reach is checked by the CALLERS (agency: `content.*` + client reach +
 * project reach; portal: the session's space, project ceiling, `content` visibility, `approve_content`); this
 * class checks that the rows agree with each other — project under the client, creative under the project, all
 * in one tenant — so a guessed id from elsewhere cannot be stitched in.
 */
final class ContentApprovals
{
    /**
     * @param  array{project_id: string, title: string, caption?: ?string, creative_id?: ?string}  $data
     */
    public function create(ClientWorkspace $client, array $data, User $by, ?string $ip = null, ?string $agent = null): ContentApproval
    {
        $tenantId = (string) $client->tenant_id;

        $project = Project::withoutGlobalScopes()->whereKey($data['project_id'])
            ->where('tenant_id', $tenantId)->where('client_workspace_id', $client->getKey())->first();
        if ($project === null) {
            throw new InvalidArgumentException('project');
        }

        $creativeId = $data['creative_id'] ?? null;
        if ($creativeId !== null) {
            $creative = ExternalCreative::withoutGlobalScopes()->whereKey($creativeId)
                ->where('tenant_id', $tenantId)->where('project_id', $project->getKey())->first();
            if ($creative === null) {
                throw new InvalidArgumentException('creative');
            }
        }

        return DB::transaction(function () use ($client, $project, $data, $creativeId, $by, $tenantId, $ip, $agent): ContentApproval {
            $approval = ContentApproval::create([
                'tenant_id' => $tenantId,
                'client_workspace_id' => $client->getKey(),
                'project_id' => $project->getKey(),
                'creative_id' => $creativeId,
                'title' => $data['title'],
                'caption' => $data['caption'] ?? null,
                'status' => 'pending',
                'requested_by' => $by->getKey(),
            ]);

            $this->audit($tenantId, (int) $by->getKey(), 'content_approval.created', $approval, null, ['status' => 'pending', 'project_id' => (string) $project->getKey()], $ip, $agent);

            return $approval;
        });
    }

    /**
     * The agency takes back an ask nobody has answered yet.
     *
     * Locked and re-read like `decide()`: a client who approves between the agency loading the row and
     * pressing «اسحب» keeps their answer — the withdraw is refused rather than writing over it.
     */
    public function withdraw(ContentApproval $approval, User $by, ?string $ip = null, ?string $agent = null): ContentApproval
    {
        return DB::transaction(function () use ($approval, $by, $ip, $agent): ContentApproval {
            /** @var ContentApproval $locked */
            $locked = ContentApproval::withoutGlobalScopes()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException("Content approval cannot be withdrawn from status [{$locked->status}].");
            }

            $locked->update(['status' => 'withdrawn']);
            $this->audit((string) $locked->tenant_id, (int) $by->getKey(), 'content_approval.withdrawn', $locked, ['status' => 'pending'], ['status' => 'withdrawn'], $ip, $agent);

            return $locked;
        });
    }

    /**
     * The client's answer. Written once: a decided ask is not re-decided, so «approved» cannot silently flip.
     *
     * @param  'approved'|'changes_requested'  $decision
     */
    public function decide(ContentApproval $approval, string $decision, ?string $note, ?User $user, ?string $contact, ?string $ip = null, ?string $agent = null): ContentApproval
    {
        if (! in_array($decision, ['approved', 'changes_requested'], true)) {
            throw new InvalidArgumentException('decision');
        }
        if ($decision === 'changes_requested' && trim((string) $note) === '') {
            throw new InvalidArgumentException('Say what should change.');
        }

        return DB::transaction(function () use ($approval, $decision, $note, $user, $contact, $ip, $agent): ContentApproval {
            /** @var ContentApproval $locked */
            $locked = ContentApproval::withoutGlobalScopes()->whereKey($approval->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new InvalidArgumentException("Content approval cannot be decided from status [{$locked->status}].");
            }

            $locked->update([
                'status' => $decision,
                'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'decided_by_user_id' => $user?->getKey(),
                'decided_by_contact' => $contact !== null ? substr($contact, 0, 190) : null,
                'decided_at' => Carbon::now(),
            ]);

            $this->audit((string) $locked->tenant_id, $user === null ? null : (int) $user->getKey(), 'content_approval.'.$decision, $locked, ['status' => 'pending'], ['status' => $decision], $ip, $agent);

            return $locked;
        });
    }

    /**
     * What a client may read about one ask: no tenant, no requester, no internal ids beyond its own, and no
     * campaign name — the creative's client-facing name only (OWNER: no campaign names on client surfaces).
     *
     * @return array<string, mixed>
     */
    public function clientShape(ContentApproval $a): array
    {
        $creative = $a->creative;

        return [
            'id' => (string) $a->getKey(),
            'title' => $a->title,
            'caption' => $a->caption,
            'status' => $a->status,
            'format' => $creative?->format,
            'thumbnail_url' => self::safeUrl($creative?->thumbnail_url),
            'decision_note' => $a->decision_note,
            'decided_at' => optional($a->decided_at)->toIso8601String(),
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function agencyShape(ContentApproval $a): array
    {
        return $this->clientShape($a) + [
            'project_id' => (string) $a->project_id,
            'creative_id' => $a->creative_id,
            'decided_by_contact' => $a->decided_by_contact,
        ];
    }

    /** Only an https address is ever handed to a browser as an image source. */
    private static function safeUrl(?string $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function audit(string $tenantId, ?int $userId, string $action, ContentApproval $a, ?array $before, ?array $after, ?string $ip, ?string $agent): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'content_approval',
            'entity_id' => (string) $a->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $ip,
            'user_agent' => $agent !== null ? substr($agent, 0, 500) : null,
        ]);
    }
}
