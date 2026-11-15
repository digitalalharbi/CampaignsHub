<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal\Observers;

use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Funding\Support\FundingMoney;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Notifications\Followup\AutoCloser;
use App\Domains\Notifications\Internal\InternalEvents;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * NOTIF-V2-INTERNAL-001 — «a budget or a limit was created / edited / renewed / deleted, by whom, before and after» and
 * «a project was created / archived» (spec §5), said by the model events so EVERY path that writes them is covered
 * (the API, the renewal wizard, an import, the e2e seeders) and no controller has to remember.
 *
 * Observers never throw into the write: the notification is a consequence, not a condition. After a budget or a sync
 * change the follow-up list is reconciled so a task closes the moment its reason is gone.
 */
final class BudgetAndProjectObservers
{
    public static function register(): void
    {
        FundingPayment::created(static fn (FundingPayment $p) => self::guard(static fn () => self::payment($p)));
        SpendLimit::created(static fn (SpendLimit $l) => self::guard(static fn () => self::limit($l, 'created')));
        SpendLimit::updated(static fn (SpendLimit $l) => self::guard(static function () use ($l): void {
            if ($l->wasChanged('active') && ! $l->active) {
                self::limit($l, 'deleted');
            } elseif ($l->wasChanged(['amount', 'starts_on', 'ends_on', 'thresholds'])) {
                self::limit($l, 'edited');
            }
        }));
        // A project made or archived by a PERSON is news; one a seeder or an import writes with nobody signed in is not.
        Project::created(static fn (Project $p) => self::guard(static function () use ($p): void {
            if (self::actor() === null) {
                return;
            }
            app(InternalEvents::class)->raise('project.created', (string) $p->tenant_id, (string) $p->getKey(), [], self::actor(), 'project', (string) $p->getKey());
        }));
        Project::updated(static fn (Project $p) => self::guard(static function () use ($p): void {
            if (self::actor() !== null && $p->wasChanged('status') && $p->status === 'archived') {
                app(InternalEvents::class)->raise('project.archived', (string) $p->tenant_id, (string) $p->getKey(), [], self::actor(), 'project', (string) $p->getKey());
            }
        }));
        IntegrationSyncRun::saved(static fn (IntegrationSyncRun $r) => self::guard(static function () use ($r): void {
            if ($r->status === 'success' && $r->project_id !== null) {
                app(AutoCloser::class)->reconcile((string) $r->tenant_id, (string) $r->project_id);
            }
        }));
    }

    private static function payment(FundingPayment $p): void
    {
        $tenant = (string) $p->tenant_id;
        $project = (string) $p->project_id;
        $signed = (float) $p->amount_sar;
        $after = (float) (app(ProjectBudgetReader::class)->forProject($project)['approved'] ?? 0);
        $before = $after - $signed;
        $renewed = str_starts_with((string) $p->reference, 'renew-');
        $variant = match (true) {
            $renewed => 'renewed',
            $p->kind === 'initial' => 'created',
            $p->kind === 'void' => 'deleted',
            default => 'edited',
        };
        $sar = static fn (float $v): string => FundingMoney::format(abs($v), 'SAR', 0);

        app(InternalEvents::class)->raise($renewed ? 'budget.renewed' : 'budget.changed', $tenant, $project, [
            'variant' => $variant, 'before' => $sar($before), 'after' => $sar($after),
        ], self::actor(), 'funding_payment', (string) $p->getKey());
        app(AutoCloser::class)->reconcile($tenant, $project);
    }

    private static function limit(SpendLimit $l, string $variant): void
    {
        $money = static fn (mixed $v): string => FundingMoney::format((float) $v, (string) $l->currency, 0);
        $before = $variant === 'edited' ? (float) $l->getOriginal('amount') : (float) $l->amount;

        app(InternalEvents::class)->raise('spend_limit.changed', (string) $l->tenant_id, (string) $l->project_id, [
            'variant' => $variant, 'before' => $money($before), 'after' => $money($l->amount),
        ], self::actor(), 'spend_limit', (string) $l->getKey(), $variant.':'.$l->updated_at?->timestamp);
    }

    private static function actor(): ?int
    {
        $id = Auth::id();

        return $id === null ? null : (int) $id;
    }

    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
