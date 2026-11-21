<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BUDGET-LEDGER-001 — edit, delete and undo of a budget, as ENTRIES.
 *
 * The ledger is append-only (the model refuses an update and a delete), so a correction is never a change of
 * history:
 *  - edit   → an `adjustment` entry: the signed difference of the amount (SAR), and the period before and after;
 *  - delete → a `void` entry that takes the net amount out, with a required reason; the budget is hidden, not removed;
 *  - undo   → a `reversal` entry that names the entry it cancels and puts back what it moved. Only the LATEST
 *    entry that is not itself a reversal and not already reversed can be undone — a stack, so the order of
 *    the money stays readable.
 *
 * Every action takes the same per-project lock adding an amount takes, writes who/when/why to the audit
 * log, and never moves money anywhere: it only changes what the budget says was approved.
 */
final class FundingLedgerActions
{
    public function __construct(
        private readonly FundingBalance $balance,
        private readonly FundingSpendRefresher $refresher,
        private readonly AuditLogger $audit,
        private readonly FundingPlan $plans,
        private readonly FundingNotifier $notifier,
    ) {}

    /** The one entry that can be undone now, or null. */
    public function latestUndoable(string $projectId): ?FundingPayment
    {
        $reversed = FundingPayment::query()->where('project_id', $projectId)->whereNotNull('reverses_id')->pluck('reverses_id')->all();

        return FundingPayment::query()->where('project_id', $projectId)->where('kind', '!=', 'reversal')
            ->when($reversed !== [], static fn ($q) => $q->whereNotIn('id', $reversed))
            ->orderByDesc('seq')->first();
    }

    /**
     * Edit: a new approved total (SAR), a new period, a note. Any of them; at least one must change.
     *
     * BUDGET-MODES-001: `payment_mode?` (direct | credit) and `platform_plan?` (null = no plan) are edited the same way; an
     * amount of 0 is allowed only on a credit budget (no ceiling). Each change is recorded in the entry's meta, so undo restores it.
     *
     * @param  array<string, mixed>  $in  amount?, period_start? (null = the first day of spend), period_end? (null = open), payment_mode?, platform_plan?, note?, idempotency_key?
     */
    public function adjust(User $actor, ProjectFunding $funding, array $in): FundingPayment
    {
        return DB::transaction(function () use ($actor, $funding, $in): FundingPayment {
            $this->lock($funding);
            $funding->refresh();
            if ($funding->voided_at !== null) {
                throw ValidationException::withMessages(['project_id' => [__('funding.is_void')]]);
            }
            $key = (string) ($in['idempotency_key'] ?? 'adjust:'.Str::uuid());
            $same = FundingPayment::query()->where('project_id', $funding->project_id)->where('idempotency_key', $key)->first();
            if ($same !== null) {
                return $same;
            }

            $this->refresher->refresh($funding);
            $before = $this->balance->of($funding);
            $periodBefore = ['start' => $funding->starts_on?->toDateString(), 'end' => $funding->ends_on?->toDateString()];
            $periodAfter = [
                'start' => array_key_exists('period_start', $in) ? $in['period_start'] : $periodBefore['start'],
                'end' => array_key_exists('period_end', $in) ? $in['period_end'] : $periodBefore['end'],
            ];
            if ($periodAfter['start'] !== null && $periodAfter['end'] !== null && $periodAfter['end'] < $periodAfter['start']) {
                throw ValidationException::withMessages(['period_end' => [__('funding.period_order')]]);
            }

            $delta = 0.0;
            if (isset($in['amount'])) {
                $delta = round((float) $in['amount'] - (float) $before['approved'], 2);
            }
            $approvedAfter = round((float) $before['approved'] + $delta, 2);

            $modeBefore = (string) $funding->payment_mode;
            $modeAfter = array_key_exists('payment_mode', $in) ? (string) $in['payment_mode'] : $modeBefore;
            if (! in_array($modeAfter, ProjectFunding::MODES, true) || ($modeAfter === 'direct' && $approvedAfter <= 0)) {
                throw ValidationException::withMessages([$modeAfter === 'direct' ? 'amount' : 'payment_mode' => [__('funding.direct_needs_amount')]]);
            }
            $planBefore = $funding->platform_plan;
            $planAfter = $planBefore;
            if (array_key_exists('platform_plan', $in)) {
                $planAfter = empty($in['platform_plan']) ? null : $this->plans->normalise((array) $in['platform_plan'], $approvedAfter);
            }
            $planChanged = $planAfter != $planBefore; // loose: JSON gives back 60 for 60.0

            $note = isset($in['note']) ? trim((string) $in['note']) : null;
            if ($delta === 0.0 && $periodAfter === $periodBefore && $modeAfter === $modeBefore && ! $planChanged && ($note === null || $note === '')) {
                throw ValidationException::withMessages(['amount' => [__('funding.nothing_changed')]]);
            }

            $funding->forceFill(['starts_on' => $periodAfter['start'], 'ends_on' => $periodAfter['end'],
                'payment_mode' => $modeAfter, 'platform_plan' => $planAfter])->save();
            $this->refresher->refresh($funding);

            $entry = $this->entry($actor, $funding, 'adjustment', $delta, $key, [
                'note' => $note === '' ? null : $note,
                'meta' => ['period_before' => $periodBefore, 'period_after' => $periodAfter, 'approved_before' => $before['approved'],
                    'mode_before' => $modeBefore, 'mode_after' => $modeAfter, 'plan_before' => $planBefore, 'plan_after' => $planAfter],
                'reason' => $note === '' ? null : $note,
                'remaining_before' => $before['remaining'],
            ]);

            $this->audit->log('funding.adjusted', FundingPayment::class, (string) $entry->id,
                before: ['approved' => $before['approved'], 'period' => $periodBefore, 'payment_mode' => $modeBefore, 'platform_plan' => $planBefore],
                after: ['approved' => $approvedAfter, 'period' => $periodAfter, 'delta' => $delta, 'payment_mode' => $modeAfter, 'platform_plan' => $planAfter],
                reason: $entry->reason, userId: $actor->id, tenantId: $funding->tenant_id);

            return $entry;
        });
    }

    /**
     * BUDGET-MODES-001 — «أعد التوزيع»: re-divide the budget among the platforms, as an entry with who, when and why.
     * With no `plan` given it is the one-click rebalance (`FundingPlan::rebalance`: what each platform spent stays, the
     * rest follows what each still had left); with a `plan` it is that plan, entered by hand. Either way the old plan
     * is kept in the entry, so undo puts it back. Money does not move: only what the plan says changes.
     *
     * @param  array<int, mixed>|null  $plan
     */
    public function reallocate(User $actor, ProjectFunding $funding, ?array $plan, ?string $reason, ?string $key = null): FundingPayment
    {
        $reason = $reason === null ? null : trim($reason);

        $entry = DB::transaction(function () use ($actor, $funding, $plan, $reason, $key): FundingPayment {
            $this->lock($funding);
            $funding->refresh();
            if ($funding->voided_at !== null) {
                throw ValidationException::withMessages(['project_id' => [__('funding.is_void')]]);
            }
            if (! is_array($funding->platform_plan) || $funding->platform_plan === []) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.no_plan')]]);
            }
            $key ??= 'realloc:'.Str::uuid();
            $same = FundingPayment::query()->where('project_id', $funding->project_id)->where('idempotency_key', $key)->first();
            if ($same !== null) {
                return $same;
            }

            $this->refresher->refresh($funding);
            $before = $this->balance->of($funding);
            $approved = (float) $before['approved'];
            $planBefore = $funding->platform_plan;

            $auto = $plan === null || $plan === [];
            if ($auto) {
                $spentBy = [];
                foreach ($before['by_platform'] as $p) {
                    $spentBy[$p['provider']] = (float) $p['spent'];
                }
                $planAfter = $this->plans->rebalance((array) $before['platform_plan'], $spentBy, $approved);
            } else {
                $planAfter = $this->plans->normalise($plan, $approved);
            }
            if ($planAfter == $planBefore) { // loose: JSON gives back 65 for 65.0
                throw ValidationException::withMessages(['platform_plan' => [__('funding.nothing_changed')]]);
            }

            $funding->forceFill(['platform_plan' => $planAfter])->save();
            $entry = $this->entry($actor, $funding, 'reallocation', 0.0, $key, [
                'reason' => $reason === '' ? null : $reason, 'note' => $reason === '' ? null : $reason,
                'remaining_before' => $before['remaining'],
                'meta' => ['plan_before' => $planBefore, 'plan_after' => $planAfter, 'mode' => $auto ? 'auto' : 'manual', 'approved' => $approved,
                    'spent_by_platform' => array_column($before['by_platform'], 'spent', 'provider')],
            ]);

            $this->audit->log('funding.reallocated', FundingPayment::class, (string) $entry->id,
                before: ['platform_plan' => $planBefore], after: ['platform_plan' => $planAfter, 'mode' => $auto ? 'auto' : 'manual'],
                reason: $entry->reason, userId: $actor->id, tenantId: $funding->tenant_id);

            return $entry;
        });

        // A new plan ends the warnings about the old one; said after the commit, a failed notice never undoes it.
        $this->notifier->planChanged($funding);

        return $entry;
    }

    /** Delete: the budget is hidden, the entries stay. A reason is required. */
    public function void(User $actor, ProjectFunding $funding, string $reason, ?string $key = null): FundingPayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [__('funding.reason_required')]]);
        }

        return DB::transaction(function () use ($actor, $funding, $reason, $key): FundingPayment {
            $this->lock($funding);
            $funding->refresh();
            if ($funding->voided_at !== null) {
                throw ValidationException::withMessages(['project_id' => [__('funding.is_void')]]);
            }
            $this->refresher->refresh($funding);
            $before = $this->balance->of($funding);

            $entry = $this->entry($actor, $funding, 'void', -(float) $before['approved'], $key ?? 'void:'.Str::uuid(), [
                'reason' => $reason, 'note' => $reason, 'remaining_before' => $before['remaining'],
                'meta' => ['approved_before' => $before['approved']],
            ]);
            $funding->forceFill(['voided_at' => now(), 'void_reason' => $reason, 'voided_by' => $actor->id])->save();

            $this->audit->log('funding.voided', FundingPayment::class, (string) $entry->id,
                before: ['approved' => $before['approved'], 'remaining' => $before['remaining']], after: ['approved' => 0.0],
                reason: $reason, userId: $actor->id, tenantId: $funding->tenant_id);

            return $entry;
        });
    }

    /**
     * Undo the latest action. `$expected` (an entry id the client was looking at) must still be the latest:
     * a stale button answers 409 instead of undoing something the user did not see.
     */
    public function undo(User $actor, ProjectFunding $funding, ?string $expected = null): FundingPayment
    {
        return DB::transaction(function () use ($actor, $funding, $expected): FundingPayment {
            $this->lock($funding);
            $funding->refresh();
            $target = $this->latestUndoable((string) $funding->project_id);
            if ($target === null) {
                throw ValidationException::withMessages(['entry_id' => [__('funding.nothing_to_undo')]]);
            }
            if ($expected !== null && $expected !== (string) $target->id) {
                abort(409, __('funding.not_latest'));
            }

            $this->refresher->refresh($funding);
            $before = $this->balance->of($funding);
            $meta = (array) $target->meta;

            $reversal = $this->entry($actor, $funding, 'reversal', -(float) $target->amount, 'undo:'.$target->id, [
                'reverses_id' => $target->id, 'reason' => 'undo:'.$target->kind, 'note' => null,
                'amount_sar' => -(float) $target->amount_sar, 'currency' => $target->currency, 'remaining_before' => $before['remaining'],
                'meta' => ['reverses_kind' => $target->kind],
                'pre' => function () use ($funding, $target, $meta): void {
                    if ($target->kind === 'adjustment' && isset($meta['period_before'])) {
                        $funding->forceFill(['starts_on' => $meta['period_before']['start'] ?? null, 'ends_on' => $meta['period_before']['end'] ?? null])->save();
                    }
                    // BUDGET-MODES-001 — the mode and the plan an edit or a reallocation replaced are put back with it.
                    if ($target->kind === 'adjustment' && array_key_exists('mode_before', $meta)) {
                        $funding->forceFill(['payment_mode' => $meta['mode_before'], 'platform_plan' => $meta['plan_before'] ?? null])->save();
                    }
                    if ($target->kind === 'reallocation') {
                        $funding->forceFill(['platform_plan' => $meta['plan_before'] ?? null])->save();
                    }
                    if ($target->kind === 'void') {
                        $funding->forceFill(['voided_at' => null, 'void_reason' => null, 'voided_by' => null])->save();
                    }
                },
            ]);

            // Undoing the entry that STARTED the budget leaves nothing approved: the budget is hidden like a deleted one.
            if ($target->kind === 'initial') {
                $stillOpen = FundingPayment::query()->where('project_id', $funding->project_id)->whereIn('kind', ['initial', 'top_up'])
                    ->where('id', '!=', $target->id)->exists();
                if (! $stillOpen) {
                    $funding->forceFill(['voided_at' => now(), 'void_reason' => 'undo', 'voided_by' => $actor->id])->save();
                }
            }

            if (in_array($target->kind, ['adjustment', 'reallocation'], true)) {
                DB::afterCommit(fn () => $this->notifier->planChanged($funding));
            }

            $this->audit->log('funding.undone', FundingPayment::class, (string) $reversal->id,
                before: ['undone_entry' => $target->id, 'kind' => $target->kind, 'amount_sar' => (float) $target->amount_sar],
                after: ['approved' => round((float) $before['approved'] - (float) $target->amount_sar, 2)],
                reason: 'undo '.$target->kind, userId: $actor->id, tenantId: $funding->tenant_id);

            return $reversal;
        });
    }

    private function lock(ProjectFunding $funding): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding:'.$funding->project_id]);
    }

    /**
     * Writes one entry (amount in SAR unless `currency`/`amount_sar` say otherwise), then re-reads spend so the
     * remaining AFTER it is known. `pre` runs before the re-read (a period or void flag the figure depends on).
     *
     * @param  array<string, mixed>  $extra
     */
    private function entry(User $actor, ProjectFunding $funding, string $kind, float $delta, string $key, array $extra): FundingPayment
    {
        $today = Carbon::today('Asia/Riyadh')->toDateString();
        $pre = $extra['pre'] ?? null;
        unset($extra['pre']);

        $entry = FundingPayment::query()->create([
            'tenant_id' => $funding->tenant_id,
            'client_workspace_id' => $funding->client_workspace_id,
            'project_id' => $funding->project_id,
            'kind' => $kind,
            'amount' => round($delta, 2),
            'currency' => $extra['currency'] ?? 'SAR',
            'amount_sar' => round((float) ($extra['amount_sar'] ?? $delta), 2),
            'rate' => 1,
            'rate_date' => $today,
            'rate_source' => 'ledger',
            'paid_on' => $today,
            'idempotency_key' => $key,
            'created_by' => $actor->id,
        ] + array_diff_key($extra, ['currency' => 1, 'amount_sar' => 1, 'remaining_before' => 1]) + ['remaining_before' => $extra['remaining_before'] ?? null]);

        if (is_callable($pre)) {
            $pre();
        }
        $this->refresher->refresh($funding);
        $after = $this->balance->of($funding)['remaining'];
        DB::table('funding_payments')->where('id', $entry->id)->update(['remaining_after' => $after]);
        $entry->setRawAttributes(array_merge($entry->getAttributes(), ['remaining_after' => $after]), true);

        return $entry;
    }
}
