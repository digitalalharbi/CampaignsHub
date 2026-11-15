<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Push\MinishlinkTransport;
use App\Domains\Notifications\Push\PushDispatcher;
use App\Domains\Notifications\Push\PushSubscription;
use App\Domains\Notifications\Push\PushTransport;
use App\Domains\Notifications\Push\SendPushNotification;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * NOTIF-PUSH-001 — Web Push, up to the edge of the push service.
 *
 * A physical phone and the production VAPID keys do not exist here, so the push service is a Guzzle mock — but the message that
 * reaches it is REAL: encrypted by the library to a subscription whose key pair this test generated, signed with a real VAPID
 * key, and then DECRYPTED here (RFC 8291) to read what a phone would read. What is simulated is the push service and the device;
 * what is proven is who is sent what, when, and in what words.
 */
final class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private Project $project;

    private Project $other;

    private ClientWorkspace $client;

    /** @var list<RequestInterface> */
    private array $sent = [];

    private MockHandler $service;

    /** @var array<string, array{key: \OpenSSLAsymmetricKey, pub: string, auth: string}> */
    private array $devices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $keys = VAPID::createVapidKeys();
        config([
            'webpush.vapid.public_key' => $keys['publicKey'], 'webpush.vapid.private_key' => $keys['privateKey'],
            'webpush.allowed_hosts' => ['push.test'],
        ]);
        $this->service = new MockHandler;
        $stack = HandlerStack::create($this->service);
        $this->history = new \ArrayObject;
        $stack->push(Middleware::history($this->history));
        $this->app->bind(PushTransport::class, fn () => new MinishlinkTransport($stack));

        $this->agency = Tenant::create(['name' => 'Push Agency', 'slug' => 'push-agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);
        $this->client = ClientWorkspace::create(['tenant_id' => $this->agency->id, 'name' => 'قهوة الصباح', 'slug' => 'q-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->client->id, 'name' => 'A1', 'status' => 'active']);
        $other = ClientWorkspace::create(['tenant_id' => $this->agency->id, 'name' => 'Other', 'slug' => 'o-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $other->id, 'name' => 'B1', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private \ArrayObject $history;

    /** @return list<RequestInterface> */
    private function requests(): array
    {
        return array_map(static fn (array $h): RequestInterface => $h['request'], $this->history->getArrayCopy());
    }

    private function ok(int $n = 20): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->service->append(new Response(201));
        }
    }

    // ---- fixtures --------------------------------------------------------------------------------------------------

    private function member(string $email, array $permissions = ['projects.view', 'projects.view.all', 'analytics.view', 'clients.view_all'], ?array $clients = null, Portal $portal = Portal::Agency): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now(), 'locale' => 'ar']);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $this->agency, portal: $portal, role: 'member', clientScopeIds: $clients));

        return $user;
    }

    /** A browser: a real P-256 key pair and auth secret, registered through the API. */
    private function subscribe(User $user, string $name): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($key);
        $pub = "\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        $b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        $endpoint = "https://updates.push.test/send/{$name}-".Str::random(8);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => $b64($pub), 'auth' => $b64($auth)], 'label' => $name,
        ])->assertCreated();
        $this->devices[$endpoint] = ['key' => $key, 'pub' => $pub, 'auth' => $auth];

        return $endpoint;
    }

    /** What the phone would read: RFC 8291 aes128gcm, undone with the subscription's private key. */
    private function decrypt(RequestInterface $request): array
    {
        $device = $this->devices[(string) $request->getUri()];
        $body = (string) $request->getBody();
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $cipher = substr($body, 21 + $idLen);

        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$asPublic;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
        $ecdh = openssl_pkey_derive(openssl_pkey_get_public($pem), $device['key'], 32);
        $prk = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0".$device['pub'].$asPublic, $device['auth']);
        $cek = hash_hkdf('sha256', $prk, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $prk, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'the message must decrypt with the device key');
        $json = rtrim($plain, "\0");
        $json = substr($json, 0, strrpos($json, "\x02") ?: strlen($json));

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $over */
    private function raise(array $over = []): ?AppNotification
    {
        return app(NotificationDispatcher::class)->dispatch($over + [
            'tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->client->id,
            'type' => 'budget_risk', 'kind' => 'budget_90', 'severity' => 'warning', 'title' => 'Internal spend limit at 90%',
            'message' => 'SAR 9,000 of SAR 10,000', 'source' => 'alerts', 'entity_type' => 'limit', 'entity_id' => 'L1', 'upsert_daily' => true,
        ]);
    }

    private function noon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC')); // 12:00 Riyadh
    }

    // ---- subscribing -----------------------------------------------------------------------------------------------

    public function test_push_is_off_until_a_key_pair_is_configured_and_the_page_says_so(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        $user = $this->member('a@push.dev');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/push')->assertOk()->assertJsonPath('data.configured', false)->assertJsonPath('data.public_key', null);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://updates.push.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(503);
    }

    public function test_subscribing_lists_the_device_without_its_secrets_and_a_person_can_hold_several(): void
    {
        $user = $this->member('a@push.dev');
        $this->subscribe($user, 'Phone');
        $this->subscribe($user, 'Laptop');

        $json = $this->actingAs($user, 'sanctum')->getJson('/api/v1/push')->assertOk()->json('data');
        $this->assertTrue($json['configured']);
        $this->assertSame(config('webpush.vapid.public_key'), $json['public_key']);
        $this->assertEqualsCanonicalizing(['Phone', 'Laptop'], array_column($json['devices'], 'label'));
        $this->assertStringNotContainsString('endpoint', (string) json_encode($json['devices']));
        $this->assertStringNotContainsString('auth', (string) json_encode($json['devices']));
        // The private key is never served; the auth secret is encrypted at rest.
        $this->assertStringNotContainsString((string) config('webpush.vapid.private_key'), (string) json_encode($json));
        $stored = DB::table('push_subscriptions')->where('user_id', $user->id)->value('auth');
        $this->assertNotSame(1, preg_match('/^[A-Za-z0-9_-]{22}$/', (string) $stored), 'auth is not stored as the plain secret');
    }

    public function test_an_endpoint_that_is_not_a_push_service_is_refused_so_the_server_cannot_be_aimed_elsewhere(): void
    {
        $user = $this->member('a@push.dev');
        $keys = ['p256dh' => 'a', 'auth' => 'b'];

        foreach (['http://updates.push.test/x', 'https://evil.example/x', 'https://169.254.169.254/latest', 'https://127.0.0.1/x', 'https://updates.push.test:8443/x', 'https://u:p@updates.push.test/x', 'https://notpush.test/x', 'https://push.test.evil.example/x'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => $keys])->assertStatus(422);
        }
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_a_person_revokes_one_device_and_never_sees_or_removes_anothers(): void
    {
        $a = $this->member('a@push.dev');
        $b = $this->member('b@push.dev');
        $phone = $this->subscribe($a, 'Phone');
        $this->subscribe($a, 'Laptop');
        $id = (string) PushSubscription::where('endpoint_hash', PushSubscription::hashOf($phone))->value('id');

        $this->actingAs($b, 'sanctum')->deleteJson("/api/v1/push/subscriptions/{$id}")->assertNotFound();
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/push/subscriptions/{$id}/test")->assertNotFound();
        $this->assertSame(2, PushSubscription::where('user_id', $a->id)->count());

        $this->actingAs($a, 'sanctum')->deleteJson("/api/v1/push/subscriptions/{$id}")->assertOk();
        $this->assertSame(['Laptop'], PushSubscription::where('user_id', $a->id)->pluck('label')->all());

        $laptop = (string) PushSubscription::where('user_id', $a->id)->value('endpoint');
        $this->actingAs($a, 'sanctum')->postJson('/api/v1/push/forget', ['endpoint' => $laptop])->assertOk();
        $this->assertSame(0, PushSubscription::where('user_id', $a->id)->count());
    }

    // ---- what is sent, to whom -------------------------------------------------------------------------------------

    public function test_subscribe_then_a_budget_alert_reaches_the_push_service_as_a_short_signed_encrypted_message_without_amounts(): void
    {
        $this->noon();
        $this->ok();
        $user = $this->member('a@push.dev');
        $endpoint = $this->subscribe($user, 'Phone');

        $this->raise();

        $this->assertCount(1, $this->requests());
        $request = $this->requests()[0];
        $this->assertSame($endpoint, (string) $request->getUri());
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('k='.config('webpush.vapid.public_key'), $request->getHeaderLine('Authorization'));
        $this->assertSame('14400', $request->getHeaderLine('TTL'));

        $payload = $this->decrypt($request);
        $this->assertSame('قهوة الصباح', $payload['title']);
        $this->assertSame('الميزانية 90%', $payload['body']);
        $this->assertSame('/agency/budgets?project='.$this->project->id, $payload['url']);
        // No amount, no campaign name, no figure of any kind in the words a lock screen shows.
        $this->assertDoesNotMatchRegularExpression('/\d{3}|SAR|ر\.س|9,000/u', $payload['title'].' '.$payload['body']);
        $this->assertStringNotContainsString('9,000', (string) json_encode($payload));
        $this->assertNotNull(PushSubscription::first()->last_used_at);
    }

    public function test_only_the_five_push_types_ring_a_phone(): void
    {
        $this->noon();
        $this->ok();
        $this->subscribe($this->member('a@push.dev'), 'Phone');

        $this->raise(['kind' => 'no_results', 'severity' => 'warning', 'title' => 'x', 'entity_id' => 'c1']);          // a warning: bell only
        $this->raise(['kind' => 'budget_75', 'severity' => 'warning', 'title' => 'x', 'entity_id' => 'c2']);           // 75%: bell only
        $this->raise(['kind' => 'report_ready', 'type' => 'report_ready', 'severity' => 'info', 'title' => 'x', 'entity_id' => 'c3']);
        $this->raise(['kind' => 'recovered', 'severity' => 'info', 'title' => 'x', 'entity_id' => 'c4']);
        $this->assertCount(0, $this->requests());

        $this->raise(['kind' => 'budget_90', 'entity_id' => 'c5']);
        $this->raise(['kind' => 'budget_100', 'severity' => 'critical', 'entity_id' => 'c6']);
        $this->raise(['kind' => 'budget_over', 'severity' => 'error', 'entity_id' => 'c7']);
        $this->raise(['kind' => 'sync_failed', 'type' => 'sync_failed', 'severity' => 'warning', 'entity_id' => 'c8']);
        $this->raise(['kind' => 'no_results', 'severity' => 'error', 'entity_id' => 'c9']);                            // critical, any kind
        $this->raise(['kind' => 'approval_request', 'type' => 'approval_request', 'severity' => 'info', 'entity_id' => 'c10']);
        $this->assertCount(6, $this->requests());
    }

    public function test_a_member_without_access_to_the_project_gets_nothing_while_a_member_with_access_does(): void
    {
        $this->noon();
        $this->ok();
        $allowed = $this->member('allowed@push.dev');
        $narrow = $this->member('narrow@push.dev', ['projects.view'], [(string) $this->other->client_workspace_id]); // another client's projects only
        $none = $this->member('none@push.dev', ['projects.view']);                                                    // no project membership at all
        $portal = $this->member('portal@push.dev', ['projects.view', 'projects.view.all'], null, Portal::ClientPortal);
        $e1 = $this->subscribe($allowed, 'Allowed');
        $e2 = $this->subscribe($narrow, 'Narrow');
        $e3 = $this->subscribe($none, 'None');
        $e4 = $this->subscribe($portal, 'Portal');

        $this->raise();

        $this->assertSame([$e1], array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests()));
        $this->assertNotContains($e2, array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests()));
        $this->assertNotContains($e3, array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests()));
        $this->assertNotContains($e4, array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests()));
    }

    public function test_a_member_whose_budgets_section_is_switched_off_gets_no_budget_push_but_still_gets_a_sync_failure(): void
    {
        $this->noon();
        $this->ok();
        $user = $this->member('a@push.dev');
        $this->subscribe($user, 'Phone');
        DB::table('member_access_overrides')->insert(['tenant_id' => $this->agency->id, 'user_id' => $user->id, 'permission_key' => 'section:budgets', 'effect' => 'deny', 'created_at' => now(), 'updated_at' => now()]);

        $this->raise(['kind' => 'budget_90']);
        $this->assertCount(0, $this->requests());

        $this->raise(['kind' => 'sync_failed', 'type' => 'sync_failed', 'entity_id' => 'sync1']);
        $this->assertCount(1, $this->requests());
    }

    public function test_a_person_can_switch_a_push_type_off_for_themselves(): void
    {
        $this->noon();
        $this->ok();
        $user = $this->member('a@push.dev');
        $other = $this->member('b@push.dev');
        $this->subscribe($user, 'Phone');
        $this->subscribe($other, 'Other');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/notifications/alert-settings', ['push_types' => ['budget_90' => false]])
            ->assertOk()->assertJsonPath('data.push_types.budget_90', false)->assertJsonPath('data.push_types.critical', true);
        $this->raise();

        $this->assertCount(1, $this->requests());
        $this->assertStringContainsString('Other', (string) $this->requests()[0]->getUri());
    }

    public function test_a_notification_already_read_or_closed_is_not_pushed(): void
    {
        $this->noon();
        $this->ok();
        $this->subscribe($this->member('a@push.dev'), 'Phone');
        AppNotification::unguarded(fn () => null);

        $n = $this->raise();
        $this->assertCount(1, $this->requests());

        // A finding that is already closed when its push comes to be sent is skipped.
        $m = AppNotification::create(['tenant_id' => $this->agency->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->client->id, 'type' => 'budget_risk', 'kind' => 'budget_100', 'severity' => 'critical', 'title' => 't', 'status' => 'resolved', 'source' => 'alerts']);
        (new SendPushNotification((string) $m->id))->handle(app(PushDispatcher::class));
        $this->assertCount(1, $this->requests());
        $this->assertNotNull($n);
    }

    // ---- quiet hours -----------------------------------------------------------------------------------------------

    public function test_quiet_hours_hold_the_push_and_it_goes_out_afterwards_as_one_summary(): void
    {
        $this->ok();
        $user = $this->member('a@push.dev');
        $this->subscribe($user, 'Phone');

        Carbon::setTestNow(Carbon::parse('2026-11-15 20:30:00', 'UTC')); // 23:30 Riyadh — inside the default 22:00–08:00
        $this->raise(['entity_id' => 'night1']);
        $this->raise(['kind' => 'budget_100', 'severity' => 'critical', 'entity_id' => 'night2']);
        $this->raise(['kind' => 'sync_failed', 'type' => 'sync_failed', 'entity_id' => 'night3']);

        $this->assertCount(0, $this->requests(), 'nothing rings during quiet hours');
        $this->assertSame(3, DB::table('push_deferred')->count());

        // Still quiet: the sweep sends nothing and keeps them.
        $this->artisan('notifications:flush-deferred-push')->assertSuccessful();
        $this->assertCount(0, $this->requests());
        $this->assertSame(3, DB::table('push_deferred')->count());

        Carbon::setTestNow(Carbon::parse('2026-11-16 05:30:00', 'UTC')); // 08:30 Riyadh
        $this->artisan('notifications:flush-deferred-push')->assertSuccessful();

        $this->assertCount(1, $this->requests(), 'one summary, not three pushes');
        $payload = $this->decrypt($this->requests()[0]);
        $this->assertSame('وصلتك 3 تنبيهات وقت الهدوء', $payload['body']);
        $this->assertSame('/agency/alerts', $payload['url']);
        $this->assertSame(0, DB::table('push_deferred')->count());

        $this->artisan('notifications:flush-deferred-push')->assertSuccessful();
        $this->assertCount(1, $this->requests(), 'and it is not sent twice');
    }

    public function test_something_read_or_closed_while_it_waited_does_not_count_in_the_summary(): void
    {
        $this->ok();
        $user = $this->member('a@push.dev');
        $this->subscribe($user, 'Phone');
        Carbon::setTestNow(Carbon::parse('2026-11-15 20:30:00', 'UTC'));
        $a = $this->raise(['entity_id' => 'n1']);
        $this->raise(['entity_id' => 'n2']);
        $a->forceFill(['status' => 'resolved'])->save();

        Carbon::setTestNow(Carbon::parse('2026-11-16 05:30:00', 'UTC'));
        $this->artisan('notifications:flush-deferred-push')->assertSuccessful();

        $this->assertCount(1, $this->requests());
        $this->assertSame('وصلتك 1 تنبيهات وقت الهدوء', $this->decrypt($this->requests()[0])['body']);
    }

    public function test_the_hourly_ceiling_holds_the_rest_for_the_summary(): void
    {
        $this->noon();
        $this->ok();
        $this->subscribe($this->member('a@push.dev'), 'Phone');
        config(['webpush.ceiling_per_hour' => 2]);

        foreach (range(1, 4) as $i) {
            $this->raise(['entity_id' => "c{$i}"]);
        }

        $this->assertCount(2, $this->requests());
        $this->assertSame(2, DB::table('push_deferred')->where('reason', 'ceiling')->count());
    }

    // ---- the push service answering --------------------------------------------------------------------------------

    public function test_a_410_or_404_from_the_push_service_deletes_that_device_and_other_failures_keep_it(): void
    {
        $this->noon();
        $user = $this->member('a@push.dev');
        $gone = $this->subscribe($user, 'Gone');
        $missing = $this->subscribe($user, 'Missing');
        $flaky = $this->subscribe($user, 'Flaky');
        $good = $this->subscribe($user, 'Good');

        $by = [$gone => 410, $missing => 404, $flaky => 500, $good => 201];
        $this->service->append(...array_map(static fn (int $s): Response => new Response($s), array_values($this->orderOf($user, $by))));
        $this->raise();

        $left = PushSubscription::where('user_id', $user->id)->pluck('label')->sort()->values()->all();
        $this->assertSame(['Flaky', 'Good'], $left);
        $this->assertSame(1, (int) PushSubscription::where('label', 'Flaky')->value('failure_count'));
        $this->assertNull(PushSubscription::where('label', 'Flaky')->value('last_used_at'));
        $this->assertNotNull(PushSubscription::where('label', 'Good')->value('last_used_at'));
    }

    /**
     * The mock answers in the order the dispatcher visits devices.
     *
     * @param  array<string,int>  $byEndpoint
     * @return list<int>
     */
    private function orderOf(User $user, array $byEndpoint): array
    {
        return PushSubscription::query()->where('user_id', $user->id)->get()->map(static fn (PushSubscription $d): int => $byEndpoint[$d->endpoint])->all();
    }

    public function test_a_redirect_from_a_push_endpoint_is_never_followed(): void
    {
        $this->noon();
        $user = $this->member('a@push.dev');
        $this->subscribe($user, 'Phone');
        $this->service->append(new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data']));

        $this->raise();

        $this->assertCount(1, $this->requests(), 'one request, to the registered endpoint only');
        $this->assertSame(1, (int) PushSubscription::value('failure_count'));
    }

    public function test_the_test_button_sends_one_harmless_message_to_that_device_only(): void
    {
        $this->ok();
        $user = $this->member('a@push.dev');
        $phone = $this->subscribe($user, 'Phone');
        $this->subscribe($user, 'Laptop');
        $id = (string) PushSubscription::where('endpoint_hash', PushSubscription::hashOf($phone))->value('id');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/push/subscriptions/{$id}/test")->assertOk()->assertJsonPath('data.delivered', true);

        $this->assertCount(1, $this->requests());
        $this->assertSame($phone, (string) $this->requests()[0]->getUri());
        $this->assertSame('جرّبنا إشعارات الجوال — وصلتك', $this->decrypt($this->requests()[0])['body']);
    }

    public function test_nothing_is_sent_and_nothing_breaks_when_no_key_pair_is_set(): void
    {
        $this->noon();
        $user = $this->member('a@push.dev');
        $this->subscribe($user, 'Phone');
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $n = $this->raise();

        $this->assertNotNull($n, 'the bell still gets its notification');
        $this->assertCount(0, $this->requests());
    }

    public function test_the_vapid_command_prints_a_pair_to_the_console_and_writes_nothing(): void
    {
        $envFile = base_path('.env');
        $before = is_file($envFile) ? md5_file($envFile) : null;

        $this->artisan('webpush:vapid')->expectsOutputToContain('VAPID_PUBLIC_KEY=')->expectsOutputToContain('VAPID_PRIVATE_KEY=')->assertSuccessful();

        $this->assertSame($before, is_file($envFile) ? md5_file($envFile) : null);
    }

    public function test_no_vapid_key_is_committed_in_the_repository_examples(): void
    {
        foreach ([base_path('.env.example'), base_path('../deploy/backend.production.env.example')] as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                if (preg_match('/^\s*#?\s*VAPID_(PUBLIC|PRIVATE)_KEY=(.*)$/', $line, $m) === 1) {
                    $this->assertSame('', trim($m[2]), "$file must name the key and leave it empty");
                }
                // The production contract: a NEW key must be commented out, or every deploy is blocked.
                if (str_contains($file, 'deploy/') && preg_match('/^\s*VAPID_/', $line) === 1) {
                    $this->fail('an uncommented VAPID key in the production env contract blocks deploys');
                }
            }
        }
        $this->assertStringNotContainsString('VAPID', (string) shell_exec('git -C '.escapeshellarg(base_path('..')).' ls-files -z backend/config | xargs -0 grep -l "BEGIN\\|privateKey.*=>.*[A-Za-z0-9_-]\\{40\\}" 2>/dev/null'));
    }
}
