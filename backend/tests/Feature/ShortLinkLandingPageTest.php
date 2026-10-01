<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Services\ShortLinkHops;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SHORT-LINKS-LANDING-001 — a link in an ad has to lead somewhere a person can read.
 *
 * Forwarding straight into WhatsApp gives the reader nothing to decide from and an ad reviewer
 * nothing to review: the destination is an app handoff with no offer on it, which is the shape of a
 * cloaked link whether or not it is one.
 *
 * These hold the three properties that make the page honest rather than a slower redirect — it
 * forwards nobody, everybody gets the same document, and the button goes to the link's own
 * destination — and the one property that makes the change safe: every link already minted keeps
 * forwarding exactly as before.
 */
final class ShortLinkLandingPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active']);
    }

    /** The named slug opens the page rather than forwarding. */
    public function test_a_landing_link_serves_a_page_instead_of_forwarding(): void
    {
        $link = $this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST);

        $response = $this->get('/l/'.$link->slug)->assertOk();

        $response->assertSee('اطلب الفيديوهات بسهولة', escape: false);
        $response->assertSee('اطلب الآن عبر واتساب', escape: false);
    }

    /**
     * And it forwards NOBODY. No meta refresh, no timer, no script that leaves.
     *
     * Asserted on the served bytes rather than on intent, because «we do not redirect» is the kind
     * of promise that survives in a comment long after a well-meant convenience has been added.
     */
    public function test_the_page_never_forwards_on_its_own(): void
    {
        $html = $this->get('/l/'.$this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST)->slug)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('http-equiv="refresh"', (string) $html);
        $this->assertStringNotContainsString('location.href', (string) $html);
        $this->assertStringNotContainsString('setTimeout', (string) $html);
        $this->assertStringNotContainsString('<script', (string) $html);
    }

    /**
     * Everybody gets the same document — no cloaking by user agent or referrer.
     *
     * A reviewer's crawler and a customer's phone are the two readers this has to treat identically,
     * and «we do not vary the response» is only true if nothing downstream ever starts to.
     */
    public function test_every_reader_gets_the_same_page(): void
    {
        $slug = $this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST)->slug;

        $asPhone = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
            'Referer' => 'https://www.snapchat.com/',
        ])->get('/l/'.$slug)->getContent();

        $asReviewer = $this->withHeaders([
            'User-Agent' => 'AdsBot-Google (+http://www.google.com/adsbot.html)',
        ])->get('/l/'.$slug)->getContent();

        $this->assertSame($asPhone, $asReviewer, 'the page is cloaked — a reviewer sees different bytes from a customer');
    }

    /** The button goes to the link's OWN destination, through a path that counts the decision. */
    public function test_the_button_forwards_to_the_links_destination(): void
    {
        $link = $this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST);

        $this->get('/l/'.$link->slug)->assertSee('/l/'.$link->slug.'/go', escape: false);

        $this->get('/l/'.$link->slug.'/go')->assertRedirect($link->destination);
    }

    /**
     * A visit and a decision are different events, and the link counts them apart.
     *
     * Without this the numbers on the one link that converts well would be doubled, which is the
     * link whose numbers anybody actually reads.
     */
    public function test_a_visit_and_a_decision_are_counted_apart(): void
    {
        $link = $this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST);

        $this->get('/l/'.$link->slug);
        $this->get('/l/'.$link->slug);
        $this->get('/l/'.$link->slug.'/go');

        $fresh = ShortLink::withoutGlobalScopes()->findOrFail($link->getKey());

        $this->assertSame(2, (int) $fresh->clicks, 'the page visit stopped being counted');
        $this->assertSame(1, (int) $fresh->cta_clicks, 'the decision was not counted apart from the visit');
    }

    /** Every link already minted keeps forwarding, exactly as before. */
    public function test_an_ordinary_short_link_still_forwards(): void
    {
        $link = $this->link('abc2345', null);

        $this->get('/l/'.$link->slug)->assertRedirect($link->destination);
    }

    /**
     * The page is a NEW link beside the forwarder, and the forwarder is untouched.
     *
     * `m5pxgr2` is already in circulation. Changing what an address somebody has printed DOES is a
     * different act from adding an address, and this holds the two apart.
     */
    public function test_the_page_is_a_new_link_and_the_old_one_still_forwards(): void
    {
        $forwarder = $this->link('m5pxgr2', null);
        $page = $this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST);

        $this->get('/l/'.$forwarder->slug)->assertRedirect($forwarder->destination);
        $this->get('/l/'.$page->slug)->assertOk();
    }

    /**
     * And the page's address READS as a short link.
     *
     * The owner's requirement, and it is a real one: a guessable word like `/videos` announces
     * itself as a named page, while a minted slug is indistinguishable from every other link this
     * product issues. Asserted against the hop's own pattern rather than against a length, so a
     * slug this application could never mint cannot pass.
     */
    public function test_the_pages_address_is_shaped_like_every_other_short_link(): void
    {
        $this->assertSame(
            1,
            preg_match('/^'.ShortLinkHops::slugPattern().'$/', 'v7kq3md'),
            'the landing link is not a slug this product could have minted',
        );
    }

    /**
     * The page is typeset in the PRODUCT's own face, served by the product.
     *
     * The first version used the generic system stack, which renders Arabic in whatever the device
     * happens to have — so the page a customer meets from an ad looked like a different company
     * from the one that sent them. IBM Plex Sans Arabic is what the application uses; it is
     * self-hosted here for the same reason it is self-hosted there, which is that a page opened from
     * a paid ad must not hand every visit to a font CDN.
     */
    public function test_the_page_is_set_in_the_products_own_face(): void
    {
        $html = (string) $this->get('/l/'.$this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST)->slug)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("font-family: 'IBM Plex Sans Arabic'", $html);
        $this->assertStringContainsString('/fonts/ibm-plex-sans-arabic-arabic-400-normal.woff2', $html);

        $this->assertStringNotContainsString('fonts.googleapis.com', $html, 'the page fetches its face from a third party');
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);

        foreach ([400, 600, 700] as $weight) {
            $this->assertFileExists(
                public_path("fonts/ibm-plex-sans-arabic-arabic-{$weight}-normal.woff2"),
                "weight {$weight} is declared and not served",
            );
        }
    }

    /**
     * It is the OFFER, and nothing about the system behind it.
     *
     * A conversion page carrying a product's name, navigation or dashboard links gives the reader
     * something else to click and tells them nothing they came for.
     */
    public function test_the_page_carries_no_system_detail(): void
    {
        $html = (string) $this->get('/l/'.$this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST)->slug)
            ->assertOk()
            ->getContent();

        foreach (['كامبينز هب', 'CampaignsHub', 'تسجيل الدخول', 'لوحة التحكم', '<nav', '<form'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "the page carries «{$absent}»");
        }
    }

    /** And the address stays the one that was printed in the ad — no extension, no new path. */
    public function test_the_public_address_is_a_bare_slug(): void
    {
        $this->get('/l/v7kq3md.html')->assertNotFound();

        $this->link('v7kq3md', ShortLink::LANDING_VIDEO_REQUEST);

        $this->get('/l/v7kq3md')->assertOk();
    }

    private function link(string $slug, ?string $landing): ShortLink
    {
        $link = new ShortLink([
            'tenant_id' => $this->tenant->getKey(),
            'slug' => $slug,
            'kind' => ShortLink::KIND_WHATSAPP,
            'destination' => 'https://wa.me/966500000000?text=%D8%A3%D8%B1%D9%8A%D8%AF',
            'source_value' => '+966500000000',
            'clicks' => 0,
            'cta_clicks' => 0,
            'is_active' => true,
            'landing_page' => $landing,
        ]);
        $link->id = (string) Str::uuid();
        $link->save();

        return $link;
    }
}
