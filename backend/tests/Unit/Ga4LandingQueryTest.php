<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Measurement\Ga4LandingQuery;
use PHPUnit\Framework\TestCase;

final class Ga4LandingQueryTest extends TestCase
{
    public function test_a_click_id_is_folded_to_its_platform_family_and_its_value_is_never_kept(): void
    {
        $q = new Ga4LandingQuery;
        foreach ([
            '/p?fbclid=AbC123&utm_source=facebook&utm_campaign=Spring' => 'meta',
            '/p?gclid=Cj0K&utm_campaign=Spring' => 'google',
            '/p?wbraid=zz' => 'google',
            '/p?ttclid=tt1' => 'tiktok',
            '/p?ScCid=7b3a7917-a82a-47e8-9728-e1b3b045abb2&utm_source=snapchat' => 'snapchat',
            '/p?msclkid=ms' => 'microsoft',
            '/p?li_fat_id=li' => 'linkedin',
        ] as $url => $family) {
            $sig = $q->signature($url);
            $this->assertSame($family, $q->fromSignature($sig)['family'], $url);
            $this->assertDoesNotMatchRegularExpression('/AbC123|Cj0K|tt1|7b3a7917|=ms$/', $sig);
        }
        $this->assertSame('family=meta;utm_campaign=Spring;utm_source=facebook', $q->signature('/p?fbclid=AbC123&utm_source=facebook&utm_campaign=Spring&email=a@b.c'));
    }

    public function test_unfilled_macros_and_untagged_landings_are_not_a_click(): void
    {
        $q = new Ga4LandingQuery;
        $this->assertSame('', $q->signature('/collections/abaya'));
        $this->assertNull($q->fromSignature($q->signature('/p?fbclid={fbclid}'))['family']);
        $this->assertNull($q->fromSignature($q->signature('/p?ttclid=__CLICKID__'))['family']);
        $this->assertSame([], $q->parse('/p?utm_campaign={campaign.name}')['utm']);
    }

    public function test_openai_ads_has_no_invented_parameter(): void
    {
        // No primary OpenAI documentation names a click-id parameter; third-party guides disagree (oppref?).
        $this->assertNull((new Ga4LandingQuery)->parse('/p?oppref=abc&openai_click=1')['family']);
    }
}
