<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * BUDGET-RENEW-ALL-001 — «جدّد كل الميزانيات للشهر القادم».
 *
 * `preview()` WRITES NOTHING: it lists what would be copied, with the amounts to confirm. `apply()` runs only with the amounts
 * the person confirmed, and only for items that are in the preview (so nothing outside the reader's reach, nothing void or
 * inactive, can be renewed by guessing an id).
 *
 *  - a budget is renewed the way the product already renews one: an amount ADDED to the ledger (a «تجديد» entry), same
 *    mode and plan; its proposed amount is its latest approved amount. A credit budget with no ceiling has no amount to renew.
 *  - a spend limit is copied as a NEW limit for the next calendar month, with the same scope, currency and warning
 *    thresholds. The old one is untouched.
 * Both are idempotent for the month: running it twice never adds the budget twice or a second copy of a limit.
 */
final class RenewNextMonth
{
    public function __construct(
        private readonly ClientAccess $access,
        private readonly FundingReach $reach,
        private readonly AddFundingPayment $add,
    ) {}

    /** @return array{month: string, from: string, to: string, budgets: list<array<string, mixed>>, limits: list<array<string, mixed>>} */
    public function preview(User $user, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today('Asia/Riyadh'))->copy();
        $from = Carbon::parse($today->format('Y-m-01'))->addMonth();
        $to = $from->copy()->endOfMonth();
        $key = $from->format('Y-m');

        $fundings = $this->fundings($user);
        $budgets = [];
        foreach ($fundings as $f) {
            if ($f->payment_method !== 'prepaid') {
                continue;
            }
            $last = FundingPayment::query()->where('project_id', $f->project_id)->whereIn('kind', ['initial', 'top_up'])
                ->whereNull('reverses_id')->orderByDesc('seq')->first();
            if ($last === null || (float) $last->amount_sar <= 0) {
                continue;
            }
            $done = FundingPayment::query()->where('project_id', $f->project_id)->where('reference', 'renew-'.$key)->exists();
            $budgets[] = [
                'project_id' => (string) $f->project_id,
                'client_workspace_id' => (string) $f->client_workspace_id,
                'project_name' => (string) ($f->project->name ?? ''),
                'client_name' => (string) ($f->client_name ?? ''),
                'amount' => round((float) $last->amount_sar, 2),
                'currency' => 'SAR',
                'mode' => $f->payment_mode,
                'already_renewed' => $done,
            ];
        }

        $projectIds = $fundings->pluck('project_id')->all();
        $limits = SpendLimit::query()->where('active', true)->whereDate('ends_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $today->copy()->subMonths(2)->toDateString())
            ->get()
            ->filter(fn (SpendLimit $l): bool => $this->reachesProject($user, (string) $l->project_id, $projectIds))
            ->map(function (SpendLimit $l) use ($from, $to): array {
                $exists = SpendLimit::query()->where('project_id', $l->project_id)->where('scope', $l->scope->value)
                    ->where('scope_id', $l->scope_id)->where('active', true)->whereDate('starts_on', $from->toDateString())->exists();

                return [
                    'id' => (string) $l->getKey(),
                    'project_id' => (string) $l->project_id,
                    'scope' => $l->scope->value,
                    'scope_id' => $l->scope_id,
                    'amount' => round((float) $l->amount, 2),
                    'currency' => $l->currency,
                    'thresholds' => $l->thresholdPercents(),
                    'starts_on' => $from->toDateString(),
                    'ends_on' => $to->toDateString(),
                    'already_renewed' => $exists,
                ];
            })->values()->all();

        return ['month' => $key, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'budgets' => $budgets, 'limits' => $limits];
    }

    /**
     * @param  list<array{project_id: string, amount: float|int|string}>  $budgets
     * @param  list<array{id: string, amount: float|int|string}>  $limits
     * @return array{budgets: int, limits: int, skipped: int}
     */
    public function apply(User $user, array $budgets, array $limits, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today('Asia/Riyadh'))->copy();
        $preview = $this->preview($user, $today);
        $okBudgets = collect($preview['budgets'])->keyBy('project_id');
        $okLimits = collect($preview['limits'])->keyBy('id');

        foreach ($budgets as $b) {
            if (! $okBudgets->has($b['project_id']) || ! is_numeric($b['amount']) || (float) $b['amount'] <= 0) {
                throw ValidationException::withMessages(['budgets' => [__('funding.renew_bad_item')]]);
            }
        }
        foreach ($limits as $l) {
            if (! $okLimits->has($l['id']) || ! is_numeric($l['amount']) || (float) $l['amount'] <= 0) {
                throw ValidationException::withMessages(['limits' => [__('funding.renew_bad_item')]]);
            }
        }

        $made = ['budgets' => 0, 'limits' => 0, 'skipped' => 0];
        foreach ($budgets as $b) {
            $row = $okBudgets[$b['project_id']];
            if ($row['already_renewed']) {
                $made['skipped']++;

                continue;
            }
            ['created' => $created] = ($this->add)($user, [
                'client_workspace_id' => $row['client_workspace_id'], 'project_id' => $row['project_id'], 'amount' => round((float) $b['amount'], 2), 'currency' => 'SAR',
                'paid_on' => $today->toDateString(), 'reference' => 'renew-'.$preview['month'], 'idempotency_key' => 'renew-'.$preview['month'].'-'.$row['project_id'],
            ]);
            $created ? $made['budgets']++ : $made['skipped']++;
        }
        foreach ($limits as $l) {
            $row = $okLimits[$l['id']];
            if ($row['already_renewed']) {
                $made['skipped']++;

                continue;
            }
            $src = SpendLimit::query()->findOrFail($row['id']);
            SpendLimit::create([
                'tenant_id' => app(TenantContext::class)->tenantId(), 'project_id' => $src->project_id, 'scope' => $src->scope->value,
                'scope_id' => $src->scope_id, 'amount' => round((float) $l['amount'], 2), 'currency' => $src->currency,
                'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'thresholds' => $src->thresholds, 'active' => true, 'created_by' => $user->getKey(),
            ]);
            $made['limits']++;
        }

        return $made;
    }

    /** @return Collection<int, ProjectFunding> */
    private function fundings(User $user): Collection
    {
        // PROJECT-LIFECYCLE-001 — live projects only; client state does not decide (rule 4).
        $clients = ClientWorkspace::query();
        $this->access->restrictQuery($clients, $user);
        $names = $clients->pluck('name', 'id');
        $ceilings = $this->access->projectCeilings($user);

        return ProjectFunding::query()->with('project:id,name,client_workspace_id')->whereIn('client_workspace_id', $names->keys()->all())
            ->whereIn('project_id', LiveProjects::idsQuery())
            ->whereNull('voided_at')->get()
            ->filter(fn (ProjectFunding $f): bool => $this->access->projectAllowed($ceilings, $f->client_workspace_id, $f->project_id)
                && ! $this->reach->moneyWithheld($user, $f->project_id))
            ->each(function (ProjectFunding $f) use ($names): void {
                $f->setAttribute('client_name', $names[$f->client_workspace_id] ?? null);
            })->values();
    }

    /** @param  list<string>  $fundedProjects */
    private function reachesProject(User $user, string $projectId, array $fundedProjects): bool
    {
        return in_array($projectId, $fundedProjects, true) || ! $this->reach->moneyWithheld($user, $projectId) && $this->projectInReach($user, $projectId);
    }

    private function projectInReach(User $user, string $projectId): bool
    {
        $project = Project::query()->find($projectId);
        if ($project === null) {
            return false;
        }
        $client = ClientWorkspace::query()->find($project->client_workspace_id);

        return $client !== null && $this->access->canAccessClient($user, $client)
            && $this->access->projectAllowed($this->access->projectCeilings($user), (string) $client->id, (string) $project->id);
    }
}
