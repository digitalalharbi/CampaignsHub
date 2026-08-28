<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 — the five objectives a customer chooses from.
 *
 * ## Why a fourth type is not a fourth taxonomy
 *
 * Three groupings were already live. `CampaignObjective` holds fourteen raw values, each a real thing
 * a provider said, and is kept for provenance. `ObjectiveFamily` groups those into eight and is what
 * the metric engines already reason about. The frontend then carried a THIRD grouping —
 * `PATH_OBJECTIVES`, three «marketing paths» — which is why a reader could be shown «التحويل
 * والمبيعات» and «المبيعات» as two simultaneous primary choices for the same money.
 *
 * This does not add a fourth. It is derived FROM `ObjectiveFamily` (see `ObjectiveFamily::canonical()`)
 * and replaces the frontend path grouping outright. The chain stays single:
 *
 *   provider string → CampaignObjective (raw, preserved) → ObjectiveFamily → CanonicalObjective
 *
 * Nothing downstream may map a provider string straight to one of these; the middle steps are where
 * provenance and metric behaviour live.
 *
 * ## Why five
 *
 * Awareness, Engagement and Video are one question — «did people see it and react» — measured three
 * ways depending on what a platform publishes. Asking a user to choose between «الوعي» and «التفاعل»
 * is asking a question with no correct answer, and the split existed because providers name the same
 * intent differently, not because the intents differ.
 *
 * Traffic, Leads, App Promotion and Sales each buy a DIFFERENT outcome, and the metric that proves
 * success for one proves nothing for another — which is exactly why they must not be merged, and why
 * `الكل` may never rank across them with a single metric such as ROAS.
 */
enum CanonicalObjective: string
{
    case AwarenessEngagement = 'awareness_engagement';
    case Traffic = 'traffic';
    case Leads = 'leads';
    case AppPromotion = 'app_promotion';
    case Sales = 'sales';

    /**
     * Not offered as a choice, and deliberately not hidden either.
     *
     * A provider that invents an objective string this product has not mapped must not be quietly
     * folded into a real group — a campaign silently becoming «Sales» would then be judged by ROAS it
     * never set out to earn. Unknown is a state a reader can see and act on; a wrong group is not.
     */
    case Unknown = 'unknown';

    /**
     * The five a customer picks from, in the product's own order.
     *
     * `Unknown` is absent by construction rather than filtered by callers, so a new caller cannot
     * accidentally offer it.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return [
            self::AwarenessEngagement,
            self::Traffic,
            self::Leads,
            self::AppPromotion,
            self::Sales,
        ];
    }

    /** @return array{ar: string, en: string} */
    public function label(): array
    {
        return match ($this) {
            self::AwarenessEngagement => ['ar' => 'الوعي والتفاعل', 'en' => 'Awareness & Engagement'],
            self::Traffic => ['ar' => 'الزيارات', 'en' => 'Traffic'],
            self::Leads => ['ar' => 'العملاء المحتملون', 'en' => 'Leads'],
            self::AppPromotion => ['ar' => 'الترويج للتطبيق', 'en' => 'App Promotion'],
            self::Sales => ['ar' => 'المبيعات', 'en' => 'Sales'],
            self::Unknown => ['ar' => 'غير مصنّف', 'en' => 'Unclassified'],
        };
    }

    /** The `ObjectiveFamily` cases this objective covers. */
    public function families(): array
    {
        return match ($this) {
            self::AwarenessEngagement => [ObjectiveFamily::Awareness, ObjectiveFamily::Engagement, ObjectiveFamily::Video],
            self::Traffic => [ObjectiveFamily::Traffic],
            self::Leads => [ObjectiveFamily::Leads],
            self::AppPromotion => [ObjectiveFamily::App],
            self::Sales => [ObjectiveFamily::Sales],
            self::Unknown => [ObjectiveFamily::Unknown],
        };
    }

    /**
     * The RAW objective values the metrics API must be given to filter by this objective.
     *
     * This is what keeps the filter honest. The server takes a list of `CampaignObjective` values, so
     * choosing «الوعي والتفاعل» has to expand into every raw objective that groups there. Narrowing
     * only the label and leaving the query alone is the frontend-only filtering
     * ANALYTICS-FILTER-TRUTH-001 forbids — the KPI row would move and the chart beneath it would not.
     *
     * Derived by asking every raw objective which family it belongs to, so a new `CampaignObjective`
     * case is covered the moment it declares its family, with nothing here to remember to update.
     *
     * @return list<string>
     */
    public function rawObjectives(): array
    {
        $families = $this->families();

        return array_values(array_map(
            static fn (CampaignObjective $o): string => $o->value,
            array_filter(
                CampaignObjective::cases(),
                fn (CampaignObjective $o): bool => in_array($o->family(), $families, true),
            ),
        ));
    }
}
