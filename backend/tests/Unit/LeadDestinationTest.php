<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\LeadDestination;
use PHPUnit\Framework\TestCase;

/**
 * LEAD-DESTINATION-001 — the platform's destination, in the product's words.
 */
final class LeadDestinationTest extends TestCase
{
    public function test_meta_destinations_map_to_the_five_the_report_knows(): void
    {
        $this->assertSame(LeadDestination::LandingPage, LeadDestination::fromMeta('WEBSITE'));
        $this->assertSame(LeadDestination::LeadForm, LeadDestination::fromMeta('ON_AD'));
        $this->assertSame(LeadDestination::Whatsapp, LeadDestination::fromMeta('whatsapp'));
        $this->assertSame(LeadDestination::DirectMessage, LeadDestination::fromMeta('MESSENGER'));
        $this->assertSame(LeadDestination::DirectMessage, LeadDestination::fromMeta('INSTAGRAM_DIRECT'));
    }

    public function test_an_unknown_destination_is_absent_not_a_landing_page(): void
    {
        $this->assertNull(LeadDestination::fromMeta(null));
        $this->assertNull(LeadDestination::fromMeta('UNDEFINED'));
        $this->assertNull(LeadDestination::fromMeta('PHONE_CALL'));
    }

    public function test_only_the_message_destinations_are_messaging_and_they_say_so(): void
    {
        $this->assertTrue(LeadDestination::Whatsapp->isMessaging());
        $this->assertTrue(LeadDestination::DirectMessage->isMessaging());
        foreach ([LeadDestination::LandingPage, LeadDestination::LeadForm, LeadDestination::Mixed] as $other) {
            $this->assertFalse($other->isMessaging());
            $this->assertSame('Leads', $other->resultLabel()['en']);
        }
        $this->assertSame('Cost per messaging result', LeadDestination::Whatsapp->costLabel()['en']);
    }
}
