<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Notifications\Mail\SpendSummaryMail;
use App\Domains\Notifications\Models\ExternalDigestRecipient;
use App\Domains\Notifications\Providers\ProviderRegistry;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\ProjectSpendSummary;
use App\Domains\Notifications\Services\SpendSummaryDispatcher;
use App\Domains\Notifications\Support\MailAddress;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — the workspace's recipients OUTSIDE the platform, under Settings → Notifications.
 *
 * Deny by default:
 *   - only a member who may manage settings (`settings.manage`) lists, adds, changes or removes one;
 *   - every recipient and every bound project is the CURRENT tenant's — a project id of another workspace is refused (422),
 *     and a recipient of another workspace is not found (404, through the tenant scope);
 *   - a project may be bound only if the member managing it can open it (`DigestScope::visibleProjectIds`, the membership's
 *     ceiling, not their own digest preference) AND may see its money (`money.view`), so nobody can mail out figures they
 *     themselves may not see;
 *   - a cap per workspace (`mail.external_recipients_per_tenant`) and a valid address; every change is audited.
 *
 * «أرسل تجربة لي» sends the external mail to the signed-in member's OWN address only — never to the recipient.
 */
final class ExternalRecipientController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly DigestScope $scope,
        private readonly AuditLogger $audit,
        private readonly ProjectAbilities $abilities,
    ) {}

    /** The projects this member may bind to a recipient (and send themselves a test about): visible, with their money visible. */
    public function projects(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $tenantId = $this->tenants->tenantId();
        $ids = $this->bindable($request->user(), $tenantId);

        return ApiResponse::success(
            $ids === [] ? [] : Project::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Project $p): array => ['id' => (string) $p->id, 'name' => (string) $p->name])->all(),
            'Projects that may be bound.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return ApiResponse::success(
            ExternalDigestRecipient::query()->orderBy('email')->get()->map(fn (ExternalDigestRecipient $r): array => $this->present($r))->all(),
            'External recipients.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request, null);
        $tenantId = $this->tenants->tenantId();

        if (ExternalDigestRecipient::query()->count() >= (int) config('mail.external_recipients_per_tenant', 25)) {
            return ApiResponse::error('This workspace has reached its number of outside recipients.', ['code' => 'recipient_cap_reached'], status: 422);
        }

        $recipient = ExternalDigestRecipient::create([
            'tenant_id' => $tenantId,
            'email' => Str::lower(trim($data['email'])),
            'name' => $data['name'] ?? null,
            'cadence' => $data['cadence'],
            'active' => true,
            'created_by' => $request->user()?->getKey(),
        ]);
        $recipient->bindProjects($data['project_ids']);

        $this->audit->log(action: 'notifications.external_recipient.created', entityType: ExternalDigestRecipient::class, entityId: (string) $recipient->id,
            after: ['recipient' => MailAddress::mask($recipient->email), 'cadence' => $recipient->cadence, 'projects' => $recipient->projectIds()]);

        return ApiResponse::success($this->present($recipient), 'External recipient added.', status: 201);
    }

    public function update(Request $request, string $recipient): JsonResponse
    {
        $this->authorizeManage($request);
        $model = ExternalDigestRecipient::query()->whereKey($recipient)->firstOrFail();
        $data = $this->validated($request, $model);
        $before = ['cadence' => $model->cadence, 'active' => $model->active, 'projects' => $model->projectIds()];

        $model->fill(array_intersect_key($data, array_flip(['name', 'cadence', 'active'])))->save();
        if (array_key_exists('project_ids', $data)) {
            $model->bindProjects($data['project_ids']);
        }

        $this->audit->log(action: 'notifications.external_recipient.updated', entityType: ExternalDigestRecipient::class, entityId: (string) $model->id,
            before: $before, after: ['cadence' => $model->cadence, 'active' => $model->active, 'projects' => $model->projectIds()]);

        return ApiResponse::success($this->present($model), 'External recipient updated.');
    }

    public function destroy(Request $request, string $recipient): JsonResponse
    {
        $this->authorizeManage($request);
        $model = ExternalDigestRecipient::query()->whereKey($recipient)->firstOrFail();
        $this->audit->log(action: 'notifications.external_recipient.deleted', entityType: ExternalDigestRecipient::class, entityId: (string) $model->id,
            before: ['recipient' => MailAddress::mask($model->email), 'projects' => $model->projectIds()]);
        $model->delete();

        return ApiResponse::success(null, 'External recipient removed.');
    }

    /** «أرسل تجربة لي» — what a recipient would receive, sent to the signed-in member only, over projects they can see. */
    public function test(Request $request, ProjectSpendSummary $summary, ProviderRegistry $providers): JsonResponse
    {
        $this->authorizeManage($request);
        /** @var User $user */
        $user = $request->user();
        if ($user->email_verified_at === null) {
            return ApiResponse::error('Your address has not been verified, so nothing can be sent to it.', ['code' => 'address_not_proven'], status: 422);
        }
        $data = $request->validate([
            'project_ids' => ['sometimes', 'array', 'max:50'],
            'project_ids.*' => ['uuid'],
            'cadence' => ['sometimes', Rule::in(ExternalDigestRecipient::CADENCES)],
        ]);
        $tenantId = $this->tenants->tenantId();
        $visible = $this->bindable($user, $tenantId);
        $projectIds = array_values(array_intersect($visible, (array) ($data['project_ids'] ?? $visible)));
        if ($projectIds === []) {
            return ApiResponse::error('There is no project whose spend you may see.', ['code' => 'no_projects'], status: 422);
        }
        if (! $providers->isConfigured('email')) {
            return ApiResponse::error('No email provider is configured, so nothing was sent.', ['code' => 'no_email_provider'], status: 422);
        }
        $cadence = (string) ($data['cadence'] ?? 'weekly');
        [$from, $to] = SpendSummaryDispatcher::window($cadence, Carbon::now('Asia/Riyadh'));

        $mail = new SpendSummaryMail($summary->build($tenantId, $projectIds, $from, $to, Carbon::now('Asia/Riyadh')->startOfDay()), 'ar', $cadence, external: true, recipientName: (string) $user->name);
        Mail::to($user->email)->send($mail);
        $this->audit->log(action: 'notifications.external_recipient.test_sent', after: ['recipient' => MailAddress::mask((string) $user->email), 'projects' => $projectIds]);

        return ApiResponse::success(['state' => 'sent', 'projects' => count($projectIds)], 'Test sent to you.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?ExternalDigestRecipient $model): array
    {
        $tenantId = $this->tenants->tenantId();
        $creating = $model === null;
        // Stored lower-cased, so it is compared lower-cased: «A@x.com» next to «a@x.com» is a 422, not a unique-index 500.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
        $data = $request->validate([
            'email' => [$creating ? 'required' : 'prohibited', 'string', 'max:254', 'email:rfc',
                Rule::unique('external_digest_recipients', 'email')->where('tenant_id', $tenantId)],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'cadence' => [$creating ? 'required' : 'sometimes', Rule::in(ExternalDigestRecipient::CADENCES)],
            'active' => ['sometimes', 'boolean'],
            'project_ids' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            'project_ids.*' => ['uuid', 'distinct'],
        ]);

        if (array_key_exists('project_ids', $data)) {
            $ids = array_values(array_unique(array_map('strval', $data['project_ids'])));
            // The CURRENT workspace's projects only — another workspace's id is refused, not silently dropped.
            $own = Project::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('id', $ids)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
            // …and only projects the member managing this can open, with their money visible to them.
            $visible = $this->bindable($request->user(), $tenantId);
            $refused = array_values(array_diff($ids, array_intersect($own, $visible)));
            if ($refused !== []) {
                abort(response()->json(['success' => false, 'message' => 'Some projects cannot be bound to this recipient.', 'errors' => ['project_ids' => ['not_in_your_workspace']]], 422));
            }
            $data['project_ids'] = $ids;
        }

        return $data;
    }

    /** @return list<string> */
    private function bindable(User $user, string $tenantId): array
    {
        $ids = $this->scope->visibleProjectIds($user, $tenantId);
        if (! ProjectCapability::exists('money.view')) {
            return $ids;
        }

        return array_values(array_filter($ids, fn (string $id): bool => $this->abilities->allows($user, $id, 'money.view')));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->hasPermission('settings.manage'), 403);
    }

    /** @return array<string,mixed> */
    private function present(ExternalDigestRecipient $r): array
    {
        return [
            'id' => (string) $r->id,
            'email' => $r->email,
            'name' => $r->name,
            'cadence' => $r->cadence,
            'active' => $r->active,
            'project_ids' => $r->projectIds(),
        ];
    }
}
