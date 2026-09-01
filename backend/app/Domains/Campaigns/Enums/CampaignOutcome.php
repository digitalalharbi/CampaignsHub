<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * CAMPAIGN-OUTCOME-DIMENSION-001 — what a campaign actually BUYS, which is not what it is for.
 *
 * ## Why this is a second dimension and not more objectives
 *
 * `ObjectiveFamily` answers «what is this campaign for» — leads, sales, awareness. Two campaigns in
 * the SAME family routinely buy completely different actions: one collects a native lead form inside
 * Meta, the next sends people to a landing page, the third opens a WhatsApp conversation, the fourth
 * rings a phone. All four are `leads`. All four report a «cost per result». None of those four costs
 * is comparable with any other.
 *
 * Folding the action into the objective would need a family per combination and would still not
 * help, because the objective is the thing a client asks for and the action is the thing the media
 * buyer chose. They change independently — the same brief is run as a lead form this month and as
 * click-to-WhatsApp the next — and a taxonomy that cannot express that has to lie about one of them.
 *
 * ## The cost that this makes sayable
 *
 * «Cost per result» over a mixed set is an average of four different things. With the action named,
 * the product can say «cost per form» and «cost per conversation» and refuse to compare them, which
 * is the honest answer and the one an operator can act on.
 *
 * ## A click is not a person
 *
 * `LinkClick` and `LandingPageVisit` are here because campaigns genuinely buy them, and they are
 * deliberately NOT lead actions. A click is an event with no identity attached, and the product must
 * never turn a count of them into people — see LEAD-SOURCE-ATTRIBUTION-001, which owns that rule.
 * `Messaging` is the ads-side metric, and it is not a WhatsApp conversation either: that needs an
 * authorisation this install does not hold (WHATSAPP-CONVERSATION-SOURCE-001).
 */
enum CampaignOutcome: string
{
    /** A form the person filled in without leaving the platform. */
    case NativeLeadForm = 'native_lead_form';

    /** A form on the advertiser's own site, reported through a pixel or a conversion API. */
    case WebsiteLead = 'website_lead';

    /** An outbound click. An event, never a person. */
    case LinkClick = 'link_click';

    /** A click that arrived — the platform's landing-page view, which is fewer than the clicks. */
    case LandingPageVisit = 'landing_page_visit';

    /** A call placed from the ad. */
    case PhoneCall = 'phone_call';

    /**
     * The ads-side messaging metric: Meta click-to-WhatsApp and the other messaging objectives.
     *
     * «Conversations started» as the PLATFORM counts them. It is not a conversation this product has
     * read, and no identity may be derived from it.
     */
    case Messaging = 'messaging';

    /** A purchase confirmed by the store or the platform's own conversion. */
    case Purchase = 'purchase';

    case AppInstall = 'app_install';

    /** A view, a reach, an engagement — bought as itself, with nothing further expected. */
    case Attention = 'attention';

    /** The provider named an action this product does not model yet. Stated, never guessed at. */
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Is this an action that produces a person the team can follow up?
     *
     * The distinction the whole enum exists for. A click and a landing-page visit are events; a form
     * and a call and a conversation are people. A product that blurs the two ends up reporting a
     * «cost per lead» computed from clicks, which is a number with no referent.
     */
    public function producesALead(): bool
    {
        return in_array($this, [self::NativeLeadForm, self::WebsiteLead, self::PhoneCall, self::Messaging], true);
    }

    /**
     * May two campaigns' cost-per-result be compared?
     *
     * Only when they bought the same action. `unknown` is comparable with nothing, including another
     * `unknown` — two providers' unmodelled actions are not thereby the same action.
     */
    public function comparableWith(self $other): bool
    {
        return $this === $other && $this !== self::Unknown;
    }

    /** @return array{ar: string, en: string} */
    public function label(): array
    {
        return match ($this) {
            self::NativeLeadForm => ['ar' => 'نموذج داخل المنصة', 'en' => 'Native lead form'],
            self::WebsiteLead => ['ar' => 'نموذج على الموقع', 'en' => 'Website lead'],
            self::LinkClick => ['ar' => 'نقرة على الرابط', 'en' => 'Link click'],
            self::LandingPageVisit => ['ar' => 'زيارة صفحة الهبوط', 'en' => 'Landing page visit'],
            self::PhoneCall => ['ar' => 'مكالمة هاتفية', 'en' => 'Phone call'],
            self::Messaging => ['ar' => 'محادثة (حسب المنصة)', 'en' => 'Messaging (as the platform counts it)'],
            self::Purchase => ['ar' => 'شراء', 'en' => 'Purchase'],
            self::AppInstall => ['ar' => 'تثبيت التطبيق', 'en' => 'App install'],
            self::Attention => ['ar' => 'مشاهدة أو تفاعل', 'en' => 'Views and engagement'],
            self::Unknown => ['ar' => 'إجراء غير محدّد', 'en' => 'Action not stated'],
        };
    }

    /**
     * What the metric measuring this action should be CALLED.
     *
     * «Cost per result» over a mixed set averages four different things. Naming the cost after the
     * action is what makes a reader notice that two rows are not comparable, without having to be
     * told.
     *
     * @return array{ar: string, en: string}
     */
    public function costLabel(): array
    {
        return match ($this) {
            self::NativeLeadForm, self::WebsiteLead => ['ar' => 'تكلفة النموذج', 'en' => 'Cost per form'],
            self::LinkClick => ['ar' => 'تكلفة النقرة', 'en' => 'Cost per click'],
            self::LandingPageVisit => ['ar' => 'تكلفة الزيارة', 'en' => 'Cost per visit'],
            self::PhoneCall => ['ar' => 'تكلفة المكالمة', 'en' => 'Cost per call'],
            self::Messaging => ['ar' => 'تكلفة المحادثة', 'en' => 'Cost per conversation'],
            self::Purchase => ['ar' => 'تكلفة الطلب', 'en' => 'Cost per order'],
            self::AppInstall => ['ar' => 'تكلفة التثبيت', 'en' => 'Cost per install'],
            self::Attention => ['ar' => 'تكلفة الألف ظهور', 'en' => 'Cost per thousand impressions'],
            self::Unknown => ['ar' => 'تكلفة النتيجة', 'en' => 'Cost per result'],
        };
    }

    /**
     * The action a provider's own objective name implies, where it implies one.
     *
     * Deliberately conservative. Meta's `OUTCOME_LEADS` covers a native form AND a website form AND
     * click-to-WhatsApp — the objective alone cannot tell them apart, and guessing would put «cost
     * per form» on a campaign that buys conversations. Where the provider's word is not decisive
     * this returns `Unknown`, and the answer improves when the DESTINATION is read rather than by
     * this method getting cleverer.
     */
    public static function fromProviderObjective(?string $objective): self
    {
        $key = strtolower(trim((string) $objective));

        return match (true) {
            $key === '' => self::Unknown,
            str_contains($key, 'lead_form') || str_contains($key, 'leadgen') || str_contains($key, 'lead_generation') => self::NativeLeadForm,
            str_contains($key, 'message') || str_contains($key, 'whatsapp') || str_contains($key, 'conversation') => self::Messaging,
            str_contains($key, 'call') => self::PhoneCall,
            str_contains($key, 'install') || str_contains($key, 'app_promotion') => self::AppInstall,
            str_contains($key, 'purchase') || str_contains($key, 'sales') || str_contains($key, 'catalog') => self::Purchase,
            str_contains($key, 'landing_page') => self::LandingPageVisit,
            str_contains($key, 'traffic') || str_contains($key, 'link_click') => self::LinkClick,
            str_contains($key, 'awareness') || str_contains($key, 'reach') || str_contains($key, 'video') || str_contains($key, 'engagement') => self::Attention,
            /*
             * `leads` alone is NOT enough. Meta's OUTCOME_LEADS is a native form, a website form or a
             * WhatsApp conversation depending on a destination this method cannot see, and the three
             * have three different costs. Unknown is the honest answer and it is a better one than a
             * plausible guess printed as a label.
             */
            default => self::Unknown,
        };
    }
}
