<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Support\CreativeKind;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CONTENT-KIND-VOCABULARY-001 — the card carries what the creative IS, not only what it was CALLED.
 *
 * The owner, reading the comparison: «لاحظت اسم مجموعة، صورة، وكلمة انجليزية، لذلك هي انواع، صورة
 * فيديو، ستوري اد، كولكشن، كاروسيل وهكذا».
 *
 * The card sent `format` and nothing else, and `format` is the PROVIDER's token. Snapchat's importer
 * maps the types it knows and stores `strtolower($type)` for the rest, so `story_ad` is a real value
 * in this column; X stores `text` and the chat card stores `chat_card`. The preview dialog labelled
 * that column «النوع» and printed it, which is how a platform's internal spelling was shown to a
 * reader as the kind of ad they were looking at.
 *
 * `CreativeKind::of()` already answers the question properly, for the card and the filter alike.
 * This asserts the card now SENDS that answer, over the shapes real connectors write rather than the
 * tidy ones the seed uses — a fixture carrying only `video`/`image` would pass against the defect,
 * because for those two the token and the kind happen to be the same word.
 */
final class CreativeCardKindTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'Project 1',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $assets
     */
    private function creative(string $name, string $provider, ?string $format, array $assets = []): ExternalCreative
    {
        return ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
            'external_creative_id' => 'ext-'.$name,
            'name' => $name,
            'format' => $format,
        ] + $assets + [
            'asset_url' => null, 'video_url' => null, 'thumbnail_url' => null, 'preview_url' => null,
        ]);
    }

    public function test_the_card_sends_the_kind_beside_the_providers_own_token(): void
    {
        $creative = $this->creative('story', 'snapchat', 'story_ad', ['video_url' => 'https://cdn.test/s.mp4']);

        $card = app(CreativePresenter::class)->card($creative, null);

        /* The platform's token is kept — an operator pastes it back into the ad manager. */
        $this->assertSame('story_ad', $card['format']);

        /* …and the kind is sent, so no surface has to guess from the token. */
        $this->assertArrayHasKey('kind', $card);
        $this->assertSame(CreativeKind::of($creative), $card['kind']);
        $this->assertSame('video', $card['kind'], 'a story ad carrying only a film is a film');
    }

    /**
     * Every kind the card can send is one the filter offers, or the explicit «none of these».
     *
     * This is the property that matters: a kind the vocabulary does not know is a kind some surface
     * will print raw. `other` is the one value outside `ALL`, and it is deliberate — the frontend
     * has a word for it.
     *
     * @return array<string, array{0: string, 1: ?string, 2: array<string, mixed>, 3: string}>
     */
    public static function shapes(): array
    {
        return [
            'snapchat story ad with a film' => ['snapchat', 'story_ad', ['video_url' => 'https://cdn.test/a.mp4'], 'video'],
            'snapchat collection, dynamic' => ['snapchat', 'collection_dynamic', [], 'collection'],
            'meta carousel by object type' => ['meta', 'carousel', [], 'carousel'],
            'a catalog by its platform name' => ['meta', 'DYNAMIC_PRODUCT_AD', [], 'catalog'],
            'x, which sells text' => ['x', 'text', [], 'other'],
            'the chat card' => ['openai', 'chat_card', [], 'other'],
            'no format at all, and a still' => ['meta', null, ['asset_url' => 'https://cdn.test/a.jpg'], 'image'],
            'no format and nothing resolved' => ['meta', null, [], 'other'],
        ];
    }

    /**
     * @param  array<string, mixed>  $assets
     */
    #[DataProvider('shapes')]
    public function test_the_kind_is_one_the_product_has_a_word_for(
        string $provider,
        ?string $format,
        array $assets,
        string $expected,
    ): void {
        $creative = $this->creative('c-'.md5($provider.(string) $format), $provider, $format, $assets);

        $card = app(CreativePresenter::class)->card($creative, null);

        $this->assertSame($expected, $card['kind']);
        $this->assertContains(
            $card['kind'],
            [...CreativeKind::ALL, 'other'],
            'a kind outside the vocabulary is a kind some surface will print raw',
        );
    }
}
