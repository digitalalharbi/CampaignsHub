<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestStatus;
use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CP-ISOLATION-001 — enumeration: «not yours» must read EXACTLY like «not there».
 *
 * `ClientPortalIsolationProbeTest` and `ClientPortalWriteIsolationTest` assert a refusal (403/404/422) for the other client's ids. A refusal
 * is not enough: if another client's slug, another tenant's slug, another client's request reference, invoice, quote, thread or project
 * answers with a different status or a different body from an id that does not exist, a prober learns which guesses are real — which is
 * enumeration, and the first step of every cross-tenant attack. So each case here asks the same question twice, once about something that
 * is somebody else's and once about something that exists nowhere, and requires the two answers to be byte-identical.
 *
 * Two kinds of caller are probed, because the portal has two doors: a contact signed in with an OTP (`X-Client-Token`, the slug chosen with
 * `X-Client-Space`) and a member of the client portal signed in with a session.
 */
final class ClientPortalEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'BETA-SECRET';

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private Tenant $other;

    private ClientWorkspace $alpha;

    private ClientWorkspace $beta;

    private ClientWorkspace $gamma;

    /** @var array<string, array<string, string>> ids by owner, then by kind */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        $this->other = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active']);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->alpha = $this->client($this->tenant, 'Alpha', 'alpha');
        $this->beta = $this->client($this->tenant, self::MARKER.' Co', 'beta');
        $this->ids['alpha'] = $this->records($this->tenant, $this->alpha, 'Alpha', 'me@x.test');
        $this->ids['beta'] = $this->records($this->tenant, $this->beta, self::MARKER, 'beta@x.test');

        app(TenantContext::class)->setTenantId($this->other->id);
        $this->gamma = $this->client($this->other, self::MARKER.' Rival', 'gamma');
        $this->ids['gamma'] = $this->records($this->other, $this->gamma, self::MARKER.'G', 'gamma@x.test');

        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function client(Tenant $tenant, string $name, string $slug): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => $name, 'slug' => $slug, 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    /** @return array<string, string> */
    private function records(Tenant $tenant, ClientWorkspace $w, string $label, string $email): array
    {
        $project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $w->id, 'name' => $label.' project', 'status' => 'active']);
        $second = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $w->id, 'name' => $label.' second project', 'status' => 'active']);
        $invoice = Invoice::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $w->id, 'number' => $label.'-INV-1', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'amount_paid' => 0, 'status' => 'issued', 'due_date' => '2026-12-01']);
        $quote = Quote::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $w->id, 'number' => $label.'-QUO-1', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'status' => 'sent']);
        $thread = MessageThread::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $w->id, 'subject' => $label.' thread', 'status' => 'open']);
        $request = ExternalRequest::create([
            'tenant_id' => $tenant->id, 'reference' => 'REQ-2026-'.strtoupper(substr(md5($label), 0, 6)),
            'type_id' => RequestType::query()->firstOrFail()->id, 'status_id' => RequestStatus::query()->firstOrFail()->id,
            'contact_name' => $label, 'contact_email' => $email, 'client_id' => $w->id, 'project_id' => $project->id,
            'objective' => $label.' objective', 'journey_stage' => 'in_progress', 'submitted_at' => now(),
        ]);

        return [
            'invoice' => (string) $invoice->id, 'quote' => (string) $quote->id, 'thread' => (string) $thread->id,
            'reference' => $request->reference, 'project' => (string) $project->id, 'second_project' => (string) $second->id,
        ];
    }

    private function contactToken(string $email = 'me@x.test'): string
    {
        $start = $this->postJson('/api/v1/client/login/start', ['channel' => 'email', 'destination' => $email])->assertCreated();

        return $this->postJson('/api/v1/client/login/verify', [
            'verification_id' => $start->json('data.verification_id'),
            'code' => $start->json('data.dev_code'),
        ])->assertOk()->json('data.dev_token');
    }

    private function signInAsAlphaMember(?array $projects = null): void
    {
        $user = User::create(['name' => 'Alpha user', 'email' => 'alpha-user@x.test', 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_admin',
            clientScopeIds: [(string) $this->alpha->id], projectScopeIds: $projects,
        ));
        $this->actingAs($user);
    }

    /** What a prober can observe of an answer: the status and the whole body. */
    private function seen(TestResponse $res): string
    {
        // `meta.request_id` is minted per request and says nothing about the record; everything else a prober reads is compared.
        return $res->status().' '.preg_replace('/"request_id":"[^"]*"/', '"request_id":"-"', $res->getContent());
    }

    /** A well-formed id of the given kind that exists nowhere: invoices and quotes are numbered, threads and projects are uuids. */
    private function absentId(string $kind): string
    {
        return in_array($kind, ['invoice', 'quote'], true) ? '999999999' : $this->absentUuid();
    }

    private function absentUuid(): string
    {
        return (string) Str::uuid();
    }

    /**
     * The slugs somebody else owns or that merely try to look like the caller's own.
     * Every one of them must read as the slug that exists nowhere.
     *
     * @return array<string, string>
     */
    private function foreignSlugs(): array
    {
        return [
            'another client of the same agency' => 'beta',
            "another agency's client" => 'gamma',
            'the other client, upper-cased' => 'BETA',
            'the other client, with a path escape' => 'alpha/../beta',
            'both slugs in one value' => 'alpha,beta',
            'a SQL wildcard' => '%',
            'a SQL wildcard after the caller\'s own slug' => 'alpha%',
            'a glob' => '*',
            'a NUL byte after the other client' => "beta\0",
            'the other client percent-encoded' => '%62eta',
        ];
    }

    /** @return list<string> every space-scoped GET a contact can call without an id */
    private function scopedGets(): array
    {
        return ['requests', 'invoices', 'quotes', 'messages', 'files', 'campaigns', 'reports', 'overview'];
    }

    public function test_a_slug_that_is_not_the_contacts_reads_exactly_like_one_that_does_not_exist(): void
    {
        $token = $this->contactToken();
        $headers = fn (string $slug): array => ['X-Client-Token' => $token, 'X-Client-Space' => $slug];

        foreach ($this->scopedGets() as $path) {
            // The portal throttles per caller; a probe that walks every endpoint with a dozen slugs must not be answered 429 for it.
            Cache::flush();
            $baseline = $this->withHeaders($headers('no-such-space-anywhere'))->getJson("/api/v1/client/{$path}");
            $this->assertSame(404, $baseline->status(), "/client/{$path} did not refuse a slug that exists nowhere");

            foreach ($this->foreignSlugs() as $why => $slug) {
                $res = $this->withHeaders($headers($slug))->getJson("/api/v1/client/{$path}");

                $this->assertSame($this->seen($baseline), $this->seen($res), "/client/{$path} told «{$why}» apart from a slug that exists nowhere");
                $this->assertStringNotContainsString(self::MARKER, $res->getContent());
            }
        }

        // The control: the caller's OWN slug opens its own space, so the 404s above are refusals and not a dead endpoint.
        $own = $this->withHeaders($headers('alpha'))->getJson('/api/v1/client/requests')->assertOk();
        $this->assertSame([$this->ids['alpha']['reference']], array_column($own->json('data.requests'), 'reference'));
    }

    public function test_the_slug_in_the_query_string_is_held_to_the_same_rule_as_the_header(): void
    {
        $token = $this->contactToken();

        foreach ($this->scopedGets() as $path) {
            $baseline = $this->withHeaders(['X-Client-Token' => $token])->getJson("/api/v1/client/{$path}?space=no-such-space-anywhere");
            $this->assertSame(404, $baseline->status());

            foreach (['beta', 'gamma'] as $slug) {
                $res = $this->withHeaders(['X-Client-Token' => $token])->getJson("/api/v1/client/{$path}?space={$slug}");
                $this->assertSame($this->seen($baseline), $this->seen($res), "/client/{$path}?space={$slug} was told apart from an unknown space");
            }
        }
    }

    public function test_an_array_or_repeated_slug_never_widens_the_view(): void
    {
        $token = $this->contactToken();

        // `?space[]=alpha&space[]=beta` reaches the controller as an array; whatever it does it must not return the other client's rows.
        foreach (['?space[]=alpha&space[]=beta', '?space[]=beta', '?space[beta]=1'] as $query) {
            foreach ($this->scopedGets() as $path) {
                $res = $this->withHeaders(['X-Client-Token' => $token])->getJson("/api/v1/client/{$path}{$query}");

                $this->assertLessThan(500, $res->status(), "/client/{$path}{$query} broke instead of refusing");
                $this->assertStringNotContainsString(self::MARKER, $res->getContent(), "/client/{$path}{$query} carried the other client's data");
            }
        }
    }

    public function test_a_slug_shared_with_another_agency_resolves_inside_the_callers_own_agency_only(): void
    {
        // The rival agency has a client with the SAME slug as the caller's. Slugs are unique per agency, not globally.
        app(TenantContext::class)->setTenantId($this->other->id);
        $twin = $this->client($this->other, self::MARKER.' Twin', 'alpha');
        $this->records($this->other, $twin, self::MARKER.'T', 'twin@x.test');
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $token = $this->contactToken();
        $res = $this->withHeaders(['X-Client-Token' => $token, 'X-Client-Space' => 'alpha'])->getJson('/api/v1/client/requests')->assertOk();

        $this->assertSame([$this->ids['alpha']['reference']], array_column($res->json('data.requests'), 'reference'));
        $this->assertStringNotContainsString(self::MARKER, $res->getContent());
    }

    public function test_a_guessed_request_reference_reads_the_same_whether_it_is_another_clients_another_agencys_or_nobodys(): void
    {
        $token = $this->contactToken();
        $guesses = [
            'another client of the same agency' => $this->ids['beta']['reference'],
            "another agency's client" => $this->ids['gamma']['reference'],
            'lower-cased' => strtolower($this->ids['beta']['reference']),
            'one that exists nowhere' => 'REQ-2026-ZZZZZZ',
        ];

        $routes = [
            ['GET', '%s'], ['GET', '%s/journey'], ['GET', '%s/files/1'], ['POST', '%s/reply'],
        ];

        foreach ($routes as [$method, $pattern]) {
            $seen = [];
            foreach ($guesses as $why => $reference) {
                $path = '/api/v1/client/requests/'.sprintf($pattern, rawurlencode($reference));
                $res = $this->withHeaders(['X-Client-Token' => $token])->json($method, $path, $method === 'POST' ? ['message' => 'hello there'] : []);
                $seen[$why] = $this->seen($res);
                $this->assertStringNotContainsString(self::MARKER, $res->getContent());
            }

            $this->assertCount(1, array_unique($seen), "{$method} /client/requests/".sprintf($pattern, '{reference}').' answered differently by whose reference it was: '.json_encode(array_map(fn (string $s): string => substr($s, 0, 60), $seen)));
            $this->assertStringStartsWith('404 ', reset($seen));
        }

        // The control: the contact's own reference is readable.
        $this->withHeaders(['X-Client-Token' => $token])->getJson('/api/v1/client/requests/'.$this->ids['alpha']['reference'])->assertOk();
    }

    public function test_guessed_ids_read_the_same_whether_they_are_another_clients_another_agencys_or_nobodys(): void
    {
        $this->signInAsAlphaMember();

        $kinds = [
            ['invoice', ['GET invoices/%s', 'GET invoices/%s/pdf', 'POST invoices/%s/pay']],
            ['quote', ['GET quotes/%s', 'POST quotes/%s/approve', 'POST quotes/%s/reject']],
            ['thread', ['GET messages/%s', 'POST messages/%s']],
        ];

        foreach ($kinds as [$kind, $routes]) {
            foreach ($routes as $route) {
                [$method, $pattern] = explode(' ', $route);
                $answers = [];
                foreach ([
                    'another client of the same agency' => $this->ids['beta'][$kind],
                    "another agency's client" => $this->ids['gamma'][$kind],
                    'a well-formed id that exists nowhere' => $this->absentId($kind),
                ] as $why => $id) {
                    $res = $this->json($method, '/api/v1/client/'.sprintf($pattern, $id), ['body' => 'hello'], $this->spa);
                    $answers[$why] = $this->seen($res);
                    $this->assertStringNotContainsString(self::MARKER, $res->getContent());
                }

                $this->assertCount(1, array_unique($answers), "{$method} /client/".sprintf($pattern, '{'.$kind.'}').' answered differently by whose id it was: '.json_encode(array_map(fn (string $s): string => substr($s, 0, 80), $answers)));
                $this->assertStringStartsWith('404 ', reset($answers));
            }
        }
    }

    public function test_a_malformed_id_reads_like_an_id_that_is_not_there_and_never_breaks(): void
    {
        $this->signInAsAlphaMember();

        foreach (['invoices', 'quotes', 'messages'] as $collection) {
            $absent = $this->getJson("/api/v1/client/{$collection}/".$this->absentId($collection === 'messages' ? 'thread' : 'invoice'), $this->spa);
            foreach (['not-a-uuid', '1', '0', '%27%20OR%201=1', 'true'] as $junk) {
                $res = $this->getJson("/api/v1/client/{$collection}/{$junk}", $this->spa);

                $this->assertSame($this->seen($absent), $this->seen($res), "/client/{$collection}/{$junk} was told apart from an id that is simply not there");
            }
        }
    }

    public function test_a_guessed_project_id_reads_the_same_whether_it_is_another_clients_another_agencys_nobodys_or_outside_the_users_reach(): void
    {
        // Confined to Alpha's FIRST project only; Alpha's second project is inside the client but outside this user's reach.
        $this->signInAsAlphaMember([$this->ids['alpha']['project']]);
        $type = RequestType::query()->value('key');

        $answers = [];
        foreach ([
            'a project of another client' => $this->ids['beta']['project'],
            "a project of another agency's client" => $this->ids['gamma']['project'],
            "a project of the caller's own client outside their reach" => $this->ids['alpha']['second_project'],
            'a well-formed id that exists nowhere' => $this->absentUuid(),
            'a malformed id' => 'not-a-uuid',
        ] as $why => $project) {
            $res = $this->postJson('/api/v1/client/requests', ['type' => $type, 'message' => 'about that project', 'project_id' => $project], $this->spa);
            $answers[$why] = $this->seen($res);
            $this->assertStringNotContainsString(self::MARKER, $res->getContent());
        }

        $this->assertCount(1, array_unique($answers), 'filing a request against a guessed project answered differently by whose project it was: '.json_encode(array_map(fn (string $s): string => substr($s, 0, 80), $answers)));
        $this->assertStringStartsWith('404 ', reset($answers));

        // The control: the project inside the caller's reach is accepted, and none of the refusals created a request.
        $this->postJson('/api/v1/client/requests', ['type' => $type, 'message' => 'about my project', 'project_id' => $this->ids['alpha']['project']], $this->spa)->assertSuccessful();
        $this->assertSame(2, ExternalRequest::query()->where('client_id', $this->alpha->id)->count(), 'a refused request was created anyway');
    }

    public function test_a_related_record_that_is_not_the_callers_reads_like_one_that_does_not_exist(): void
    {
        $this->signInAsAlphaMember();
        $type = RequestType::query()->value('key');

        $answers = [];
        foreach ([
            "another client's invoice" => ['invoice', $this->ids['beta']['invoice']],
            "another agency's invoice" => ['invoice', $this->ids['gamma']['invoice']],
            'an invoice that exists nowhere' => ['invoice', '999999999'],
            'a malformed invoice id' => ['invoice', 'not-a-uuid'],
        ] as $why => [$kind, $id]) {
            $res = $this->postJson('/api/v1/client/requests', ['type' => $type, 'message' => 'about that record', 'related' => ['kind' => $kind, 'id' => $id]], $this->spa);
            $answers[$why] = $this->seen($res);
        }

        $this->assertCount(1, array_unique($answers), 'a related record answered differently by whose it was: '.json_encode(array_map(fn (string $s): string => substr($s, 0, 80), $answers)));
    }

    public function test_writing_under_another_clients_slug_is_refused_like_an_unknown_slug_and_creates_nothing(): void
    {
        $token = $this->contactToken();
        $type = RequestType::query()->value('key');
        $requests = ExternalRequest::query()->withoutGlobalScopes()->count();
        $threads = MessageThread::query()->withoutGlobalScopes()->count();

        $attempts = [
            ['POST', 'requests', ['type' => $type, 'message' => 'filed under your slug']],
            ['POST', 'messages', ['subject' => 'Hello', 'body' => 'filed under your slug']],
        ];

        foreach ($attempts as [$method, $path, $body]) {
            $baseline = $this->withHeaders(['X-Client-Token' => $token, 'X-Client-Space' => 'no-such-space-anywhere'])->json($method, "/api/v1/client/{$path}", $body);

            foreach (['beta', 'gamma'] as $slug) {
                $res = $this->withHeaders(['X-Client-Token' => $token, 'X-Client-Space' => $slug])->json($method, "/api/v1/client/{$path}", $body);

                $this->assertSame($this->seen($baseline), $this->seen($res), "{$method} /client/{$path} under «{$slug}» was told apart from an unknown slug");
                $this->assertLessThan(500, $res->status());
            }
        }

        $this->assertSame($requests, ExternalRequest::query()->withoutGlobalScopes()->count(), 'a write under a foreign slug created a request');
        $this->assertSame($threads, MessageThread::query()->withoutGlobalScopes()->count(), 'a write under a foreign slug created a thread');
        $this->assertSame(0, ExternalRequest::query()->withoutGlobalScopes()->where('client_id', $this->beta->id)->where('objective', 'like', '%your slug%')->count());
    }

    public function test_a_caller_with_no_session_learns_nothing_from_the_slug_or_the_id(): void
    {
        $guesses = [
            'requests?space=beta', 'requests?space=gamma', 'requests?space=no-such-space-anywhere',
            'requests/'.$this->ids['beta']['reference'], 'requests/REQ-2026-ZZZZZZ',
            'invoices/'.$this->ids['beta']['invoice'], 'invoices/999999999',
            'quotes/'.$this->ids['gamma']['quote'], 'messages/'.$this->ids['beta']['thread'],
        ];

        $seen = [];
        foreach ($guesses as $path) {
            $res = $this->withHeaders(['X-Client-Space' => 'beta'])->getJson("/api/v1/client/{$path}");
            $seen[$path] = $this->seen($res);
            $this->assertSame(401, $res->status(), "/client/{$path} answered a caller with no session");
        }

        $this->assertCount(1, array_unique($seen), 'an unauthenticated caller was told something by the slug or id: '.json_encode($seen));
    }
}
