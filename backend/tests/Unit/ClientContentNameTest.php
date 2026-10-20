<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ClientEntityBoundary;
use App\Domains\Reports\Support\ClientContentName;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-CLIENT-TECHNICAL-NAMES-001 — the Owner's Hanka report showed content names ending in a 32-character
 * hash, and one that was only a hash. A client reads neither.
 */
final class ClientContentNameTest extends TestCase
{
    public function test_a_trailing_hash_or_uuid_is_removed_after_its_separator(): void
    {
        $this->assertSame('لا تضيع الفرصة، تواصل معنا الآن! 2026-08-24', ClientContentName::clean('لا تضيع الفرصة، تواصل معنا الآن! 2026-08-24-9165f6a05f13c4ad27505e8535b0e6fe'));
        $this->assertSame('Summer offer', ClientContentName::clean('Summer offer_0f8fad5bd9cb469fa16570867728950e'));
        $this->assertSame('Summer offer', ClientContentName::clean('Summer offer 0f8fad5b-d9cb-469f-a165-70867728950e'));
        $this->assertSame('Spring', ClientContentName::clean('  Spring | 9165f6a05f13c4ad27505e8535b0e6fe  '));
    }

    public function test_a_name_that_is_only_an_identifier_becomes_a_neutral_label_not_the_hash(): void
    {
        $this->assertSame(ClientContentName::FALLBACK, ClientContentName::clean('c31955d170048d9477e24ea8f3a1b2c4'));
        $this->assertSame(ClientContentName::FALLBACK, ClientContentName::clean('c31955d170048d9477e24ea8f3a1b2c4…'));
        $this->assertSame(ClientContentName::FALLBACK, ClientContentName::clean('0f8fad5b-d9cb-469f-a165-70867728950e'));
    }

    public function test_real_names_are_left_alone(): void
    {
        foreach (['Ramadan 2026', 'deadbeef sale', 'Offer-12345', 'Summer-offer-accessible', '2026-08-24', 'abcdef0123456789', 'عرض رمضان'] as $name) {
            $this->assertSame($name, ClientContentName::clean($name), $name);
        }
        $this->assertNull(ClientContentName::clean(null));
    }

    public function test_the_client_boundary_applies_it_to_ranked_ads_and_the_roster(): void
    {
        $ad = ['name' => 'Offer-9165f6a05f13c4ad27505e8535b0e6fe', 'id' => 'internal', 'campaign_name' => 'C', 'spend' => 5];
        $row = ['name' => 'c31955d170048d9477e24ea8f3a1b2c4', 'campaign_name' => 'C', 'metrics' => ['spend' => 1]];

        $ads = ClientEntityBoundary::ads([$ad]);
        $roster = ClientEntityBoundary::roster([$row]);

        $this->assertSame('Offer', $ads[0]['name']);
        $this->assertArrayNotHasKey('campaign_name', $ads[0]);
        $this->assertSame(ClientContentName::FALLBACK, $roster[0]['name']);
        $this->assertSame(5, $ads[0]['spend'], 'figures are untouched');
    }
}
