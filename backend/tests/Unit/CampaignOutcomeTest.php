<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\CampaignOutcome;
use PHPUnit\Framework\TestCase;

/**
 * CAMPAIGN-OUTCOME-DIMENSION-001 — what a campaign buys is not what it is for.
 *
 * Four campaigns can all be `leads`: one collects a native form inside Meta, one sends people to a
 * landing page, one opens a WhatsApp conversation, one rings a phone. All four report a «cost per
 * result», and none of those four costs is comparable with any other. Averaging them is the number
 * a media buyer then optimises against, which is how a client ends up paying more for worse leads.
 */
final class CampaignOutcomeTest extends TestCase
{
    /**
     * A click is an event. A form is a person.
     *
     * The distinction the enum exists for: a product that blurs it reports a «cost per lead»
     * computed from clicks, which is a number with no referent.
     */
    public function test_an_event_is_not_a_person(): void
    {
        foreach ([CampaignOutcome::NativeLeadForm, CampaignOutcome::WebsiteLead, CampaignOutcome::PhoneCall, CampaignOutcome::Messaging] as $outcome) {
            $this->assertTrue($outcome->producesALead(), "{$outcome->value} should produce a lead");
        }

        foreach ([CampaignOutcome::LinkClick, CampaignOutcome::LandingPageVisit, CampaignOutcome::Attention, CampaignOutcome::Purchase] as $outcome) {
            $this->assertFalse($outcome->producesALead(), "{$outcome->value} is not a person");
        }
    }

    /** Two costs may be compared only when they bought the same action. */
    public function test_costs_are_comparable_only_within_one_action(): void
    {
        $this->assertTrue(CampaignOutcome::NativeLeadForm->comparableWith(CampaignOutcome::NativeLeadForm));
        $this->assertFalse(CampaignOutcome::NativeLeadForm->comparableWith(CampaignOutcome::Messaging));
        $this->assertFalse(CampaignOutcome::PhoneCall->comparableWith(CampaignOutcome::WebsiteLead));
    }

    /**
     * And two unmodelled actions are not thereby the same action.
     *
     * `unknown` comparing equal to `unknown` would silently rank one provider's unmapped objective
     * against another's, which is the worst case: it looks like a comparison and is a coincidence of
     * vocabulary.
     */
    public function test_two_unknown_actions_are_not_the_same_action(): void
    {
        $this->assertFalse(CampaignOutcome::Unknown->comparableWith(CampaignOutcome::Unknown));
    }

    /** The cost is named after the action, so a reader notices the difference without being told. */
    public function test_the_cost_is_named_after_what_was_bought(): void
    {
        $this->assertSame('Cost per form', CampaignOutcome::NativeLeadForm->costLabel()['en']);
        $this->assertSame('Cost per conversation', CampaignOutcome::Messaging->costLabel()['en']);
        $this->assertSame('تكلفة المكالمة', CampaignOutcome::PhoneCall->costLabel()['ar']);
        // Only the unmodelled action falls back to the generic phrase.
        $this->assertSame('Cost per result', CampaignOutcome::Unknown->costLabel()['en']);
    }

    /** Every case says something in both languages — a missing label renders as an empty column. */
    public function test_every_action_is_named_in_both_languages(): void
    {
        foreach (CampaignOutcome::cases() as $outcome) {
            foreach (['ar', 'en'] as $locale) {
                $this->assertNotSame('', trim($outcome->label()[$locale]), "{$outcome->value} has no {$locale} label");
                $this->assertNotSame('', trim($outcome->costLabel()[$locale]), "{$outcome->value} has no {$locale} cost label");
            }
        }
    }

    /**
     * The reading of a provider's objective is deliberately conservative.
     *
     * Meta's `OUTCOME_LEADS` is a native form, a website form or a click-to-WhatsApp conversation
     * depending on a destination the objective does not carry. Guessing would print «cost per form»
     * over a campaign that buys conversations — a plausible label is worse than an honest absence,
     * because nobody checks a label that looks right.
     */
    public function test_an_objective_that_cannot_decide_the_action_says_so(): void
    {
        $this->assertSame(CampaignOutcome::Unknown, CampaignOutcome::fromProviderObjective('OUTCOME_LEADS'));
        $this->assertSame(CampaignOutcome::Unknown, CampaignOutcome::fromProviderObjective('leads'));
        $this->assertSame(CampaignOutcome::Unknown, CampaignOutcome::fromProviderObjective(null));
        $this->assertSame(CampaignOutcome::Unknown, CampaignOutcome::fromProviderObjective(''));
    }

    /** Where the provider IS decisive, it is read. */
    public function test_a_decisive_objective_is_read(): void
    {
        $this->assertSame(CampaignOutcome::NativeLeadForm, CampaignOutcome::fromProviderObjective('LEAD_GENERATION'));
        $this->assertSame(CampaignOutcome::Messaging, CampaignOutcome::fromProviderObjective('OUTCOME_MESSAGES'));
        $this->assertSame(CampaignOutcome::Messaging, CampaignOutcome::fromProviderObjective('click_to_whatsapp'));
        $this->assertSame(CampaignOutcome::PhoneCall, CampaignOutcome::fromProviderObjective('CALL'));
        $this->assertSame(CampaignOutcome::AppInstall, CampaignOutcome::fromProviderObjective('APP_INSTALLS'));
        $this->assertSame(CampaignOutcome::Purchase, CampaignOutcome::fromProviderObjective('OUTCOME_SALES'));
        $this->assertSame(CampaignOutcome::LinkClick, CampaignOutcome::fromProviderObjective('OUTCOME_TRAFFIC'));
        $this->assertSame(CampaignOutcome::Attention, CampaignOutcome::fromProviderObjective('OUTCOME_AWARENESS'));
    }

    /**
     * The messaging action is the ADS-side metric and says so.
     *
     * Meta counts «conversations started»; this product has read no conversation and holds no
     * WhatsApp Business authorisation. The label carries the qualifier so a reader is never handed
     * a platform's count as though the product had verified it.
     */
    public function test_the_messaging_label_says_whose_count_it_is(): void
    {
        $this->assertStringContainsString('as the platform counts it', CampaignOutcome::Messaging->label()['en']);
        $this->assertStringContainsString('حسب المنصة', CampaignOutcome::Messaging->label()['ar']);
    }
}
