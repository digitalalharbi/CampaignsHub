<?php

declare(strict_types=1);

namespace App\Domains\CRM\Services;

use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * LEAD-SLA-NOTIFICATION-001 — the figures a lead-operations manager actually opens the product for.
 *
 * ## The questions, in the order they get asked
 *
 * How many came in. How many has nobody been given. How many has nobody spoken to. What is overdue
 * right now. How fast do we answer. And of the ones we answered, how many were worth answering.
 *
 * Every one was unanswerable from `leads` until the pipeline work put the stages and stamps in. What
 * this adds is that they are answered CONSISTENTLY: one window, one scope, one definition of
 * «contacted», computed together, so two figures on the same screen cannot disagree about what they
 * are describing — a defect LEAD-DEDUP-001 already had to fix once for «received» and «unique».
 *
 * ## Absence is not zero, here more than anywhere
 *
 * A rate with no denominator is not «0%», it is a question nobody can answer yet, and a dashboard
 * printing 0% contact rate on a quiet day tells a manager their team stopped working. Every rate is
 * null when it cannot be divided, and the caller renders that as «—».
 */
final class FollowUpWorkspace
{
    /**
     * @param  Builder<Lead>  $scope  already narrowed to what the reader may see
     * @return array<string, mixed>
     */
    public function summary(Builder $scope, Carbon $from, Carbon $to, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $base = (clone $scope)->whereBetween('received_at', [$from, $to]);

        $received = (clone $base)->count();
        $unassigned = (clone $base)->whereNull('owner_id')->count();

        $contactedValues = array_map(
            static fn (LeadStage $s): string => $s->value,
            array_values(array_filter(LeadStage::cases(), static fn (LeadStage $s): bool => $s->isContacted())),
        );

        $contacted = (clone $base)->whereIn('status', $contactedValues)->count();

        /*
         * «Not contacted» is not «received minus contacted».
         *
         * A lead marked `invalid` was never going to be contacted and is not a failure of follow-up.
         * Counting it as one hands a team an overdue list they can never clear, and a list nobody can
         * clear is a list nobody reads.
         */
        $notContacted = (clone $base)
            ->whereNotIn('status', [...$contactedValues, LeadStage::Invalid->value])
            ->count();

        $qualified = (clone $base)->whereIn('status', [
            LeadStage::Qualified->value, LeadStage::Appointment->value, LeadStage::Won->value,
        ])->count();

        $appointments = (clone $base)->whereIn('status', [
            LeadStage::Appointment->value, LeadStage::Won->value,
        ])->count();

        $won = (clone $base)->where('status', LeadStage::Won->value)->count();
        $lost = (clone $base)->where('status', LeadStage::Lost->value)->count();
        $invalid = (clone $base)->where('status', LeadStage::Invalid->value)->count();

        /*
         * Overdue is asked of the whole open pipeline, not of the window.
         *
         * A promise made three weeks ago is overdue today, and a manager filtering to «this week»
         * must not thereby stop seeing it. It is the one figure whose scope is deliberately wider
         * than the rest, and the payload says which scope it used rather than leaving a reader to
         * assume they agree.
         */
        $overdue = (clone $scope)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<', $now)
            ->whereNotIn('status', [LeadStage::Won->value, LeadStage::Lost->value, LeadStage::Invalid->value])
            ->count();

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'received' => $received,
            'unassigned' => $unassigned,
            'contacted' => $contacted,
            'not_contacted' => $notContacted,
            'qualified' => $qualified,
            'appointments' => $appointments,
            'won' => $won,
            'lost' => $lost,
            'invalid' => $invalid,
            'overdue' => $overdue,
            'overdue_scope' => 'all_open',
            /* Junk is out of the denominator: a team is not judged on leads that were never people. */
            'contact_rate' => $this->rate($contacted, $received - $invalid),
            'qualification_rate' => $this->rate($qualified, $contacted),
            'appointment_rate' => $this->rate($appointments, $qualified),
            'win_rate' => $this->rate($won, $qualified),
            'first_response' => $this->firstResponse($base),
        ];
    }

    /**
     * A rate, or null when nothing can be divided.
     *
     * «0%» and «no leads yet» are different statements and only one of them is about the team.
     */
    private function rate(int $part, int $whole): ?float
    {
        return $whole <= 0 ? null : round($part / $whole, 4);
    }

    /**
     * How long the first reply took — a median, with the count it came from.
     *
     * A mean is destroyed by one lead somebody finds in a spam folder three weeks later: it moves the
     * figure past usefulness and makes a working team look broken. The count travels with the median
     * because «2 minutes» from two leads is not a fact about the team, and a reader given only the
     * number cannot tell the difference.
     *
     * @param  Builder<Lead>  $base
     * @return array{median_minutes: int|null, measured: int, of: int}
     */
    private function firstResponse(Builder $base): array
    {
        $of = (clone $base)->count();

        $minutes = (clone $base)
            ->whereNotNull('received_at')
            ->whereNotNull('first_contact_at')
            ->get(['received_at', 'first_contact_at'])
            ->map(static fn ($lead): int => (int) round($lead->received_at->diffInSeconds($lead->first_contact_at) / 60))
            /* A clock skew or a backfilled row must not report a reply that arrived before the lead. */
            ->filter(static fn (int $m): bool => $m >= 0)
            ->sort()
            ->values();

        if ($minutes->isEmpty()) {
            return ['median_minutes' => null, 'measured' => 0, 'of' => $of];
        }

        $middle = intdiv($minutes->count(), 2);
        $median = $minutes->count() % 2 === 1
            ? (int) $minutes[$middle]
            : (int) round(((int) $minutes[$middle - 1] + (int) $minutes[$middle]) / 2);

        return ['median_minutes' => $median, 'measured' => $minutes->count(), 'of' => $of];
    }

    /**
     * The same figures per owner, for the «who is behind» question.
     *
     * Unowned leads get their own row rather than being dropped: they are the most urgent thing on
     * the screen, and a per-person table that silently omits them lets a growing pile of unassigned
     * leads sit outside every column a manager reads.
     *
     * @param  Builder<Lead>  $scope
     * @return list<array<string, mixed>>
     */
    public function byOwner(Builder $scope, Carbon $from, Carbon $to, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $owners = (clone $scope)
            ->whereBetween('received_at', [$from, $to])
            ->distinct()
            ->pluck('owner_id');

        $out = [];

        foreach ($owners as $ownerId) {
            $per = (clone $scope)->when(
                $ownerId === null,
                static fn (Builder $q): Builder => $q->whereNull('owner_id'),
                static fn (Builder $q): Builder => $q->where('owner_id', $ownerId),
            );

            $out[] = ['owner_id' => $ownerId === null ? null : (int) $ownerId] + $this->summary($per, $from, $to, $now);
        }

        return $out;
    }
}
