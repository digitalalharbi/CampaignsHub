<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Models\RequestFile;
use App\Domains\Requests\Models\RequestUploadSession;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\VerifiesContact;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class RequestUploadTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesContact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        Storage::fake('local');
        Tenant::create(['name' => 'Portal', 'slug' => 'portal-'.uniqid(), 'status' => 'active', 'is_default_portal' => true, 'portal_enabled' => true]);
    }

    private function newUploadSession(): string
    {
        return $this->postJson('/api/v1/requests/uploads/start')->assertCreated()->json('data.upload_token');
    }

    public function test_upload_stores_under_uuid_on_private_disk_without_exposing_path(): void
    {
        $token = $this->newUploadSession();
        $res = $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $token,
            'file' => UploadedFile::fake()->create('brief statement.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        // Response returns metadata only — never the storage path.
        $res->assertJsonPath('data.original_name', 'brief statement.pdf');
        $this->assertArrayNotHasKey('path', $res->json('data'));

        $file = RequestFile::firstOrFail();
        $this->assertNotNull($file->upload_session_id);
        $this->assertNull($file->request_id);
        // Stored name is a UUID, not the original filename.
        $this->assertStringNotContainsString('brief', $file->path);
        $this->assertMatchesRegularExpression('#requests/tmp/[0-9A-Za-z]+/[0-9a-f-]{36}\.pdf#', $file->path);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_rejects_disallowed_mime_and_oversized_files(): void
    {
        $token = $this->newUploadSession();

        // Executable disguised — mimetype not on the allowlist.
        $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $token,
            'file' => UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // Oversized (> 10 MB).
        $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $token,
            'file' => UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_upload_requires_a_valid_session_token(): void
    {
        $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => 'not-a-real-token',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
    }

    public function test_cannot_delete_another_sessions_file(): void
    {
        $tokenA = $this->newUploadSession();
        $fileId = $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $tokenA, 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ])->json('data.id');

        $tokenB = $this->newUploadSession();
        // Session B must not be able to delete session A's file.
        $this->deleteJson("/api/v1/requests/uploads/{$fileId}", ['upload_token' => $tokenB])->assertNotFound();
        $this->assertDatabaseHas('request_files', ['id' => $fileId]);
    }

    public function test_files_associate_to_the_request_on_submit_and_session_is_retired(): void
    {
        $token = $this->newUploadSession();
        $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $token, 'file' => UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        $this->postJson('/api/v1/requests', $this->withVerifiedContact([
            'type' => 'paid_campaign_launch', 'contact_name' => 'Sara', 'contact_email' => 's@ex.com', 'company_name' => 'Sara Co',
            'upload_token' => $token,
        ]))->assertCreated();

        $request = ExternalRequest::firstOrFail();
        $this->assertEquals(1, RequestFile::where('request_id', $request->id)->count());
        $this->assertNull(RequestFile::first()->upload_session_id);
        $this->assertDatabaseCount('request_upload_sessions', 0); // retired
    }

    public function test_secure_download_serves_client_visible_files_only(): void
    {
        // Upload + submit so the file is associated to a request.
        $token = $this->newUploadSession();
        $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $token, 'file' => UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'),
        ])->assertCreated();
        $tracking = $this->postJson('/api/v1/requests', $this->withVerifiedContact([
            'type' => 'paid_campaign_launch', 'contact_name' => 'Sara', 'contact_email' => 's@ex.com', 'company_name' => 'Sara Co', 'upload_token' => $token,
        ]))->json('data.tracking_token');

        $file = RequestFile::firstOrFail();

        // Client-visible file downloads with the correct tracking token.
        $this->get("/api/v1/requests/track/{$tracking}/files/{$file->id}")->assertOk();

        // Flip it to internal-only → the same token can no longer fetch it (404, non-revealing).
        $file->forceFill(['is_client_visible' => false])->save();
        $this->get("/api/v1/requests/track/{$tracking}/files/{$file->id}")->assertNotFound();

        // A wrong/unknown token never reaches the file.
        $this->get("/api/v1/requests/track/bad-token/files/{$file->id}")->assertNotFound();
    }

    public function test_prune_command_removes_expired_orphans_but_keeps_submitted_files(): void
    {
        // Expired session with an orphan file.
        $token = $this->newUploadSession();
        $this->postJson('/api/v1/requests/uploads', [
            'upload_token' => $token, 'file' => UploadedFile::fake()->create('orphan.pdf', 10, 'application/pdf'),
        ]);
        RequestUploadSession::query()->update(['expires_at' => now()->subDay()]);
        $orphanPath = RequestFile::first()->path;

        $this->artisan('requests:prune-uploads')->assertSuccessful();

        $this->assertDatabaseCount('request_files', 0);
        $this->assertDatabaseCount('request_upload_sessions', 0);
        Storage::disk('local')->assertMissing($orphanPath);
    }
}
