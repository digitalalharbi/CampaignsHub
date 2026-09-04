<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNAP-SIGNED-MEDIA-001 — a CDN signature is not a credential.
 *
 * `signature` and `sig` were on the credential list, so a media URL fetched perfectly and stored
 * correctly was classified «withheld» and never rendered. The library told the operator the
 * platform's link carried a credential, when what it carried was a time-limited grant for one file.
 *
 * The distinction is not stylistic. An `access_token` is a key to the ACCOUNT — leak it and someone
 * can read and change a customer's advertising. A CloudFront-style `Signature`, with its `Expires`
 * and `Key-Pair-Id`, authorises one object for a short window and can do nothing else. It is what
 * the platform's own interface puts in an `<img>`.
 */
final class SignedMediaUrlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);
    }

    /**
     * The shape a CDN actually serves private media with — this is the case that was broken.
     *
     * Every part of it is scoped to one object: the signature covers a policy naming that file, the
     * expiry bounds it in time, and the key-pair id names which public key verifies it.
     */
    public function test_a_cloudfront_style_signed_media_url_is_shown(): void
    {
        $preview = $this->preview(
            'https://cf.snapchat.com/media/me-1.mp4?Expires=1790000000&Signature=AbCd~123__&Key-Pair-Id=APKAEXAMPLE',
            video: true,
        );

        $this->assertSame('available', $preview['state'], 'A signed CDN link is how provider media is served at all.');
        $this->assertStringContainsString('me-1.mp4', (string) $preview['video_url']);
    }

    /** The lowercase `sig` form, which several CDNs use instead. */
    public function test_a_lowercase_sig_parameter_is_shown(): void
    {
        $preview = $this->preview('https://cf.snapchat.com/media/me-2.jpg?sig=abc123&e=1790000000');

        $this->assertSame('available', $preview['state']);
    }

    /**
     * `Key-Pair-Id` must not trip the `key` rule.
     *
     * Matching is on whole parameter names rather than substrings, and this is why: a substring rule
     * would reject half of every signed URL in existence.
     */
    public function test_a_key_pair_id_is_not_mistaken_for_an_api_key(): void
    {
        $preview = $this->preview('https://cf.snapchat.com/media/me-3.jpg?Key-Pair-Id=APKAEXAMPLE');

        $this->assertSame('available', $preview['state']);
    }

    /**
     * The rule stays absolute where it matters.
     *
     * These grant access to the ACCOUNT, not to one file, and leaking one is unrecoverable.
     */
    public function test_a_real_credential_still_withholds_the_whole_url(): void
    {
        foreach (['access_token', 'oauth_token', 'bearer', 'api_key', 'apikey', 'secret', 'token', 'key'] as $param) {
            $preview = $this->preview("https://cf.snapchat.com/media/me.jpg?{$param}=SECRET-VALUE");

            $this->assertSame(
                'withheld',
                $preview['state'],
                "A URL carrying `{$param}` must never reach a browser.",
            );

            // Withheld means withheld: no half-stripped URL leaks the value in another field.
            $this->assertNull($preview['image_url']);
            $this->assertNull($preview['video_url']);
            $this->assertStringNotContainsString('SECRET-VALUE', json_encode($preview) ?: '');
        }
    }

    /** A plain unsigned URL was never in question and must keep working. */
    public function test_an_unsigned_url_is_shown(): void
    {
        $this->assertSame('available', $this->preview('https://cf.snapchat.com/media/plain.jpg')['state']);
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — the two shapes whose media is not one asset.
     *
     * Both used to arrive as `other`. A COLLECTION then rendered as an ordinary still — one sixth of
     * the ad, presented as the ad — and a CATALOG ad fell through to «the platform sent no file»,
     * which reads as a fault and sends an operator looking for a sync problem that does not exist:
     * the platform composes a catalog creative per product at delivery, so there was never a file to
     * send.
     *
     * The format string is what the providers actually return, which is why the check is a
     * `str_contains` and why «collection_video» has to read as a collection: the collection is the
     * more specific truth about an ad whose hero happens to be a film.
     */
    public function test_a_collection_and_a_catalog_ad_are_named_as_the_shapes_they_are(): void
    {
        foreach ([
            'collection' => 'collection',
            'collection_video' => 'collection',
            'catalog' => 'catalog',
            'dynamic_product_ad' => 'catalog',
            'dpa' => 'catalog',
        ] as $format => $expected) {
            $creative = ExternalCreative::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => 'meta',
                'external_creative_id' => 'cr-'.Str::random(8),
                'name' => 'A creative',
                'format' => $format,
            ]);

            $this->assertSame(
                $expected,
                app(CreativePresenter::class)->preview($creative)['kind'],
                "«{$format}» did not read as a {$expected}",
            );
        }
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — the SHAPE of the frame, which the preview could not see.
     *
     * A story or a reel is 9:16. Shown in the square frame every preview used, it is letterboxed into
     * a third of the space or cropped through its own subject, and the reader is comparing a different
     * ad from the one that ran. The columns to answer this have been synced all along — `width`,
     * `height`, `aspect_ratio` — and the preview payload never carried the answer, so no surface could
     * act on it.
     */
    public function test_the_preview_states_the_shape_of_its_frame(): void
    {
        $cases = [
            // Real pixels first: the provider's own measurement beats a label somebody wrote.
            ['w' => 1080, 'h' => 1920, 'label' => null, 'expected' => 'vertical'],
            ['w' => 1080, 'h' => 1080, 'label' => null, 'expected' => 'square'],
            ['w' => 1920, 'h' => 1080, 'label' => null, 'expected' => 'horizontal'],
            // 4:5 is Meta's most common feed portrait, and tall enough that a square frame crops it.
            ['w' => 1080, 'h' => 1350, 'label' => null, 'expected' => 'vertical'],
            // No pixels: the label is parsed rather than ignored.
            ['w' => null, 'h' => null, 'label' => '9:16', 'expected' => 'vertical'],
            ['w' => null, 'h' => null, 'label' => '1:1', 'expected' => 'square'],
            // Neither: «the platform did not say», which is not «tall».
            ['w' => null, 'h' => null, 'label' => null, 'expected' => null],
            ['w' => null, 'h' => null, 'label' => 'portrait', 'expected' => null],
        ];

        foreach ($cases as $case) {
            $creative = ExternalCreative::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'provider' => 'meta',
                'external_creative_id' => 'cr-'.Str::random(8),
                'name' => 'A creative',
                'format' => 'image',
                'asset_url' => 'https://cdn.example.com/a.jpg',
                'width' => $case['w'],
                'height' => $case['h'],
                'aspect_ratio' => $case['label'],
            ]);

            $this->assertSame(
                $case['expected'],
                app(CreativePresenter::class)->preview($creative)['aspect'],
                sprintf('%sx%s / «%s» did not read as %s', $case['w'] ?? '—', $case['h'] ?? '—', $case['label'] ?? '—', $case['expected'] ?? 'unstated'),
            );
        }
    }

    /**
     * A story that could not be fetched is still a story.
     *
     * The frame it would have filled is still tall, and a withheld or expired preview that dropped the
     * shape would collapse into the square placeholder every other absence uses — so the one state
     * where the reader has NOTHING to look at is the state that most needs to say what shape is missing.
     */
    public function test_a_withheld_preview_still_states_its_shape(): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'A story',
            'format' => 'video',
            'asset_url' => 'https://cf.snapchat.com/media/me.jpg?access_token=SECRET-VALUE',
            'width' => 1080,
            'height' => 1920,
        ]);

        $preview = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('withheld', $preview['state']);
        $this->assertSame('vertical', $preview['aspect']);
    }

    /**
     * And the picker can name them.
     *
     * A filter with no word for a shape hides every ad of it, and an operator concludes the account
     * has no collection ads rather than that the control has no word for them.
     */
    public function test_the_shape_filter_offers_every_shape_the_presenter_can_answer(): void
    {
        $options = app(CreativeRows::class)->filterOptions(
            static fn () => ExternalCreative::withoutGlobalScopes()->whereRaw('1 = 0'),
        );

        foreach (['image', 'video', 'carousel', 'collection', 'catalog'] as $kind) {
            $this->assertContains(
                $kind,
                $options['kinds'],
                "the picker has no word for «{$kind}», so every ad of that shape is invisible to it",
            );
        }
    }

    /** @return array<string, mixed> */
    /**
     * AD-MEDIA-RECOVERY-001 — our OWN asset travels as a path, and must survive the guard.
     *
     * The demo video was stored as an absolute URL built from `APP_URL` at seed time, so the row held
     * «http://127.0.0.1:8000/demo/creative-sample.mp4» permanently and the film played only while
     * something happened to be listening on that port. Storing the path is the fix, and the guard has
     * to let a path through or the fix silently becomes «unavailable» on every demo video.
     */
    public function test_our_own_asset_may_be_stored_as_a_path(): void
    {
        $out = $this->preview('/demo/creative-sample.mp4', video: true);

        $this->assertSame('available', $out['state']);
        $this->assertSame('/demo/creative-sample.mp4', $out['video_url']);
    }

    /**
     * …and a PROTOCOL-RELATIVE url is not ours, however much it looks like a path.
     *
     * «//evil.example/x.jpg» begins with a slash and points at another origin entirely, which is the
     * whole reason the scheme check exists. The path rule must not become a hole in it.
     */
    public function test_a_protocol_relative_url_is_not_treated_as_our_own(): void
    {
        $out = $this->preview('//cdn.example.com/media/x.jpg');

        /*
         * Asserted on the URL rather than the state name: what matters is that nothing renders it.
         * The presenter classifies a refused-but-present url as `withheld` — «we hold one and decline
         * to hand it over» — which is the honest label for this and for a credentialled link alike.
         */
        $this->assertNotSame('available', $out['state']);
        $this->assertNull($out['image_url']);
        $this->assertNull($out['video_url']);
        $this->assertNull($out['thumbnail_url']);
    }

    /** A non-http scheme is still refused — the path rule widened one case, not the guard. */
    public function test_a_foreign_scheme_is_still_refused(): void
    {
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'data:text/html,<script>x</script>'] as $url) {
            $out = $this->preview($url);

            $this->assertNotSame('available', $out['state'], $url);
            $this->assertNull($out['image_url'], $url);
            $this->assertNull($out['video_url'], $url);
        }
    }

    /**
     * AD-MEDIA-RECOVERY-001 — «never fetched» is not «the platform refused».
     *
     * A row DERIVED from ad-level performance was never requested from the provider, so the old
     * «this platform does not expose the creative's asset» was a false accusation. The owner met it
     * on the content library, on cards marked «Demo», and it sends an operator to debug a working
     * integration.
     */
    public function test_a_derived_row_does_not_blame_the_platform_for_its_missing_asset(): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'google',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'Derived',
            'format' => 'video',
            'source_type' => 'estimated',
        ]);

        $out = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('never_fetched', $out['state']);
        $this->assertStringContainsString('never fetched', (string) $out['note_en']);
        $this->assertStringNotContainsString('does not expose', (string) $out['note_en']);
    }

    /** A FETCHED ad with no asset still says so — the platform genuinely gave nothing. */
    public function test_a_fetched_ad_with_no_asset_still_names_the_platform(): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'google',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'Fetched',
            'format' => 'image',
            'source_type' => 'api',
        ]);

        $out = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('unavailable', $out['state']);
        $this->assertStringContainsString('fetched from the platform', (string) $out['note_en']);
    }

    private function preview(string $url, bool $video = false): array
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => 'A creative',
            'format' => $video ? 'video' : 'image',
            ...($video ? ['video_url' => $url] : ['asset_url' => $url]),
        ]);

        return app(CreativePresenter::class)->preview($creative);
    }
}
