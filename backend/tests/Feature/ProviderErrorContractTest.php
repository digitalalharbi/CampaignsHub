<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INTEGRATION-ERROR-CONTRACT-001 — the database stops refusing to say why a provider said no.
 *
 * The owner met this as `SQLSTATE[22001] value too long for varchar(255)`. The failure mode is
 * particularly bad: the exception replaces the provider's answer, so the screen reports a PostgreSQL
 * error where it should report «grant ads_read». The product tells you about itself instead of about
 * the thing you have to fix.
 */
final class ProviderErrorContractTest extends TestCase
{
    use RefreshDatabase;

    /** A real Meta refusal with its identifiers attached — the shape #422 made these sentences take. */
    private function metaRefusal(): string
    {
        return 'Meta Marketing API could not return daily insights: (#200) Ad account owner has NOT grant '
            .'ads_management or ads_read permission, refer https://developers.facebook.com/docs/marketing-api/'
            .'overview/authorization#access · code 200 · subcode 1870034 · trace AbCdEfGhIjKlMnOpQrStUvWxYz '
            .'· request req_0123456789abcdef · log 9876543210';
    }

    public function test_a_provider_refusal_with_its_identifiers_survives_storage(): void
    {
        $refusal = $this->metaRefusal();
        $this->assertGreaterThan(255, mb_strlen($refusal), 'the fixture no longer exceeds the old column width');

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        // Opened through the vault: `credential_id` is NOT NULL, so a hand-built row is not a connection.
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $tenant->getKey(),
            provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'meta',
        );
        $connection->forceFill(['status' => 'error', 'last_error' => ProviderErrorText::forStorage($refusal)])->save();

        // Whole, not truncated — every identifier an operator needs is still there.
        $stored = (string) $connection->fresh()->last_error;
        foreach (['(#200)', 'ads_read', 'code 200', 'subcode 1870034', 'AbCdEfGhIjKlMnOpQrStUvWxYz', 'req_0123456789abcdef'] as $needle) {
            $this->assertStringContainsString($needle, $stored);
        }
    }

    /** The same for a sync run's own error column, which had the identical width and the identical bug. */
    public function test_a_sync_run_records_the_whole_refusal(): void
    {
        $refusal = $this->metaRefusal();
        $id = (string) Str::uuid();
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);

        DB::table('integration_sync_runs')->insert([
            'id' => $id, 'tenant_id' => $tenant->getKey(), 'type' => 'insights', 'status' => 'failed',
            'records' => 0, 'error' => ProviderErrorText::forStorage($refusal),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertStringContainsString('subcode 1870034', (string) DB::table('integration_sync_runs')->where('id', $id)->value('error'));
    }

    /**
     * Provider errors quote the request, and a request carries a token. These rows are read by
     * operators, printed by the diagnostics command and shown in the sync log, so a token stored here
     * is a token on a screen.
     */
    public function test_a_credential_quoted_in_an_error_is_not_stored(): void
    {
        $withToken = 'Request failed: GET /v21.0/act_123/insights?access_token=EAABsecretTOKENvalue123&fields=spend '
            .'headers {"Authorization":"Bearer EAABsecretTOKENvalue123","X-Trace":"keep-me"} client_secret=shhh';

        $stored = (string) ProviderErrorText::forStorage($withToken);

        $this->assertStringNotContainsString('EAABsecretTOKENvalue123', $stored);
        $this->assertStringNotContainsString('shhh', $stored);
        // The shape of the failure survives — redaction is not deletion.
        $this->assertStringContainsString('/v21.0/act_123/insights', $stored);
        $this->assertStringContainsString('keep-me', $stored);
        $this->assertStringContainsString('[redacted]', $stored);
    }

    /** An empty or absent reason stays absent rather than becoming an empty string nobody can read. */
    public function test_nothing_to_say_is_stored_as_nothing(): void
    {
        $this->assertNull(ProviderErrorText::forStorage(null));
        $this->assertNull(ProviderErrorText::forStorage('   '));
    }

    /**
     * A pathological body is bounded, and says that it was.
     *
     * The ceiling exists for an HTML error page or a stack trace, not for provider sentences — so it
     * is far above any of them, and when it does fire the text admits it rather than ending mid-word
     * and reading like the provider stopped talking.
     */
    public function test_a_pathological_body_is_bounded_and_says_so(): void
    {
        $stored = (string) ProviderErrorText::forStorage(str_repeat('x', 9000));

        $this->assertLessThan(9000, mb_strlen($stored));
        $this->assertStringContainsString('truncated', $stored);
    }

    /**
     * No writer may invent its own bound again.
     *
     * Five call sites had five different limits — 250, 250, 250, 300 and 1000 — and the 1000 is what
     * threw, because the column held 255. The bound belongs to the contract; a call site that trims
     * before calling it is how they drift apart a second time.
     */
    public function test_no_call_site_trims_a_provider_error_itself(): void
    {
        $offenders = [];

        /*
         * Scoped to the two domains that write the PROVIDER error columns.
         *
         * The first version swept all of `app` and flagged a subscription notification and a report
         * export — both of which trim their own `error`, both of which write a `text` column of
         * their own, and neither of which is a provider refusal. A guard that fails for unrelated
         * code teaches people to widen its allow-list until it stops meaning anything.
         */
        foreach ([...$this->phpFiles(dirname(__DIR__, 2).'/app/Domains/Integrations'), ...$this->phpFiles(dirname(__DIR__, 2).'/app/Domains/Commerce')] as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
                if (preg_match('/[\'"](last_error|error)[\'"]\s*=>.*(mb_substr|substr|Str::limit)/', $line) === 1) {
                    $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $file).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'a provider error is trimmed at the call site instead of by ProviderErrorText: '.implode(', ', $offenders));
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        $this->assertGreaterThan(20, count($files), 'the sweep found almost no files — it is not looking where it thinks');

        return $files;
    }
}
