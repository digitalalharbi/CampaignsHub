<?php

declare(strict_types=1);

namespace App\Domains\CRM\Services;

use App\Domains\CRM\Enums\LeadStage;

/**
 * EXECUTIVE-OPS-DASHBOARD-001 — one project view that joins the money to the people to the work.
 *
 * ## The questions, in the order a manager actually asks them
 *
 * What was spent. What was generated. What did each result cost. Which source produced it. What
 * happened after the lead arrived. Did the team contact it. How fast. What is overdue. What needs
 * attention. Is budget and performance on plan.
 *
 * Every one of those is answerable today and NONE of them is answerable in one place: the spend is
 * on the dashboard, the leads are in the inbox, the follow-up is in the workspace, and joining them
 * is a job a manager currently does by opening three screens and holding the numbers in their head.
 *
 * ## It computes nothing of its own
 *
 * The spend comes from the metrics aggregator; the lead and team figures come from
 * `FollowUpWorkspace`. This joins two existing answers and derives ONE new figure from the pair —
 * the cost per lead — because that is the number the join exists to produce and it cannot be read
 * from either side alone.
 *
 * A second aggregation here would be a second opinion about spend, and the first time it disagreed
 * with the dashboard the manager would have no way to tell which screen was lying.
 *
 * ## The cost per lead fails closed
 *
 * A spend the money contract will not state produces no cost per lead — not a cost computed from the
 * converted half, and not zero. The whole point of this figure is that somebody divides by it when
 * deciding next month's budget.
 */
final class ExecutiveOperations
{
    /**
     * Two answers in, one join out.
     *
     * It takes the follow-up summary rather than the query it came from, deliberately: this class
     * cannot then compute a lead figure of its own, and there is no arrangement of arguments that
     * would let it. `FollowUpWorkspace` owns the lead and team numbers, the metrics aggregator owns
     * the spend, and the only thing produced here is the figure neither side can see.
     *
     * @param  array<string, mixed>  $work  `FollowUpWorkspace::summary()`, over the same window
     * @param  array<string, mixed>  $spend  the metrics aggregator's totals, over the same window
     * @return array<string, mixed>
     */
    public function build(array $work, array $spend): array
    {

        $money = $this->spendable($spend);
        $received = (int) $work['received'];
        $qualified = (int) $work['qualified'];

        return [
            'window' => $work['window'],

            /*
             * What was spent, and whether it can be stated at all. The reason travels with the
             * refusal so the executive view does not become the one screen that prints a figure the
             * rest of the product refuses to.
             */
            'spend' => $money,

            'leads' => [
                'received' => $received,
                'unassigned' => (int) $work['unassigned'],
                'contacted' => (int) $work['contacted'],
                'not_contacted' => (int) $work['not_contacted'],
                'qualified' => $qualified,
                'appointments' => (int) $work['appointments'],
                'won' => (int) $work['won'],
                'lost' => (int) $work['lost'],
                'invalid' => (int) $work['invalid'],
            ],

            'team' => [
                'contact_rate' => $work['contact_rate'],
                'qualification_rate' => $work['qualification_rate'],
                'appointment_rate' => $work['appointment_rate'],
                'win_rate' => $work['win_rate'],
                'first_response' => $work['first_response'],
                'overdue' => (int) $work['overdue'],
                'overdue_scope' => $work['overdue_scope'],
            ],

            /*
             * The one figure this join exists to produce, and the only one derived here.
             *
             * Null when the spend cannot be stated, when nothing was spent, or when no lead arrived
             * — «infinite cost per lead» is not a number anybody can act on, and a zero would say
             * the leads were free.
             */
            'cost_per_lead' => $this->costPer($money, $received - (int) $work['invalid']),
            'cost_per_qualified_lead' => $this->costPer($money, $qualified),

            /*
             * What needs attention, as a list rather than as a colour.
             *
             * A dashboard that turns a card amber has said something a reader has to decode. These
             * are sentences with a subject: what is wrong, how much of it there is, and which of the
             * three domains it belongs to.
             */
            'attention' => $this->attention($work, $money),
        ];
    }

    /**
     * The spend, as a figure that may be stated or a refusal that says why.
     *
     * @param  array<string, mixed>  $spend
     * @return array{amount: float|null, currency: string|null, reason: string|null}
     */
    private function spendable(array $spend): array
    {
        $amount = $spend['spend'] ?? null;
        $withheld = (int) ($spend['spend_withheld_rows'] ?? 0);
        $currencies = (int) ($spend['money_original_currencies'] ?? 0);

        if ($currencies > 1) {
            return ['amount' => null, 'currency' => null, 'reason' => 'mixed_currency'];
        }

        if ($withheld > 0 && $amount !== null) {
            /*
             * PARTIAL — some of the window converted and some did not. Printing the converted half
             * as the spend understates it, and this is the figure a cost per lead divides.
             */
            return ['amount' => null, 'currency' => null, 'reason' => 'partial'];
        }

        if ($amount === null) {
            return ['amount' => null, 'currency' => null, 'reason' => $withheld > 0 ? 'withheld' : 'absent'];
        }

        return [
            'amount' => (float) $amount,
            'currency' => $spend['currency'] ?? null,
            'reason' => null,
        ];
    }

    /**
     * @param  array{amount: float|null, currency: string|null, reason: string|null}  $money
     * @return array{amount: float|null, currency: string|null, reason: string|null}
     */
    private function costPer(array $money, int $count): array
    {
        if ($money['amount'] === null) {
            return ['amount' => null, 'currency' => null, 'reason' => $money['reason'] ?? 'absent'];
        }

        if ($count <= 0) {
            return ['amount' => null, 'currency' => null, 'reason' => 'no_leads'];
        }

        /*
         * Zero spend produces no cost per lead.
         *
         * The aggregator returns 0 for a window with no spend rows at all, and «0 per lead» reads as
         * «these leads were free» — which is a claim about the advertising rather than about the
         * absence of it. A window where nothing ran has no cost to report, and saying so is the
         * whole difference between a missing figure and a flattering one.
         */
        if ($money['amount'] <= 0.0) {
            return ['amount' => null, 'currency' => null, 'reason' => 'no_spend'];
        }

        return [
            'amount' => round($money['amount'] / $count, 2),
            'currency' => $money['currency'],
            'reason' => null,
        ];
    }

    /**
     * What a manager should look at, as sentences with a subject.
     *
     * Ordered by how quickly each decays: an unassigned lead is worth less every hour, an overdue
     * promise has already been broken, and an unstateable spend is a reporting problem rather than
     * an operational one. Nothing is invented — every entry counts rows this class already read.
     *
     * @param  array<string, mixed>  $work
     * @param  array{amount: float|null, currency: string|null, reason: string|null}  $money
     * @return list<array{kind: string, domain: string, count: int}>
     */
    private function attention(array $work, array $money): array
    {
        $out = [];

        if ((int) $work['unassigned'] > 0) {
            $out[] = ['kind' => 'unassigned_leads', 'domain' => 'leads', 'count' => (int) $work['unassigned']];
        }

        if ((int) $work['overdue'] > 0) {
            $out[] = ['kind' => 'overdue_follow_up', 'domain' => 'team', 'count' => (int) $work['overdue']];
        }

        if ((int) $work['not_contacted'] > 0) {
            $out[] = ['kind' => 'never_contacted', 'domain' => 'team', 'count' => (int) $work['not_contacted']];
        }

        if ($money['reason'] !== null && $money['reason'] !== 'absent') {
            /*
             * A spend nobody can state is worth saying out loud on this screen: every cost figure
             * above it is missing for the same reason, and a manager who does not know that reads
             * the gaps as «no leads cost anything».
             */
            $out[] = ['kind' => 'spend_not_comparable', 'domain' => 'money', 'count' => 1];
        }

        return $out;
    }

    /** The stages this class treats as «a real person we could have called». */
    public static function contactableStages(): array
    {
        return array_values(array_filter(
            LeadStage::cases(),
            static fn (LeadStage $s): bool => $s !== LeadStage::Invalid,
        ));
    }
}
