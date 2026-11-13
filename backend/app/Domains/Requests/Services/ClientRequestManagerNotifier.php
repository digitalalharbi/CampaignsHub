<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * CLIENT-REQUEST-PM-NOTICE-001 — a client filed a request; THEIR project manager is told.
 *
 * Who: the project manager recorded on the request's project (`projects.account_manager_id`); with no project
 * on the request, the managers of the client's live projects. A manager counts only while they hold an ACTIVE
 * agency/app membership of THIS tenant — a person who left, or who belongs to another workspace, is never
 * addressed. Nobody left → the workspace owner (the earliest active owner membership).
 *
 * How: through `NotificationDispatcher`, as `request.client_submitted` — the bell always (unless switched off),
 * and email exactly as the person's own «الطلبات» settings say (dedup, quiet hours, ceiling, the one mailer
 * and its ledger). Nothing here can change the client's answer: it runs after the request exists, and any
 * failure stays here.
 */
final class ClientRequestManagerNotifier
{
    public const TYPE = 'request.client_submitted';

    public function __construct(private readonly NotificationDispatcher $dispatcher, private readonly ClientAccess $access) {}

    /** @return list<int> the user ids notified (for the caller's tests and logs) */
    public function notify(ExternalRequest $request): array
    {
        if ($request->isPlatformOwned() || $request->tenant_id === null) {
            return [];
        }

        try {
            $recipients = $this->recipients($request);
            $company = (string) ($request->company_name ?: $request->reference);

            foreach ($recipients as $userId) {
                $this->dispatcher->dispatch([
                    'tenant_id' => (string) $request->tenant_id,
                    'user_id' => $userId,
                    'client_workspace_id' => $request->client_id,
                    'project_id' => $request->project_id,
                    'type' => self::TYPE,
                    'severity' => 'info',
                    'title' => "New client request {$request->reference}",
                    'message' => "{$company} sent a request from the client portal.",
                    'title_ar' => "طلب جديد من العميل {$request->reference}",
                    'message_ar' => "أرسل {$company} طلبًا من بوابة العملاء.",
                    'source' => 'requests',
                    'entity_type' => 'external_request',
                    'entity_id' => $request->id,
                    'action_url' => "/agency/requests/{$request->id}",
                ]);
            }

            return $recipients;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @return list<int> */
    public function recipients(ExternalRequest $request): array
    {
        $tenantId = (string) $request->tenant_id;

        $projects = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNotNull('account_manager_id');
        if ($request->project_id !== null) {
            $projects->whereKey($request->project_id);
        } elseif ($request->client_id !== null) {
            $projects->where('client_workspace_id', $request->client_id)->whereNotIn('status', ['closed', 'archived']);
        } else {
            $projects->whereRaw('1 = 0');
        }

        $managers = $projects->pluck('account_manager_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $active = $managers === [] ? [] : $this->activeAgencyMembers($tenantId)->whereIn('user_id', $managers)
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        // A manager whose reach to THIS client was taken away is not told about it, though their name is still on
        // the project (the same `ClientAccess` question every client page asks).
        if ($active !== [] && $request->client_id !== null) {
            $client = ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($request->client_id);
            $active = $client === null ? [] : array_values(array_filter($active, function (int $id) use ($client): bool {
                $user = User::query()->find($id);

                return $user !== null && $this->access->canAccessClient($user, $client);
            }));
        }

        if ($active !== []) {
            return $active;
        }

        $owner = $this->activeAgencyMembers($tenantId)->where('role', 'owner')->orderBy('created_at')->value('user_id');

        return $owner === null ? [] : [(int) $owner];
    }

    /** @return Builder<Membership> */
    private function activeAgencyMembers(string $tenantId)
    {
        return Membership::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('portal', [Portal::Agency->value, Portal::App->value]);
    }
}
