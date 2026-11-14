<?php

declare(strict_types=1);

namespace App\Domains\Funding\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\AddFundingPayment;
use App\Domains\Funding\Services\FundingAccountGuard;
use App\Domains\Funding\Services\FundingBalance;
use App\Domains\Funding\Services\FundingExport;
use App\Domains\Funding\Services\FundingMonth;
use App\Domains\Funding\Services\FundingReach;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * FIN-LOOP-001 — the approved budget of each project: read by finance, project managers and the
 * campaigns team; added to and renewed by finance only (`funding.manage`).
 *
 * Reach is the agency's own: a member confined to some clients (or some of a client's projects) reads
 * and writes those only, and a project outside the reach answers 404 so its existence is not confirmed.
 */
final class FundingController extends Controller
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly FundingBalance $balance,
        private readonly FundingSpendRefresher $refresher,
        private readonly FundingReach $reach,
        private readonly FundingMonth $months,
    ) {}

    /** GET funding — every funded project the reader reaches, with its live figures, needing a decision first. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->reader($request);
        [$fundings, $names] = $this->reachable($user);

        $rows = $fundings
            ->map(fn (ProjectFunding $f): array => [
                'project_name' => $f->project?->name,
                'client_name' => $names[$f->client_workspace_id] ?? null,
            ] + $this->balance->of($f))
            ->sortBy(fn (array $r): array => [self::NEED[$r['status']] ?? 9, $r['remaining'] ?? PHP_FLOAT_MAX])
            ->values();

        // The four tiles: totals of the prepaid budgets (only they have a remaining), and what needs a decision.
        $prepaid = $rows->where('payment_method', 'prepaid');

        return ApiResponse::success($rows->all(), 'Budgets.', [
            'totals' => [
                'approved' => round((float) $prepaid->sum('approved'), 2),
                'spent' => round((float) $prepaid->sum('spent'), 2),
                'remaining' => round((float) $prepaid->sum('remaining'), 2),
                'needs_attention' => $rows->whereIn('status', ['over', 'low', 'incomplete'])->count(),
                'currency' => 'SAR',
            ],
            'can_manage' => $user->hasPermission('funding.manage'),
        ]);
    }

    /** GET funding/projects/{project} — one project's figures and every amount added, newest first. */
    public function show(Request $request, string $funded): JsonResponse
    {
        $user = $this->reader($request);
        $funding = $this->funding($user, $funded);
        $this->refresher->refresh($funding);

        $payments = FundingPayment::query()->with('author:id,name')->where('project_id', $funding->project_id)
            ->orderByDesc('paid_on')->orderByDesc('created_at')->get()
            ->map(static fn (FundingPayment $p): array => [
                'id' => $p->id, 'kind' => $p->kind, 'amount' => (float) $p->amount, 'currency' => $p->currency,
                'amount_sar' => (float) $p->amount_sar, 'paid_on' => $p->paid_on->toDateString(), 'reference' => $p->reference,
                'note' => $p->note, 'remaining_before' => $p->remaining_before === null ? null : (float) $p->remaining_before,
                'remaining_after' => $p->remaining_after === null ? null : (float) $p->remaining_after,
                'added_by' => $p->author?->name, 'added_at' => $p->created_at->toIso8601String(),
            ])->all();

        return ApiResponse::success(['project_name' => $funding->project?->name] + $this->balance->of($funding) + [
            'history' => $payments,
            'series' => $this->balance->series($funding),
            'months' => $this->months->months($funding),
        ], 'Budget.');
    }

    /** POST funding/payments — add a budget or renew one, in one step. */
    public function store(Request $request, AddFundingPayment $add): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('funding.manage'), 403);

        $data = $request->validate([
            'client_workspace_id' => ['nullable', 'uuid', 'required_without:client_name'],
            'client_name' => ['nullable', 'string', 'max:160', 'required_without:client_workspace_id'],
            'project_id' => ['nullable', 'uuid', 'required_without:project_name'],
            'project_name' => ['nullable', 'string', 'max:160', 'required_without:project_id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'paid_on' => ['required', 'date', 'after_or_equal:'.now('Asia/Riyadh')->subYears(3)->toDateString(), 'before_or_equal:'.now('Asia/Riyadh')->toDateString()],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ]);

        ['payment' => $payment, 'created' => $created] = $add($user, $data);
        $funding = ProjectFunding::query()->where('project_id', $payment->project_id)->firstOrFail();

        return ApiResponse::success(
            ['payment_id' => $payment->id, 'created' => $created] + $this->balance->of($funding),
            $created ? 'Budget added.' : 'Already added.',
            status: $created ? 201 : 200,
        );
    }

    /** PATCH funding/projects/{project} — how the project pays, from which day spend counts, the alert line. */
    public function update(Request $request, string $funded, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('funding.manage'), 403);
        $funding = $this->funding($user, $funded);

        $data = $request->validate([
            'payment_method' => ['sometimes', Rule::in(ProjectFunding::METHODS)],
            'starts_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:'.now('Asia/Riyadh')->toDateString()],
            'alert_floor' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        // Switching to a prepaid budget makes the project's accounts exclusive to it.
        if (($data['payment_method'] ?? null) === 'prepaid' && $funding->payment_method !== 'prepaid') {
            $guard = app(FundingAccountGuard::class);
            $taken = $guard->conflict($funding->project_id, $guard->accountsOf($funding->project_id));
            if ($taken !== null) {
                throw ValidationException::withMessages(['payment_method' => [__('funding.account_taken', ['project' => $taken['project']])]]);
            }
        }

        $before = $funding->only(array_keys($data));
        $funding->update($data);
        $audit->log(action: 'funding.settings.updated', entityType: ProjectFunding::class, entityId: (string) $funding->id,
            before: $this->plain($before), after: $this->plain($funding->only(array_keys($data))));
        $this->refresher->refresh($funding);

        return ApiResponse::success($this->balance->of($funding), 'Budget updated.');
    }

    /** GET funding/projects/{funded}/months/{month} — the fixed summary of a closed month, or the live one of this month. */
    public function month(Request $request, string $funded, string $month): JsonResponse
    {
        $user = $this->reader($request);
        $funding = $this->funding($user, $funded);
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1, 404);

        return ApiResponse::success(['project_name' => $funding->project?->name] + $this->months->read($funding, $month), 'Month.');
    }

    /** GET funding/export — the budgets and every approved amount, as XLSX (two sheets) or CSV (one). */
    public function export(Request $request, FundingExport $export, AuditLogger $audit): Response
    {
        $user = $this->reader($request);
        $data = $request->validate([
            'format' => ['required', Rule::in(['xlsx', 'csv'])],
            'sheet' => ['nullable', Rule::in(['summary', 'movements'])],
            'client_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        [$fundings, $names] = $this->reachable($user);
        $fundings = $fundings
            ->when($data['client_id'] ?? null, fn ($c, $id) => $c->where('client_workspace_id', $id))
            ->when($data['project_id'] ?? null, fn ($c, $id) => $c->where('project_id', $id))
            ->values();

        $summary = $fundings->map(function (ProjectFunding $f) use ($names): array {
            $b = $this->balance->of($f);

            return [$names[$f->client_workspace_id] ?? '', $f->project->name ?? '', $b['payment_method'], $b['approved'], $b['spent'],
                $b['remaining'], $b['status'], $b['days_left'], $b['last_spend_day']];
        })->all();

        $movements = FundingPayment::query()->with('author:id,name')
            ->whereIn('project_id', $fundings->pluck('project_id')->all())
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('paid_on', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('paid_on', '<=', $d))
            ->orderBy('paid_on')->orderBy('created_at')->get()
            ->map(function (FundingPayment $p) use ($fundings, $names): array {
                $f = $fundings->firstWhere('project_id', $p->project_id);

                return [$p->paid_on->toDateString(), $names[$p->client_workspace_id] ?? '', $f?->project->name ?? '', $p->kind,
                    (float) $p->amount, $p->currency, (float) $p->amount_sar, (float) $p->rate, $p->rate_date?->toDateString(),
                    $p->reference, $p->note, $p->author?->name, $p->created_at->toIso8601String()];
            })->all();

        $today = now('Asia/Riyadh')->toDateString();
        $period = ($data['from'] ?? 'start').' .. '.($data['to'] ?? $today);
        $audit->log(action: 'funding.exported', entityType: ProjectFunding::class, entityId: null,
            after: ['format' => $data['format'], 'sheet' => $data['sheet'] ?? null, 'projects' => count($summary), 'movements' => count($movements),
                'client_id' => $data['client_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'period' => $period]);

        $stamp = now('Asia/Riyadh')->format('Y-m-d');
        if ($data['format'] === 'xlsx') {
            return response($export->xlsx($summary, $movements, $period, $today), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"budgets-{$stamp}.xlsx\"",
            ]);
        }

        $sheet = $data['sheet'] ?? 'movements';

        return response($sheet === 'summary' ? $export->csv(FundingExport::SUMMARY_HEAD, $summary) : $export->csv(FundingExport::MOVEMENTS_HEAD, $movements), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"budgets-{$sheet}-{$stamp}.csv\"",
        ]);
    }

    /**
     * The funded projects this reader reaches, and the names of their clients.
     *
     * @return array{0: Collection<int, ProjectFunding>, 1: Collection<string, string>}
     */
    private function reachable(User $user): array
    {
        $clients = ClientWorkspace::query()->whereNull('archived_at');
        $this->access->restrictQuery($clients, $user);
        $names = $clients->pluck('name', 'id');

        $ceilings = $this->access->projectCeilings($user);
        $fundings = ProjectFunding::query()->with('project:id,name,client_workspace_id')
            ->whereIn('client_workspace_id', $names->keys()->all())->get()
            ->filter(fn (ProjectFunding $f): bool => $this->access->projectAllowed($ceilings, $f->client_workspace_id, $f->project_id)
                && ! $this->reach->moneyWithheld($user, $f->project_id))
            ->values();

        return [$fundings, $names];
    }

    /** Lower sorts first: what needs a decision is at the top. */
    private const NEED = ['over' => 0, 'incomplete' => 1, 'low' => 2, 'no_budget' => 3, 'ok' => 4, 'unset' => 5, 'direct' => 6];

    private function reader(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && (
            $user->hasPermission('funding.view') || $user->hasPermission('funding.manage') || $user->hasPermission('budget.view')
        ), 403);

        return $user;
    }

    private function funding(User $user, string $projectId): ProjectFunding
    {
        $funding = ProjectFunding::query()->with('project:id,name,client_workspace_id')->where('project_id', $projectId)->first();
        abort_if($funding === null, 404);

        $client = ClientWorkspace::query()->find($funding->client_workspace_id);
        abort_unless($client !== null && $this->access->canAccessClient($user, $client)
            && $this->access->projectAllowed($this->access->projectCeilings($user), (string) $client->id, $funding->project_id)
            && ! $this->reach->moneyWithheld($user, $funding->project_id), 404);

        return $funding;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(static fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $values);
    }
}
