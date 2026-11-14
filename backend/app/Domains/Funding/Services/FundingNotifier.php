<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * FIN-LOOP-001 — tells the people who act on a project budget, so nobody has to chase anybody.
 *
 *  - «أُضيفت ميزانية» → the project manager and the project's team: the money is there, start or carry on.
 *  - «قرّبت تخلص» (remaining under the line, default 2,000 SAR, or under 7 days at the real pace)
 *    → the project manager (talks to the client) and finance (records the renewal).
 *  - «تجاوزت الميزانية» → the same two.
 *
 * Every notice names the client, the figure in SAR, the day the spend was last read, the next step and
 * who takes it — and nothing about anyone's fault. Each state is said ONCE per cycle (the number of
 * approved amounts, `funding_notices`); a renewal starts a new cycle and closes the open notices of the
 * old one. Delivery goes through `NotificationDispatcher`, so each person's switches, importance, quiet
 * hours and the hourly ceiling apply as for every other message.
 *
 * Recipients are agency staff who reach the client and the project by THEIR OWN membership scope, and who
 * hold the money capability on the project — the rule the mail half (SpendAudience) already applies. A failure to notify never undoes the money it is about.
 */
final class FundingNotifier
{
    public const TYPE = 'project_budget';

    /** The mail shell's «used against the whole» bar (`AlertMailItem::viz` shape). */
    private const VIZ = 'pace';

    public function __construct(
        private readonly FundingBalance $balance,
        private readonly NotificationDispatcher $dispatcher,
        private readonly ProjectAbilities $abilities,
    ) {}

    /**
     * An amount was added: close the old cycle's warnings and tell the people who spend it.
     *
     * `$cycle` is the count of amounts INCLUDING this one, taken inside the transaction that added it, so
     * two quick top-ups each announce their own cycle instead of both reading the later count.
     */
    public function added(FundingPayment $payment, ?int $actorId, int $cycle): int
    {
        try {
            $funding = ProjectFunding::query()->where('project_id', $payment->project_id)->firstOrFail();
            $this->closeOpen($funding);

            if (! $this->claim($funding, $cycle, 'added', null)) {
                return 0;
            }
            $figures = $this->balance->of($funding);
            $users = $this->audience($funding, ['manager', 'team'], $actorId);

            return $this->send($funding, $users, 'added', $figures, $cycle);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * After the spend is re-read: say «running low» or «over», once per cycle.
     *
     * Decided under the same per-project lock that adding an amount holds, so a renewal committing at
     * this moment is either fully before (new cycle, new balance) or fully after (its own commit closes
     * what this raised) — never a «low» about money that was just topped up.
     */
    public function evaluate(ProjectFunding $funding): int
    {
        if ($funding->payment_method !== 'prepaid') {
            return 0;
        }

        $decision = DB::transaction(function () use ($funding): ?array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding:'.$funding->project_id]);

            $cycle = $this->cycle($funding);
            if ($cycle === 0) {
                return null;
            }

            $f = $this->balance->of($funding);
            $state = match (true) {
                $f['remaining'] !== null && $f['remaining'] < 0 => 'over',
                $f['remaining'] !== null && ($f['remaining'] < $f['alert_floor'] || ($f['days_left'] !== null && $f['days_left'] < 7)) => 'low',
                default => null,
            };
            if ($state === null) {
                return null;
            }

            // «Over» outranks «low»: once it is over, being low is old news.
            if ($state === 'low' && DB::table('funding_notices')->where('project_id', $funding->project_id)
                ->where('cycle', $cycle)->where('state', 'over')->exists()) {
                return null;
            }

            return $this->claim($funding, $cycle, $state, $f['remaining']) ? [$state, $f, $cycle] : null;
        });

        if ($decision === null) {
            return 0;
        }
        [$state, $f, $cycle] = $decision;

        return $this->send($funding, $this->audience($funding, ['manager', 'finance'], null), $state, $f, $cycle);
    }

    /** The cycle is how many amounts were approved: each renewal adds one, and nothing is ever removed. */
    private function cycle(ProjectFunding $funding): int
    {
        return FundingPayment::query()->where('project_id', $funding->project_id)->count();
    }

    /** The open warnings of the cycle a renewal just ended are resolved, not left ringing. */
    private function closeOpen(ProjectFunding $funding): void
    {
        AppNotification::query()->where('project_id', $funding->project_id)->where('type', self::TYPE)
            ->whereIn('entity_type', ['funding:low', 'funding:over'])->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'status' => 'read', 'read_at' => now()]);
    }

    /** Records the state for the cycle; false when it was already said (the unique key answers, no exception). */
    private function claim(ProjectFunding $funding, int $cycle, string $state, ?float $remaining): bool
    {
        return DB::table('funding_notices')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $funding->tenant_id, 'project_id' => $funding->project_id,
            'cycle' => $cycle, 'state' => $state, 'remaining' => $remaining, 'created_at' => now(),
        ]) === 1;
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  list<User>  $users
     */
    private function send(ProjectFunding $funding, array $users, string $state, array $f, int $cycle): int
    {
        $project = Project::query()->find($funding->project_id);
        $client = ClientWorkspace::query()->find($funding->client_workspace_id);
        $clientName = (string) ($client->name ?? '');
        $projectName = (string) ($project->name ?? '');
        $sar = static fn (?float $v): string => number_format(abs((float) $v), 0).' ر.س';
        $sarEn = static fn (?float $v): string => 'SAR '.number_format(abs((float) $v), 0);
        $asOf = $f['last_spend_day'];
        $days = $f['days_left'];
        $short = $f['remaining'] !== null && $f['remaining'] < 0;

        [$severity, $titleAr, $titleEn, $lineAr, $lineEn, $stepAr, $stepEn] = match (true) {
            $state === 'added' && ! $short => ['info', "أُضيفت ميزانية: {$clientName}", "Budget added: {$clientName}",
                'المتبقي '.$sar($f['remaining']).' من '.$sar($f['approved']), 'Remaining '.$sarEn($f['remaining']).' of '.$sarEn($f['approved']),
                'الخطوة: تابع الحملات — فريق الحملات', 'Next: run the campaigns — campaigns team'],
            // A top-up that does not cover what was already spent says so, never a positive «remaining».
            $state === 'added' => ['warning', "أُضيفت ميزانية: {$clientName}", "Budget added: {$clientName}",
                'الإنفاق ما زال أعلى من الميزانية بـ '.$sar($f['remaining']), 'Spend is still '.$sarEn($f['remaining']).' over the budget',
                'الخطوة: جدّد الميزانية مع العميل — مدير المشروع، وتسجيلها — المالية', 'Next: renew with the client — project manager; record it — finance'],
            $state === 'over' => ['critical', "تجاوزت الميزانية: {$clientName}", "Over budget: {$clientName}",
                'الإنفاق زاد على الميزانية بـ '.$sar($f['remaining']), 'Spend is '.$sarEn($f['remaining']).' over the budget',
                'الخطوة: جدّد الميزانية مع العميل — مدير المشروع، وتسجيلها — المالية', 'Next: renew with the client — project manager; record it — finance'],
            default => ['warning', "قرّبت تخلص: {$clientName}", "Running low: {$clientName}",
                'المتبقي '.$sar($f['remaining']).($days !== null ? " · يكفي تقريبًا {$days} يوم" : ''),
                'Remaining '.$sarEn($f['remaining']).($days !== null ? " · about {$days} days left" : ''),
                'الخطوة: جدّد الميزانية مع العميل — مدير المشروع، وتسجيلها — المالية', 'Next: renew with the client — project manager; record it — finance'],
        };

        // The day the spend was last read — or, before any spend arrived, no claim about it at all.
        $messageAr = implode("\n", [$lineAr, $asOf !== null ? "{$projectName} · الإنفاق حتى {$asOf}" : $projectName, $stepAr]);
        $messageEn = implode("\n", [$lineEn, $asOf !== null ? "{$projectName} · spend up to {$asOf}" : $projectName, $stepEn]);
        $ratio = $f['used_ratio'] !== null ? (float) $f['used_ratio'] : null;

        $sent = 0;
        foreach ($users as $user) {
            try {
                $n = $this->dispatcher->dispatch([
                    'tenant_id' => $funding->tenant_id,
                    'user_id' => (int) $user->id,
                    'client_workspace_id' => $funding->client_workspace_id,
                    'project_id' => $funding->project_id,
                    'type' => 'project_budget',
                    'severity' => $severity,
                    'title' => $titleEn, 'message' => $messageEn,
                    'title_ar' => $titleAr, 'message_ar' => $messageAr,
                    'source' => 'funding',
                    'entity_type' => 'funding:'.$state,
                    'entity_id' => $funding->project_id,
                    'dedup_extra' => 'cycle:'.$cycle,
                    // Portal-relative: the budgets page is mounted in both staff portals.
                    'action_url' => '/budgets?project='.$funding->project_id,
                    'viz' => $this->bar($ratio, $user->locale !== 'en'),
                ]);
                $sent += $n === null ? 0 : 1;
            } catch (Throwable $e) {
                report($e);
            }
        }

        DB::table('funding_notices')->where('project_id', $funding->project_id)->where('cycle', $cycle)
            ->where('state', $state)->update(['recipients' => $sent]);

        return $sent;
    }

    /**
     * Spend against the budget as the mail shell's bar. Past the budget the plan mark moves back
     * (100 / ratio), so «over» reads as over and not as a full bar.
     *
     * @return array<string, mixed>|null
     */
    private function bar(?float $ratio, bool $ar): ?array
    {
        if ($ratio === null || $ratio <= 0) {
            return null;
        }
        $used = (int) round(min(1.0, $ratio) * 100);
        $plan = $ratio > 1 ? (int) round(100 / $ratio) : 100;
        $pct = (int) round($ratio * 100);

        return ['type' => self::VIZ, 'used' => $used, 'plan' => $plan,
            'used_label' => ($ar ? 'الإنفاق ' : 'Spent ').$pct.'%', 'plan_label' => ($ar ? 'الميزانية' : 'Budget')];
    }

    /**
     * The people who act on this budget, each checked against the client they would read about.
     *
     * @param  list<'manager'|'team'|'finance'>  $who
     * @return list<User>
     */
    private function audience(ProjectFunding $funding, array $who, ?int $exclude): array
    {
        $ids = [];
        $project = Project::query()->find($funding->project_id);
        $client = ClientWorkspace::query()->find($funding->client_workspace_id);

        if (in_array('manager', $who, true)) {
            $ids[] = $project->account_manager_id ?? $client?->owner_id;
        }
        if (in_array('team', $who, true)) {
            array_push($ids, ...ProjectMembership::withoutGlobalScopes()->where('project_id', $funding->project_id)
                ->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('user_id')->all());
        }
        if (in_array('finance', $who, true)) {
            array_push($ids, ...DB::table('role_user as ru')
                ->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->join('permission_role as pr', 'pr.role_id', '=', 'r.id')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('p.key', 'funding.manage')
                ->where(fn ($q) => $q->where('r.tenant_id', $funding->tenant_id)->orWhereNull('r.tenant_id'))
                ->pluck('ru.user_id')->all());
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids)), static fn (int $id): bool => $id !== $exclude)));
        if ($ids === [] || $client === null) {
            return [];
        }

        return User::query()->inTenant($funding->tenant_id)->whereIn('id', $ids)->get()
            // Their own reach, then the same money rule the mail half applies (SpendAudience): the bell never
            // shows a figure the inbox would refuse.
            ->filter(fn (User $u): bool => $this->reaches($u, $client, $funding->project_id)
                && $this->abilities->allows($u, $funding->project_id, ProjectCapability::MONEY_VIEW))
            ->values()->all();
    }

    /**
     * Reach for a person who is not the one making the request: THEIR membership decides — its named
     * clients and its named projects are each a ceiling — then the explicit grants.
     */
    private function reaches(User $user, ClientWorkspace $client, string $projectId): bool
    {
        $membership = Membership::query()->with('scopes')->where('user_id', $user->id)
            ->where('tenant_id', $client->tenant_id)->where('status', 'active')->first();
        if ($membership === null) {
            return false;
        }

        $projects = $membership->projectScopeIds();
        if ($projects !== [] && ! in_array($projectId, $projects, true)) {
            return false;
        }

        $named = $membership->clientScopeIds();
        if ($named !== []) {
            return in_array((string) $client->id, $named, true);
        }
        if ($projects !== [] || $user->hasPermission('clients.view_all')) {
            return true;
        }
        if ($client->owner_id !== null && (int) $client->owner_id === (int) $user->id) {
            return true;
        }

        return DB::table('client_workspace_user')->where('client_workspace_id', $client->id)->where('user_id', $user->id)->exists();
    }
}
