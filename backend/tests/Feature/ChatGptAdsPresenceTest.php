<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Taxonomy\Models\TaxonomyOption;
use Database\Seeders\TaxonomyEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INTEG-OPENAI-001 §§4, 22 — the two places ChatGPT Ads has to exist that are NOT the connector.
 *
 * A platform the product can read but nobody can NAME is only half added. A client filing a request
 * picks the platform from a taxonomy, and a client who has no OpenAI Ads account yet buys the setup
 * as a service — neither of which touches an API key, and both of which were the last things to be
 * remembered when a platform was added in the past.
 */
final class ChatGptAdsPresenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TaxonomyEngineSeeder::class);
    }

    /**
     * A request can NAME the platform.
     *
     * `campaign.platforms` is what a brief is tagged with, and it is also what routes the work. A
     * platform missing here arrives as a request with its one routing field blank.
     */
    public function test_a_campaign_can_be_tagged_with_chatgpt_ads(): void
    {
        $option = TaxonomyOption::query()
            ->whereHas('definition', fn ($q) => $q->where('key', 'campaign.platforms'))
            ->where('key', 'openai_ads')
            ->first();

        $this->assertNotNull($option, 'campaign.platforms cannot name ChatGPT Ads');
        $this->assertSame('إعلانات ChatGPT', $option->label_ar);
        $this->assertSame('ChatGPT Ads', $option->label_en);
    }

    /**
     * And it can be BOUGHT, by somebody who has no account to connect.
     *
     * The service is deliberately named after its platform where every other service here is
     * platform-agnostic: «إعداد الحساب» is the first line of its scope, so a client who must connect
     * an account before requesting it could never request the thing they actually need.
     */
    public function test_the_service_catalogue_sells_chatgpt_ads_without_an_api_key(): void
    {
        $service = TaxonomyOption::query()
            ->whereHas('definition', fn ($q) => $q->where('key', 'request.paid_service'))
            ->where('key', 'chatgpt_ads_management')
            ->first();

        $this->assertNotNull($service, 'the catalogue does not sell ChatGPT Ads');
        $this->assertSame('إعلانات ChatGPT', $service->label_ar);
        $this->assertTrue((bool) $service->is_public, 'the service exists but no client can see it');
        // Filed under a category rather than loose: the intake's required fields come from the
        // category, so a service with no parent asks for nothing and arrives unusable.
        $parent = TaxonomyOption::query()->find($service->parent_option_id);
        $this->assertNotNull($parent, 'the service sits under no category');
        $this->assertSame('launch_manage', $parent->key);
        $this->assertContains('platforms', $service->metadata['required_field_rules'] ?? []);

        // The five lines of scope, and no essay. Each one is a thing somebody is paying for.
        foreach (['إعداد الحساب', 'إعداد الحملات', 'إدارتها', 'القياس والتحليل', 'تحسين الأداء'] as $scope) {
            $this->assertStringContainsString($scope, (string) $service->description, "the scope omits «{$scope}»");
        }
    }
}
