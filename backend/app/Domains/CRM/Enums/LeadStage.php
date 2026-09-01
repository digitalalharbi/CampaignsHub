<?php

declare(strict_types=1);

namespace App\Domains\CRM\Enums;

/**
 * LEAD-OPERATIONS-001 — the pipeline a lead actually moves through, and what may follow what.
 *
 * ## Why this is not `LeadStatus`
 *
 * `LeadStatus` is a SALES lifecycle — new, contacted, qualified, proposal sent, negotiation, won,
 * lost — written for an opportunity somebody is working towards a deal. It has no answer for the
 * three things a lead-operations team asks all day: has anybody been GIVEN this lead, has anybody
 * TRIED to reach them, and is this a real person at all.
 *
 * So «assigned» was invisible (a lead sat at `new` whether or not it had an owner), «we called and
 * nobody answered» was indistinguishable from «we have not called», and a junk submission had to be
 * marked `lost` — which put it in the same bucket as a real customer who chose a competitor and
 * quietly poisoned every conversion rate computed from that column.
 *
 * The stages are additive: `new`, `contacted`, `qualified`, `won` and `lost` keep the values they
 * already have in the database, so nothing is rewritten and every existing row is already a valid
 * stage.
 *
 * ## Order is a fact about the work, not a display preference
 *
 * `rank()` is what makes «has this lead moved backwards» and «how far did it get» answerable. The
 * terminal three share the last rank because they are ends, not degrees — a lead that was marked
 * invalid did not get FURTHER than one that was won.
 */
enum LeadStage: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case ContactAttempted = 'contact_attempted';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Appointment = 'appointment';
    case Won = 'won';
    case Lost = 'lost';
    /** Not a person: a test submission, a bot, a number that was never real. Never a `lost` sale. */
    case Invalid = 'invalid';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Won, self::Lost, self::Invalid], true);
    }

    /** How far along the pipeline this stage is. The three ends share a rank; they are not degrees. */
    public function rank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Assigned => 1,
            self::ContactAttempted => 2,
            self::Contacted => 3,
            self::Qualified => 4,
            self::Appointment => 5,
            self::Won, self::Lost, self::Invalid => 6,
        };
    }

    /**
     * Has this lead been spoken to? The question every contact-rate figure is built on.
     *
     * `contact_attempted` is deliberately NOT contact: a call nobody answered is an attempt, and
     * counting it as a conversation is how a team's contact rate reads 100% while half the leads
     * have never spoken to anybody.
     */
    public function isContacted(): bool
    {
        return $this->rank() >= self::Contacted->rank() && $this !== self::Invalid;
    }

    /**
     * The stages a lead may move to from here.
     *
     * ## Forward is unrestricted, and that is deliberate
     *
     * A team that assigns a lead and reaches the person on the first call goes straight from
     * `assigned` to `contacted`; there was no failed attempt to record. An earlier draft demanded one
     * step at a time, and the only thing that achieves is teaching an agent to click through the
     * stages they did not do — which puts a fabricated attempt into the very figures the stages
     * exist to measure. The pipeline records what happened; it does not police how fast.
     *
     * ## Backwards is one step, because a correction is not a rewrite
     *
     * A mis-click has to be undoable: `contacted` marked by mistake goes back to
     * `contact_attempted`. What is refused is falling back PAST recorded work — a lead with a
     * `first_contact_at` sitting at `new` is a row whose stage and history contradict each other,
     * and every report reading one of them is then wrong about the other.
     *
     * `won`, `lost` and `invalid` are reachable from anywhere: a lead can say no on the first call,
     * and junk can be recognised at any point, including after somebody has rung it.
     *
     * @return list<self>
     */
    public function next(): array
    {
        if ($this === self::Invalid) {
            /*
             * Out of `invalid` only back to `new`. Re-opening is a correction — somebody marked a
             * real person as junk — and it starts the work again rather than resuming it midway.
             */
            return [self::New];
        }

        $allowed = [];

        foreach (self::cases() as $stage) {
            if ($stage->isTerminal()) {
                continue;
            }

            $step = $stage->rank() - $this->rank();

            if ($step > 0 || $step === -1) {
                $allowed[] = $stage;
            }
        }

        return [...$allowed, self::Won, self::Lost, self::Invalid];
    }

    public function allows(self $next): bool
    {
        return $next === $this || in_array($next, $this->next(), true);
    }

    /** @return array{ar: string, en: string} */
    public function label(): array
    {
        return match ($this) {
            self::New => ['ar' => 'جديد', 'en' => 'New'],
            self::Assigned => ['ar' => 'مُسنَد', 'en' => 'Assigned'],
            self::ContactAttempted => ['ar' => 'محاولة تواصل', 'en' => 'Contact attempted'],
            self::Contacted => ['ar' => 'تم التواصل', 'en' => 'Contacted'],
            self::Qualified => ['ar' => 'مؤهَّل', 'en' => 'Qualified'],
            self::Appointment => ['ar' => 'موعد', 'en' => 'Appointment'],
            self::Won => ['ar' => 'مكسوب', 'en' => 'Won'],
            self::Lost => ['ar' => 'مفقود', 'en' => 'Lost'],
            self::Invalid => ['ar' => 'غير صالح', 'en' => 'Invalid'],
        };
    }
}
