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
    public function test_the_supported_destinations_are_exactly_the_seven_the_report_names(): void
    {
        $this->assertSame(
            ['lead_form', 'website', 'whatsapp', 'messenger', 'instagram_direct', 'calls', 'mixed'],
            LeadDestination::values(),
        );
    }

    public function test_meta_destinations_map_to_them(): void
    {
        $this->assertSame(LeadDestination::Website, LeadDestination::fromMeta('WEBSITE'));
        $this->assertSame(LeadDestination::LeadForm, LeadDestination::fromMeta('ON_AD'));
        $this->assertSame(LeadDestination::Whatsapp, LeadDestination::fromMeta('whatsapp'));
        $this->assertSame(LeadDestination::Messenger, LeadDestination::fromMeta('MESSENGER'));
        $this->assertSame(LeadDestination::InstagramDirect, LeadDestination::fromMeta('INSTAGRAM_DIRECT'));
        $this->assertSame(LeadDestination::Calls, LeadDestination::fromMeta('PHONE_CALL'));
    }

    public function test_an_unknown_destination_is_absent_not_a_website(): void
    {
        $this->assertNull(LeadDestination::fromMeta(null));
        $this->assertNull(LeadDestination::fromMeta('UNDEFINED'));
    }

    public function test_only_the_message_destinations_are_messaging_and_they_say_so(): void
    {
        foreach ([LeadDestination::Whatsapp, LeadDestination::Messenger, LeadDestination::InstagramDirect] as $d) {
            $this->assertTrue($d->isMessaging());
            $this->assertSame('Messages', $d->resultLabel()['en']);
            $this->assertSame('تكلفة الرسالة', $d->costLabel()['ar']);
        }
        foreach ([LeadDestination::Website, LeadDestination::LeadForm, LeadDestination::Calls, LeadDestination::Mixed] as $other) {
            $this->assertFalse($other->isMessaging());
            $this->assertSame('Leads', $other->resultLabel()['en']);
        }
    }
}
