<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\CanonicalObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use Tests\TestCase;

/**
 * ANALYTICS-OBJECTIVE-SYSTEM-001 — five objectives a customer chooses from, and one way to reach them.
 *
 * ## What this replaces
 *
 * Three overlapping taxonomies were live at once. `CampaignObjective` holds fourteen raw values, each
 * a real thing a provider said. `ObjectiveFamily` groups those into eight. And the frontend carried a
 * THIRD grouping — `PATH_OBJECTIVES`, three «marketing paths» — which is why a reader could be offered
 * «التحويل والمبيعات» and «المبيعات» as two simultaneous primary choices for the same money.
 *
 * The canonical set is five, plus «all». `CanonicalObjective` is that set, and it is derived FROM
 * `ObjectiveFamily` rather than beside it — a fourth taxonomy would have been the very defect this
 * requirement exists to remove.
 *
 * ## Why Awareness, Engagement and Video collapse into one
 *
 * They are one question — «did people see it and react to it» — measured three ways depending on what
 * a platform happens to publish. Splitting them made a user choose between «الوعي» and «التفاعل»
 * without either choice being wrong, which is a choice that cannot be made correctly.
 *
 * Sales, Leads, Traffic and App stay separate because each buys a DIFFERENT outcome, and the metric
 * that proves success for one proves nothing for another.
 */
final class CanonicalObjectiveTest extends TestCase
{
    /** The five a customer sees, and nothing else. */
    public function test_exactly_five_objectives_are_offered(): void
    {
        $selectable = CanonicalObjective::selectable();

        $this->assertSame(
            ['awareness_engagement', 'traffic', 'leads', 'app_promotion', 'sales'],
            array_map(fn (CanonicalObjective $o): string => $o->value, $selectable),
            'The product offers exactly five objective groups; a sixth is a competing taxonomy.',
        );
    }

    /**
     * Awareness, Engagement and Video are one question measured three ways.
     */
    public function test_awareness_engagement_and_video_are_one_objective(): void
    {
        foreach ([ObjectiveFamily::Awareness, ObjectiveFamily::Engagement, ObjectiveFamily::Video] as $family) {
            $this->assertSame(
                CanonicalObjective::AwarenessEngagement,
                $family->canonical(),
                "{$family->value} must group into الوعي والتفاعل.",
            );
        }
    }

    /** Each remaining family buys a different outcome and keeps its own group. */
    public function test_the_outcome_objectives_stay_separate(): void
    {
        $this->assertSame(CanonicalObjective::Traffic, ObjectiveFamily::Traffic->canonical());
        $this->assertSame(CanonicalObjective::Leads, ObjectiveFamily::Leads->canonical());
        $this->assertSame(CanonicalObjective::AppPromotion, ObjectiveFamily::App->canonical());
        $this->assertSame(CanonicalObjective::Sales, ObjectiveFamily::Sales->canonical());
    }

    /**
     * An unmapped family is UNKNOWN, never quietly folded into a real objective.
     *
     * A provider inventing a new objective string must not silently become «Sales» and start being
     * judged by ROAS. Unknown is a state a reader can see and act on; a wrong group is not.
     */
    public function test_an_unknown_family_is_explicit_and_not_guessed(): void
    {
        $this->assertSame(CanonicalObjective::Unknown, ObjectiveFamily::Unknown->canonical());
        $this->assertNotContains(CanonicalObjective::Unknown, CanonicalObjective::selectable());
    }

    /**
     * Every raw provider objective resolves to a canonical one — the chain must not break midway.
     *
     * `CampaignObjective` → `ObjectiveFamily` → `CanonicalObjective` is the whole normalisation path.
     * A raw objective that reaches a dead end would render a campaign unfilterable on the one control
     * the product now offers.
     */
    public function test_every_raw_provider_objective_reaches_a_canonical_objective(): void
    {
        foreach (CampaignObjective::cases() as $raw) {
            $canonical = $raw->family()->canonical();

            $this->assertInstanceOf(
                CanonicalObjective::class,
                $canonical,
                "{$raw->value} does not resolve to a canonical objective.",
            );
        }
    }

    /** Both languages, because the filter is Arabic-first and the label is the control's whole surface. */
    public function test_each_objective_is_named_in_both_languages(): void
    {
        foreach (CanonicalObjective::selectable() as $objective) {
            $label = $objective->label();

            $this->assertArrayHasKey('ar', $label);
            $this->assertArrayHasKey('en', $label);
            $this->assertNotSame('', trim($label['ar']));
            $this->assertNotSame('', trim($label['en']));
        }

        $this->assertSame('الوعي والتفاعل', CanonicalObjective::AwarenessEngagement->label()['ar']);
        $this->assertSame('الترويج للتطبيق', CanonicalObjective::AppPromotion->label()['ar']);
    }

    /**
     * The families behind one canonical objective are recoverable, because the SERVER filters by them.
     *
     * The metrics API takes a list of objectives. Choosing «الوعي والتفاعل» must therefore expand into
     * every raw objective that groups there — otherwise the filter narrows the label and not the data,
     * which is the frontend-only filtering this requirement forbids.
     */
    public function test_a_canonical_objective_expands_to_the_raw_objectives_the_server_filters_by(): void
    {
        $raw = CanonicalObjective::AwarenessEngagement->rawObjectives();

        $this->assertContains('awareness', $raw);
        $this->assertContains('engagement', $raw);
        $this->assertContains('video_views', $raw);
        $this->assertNotContains('sales', $raw);

        $sales = CanonicalObjective::Sales->rawObjectives();
        $this->assertContains('sales', $sales);
        $this->assertNotContains('awareness', $sales);
    }
}
