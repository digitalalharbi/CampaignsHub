<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FILES-LIBRARY-001 — the library stopped at five hundred and said nothing.
 *
 * Both halves of this endpoint — request attachments and report exports — ended in `->limit(500)`
 * with no count anywhere in the response. A workspace holding eight hundred files was shown five
 * hundred of them, and nothing on screen distinguished that from a workspace that holds five
 * hundred. The reader's conclusion is «these are my files», and it is wrong.
 *
 * A cap is not the defect; a SILENT cap is. The limit stays — an unbounded file library is the
 * defect the limit was put there to prevent — and the response now states what the cap left out, so
 * the page can say so.
 */
final class FilesLibraryTruncationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $client;

    private User $user;

    private int $typeId;

    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($this->user, $this->tenant, Portal::Agency);
        $this->user->assignRole($role);

        $this->typeId = (int) (DB::table('request_types')->value('id') ?? DB::table('request_types')->insertGetId([
            'key' => 'k-'.uniqid(), 'module' => 'paid_media', 'name_ar' => 'نوع', 'name_en' => 'Type',
        ]));
        $this->statusId = (int) (DB::table('request_statuses')->value('id') ?? DB::table('request_statuses')->insertGetId([
            'key' => 'new-'.uniqid(), 'name_ar' => 'جديد', 'name_en' => 'New',
            'sort' => 0, 'is_terminal' => false, 'pauses_sla' => false, 'is_client_visible' => true,
        ]));
    }

    private function requestWithFiles(int $files): void
    {
        $requestId = (string) Str::ulid();
        DB::table('external_requests')->insert([
            'id' => $requestId, 'tenant_id' => $this->tenant->id, 'reference' => 'REQ-'.uniqid(),
            'client_id' => $this->client->id, 'type_id' => $this->typeId, 'status_id' => $this->statusId,
            'contact_name' => 'C', 'contact_email' => 'c@a.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = [];
        for ($i = 0; $i < $files; $i++) {
            $rows[] = [
                'request_id' => $requestId, 'disk' => 'local', 'path' => 'p/'.uniqid(),
                'original_name' => 'file-'.$i.'.pdf', 'mime' => 'application/pdf', 'size' => 10,
                'is_client_visible' => true, 'created_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('request_files')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function library(): array
    {
        return (array) $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/files/library')->assertOk()->json('data');
    }

    public function test_a_library_within_the_cap_reports_nothing_withheld(): void
    {
        $this->requestWithFiles(3);

        $data = $this->library();

        $this->assertCount(3, $data['files']);
        $this->assertSame(3, $data['files_total']);
        $this->assertSame(0, $data['files_withheld']);
    }

    /**
     * Past the cap the response says how many it is NOT showing.
     *
     * Without this the page cannot tell the reader anything — it has five hundred rows and no way to
     * know whether that is the library or a slice of it.
     */
    public function test_past_the_cap_it_states_what_it_left_out(): void
    {
        $this->requestWithFiles(505);

        $data = $this->library();

        $this->assertCount(500, $data['files'], 'the cap itself stays — an unbounded library is what it prevents');
        $this->assertSame(505, $data['files_total'], 'and the reader is told how many exist');
        $this->assertSame(5, $data['files_withheld']);
    }
}
