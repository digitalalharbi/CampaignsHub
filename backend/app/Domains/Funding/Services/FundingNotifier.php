<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Funding\Models\FundingPayment;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Support\FundingDisclaimer;
use App\Domains\Funding\Support\FundingMoney;
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

    /** The mail shell's «before → now» bar (`AlertMailItem::viz` shape). */
    private const VIZ_BEFORE_NOW = 'before_now';

    public function __construct(
        private readonly FundingBalance $balance,
        private readonly NotificationDispatcher $dispatcher,
        private readonly ProjectAbilities $abilities,
        private readonly FundingMonth $months,
        private readonly FundingPlan $plans,
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

        // BUDGET-MODES-001 — a credit budget has no balance to run low: it has a ceiling (if any) and a month to invoice.
        $credit = $funding->payment_mode === 'credit';
        $sent = $credit ? $this->ceiling($funding) + $this->invoice($funding) : $this->balanceState($funding);

        return $sent + $this->plan($funding);
    }

    /** «Running low» / «over» of a prepaid (direct) budget. */
    private function balanceState(ProjectFunding $funding): int
    {
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

    /**
     * BUDGET-MODES-001 — «near the ceiling» (90%, the repo's budget-alert ratio) and «over the ceiling», once per cycle each.
     * Over outranks near. A credit budget with no ceiling never says either.
     */
    private function ceiling(ProjectFunding $funding): int
    {
        $decision = DB::transaction(function () use ($funding): ?array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding:'.$funding->project_id]);
            $cycle = $this->cycle($funding);
            $f = $this->balance->of($funding);
            if ($cycle === 0 || $f['ceiling'] === null) {
                return null;
            }
            $state = match (true) {
                $f['status'] === 'credit_over' => 'ceiling_over',
                $f['ceiling_ratio'] !== null && $f['ceiling_ratio'] >= FundingPlan::NEAR => 'ceiling_near',
                default => null,
            };
            if ($state === null
                || ($state === 'ceiling_near' && DB::table('funding_notices')->where('project_id', $funding->project_id)->where('cycle', $cycle)->where('state', 'ceiling_over')->exists())) {
                return null;
            }

            return $this->claim($funding, $cycle, $state, null) ? [$state, $f, $cycle] : null;
        });
        if ($decision === null) {
            return 0;
        }
        [$state, $f, $cycle] = $decision;

        return $this->send($funding, $this->audience($funding, ['manager', 'finance'], null), $state, $f, $cycle);
    }

    /**
     * BUDGET-MODES-001 — a platform's own share of the plan is near (90%) or over (100%): one notice per platform per state
     * per cycle. A reallocation or a renewal is a new cycle, so the new plan is judged afresh and never silenced by the old one.
     * Ahead / behind in time are chips on the page only; they never notify.
     */
    private function plan(ProjectFunding $funding): int
    {
        if (! is_array($funding->platform_plan) || $funding->platform_plan === []) {
            return 0;
        }
        $decision = DB::transaction(function () use ($funding): ?array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['funding:'.$funding->project_id]);
            $cycle = $this->cycle($funding);
            $f = $this->balance->of($funding);
            if ($cycle === 0 || $f['plan'] === null) {
                return null;
            }
            $claimed = [];
            foreach ($this->plans->alerts($f['plan']) as $row) {
                $state = 'plan_'.$row['flag'].':'.$row['provider'];
                if ($row['flag'] === 'near' && DB::table('funding_notices')->where('project_id', $funding->project_id)->where('cycle', $cycle)->where('state', 'plan_over:'.$row['provider'])->exists()) {
                    continue;
                }
                if ($this->claim($funding, $cycle, $state, null)) {
                    $claimed[] = $row;
                }
            }

            return $claimed === [] ? null : [$claimed, $f, $cycle];
        });
        if ($decision === null) {
            return 0;
        }
        [$rows, $f, $cycle] = $decision;
        $users = $this->audience($funding, ['manager', 'finance'], null);
        $sent = 0;
        foreach ($rows as $row) {
            $sent += $this->sendPlan($funding, $users, $row, $f, $cycle);
        }

        return $sent;
    }

    /**
     * BUDGET-MODES-001 — «جاهز للفوترة»: once a month of a credit budget is over and every day of it has a rate, finance is told its
     * spend (the period's dates and the last day read are stated). Once per month, ever. No invoice is made, no money moves, no one
     * outside the agency is told: it is a pointer to the figure in the drawer and the export.
     */
    private function invoice(ProjectFunding $funding): int
    {
        $closed = array_values(array_filter($this->months->invoiceMonths($funding), static fn (array $m): bool => $m['closed'] && $m['spent'] > 0));
        if ($closed === []) {
            return 0;
        }
        $month = $closed[count($closed) - 1];
        $previous = count($closed) > 1 ? $closed[count($closed) - 2] : null;
        if ($month['unconverted_days'] > 0) {
            return 0; // held, never sent as a figure that is only a floor; the board already says «ناقص سعر صرف»
        }
        if (! $this->claim($funding, 0, 'invoice_'.$month['month'], null)) {
            return 0;
        }

        return $this->sendInvoice($funding, $this->audience($funding, ['finance'], null), $month, $previous);
    }

    /** A new plan ends the warnings about the old one: they are resolved, not left ringing. */
    public function planChanged(ProjectFunding $funding): void
    {
        try {
            AppNotification::query()->where('project_id', $funding->project_id)->where('type', self::TYPE)
                ->where('entity_type', 'funding:plan')->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'status' => 'read', 'read_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
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
            ->whereIn('entity_type', ['funding:low', 'funding:over', 'funding:ceiling_near', 'funding:ceiling_over', 'funding:plan'])->whereNull('resolved_at')
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
        $sar = static fn (?float $v): string => FundingMoney::format(abs((float) $v), 'SAR', 0);
        $sarEn = static fn (?float $v): string => FundingMoney::format(abs((float) $v), 'SAR', 0);
        $asOf = $f['last_spend_day'];
        $days = $f['days_left'];
        $short = $f['remaining'] !== null && $f['remaining'] < 0;

        $pctNow = $f['ceiling_ratio'] !== null ? (int) round((float) $f['ceiling_ratio'] * 100) : null;
        [$severity, $titleAr, $titleEn, $lineAr, $lineEn, $stepAr, $stepEn] = match (true) {
            // BUDGET-MODES-001 — a credit budget has nothing «remaining»: it says the ceiling (if any) and that it is invoiced later.
            $state === 'added' && $f['payment_mode'] === 'credit' => ['info', "أُضيفت ميزانية بالأجل: {$clientName}", "Credit budget added: {$clientName}",
                $f['ceiling'] !== null ? 'السقف '.$sar($f['ceiling']).' · تُفوتر لاحقًا بالإنفاق' : 'بلا سقف · تُفوتر لاحقًا بالإنفاق',
                $f['ceiling'] !== null ? 'Ceiling '.$sarEn($f['ceiling']).' · invoiced later by spend' : 'No ceiling · invoiced later by spend',
                'الخطوة: تابع الحملات — فريق الحملات', 'Next: run the campaigns — campaigns team'],
            $state === 'ceiling_near' => ['warning', "قرّبت من السقف: {$clientName}", "Near the ceiling: {$clientName}",
                'الإنفاق '.$sar($f['spent']).' من سقف '.$sar($f['ceiling']).($pctNow !== null ? " ({$pctNow}%)" : ''),
                'Spent '.$sarEn($f['spent']).' of a '.$sarEn($f['ceiling']).' ceiling'.($pctNow !== null ? " ({$pctNow}%)" : ''),
                'الخطوة: راجع السقف مع العميل — مدير المشروع، وتعديله — المالية', 'Next: review the ceiling with the client — project manager; edit it — finance'],
            $state === 'ceiling_over' => ['critical', "تجاوزت السقف: {$clientName}", "Over the ceiling: {$clientName}",
                'الإنفاق زاد على السقف بـ '.$sar((float) $f['spent'] - (float) $f['ceiling']), 'Spend is '.$sarEn((float) $f['spent'] - (float) $f['ceiling']).' over the ceiling',
                'الخطوة: راجع السقف مع العميل — مدير المشروع، وتعديله — المالية', 'Next: review the ceiling with the client — project manager; edit it — finance'],
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
        $messageAr = implode("\n", [$lineAr, $asOf !== null ? "{$projectName} · الإنفاق حتى {$asOf}" : $projectName, $stepAr, FundingDisclaimer::text('ar')]);
        $messageEn = implode("\n", [$lineEn, $asOf !== null ? "{$projectName} · spend up to {$asOf}" : $projectName, $stepEn, FundingDisclaimer::text('en')]);
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
                    'viz' => $this->bar($ratio, $user->locale !== 'en', str_starts_with($state, 'ceiling') || $f['payment_mode'] === 'credit' ? 'السقف' : 'الميزانية', str_starts_with($state, 'ceiling') || $f['payment_mode'] === 'credit' ? 'Ceiling' : 'Budget'),
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

    /** Platform names as the product says them; an unknown key is shown as it is, never guessed. */
    private function platform(string $provider, bool $ar): string
    {
        $ar_ = ['snapchat' => 'سناب شات', 'meta' => 'ميتا', 'tiktok' => 'تيك توك', 'google_ads' => 'قوقل', 'x' => 'إكس', 'linkedin' => 'لينكدإن', 'openai_ads' => 'شات جي بي تي', 'chatgpt_ads' => 'شات جي بي تي'];
        $en_ = ['snapchat' => 'Snapchat', 'meta' => 'Meta', 'tiktok' => 'TikTok', 'google_ads' => 'Google', 'x' => 'X', 'linkedin' => 'LinkedIn', 'openai_ads' => 'ChatGPT Ads', 'chatgpt_ads' => 'ChatGPT Ads'];

        return ($ar ? $ar_ : $en_)[$provider] ?? $provider;
    }

    /**
     * One platform's own share of the plan reached 90% («near») or 100% («over»). The figure only: no cause.
     *
     * @param  list<User>  $users
     * @param  array<string, mixed>  $row  one `FundingPlan::compare` row
     * @param  array<string, mixed>  $f
     */
    private function sendPlan(ProjectFunding $funding, array $users, array $row, array $f, int $cycle): int
    {
        $clientName = (string) (ClientWorkspace::query()->find($funding->client_workspace_id)->name ?? '');
        $projectName = (string) (Project::query()->find($funding->project_id)->name ?? '');
        $sar = static fn (?float $v): string => FundingMoney::format(abs((float) $v), 'SAR', 0);
        $sarEn = static fn (?float $v): string => FundingMoney::format(abs((float) $v), 'SAR', 0);
        // A platform's own figures are in USD (the platforms' one unit); with no USD rate they stay in SAR, never a guess.
        $fig = static fn (?float $usd, ?float $sarValue): string => $usd !== null ? FundingMoney::format($usd, 'USD', 0) : FundingMoney::format($sarValue, 'SAR', 0);
        $pct = (int) round((float) $row['share_used'] * 100);
        $over = $row['flag'] === 'over';
        $nameAr = $this->platform((string) $row['provider'], true);
        $nameEn = $this->platform((string) $row['provider'], false);
        $asOf = $f['last_spend_day'];

        return $this->push($funding, $users, [
            'severity' => $over ? 'critical' : 'warning',
            'entity_type' => 'funding:plan', 'dedup' => 'cycle:'.$cycle.':'.$row['provider'].':'.$row['flag'], 'state' => 'plan_'.$row['flag'].':'.$row['provider'], 'cycle' => $cycle,
            'title_ar' => "فرق عن الخطة: {$clientName}", 'title_en' => "Off plan: {$clientName}",
            'message_ar' => implode("\n", [
                "{$nameAr} ".($over ? 'تجاوزت' : 'قرّبت من').' حصتها: '.$fig($row['spent_usd'], $row['spent']).' من '.$fig($row['planned_usd'], $row['planned'])." ({$pct}%)",
                $asOf !== null ? "{$projectName} · الإنفاق حتى {$asOf}" : $projectName,
                'الخطوة: أعد التوزيع — مدير المشروع',
            ]),
            'message_en' => implode("\n", [
                "{$nameEn} is at {$pct}% of its share: ".$fig($row['spent_usd'], $row['spent']).' of '.$fig($row['planned_usd'], $row['planned']),
                $asOf !== null ? "{$projectName} · spend up to {$asOf}" : $projectName,
                'Next: reallocate — project manager',
            ]),
            'viz' => fn (bool $ar): ?array => $this->bar((float) $row['share_used'], $ar, 'الحصة', 'Share'),
        ]);
    }

    /**
     * «Ready to invoice»: one closed month of a credit budget, its spend and the dates it covers. Finance only.
     *
     * @param  list<User>  $users
     * @param  array<string, mixed>  $month  one `FundingMonth::invoiceMonths` row
     * @param  array<string, mixed>|null  $previous
     */
    private function sendInvoice(ProjectFunding $funding, array $users, array $month, ?array $previous): int
    {
        $clientName = (string) (ClientWorkspace::query()->find($funding->client_workspace_id)->name ?? '');
        $projectName = (string) (Project::query()->find($funding->project_id)->name ?? '');
        $sar = static fn (?float $v): string => FundingMoney::format(abs((float) $v), 'SAR', 0);
        $sarEn = static fn (?float $v): string => FundingMoney::format(abs((float) $v), 'SAR', 0);

        return $this->push($funding, $users, [
            'severity' => 'info',
            'entity_type' => 'funding:invoice', 'dedup' => 'invoice:'.$month['month'], 'state' => 'invoice_'.$month['month'], 'cycle' => 0,
            'title_ar' => "جاهز للفوترة: {$clientName}", 'title_en' => "Ready to invoice: {$clientName}",
            'message_ar' => implode("\n", [
                'الإنفاق '.$sar($month['spent'])." · من {$month['from']} إلى {$month['to']}",
                "{$projectName} · آخر يوم مقروء {$month['last_spend_day']}",
                'الخطوة: جهّز الفاتورة — المالية',
            ]),
            'message_en' => implode("\n", [
                'Spend '.$sarEn($month['spent'])." · {$month['from']} to {$month['to']}",
                "{$projectName} · last day read {$month['last_spend_day']}",
                'Next: prepare the invoice — finance',
            ]),
            'viz' => function (bool $ar) use ($month, $previous, $sar, $sarEn): ?array {
                if ($previous === null) {
                    return null;
                }
                $max = max(1.0, (float) $month['spent'], (float) $previous['spent']);

                return ['type' => self::VIZ_BEFORE_NOW, 'before' => max(6, (int) round((float) $previous['spent'] / $max * 100)), 'now' => max(6, (int) round((float) $month['spent'] / $max * 100)),
                    'before_label' => ($ar ? $sar($previous['spent']) : $sarEn($previous['spent'])).' '.$previous['month'], 'now_label' => ($ar ? $sar($month['spent']) : $sarEn($month['spent'])).' '.$month['month'], 'key' => 'spend'];
            },
        ]);
    }

    /**
     * Sends one prepared notice to each person through the dispatcher (their switches, importance, quiet hours and the hourly
     * ceiling apply), and records how many received it. A failure for one person never stops the rest.
     *
     * @param  list<User>  $users
     * @param  array<string, mixed>  $n
     */
    private function push(ProjectFunding $funding, array $users, array $n): int
    {
        $sent = 0;
        foreach ($users as $user) {
            try {
                $viz = $n['viz']($user->locale !== 'en');
                $r = $this->dispatcher->dispatch([
                    'tenant_id' => $funding->tenant_id, 'user_id' => (int) $user->id,
                    'client_workspace_id' => $funding->client_workspace_id, 'project_id' => $funding->project_id,
                    'type' => 'project_budget', 'severity' => $n['severity'],
                    'title' => $n['title_en'], 'message' => $n['message_en']."\n".FundingDisclaimer::text('en'), 'title_ar' => $n['title_ar'], 'message_ar' => $n['message_ar']."\n".FundingDisclaimer::text('ar'),
                    'source' => 'funding', 'entity_type' => $n['entity_type'], 'entity_id' => $funding->project_id,
                    'dedup_extra' => $n['dedup'], 'action_url' => '/budgets?project='.$funding->project_id, 'viz' => $viz,
                ]);
                $sent += $r === null ? 0 : 1;
            } catch (Throwable $e) {
                report($e);
            }
        }
        DB::table('funding_notices')->where('project_id', $funding->project_id)->where('cycle', $n['cycle'])->where('state', $n['state'])->update(['recipients' => $sent]);

        return $sent;
    }

    /**
     * Spend against the budget as the mail shell's bar. Past the budget the plan mark moves back
     * (100 / ratio), so «over» reads as over and not as a full bar.
     *
     * @return array<string, mixed>|null
     */
    private function bar(?float $ratio, bool $ar, string $planAr = 'الميزانية', string $planEn = 'Budget'): ?array
    {
        if ($ratio === null || $ratio <= 0) {
            return null;
        }
        $used = (int) round(min(1.0, $ratio) * 100);
        $plan = $ratio > 1 ? (int) round(100 / $ratio) : 100;
        $pct = (int) round($ratio * 100);

        return ['type' => self::VIZ, 'used' => $used, 'plan' => $plan,
            'used_label' => ($ar ? 'الإنفاق ' : 'Spent ').$pct.'%', 'plan_label' => ($ar ? $planAr : $planEn)];
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
