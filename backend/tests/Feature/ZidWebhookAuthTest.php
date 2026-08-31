<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Webhooks\WebhookSignature;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZID-WEBHOOK-001 — the signature scheme was invented. Zid authenticates with Basic auth.
 *
 * ## The defect
 *
 * `ProviderCatalogue` declared `webhookSignatureHeader: 'x-zid-signature'`, and `WebhookSignature`
 * computed `hash_hmac('sha256', $rawBody, $secret)` and compared it to whatever arrived in that
 * header. The `/admin` form asked the operator for a «Webhook secret … used to SIGN event deliveries».
 *
 * **Zid publishes no HMAC signature scheme at all.** Its Create Webhook reference documents the only
 * authentication it offers:
 *
 * > If username and password are provided when creating a webhook, Zid will include a **Basic
 * > Authentication** header when sending webhook requests. … `Authorization: Basic dXNlcjpzZWNyZXQ=`
 * > … This allows partners to verify that the webhook request is coming from Zid.
 *
 * So `x-zid-signature` is a header Zid never sends. Every genuine Zid delivery was refused with «The
 * delivery carried no signature», and the operator was being asked to configure a secret for a
 * mechanism that does not exist — one they could never obtain, because Zid has nothing to give them.
 *
 * It fails CLOSED, so this was never a security hole. It is the same shape as X-PKCE-001: a
 * mechanism described in detail, in the place a reviewer would look, and implemented against a
 * provider that does not have it. That is worse than an omission, because the description is what
 * gets checked instead of the behaviour.
 *
 * ## What replaces it
 *
 * The documented credential pair, compared with `hash_equals` over the whole header value so neither
 * half leaks through timing. Salla is untouched — its scheme is a real HMAC and this test asserts the
 * two providers stay apart.
 *
 * `RequiresConfirmation` stays. The poll remains authoritative, and that is now a decision grounded
 * in what Zid publishes rather than in not knowing.
 */
final class ZidWebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    private const USERNAME = 'campaignshub';

    private const PASSWORD = 'a-real-webhook-password';

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Z', 'slug' => 'z-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
    }

    // ── Zid ───────────────────────────────────────────────────────────────────────────────────

    /** The defect, pinned: the credential pair Zid documents is what is accepted. */
    public function test_a_zid_delivery_carrying_the_documented_basic_credentials_is_verified(): void
    {
        $this->configureZid();

        $result = app(WebhookSignature::class)->check('zid', '{"event":"order.create"}', $this->basic());

        $this->assertTrue(
            $result['verified'],
            'ZID-WEBHOOK-001: Zid was verified against an `x-zid-signature` HMAC it never sends, so '
                .'every genuine delivery was refused. Reason given: '.((string) $result['reason']),
        );
    }

    /** A wrong password is refused — the check is real, not a presence test. */
    public function test_a_zid_delivery_with_the_wrong_password_is_refused(): void
    {
        $this->configureZid();

        $wrong = 'Basic '.base64_encode(self::USERNAME.':not-the-password');
        $result = app(WebhookSignature::class)->check('zid', '{}', $wrong);

        $this->assertFalse($result['verified']);
    }

    /** So is a wrong username, which a comparison on the password alone would have let through. */
    public function test_a_zid_delivery_with_the_wrong_username_is_refused(): void
    {
        $this->configureZid();

        $wrong = 'Basic '.base64_encode('somebody-else:'.self::PASSWORD);

        $this->assertFalse(app(WebhookSignature::class)->check('zid', '{}', $wrong)['verified']);
    }

    /** Nothing presented is refused, as before. */
    public function test_a_zid_delivery_with_no_authorisation_header_is_refused(): void
    {
        $this->configureZid();

        $this->assertFalse(app(WebhookSignature::class)->check('zid', '{}', null)['verified']);
        $this->assertFalse(app(WebhookSignature::class)->check('zid', '{}', '')['verified']);
    }

    /**
     * Unconfigured means REFUSED, never accepted.
     *
     * The rule the whole class exists for, restated for the new branch: an endpoint that cannot
     * verify must refuse, or an unfinished setup becomes an open endpoint writing whatever anybody
     * posts into a customer's funnel.
     */
    public function test_zid_refuses_every_delivery_until_the_credentials_are_configured(): void
    {
        // Deliberately NOT configured.
        $result = app(WebhookSignature::class)->check('zid', '{}', $this->basic());

        $this->assertFalse($result['verified']);
        $this->assertNotNull($result['reason']);
    }

    /** An HMAC — the thing we used to demand — is now correctly refused, because Zid does not send one. */
    public function test_a_zid_delivery_carrying_an_hmac_signature_is_refused(): void
    {
        $this->configureZid();

        $body = '{"event":"order.create"}';
        $hmac = hash_hmac('sha256', $body, self::PASSWORD);

        $this->assertFalse(app(WebhookSignature::class)->check('zid', $body, $hmac)['verified']);
    }

    // ── The catalogue ─────────────────────────────────────────────────────────────────────────

    /** The header named in the catalogue is the one Zid actually sends. */
    public function test_the_catalogue_names_the_header_zid_really_sends(): void
    {
        $this->assertSame(
            'Authorization',
            ProviderCatalogue::get('zid')->webhookSignatureHeader,
            'ZID-WEBHOOK-001: the catalogue named `x-zid-signature`, a header Zid does not publish',
        );
    }

    /** And the operator is asked for the credentials Zid can actually give them. */
    public function test_the_admin_form_asks_for_the_credentials_zid_documents(): void
    {
        $fields = array_map(
            static fn ($field): string => $field->key,
            ProviderCatalogue::get('zid')->fields,
        );

        $this->assertContains('webhook_username', $fields);
        $this->assertContains('webhook_password', $fields);
        $this->assertNotContains(
            'webhook_secret',
            $fields,
            'asking for a signing secret Zid never issues invites an operator to invent one',
        );
    }

    // ── Salla is untouched ────────────────────────────────────────────────────────────────────

    /**
     * Salla really does sign, and still does.
     *
     * Its reference: a 64-character SHA-256 hash of the request body, appended to `x-salla-signature`,
     * compared with a timing-safe equality function. That is exactly what was already implemented —
     * and over the RAW body, which is safer than Salla's own Node sample, that re-encodes with
     * `JSON.stringify` before hashing.
     */
    public function test_salla_still_verifies_a_real_hmac_over_the_raw_body(): void
    {
        config()->set('commerce_platforms.platforms.salla.webhook_secret', 'salla-secret');

        $body = '{"event":"order.created","data":{"id":1}}';

        $result = app(WebhookSignature::class)->check('salla', $body, hash_hmac('sha256', $body, 'salla-secret'));

        $this->assertTrue($result['verified'], (string) $result['reason']);
    }

    /** And Zid's credentials are not a key that opens Salla. */
    public function test_a_zid_basic_header_is_not_accepted_by_salla(): void
    {
        config()->set('commerce_platforms.platforms.salla.webhook_secret', 'salla-secret');

        $this->assertFalse(app(WebhookSignature::class)->check('salla', '{}', $this->basic())['verified']);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function configureZid(): void
    {
        config()->set('commerce_platforms.platforms.zid.webhook_username', self::USERNAME);
        config()->set('commerce_platforms.platforms.zid.webhook_password', self::PASSWORD);
    }

    /** Exactly the header Zid documents: `Basic base64(username:password)`. */
    private function basic(): string
    {
        return 'Basic '.base64_encode(self::USERNAME.':'.self::PASSWORD);
    }
}
