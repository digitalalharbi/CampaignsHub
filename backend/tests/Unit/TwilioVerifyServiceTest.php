<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Identity\Services\TwilioVerifyException;
use App\Domains\Identity\Services\TwilioVerifyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class TwilioVerifyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.twilio.account_sid' => 'AC_test',
            'services.twilio.auth_token' => 'secret-token',
            'services.twilio.verify_service_sid' => 'VA_test',
            'services.twilio.verify_channel' => 'sms',
        ]);
    }

    public function test_it_sends_and_checks_with_the_existing_verify_service(): void
    {
        Http::fake(fn (Request $request) => str_ends_with($request->url(), '/Verifications')
            ? Http::response(['status' => 'pending'], 201)
            : Http::response(['status' => 'approved'], 200));

        $service = app(TwilioVerifyService::class);

        $this->assertSame('pending', $service->send('+966501234567'));
        $this->assertSame('approved', $service->check('+966501234567', '123456'));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization')
            && ! str_contains($request->body(), 'secret-token'));
    }

    public function test_an_expired_or_deleted_twilio_challenge_is_not_approved(): void
    {
        Http::fake(['*' => Http::response(['code' => 20404], 404)]);

        $this->assertSame(
            'deleted',
            app(TwilioVerifyService::class)->check('+966501234567', '123456'),
        );
    }

    public function test_provider_errors_are_redacted_and_fail_closed(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response([
            'code' => 20500,
            'message' => 'response text that must not be logged',
        ], 500)]);

        try {
            app(TwilioVerifyService::class)->send('+966501234567');
            $this->fail('A provider failure must not be treated as a send.');
        } catch (TwilioVerifyException) {
            $this->assertTrue(true);
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode([$message, $context]);

            return $context['provider_code'] === '20500'
                && ! str_contains($encoded, '+966501234567')
                && ! str_contains($encoded, '123456')
                && ! str_contains($encoded, 'secret-token')
                && ! str_contains($encoded, 'response text');
        });
    }
}
