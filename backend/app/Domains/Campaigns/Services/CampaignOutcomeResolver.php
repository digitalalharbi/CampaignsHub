<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CampaignOutcome;

/**
 * What a campaign BOUGHT, from the evidence there is — CAMPAIGN-OUTCOME-DIMENSION-001.
 *
 * `CampaignOutcome` has existed as a well-argued enum that nothing constructed: an abstraction with
 * no producer, which is the same as not having it. This is the producer, and its whole design
 * question is where to stop guessing.
 *
 * ## Two kinds of objective, and only one of them names an action
 *
 * Some canonical objectives name the action by themselves. `purchases` buys a purchase; `reach` buys
 * attention; `app_installs` buys an install. There is nothing left to learn and the outcome is
 * simply read off.
 *
 * `leads` and `conversions` do not. A lead campaign collects a native form, or sends people to a
 * form on the advertiser's own site, or opens a WhatsApp conversation, or rings a phone — four
 * actions, four costs, none comparable with any other, all reported as «cost per result». Deciding
 * between them needs the provider's own destination, which lives in the raw payload and is spelt
 * differently by every provider. Where that is present it is read; where it is not, the answer is
 * `Unknown`.
 *
 * ## Unknown is the point, not a gap
 *
 * `CampaignOutcome::comparableWith()` refuses `Unknown` against everything, including another
 * `Unknown` — two providers' unmodelled actions are not thereby the same action. So an honest
 * `Unknown` costs a comparison the product could not have made truthfully anyway, and a confident
 * guess would buy that comparison with a fabricated premise. The whole reason this dimension exists
 * is that «cost per result» over a mixed set is an average of different things; a resolver that
 * guessed would recreate the defect one layer down and hide it behind a label.
 */
final class CampaignOutcomeResolver
{
    /**
     * Meta's destination for a lead objective, by the field it actually sends.
     *
     * `ON_AD` is the native instant form; `WEBSITE` is the advertiser's own page; `MESSENGER`,
     * `WHATSAPP` and `INSTAGRAM_DIRECT` are messaging; `PHONE_CALL` is a call. These are the values
     * the Graph API returns on `destination_type`, and reading them is the difference between «cost
     * per lead» meaning one thing and meaning four.
     */
    private const META_DESTINATION = [
        'ON_AD' => CampaignOutcome::NativeLeadForm,
        'ON_POST' => CampaignOutcome::NativeLeadForm,
        'WEBSITE' => CampaignOutcome::WebsiteLead,
        'MESSENGER' => CampaignOutcome::Messaging,
        'WHATSAPP' => CampaignOutcome::Messaging,
        'INSTAGRAM_DIRECT' => CampaignOutcome::Messaging,
        'PHONE_CALL' => CampaignOutcome::PhoneCall,
        'CALL' => CampaignOutcome::PhoneCall,
    ];

    /**
     * The same question for the other providers, on the field each of them uses.
     *
     * Snapchat and TikTok express it as an optimisation goal rather than a destination. Only the
     * values that genuinely settle the action are listed; a goal that narrows nothing is deliberately
     * absent, so it falls through to `Unknown` rather than being mapped to something plausible.
     */
    private const GOAL = [
        // Snapchat
        'LEAD_FORM_SUBMISSIONS' => CampaignOutcome::NativeLeadForm,
        'SIGN_UPS' => CampaignOutcome::WebsiteLead,
        'CALLS' => CampaignOutcome::PhoneCall,
        // TikTok
        'LEAD_GENERATION' => CampaignOutcome::NativeLeadForm,
        'FORM' => CampaignOutcome::NativeLeadForm,
        'MESSAGE' => CampaignOutcome::Messaging,
        // Google
        'LEAD_FORM' => CampaignOutcome::NativeLeadForm,
        'PHONE_CALLS' => CampaignOutcome::PhoneCall,
        'CALL_CLICKS' => CampaignOutcome::PhoneCall,
    ];

    /**
     * The outcome this campaign bought.
     *
     * @param  array<string,mixed>  $raw  the provider's untouched campaign payload, or []
     */
    public function resolve(?string $canonicalObjective, array $raw = []): CampaignOutcome
    {
        $objective = $canonicalObjective === null ? null : CampaignObjective::tryFrom($canonicalObjective);

        if ($objective === null) {
            return CampaignOutcome::Unknown;
        }

        /*
         * The unambiguous ones, read off rather than inferred.
         *
         * `add_to_cart` is deliberately a purchase-family action rather than its own case: it is a
         * step of the same buying journey measured by the same pixel, and the enum models what was
         * bought, not how far down the funnel it got.
         */
        $direct = match ($objective) {
            CampaignObjective::Sales,
            CampaignObjective::Purchases,
            CampaignObjective::AddToCart => CampaignOutcome::Purchase,
            CampaignObjective::AppInstalls => CampaignOutcome::AppInstall,
            CampaignObjective::Traffic => CampaignOutcome::LinkClick,
            CampaignObjective::LandingPageViews => CampaignOutcome::LandingPageVisit,
            CampaignObjective::Awareness,
            CampaignObjective::Reach,
            CampaignObjective::VideoViews,
            CampaignObjective::Engagement => CampaignOutcome::Attention,
            default => null,
        };

        if ($direct !== null) {
            return $direct;
        }

        /*
         * `leads` and `conversions` reach here, and only the provider can settle them.
         *
         * `conversions` is in this branch on purpose: it is the objective a media buyer picks when
         * optimising for an event the pixel defines, and that event is a purchase on one account and
         * a form submission on the next. Treating it as a purchase because it usually is would put a
         * cost per form into a cost-per-order comparison, which is the exact averaging this
         * dimension exists to stop.
         */
        return $this->fromProvider($raw);
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function fromProvider(array $raw): CampaignOutcome
    {
        foreach (['destination_type', 'destinationType'] as $key) {
            $value = $this->text($raw[$key] ?? null);

            if ($value !== null && isset(self::META_DESTINATION[$value])) {
                return self::META_DESTINATION[$value];
            }
        }

        foreach (['optimization_goal', 'optimizationGoal', 'optimization_event', 'objective_type'] as $key) {
            $value = $this->text($raw[$key] ?? null);

            if ($value !== null && isset(self::GOAL[$value])) {
                return self::GOAL[$value];
            }
        }

        return CampaignOutcome::Unknown;
    }

    /** Upper-cased, because providers are inconsistent about case and nothing else about it matters. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtoupper(trim($value));

        return $value === '' ? null : $value;
    }
}
