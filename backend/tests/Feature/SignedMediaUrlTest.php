<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
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

    /** @return array<string, mixed> */
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
