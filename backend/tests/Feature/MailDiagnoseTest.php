<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** MAIL-DIAGNOSE-001 — the read-only mail diagnosis shows the shape and the transport's error, never a secret or a full address. */
final class MailDiagnoseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_shape_and_the_last_failure_without_secrets(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.username' => 'user', 'mail.mailers.smtp.password' => 'super-secret-pw']);
        DB::table('mail_deliveries')->insert([
            'id' => (string) Str::uuid(), 'kind' => 'invitation', 'recipient' => 'digital@smartcode.sa', 'locale' => 'ar',
            'template' => 'mail.invitation', 'transport' => 'smtp', 'status' => 'failed', 'error' => 'Connection could not be established with host smtp.example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('mail:diagnose')
            ->expectsOutputToContain('host           : smtp.example.test')
            ->expectsOutputToContain('password set   : yes')
            ->expectsOutputToContain('d***@smartcode.sa  failed — Connection could not be established')
            ->doesntExpectOutputToContain('super-secret-pw')
            ->doesntExpectOutputToContain('digital@smartcode.sa')
            ->assertSuccessful();
    }
}
