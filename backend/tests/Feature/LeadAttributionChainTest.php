<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\CRM\Attribution\LeadAttributionChain;
use App\Domains\CRM\Attribution\ProviderAttribution;
use App\Domains\CRM\Models\Lead;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * LEAD-SOURCE-ATTRIBUTION-001 — every lead names its chain, or says why it cannot.
 *
 * `LeadProvenanceTest` proves a lead REMEMBERS what produced it. This proves the product can READ
 * that back honestly, which is a different claim and the one a client actually sees. The whole
 * requirement turns on telling four situations apart that all look like a dash on a screen:
 *
 *   the provider sent it; the provider never sends it; the provider sends it and this lead has not
 *   got it; and no platform is claiming this lead at all.
 *
 * A product that renders all four as «—» has answered none of them, and the client cannot tell a
 * platform limit from a broken sync — which is exactly the moment they stop believing the numbers.
 *
 * The last test here is the one that matters most, and it is a structural one: **a click is not a
 * person**. Filling these gaps from the metrics tables would be easy, would make every screen look
 * complete, and would be fabrication. Nothing in the chain may open a metrics model.
 */
final class LeadAttributionChainTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-attr-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** @param array<string,mixed> $over */
    private function lead(array $over = []): Lead
    {
        return Lead::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer', 'phone' => '+966500000001',
            'source' => 'paid', 'status' => 'new',
            'received_at' => Carbon::parse('2026-08-20 09:00:04'),
        ], $over));
    }

    /** @return array<string, array{rung: string, state: string, id: string|null, name: string|null, reason: string|null}> */
    private function rungs(Lead $lead): array
    {
        $chain = app(LeadAttributionChain::class)->for($lead);

        return collect($chain['rungs'])->keyBy('rung')->all();
    }

    public function test_a_meta_lead_names_every_rung_it_arrived_with(): void
    {
        $lead = $this->lead([
            'provider' => 'meta', 'provider_lead_id' => 'l-1',
            'external_campaign_id' => 'cmp-1', 'campaign_name' => 'Riyadh — Q3',
            'external_adset_id' => 'set-1', 'adset_name' => 'Riyadh 25-45',
            'external_ad_id' => 'ad-1', 'ad_name' => 'Villa carousel',
            'external_creative_id' => 'cr-1', 'creative_name' => 'Villa 3BR',
        ]);

        $chain = app(LeadAttributionChain::class)->for($lead);
        $rungs = $this->rungs($lead);

        $this->assertSame('native_form', $chain['route']);
        $this->assertSame('named', $chain['platform']['state']);
        $this->assertSame('meta', $chain['platform']['provider']);

        foreach (ProviderAttribution::RUNGS as $rung) {
            $this->assertSame('named', $rungs[$rung]['state'], "the {$rung} rung lost its name");
        }

        $this->assertSame('Villa 3BR', $rungs['creative']['name']);
        $this->assertSame('cmp-1', $rungs['campaign']['id']);
        $this->assertTrue($chain['complete']);
    }

    /**
     * The stated-unknown path, exercised rather than hypothetical.
     *
     * LinkedIn has no ad-set level. A lead that arrives without one is not defective and must not be
     * reported as though a sync had dropped something — it must say what LinkedIn's model is, in a
     * sentence a person can read, and still count as a COMPLETE chain.
     */
    public function test_a_rung_a_platform_does_not_have_is_explained_and_still_complete(): void
    {
        $lead = $this->lead([
            'provider' => 'linkedin', 'provider_lead_id' => 'l-2',
            'external_campaign_id' => 'cmp-9', 'campaign_name' => 'Enterprise Q3',
            'external_creative_id' => 'cr-9', 'creative_name' => 'Whitepaper',
        ]);

        $chain = app(LeadAttributionChain::class)->for($lead);
        $rungs = $this->rungs($lead);

        $this->assertSame('not_offered', $rungs['adset']['state']);
        $this->assertNotNull($rungs['adset']['reason'], 'an unavailable rung must say why');
        $this->assertStringContainsString('لينكدإن', (string) $rungs['adset']['reason']);
        $this->assertTrue($chain['complete'], 'a platform limit is not a defect in this lead');
    }

    /**
     * The opposite case, and the reason the two may never share a dash.
     *
     * Meta DOES return the ad set on a lead form. A Meta lead without one is a real gap in our own
     * pipeline, and it has to read as one — otherwise a broken sync is invisible for as long as it
     * takes somebody to notice the leads got worse.
     */
    public function test_a_rung_the_platform_does_send_and_this_lead_lacks_reads_as_a_gap(): void
    {
        $lead = $this->lead([
            'provider' => 'meta', 'provider_lead_id' => 'l-3',
            'external_campaign_id' => 'cmp-1', 'campaign_name' => 'Riyadh — Q3',
        ]);

        $rungs = $this->rungs($lead);

        $this->assertSame('missing', $rungs['adset']['state']);
        $this->assertSame('missing', $rungs['ad']['state']);
        $this->assertNull($rungs['adset']['reason'], 'a defect must not be dressed up as a platform limit');
        $this->assertFalse(app(LeadAttributionChain::class)->for($lead)['complete']);
    }

    public function test_a_website_lead_reports_the_trail_the_link_carried_and_no_platform(): void
    {
        $lead = $this->lead([
            'landing_page' => 'https://example.test/villas',
            'utm_source' => 'newsletter', 'utm_campaign' => 'august',
        ]);

        $chain = app(LeadAttributionChain::class)->for($lead);
        $rungs = $this->rungs($lead);

        $this->assertSame('website_form', $chain['route']);
        $this->assertSame('no_platform', $chain['platform']['state']);
        $this->assertSame('no_platform', $rungs['campaign']['state']);
        $this->assertSame('newsletter', $chain['web']['utm_source']);

        // A UTM that was never set is a link that did not use one, not a hole in the chain.
        $this->assertArrayNotHasKey('utm_term', $chain['web']);
    }

    public function test_a_hand_entered_lead_says_so_rather_than_naming_a_platform(): void
    {
        $chain = app(LeadAttributionChain::class)->for($this->lead(['source' => 'manual']));

        $this->assertSame('manual', $chain['route']);
        $this->assertSame('no_platform', $chain['platform']['state']);
        $this->assertSame([], $chain['web']);
    }

    /** A provider string we have never modelled is named and flagged, never silently vouched for. */
    public function test_an_unmodelled_platform_is_named_but_not_vouched_for(): void
    {
        $chain = app(LeadAttributionChain::class)->for($this->lead([
            'provider' => 'pinterest', 'provider_lead_id' => 'l-4',
        ]));

        $this->assertSame('unrecognised', $chain['platform']['state']);
        $this->assertSame('pinterest', $chain['platform']['provider']);
    }

    /**
     * Every provider we model must explain every rung it cannot supply.
     *
     * This is what stops the table degrading: adding a provider with a short `supplies` list and no
     * matching sentences would ship a screen full of unexplained dashes, and the failure would be
     * invisible until a client asked.
     */
    public function test_every_platform_explains_each_rung_it_cannot_supply(): void
    {
        foreach (['meta', 'linkedin', 'snapchat', 'tiktok', 'google', 'x'] as $provider) {
            foreach (ProviderAttribution::RUNGS as $rung) {
                if (ProviderAttribution::offers($provider, $rung)) {
                    continue;
                }

                $this->assertNotNull(
                    ProviderAttribution::limit($provider, $rung),
                    "{$provider} claims no {$rung} and does not say why",
                );

                /*
                 * And says it in BOTH languages. A reason that exists only in Arabic leaves an
                 * English reader with the unexplained dash this whole table exists to prevent.
                 */
                $this->assertNotNull(
                    ProviderAttribution::limitEn($provider, $rung),
                    "{$provider} explains its missing {$rung} in Arabic only",
                );
            }
        }
    }

    /**
     * **A click is not a person.**
     *
     * The structural guard. Every gap on this screen could be filled from the metrics tables, which
     * carry a campaign id and an ad id for the same day — and every figure so produced would be
     * invented, because those rows count events and this row is a human being. The check is on the
     * source rather than on an output, because an output test can only catch the fabrications
     * somebody thought to write a fixture for.
     */
    public function test_the_chain_never_reads_a_metrics_table(): void
    {
        $source = file_get_contents(app_path('Domains/CRM/Attribution/LeadAttributionChain.php'));
        $this->assertIsString($source);

        /*
         * The COMMENTS are stripped before looking.
         *
         * This class has to talk about clicks and insight rows to explain why it refuses them, and a
         * guard that reads prose would either fail on its own rationale or force the rationale out of
         * the file. What is being asserted is about executed code, so only executed code is scanned.
         */
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach ([
            'DailyMetric', 'EntityDailyMetric', 'CampaignDailyMetric', 'CreativeDailyMetric',
            'AccountDailyMetric', 'Insight', 'impressions', 'clicks', 'DB::', 'query(',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $code,
                "attribution reached for {$forbidden}: a click is not a person",
            );
        }
    }
}
