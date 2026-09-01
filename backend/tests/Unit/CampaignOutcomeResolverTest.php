<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\CampaignOutcome;
use App\Domains\Campaigns\Services\CampaignOutcomeResolver;
use PHPUnit\Framework\TestCase;

/**
 * CAMPAIGN-OUTCOME-DIMENSION-001 — the action a campaign buys, and where the product stops guessing.
 *
 * `CampaignOutcome` was a well-argued enum that nothing ever constructed. This is the producer, and
 * the tests that matter here are the ones about REFUSAL: a resolver that answers confidently for
 * everything recreates, one layer down, the averaging this whole dimension exists to stop.
 *
 * «Cost per result» over a lead campaign that collected native forms and a lead campaign that opened
 * WhatsApp conversations is the average of two different things. Naming the action makes the product
 * able to say «cost per form» and «cost per conversation» and refuse to compare them.
 */
final class CampaignOutcomeResolverTest extends TestCase
{
    private CampaignOutcomeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CampaignOutcomeResolver;
    }

    /** The objectives that name their own action need no provider payload at all. */
    public function test_an_objective_that_names_its_action_is_read_off(): void
    {
        $this->assertSame(CampaignOutcome::Purchase, $this->resolver->resolve('sales'));
        $this->assertSame(CampaignOutcome::Purchase, $this->resolver->resolve('purchases'));
        $this->assertSame(CampaignOutcome::AppInstall, $this->resolver->resolve('app_installs'));
        $this->assertSame(CampaignOutcome::LinkClick, $this->resolver->resolve('traffic'));
        $this->assertSame(CampaignOutcome::LandingPageVisit, $this->resolver->resolve('landing_page_views'));
        $this->assertSame(CampaignOutcome::Attention, $this->resolver->resolve('reach'));
        $this->assertSame(CampaignOutcome::Attention, $this->resolver->resolve('video_views'));
    }

    /**
     * The four lead actions, told apart by the destination the provider actually sent.
     *
     * All four are `leads`. All four report «cost per result». No two of those costs mean the same
     * thing, and this is the only evidence that separates them.
     */
    public function test_a_lead_objective_is_settled_by_the_providers_destination(): void
    {
        $this->assertSame(
            CampaignOutcome::NativeLeadForm,
            $this->resolver->resolve('leads', ['destination_type' => 'ON_AD']),
        );
        $this->assertSame(
            CampaignOutcome::WebsiteLead,
            $this->resolver->resolve('leads', ['destination_type' => 'WEBSITE']),
        );
        $this->assertSame(
            CampaignOutcome::Messaging,
            $this->resolver->resolve('leads', ['destination_type' => 'WHATSAPP']),
        );
        $this->assertSame(
            CampaignOutcome::PhoneCall,
            $this->resolver->resolve('leads', ['destination_type' => 'PHONE_CALL']),
        );
    }

    /** Providers that express it as an optimisation goal are read on that field instead. */
    public function test_a_lead_objective_is_settled_by_an_optimisation_goal_too(): void
    {
        $this->assertSame(
            CampaignOutcome::NativeLeadForm,
            $this->resolver->resolve('leads', ['optimization_goal' => 'LEAD_FORM_SUBMISSIONS']),
        );
        $this->assertSame(
            CampaignOutcome::PhoneCall,
            $this->resolver->resolve('leads', ['optimization_goal' => 'call_clicks']),
        );
    }

    /**
     * **The test this class exists for.** A lead campaign whose destination nobody sent is Unknown.
     *
     * The tempting answer is «native form» — it is the commonest case, and it would be right most of
     * the time. It would also mean a cost per WhatsApp conversation silently entering a cost-per-form
     * comparison, indistinguishable on screen from a real one. An honest Unknown costs a comparison
     * the product could not have made truthfully anyway.
     */
    public function test_a_lead_objective_with_no_destination_refuses_to_guess(): void
    {
        $this->assertSame(CampaignOutcome::Unknown, $this->resolver->resolve('leads'));
        $this->assertSame(CampaignOutcome::Unknown, $this->resolver->resolve('leads', ['destination_type' => 'SOMETHING_NEW']));
    }

    /**
     * `conversions` is ambiguous too, and is deliberately not folded into purchases.
     *
     * It is what a media buyer picks when optimising for a pixel event, and that event is a purchase
     * on one account and a form submission on the next. Calling it a purchase because it usually is
     * would put a cost per form into a cost-per-order comparison.
     */
    public function test_a_conversions_objective_is_not_assumed_to_be_a_purchase(): void
    {
        $this->assertSame(CampaignOutcome::Unknown, $this->resolver->resolve('conversions'));
        $this->assertSame(
            CampaignOutcome::WebsiteLead,
            $this->resolver->resolve('conversions', ['destination_type' => 'WEBSITE']),
        );
    }

    public function test_an_objective_this_product_does_not_model_is_unknown(): void
    {
        $this->assertSame(CampaignOutcome::Unknown, $this->resolver->resolve(null));
        $this->assertSame(CampaignOutcome::Unknown, $this->resolver->resolve('something_else'));
        $this->assertSame(CampaignOutcome::Unknown, $this->resolver->resolve('store_visits'));
    }

    /**
     * And the refusal carries: two Unknowns are not the same action.
     *
     * Stated here rather than only in the enum's own test, because this is the pairing that decides
     * whether the resolver's honesty survives into a comparison.
     */
    public function test_two_campaigns_the_product_cannot_read_are_not_comparable_with_each_other(): void
    {
        $a = $this->resolver->resolve('leads');
        $b = $this->resolver->resolve('conversions');

        $this->assertFalse($a->comparableWith($b));
        $this->assertFalse($a->comparableWith($a));

        // While two that ARE known and equal compare fine.
        $this->assertTrue($this->resolver->resolve('sales')->comparableWith($this->resolver->resolve('purchases')));
    }
}
