<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A new UNCOMMENTED key in deploy/backend.production.env.example blocks every deploy.
 *
 * The deploy's env-contract step refuses to go on when the live env file lacks a key the example declares — by design, so a
 * required setting cannot go missing silently. The cost: a PR that adds an optional key uncommented stops production for every
 * later merge until someone edits the server's file. It happened with SUBSCRIPTION_LIFECYCLE_ENABLED (#897) and the Twilio keys
 * (#877). This test fails at PR time instead.
 *
 * If the new key is optional (it has a default in config/), add it COMMENTED (`# KEY=value`). If production genuinely cannot run
 * without it, add it to the live env file first, then add it to KNOWN below in the same PR.
 */
final class EnvContractNewKeyTest extends TestCase
{
    private const KNOWN = [
        'AD_PLATFORM_REDIRECT_BASE',
        'API_URL',
        'APPLICATION_URL',
        'APP_BRAND_NAME',
        'APP_DEBUG',
        'APP_DOMAIN',
        'APP_ENV',
        'APP_FAKER_LOCALE',
        'APP_FALLBACK_LOCALE',
        'APP_KEY',
        'APP_LOCALE',
        'APP_MARKETING_URL',
        'APP_NAME',
        'APP_URL',
        'AUTH_LOGIN_THROTTLE',
        'BROADCAST_CONNECTION',
        'CACHE_STORE',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PASSWORD',
        'DB_PORT',
        'DB_SSLMODE',
        'DB_TIMEZONE',
        'DB_USERNAME',
        'DOCS_URL',
        'FEATURE_INFLUENCERS_UGC',
        'FILESYSTEM_DISK',
        'FRONTEND_URL',
        'GOOGLE_ADS_CLIENT_ID',
        'GOOGLE_ADS_CLIENT_SECRET',
        'GOOGLE_ADS_DEVELOPER_TOKEN',
        'GOOGLE_CLIENT_ID',
        'GOOGLE_CLIENT_SECRET',
        'GOOGLE_REDIRECT_URI',
        'LOG_CHANNEL',
        'LOG_LEVEL',
        'LOG_STACK',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_HOST',
        'MAIL_MAILER',
        'MAIL_PASSWORD',
        'MAIL_PORT',
        'MAIL_REPLY_TO',
        'MAIL_SCHEME',
        'MAIL_USERNAME',
        'MOYASAR_PUBLISHABLE_KEY',
        'MOYASAR_SECRET_KEY',
        'MOYASAR_WEBHOOK_TOKEN',
        'NOTIFICATION_EMAIL_PROVIDER',
        'QUEUE_CONNECTION',
        'REDIS_CLIENT',
        'REDIS_HOST',
        'REDIS_PASSWORD',
        'REDIS_PORT',
        'REPORTS_CHROMIUM_ENABLED',
        'REPORTS_CHROMIUM_PATH',
        'REPORTS_NODE_BIN',
        'REPORTS_PRINT_APP_URL',
        'REPORTS_PYTHON_BIN',
        'REPORTS_RENDERER_VERSION',
        'REPORTS_REQUIRE_BASE',
        'SANCTUM_STATEFUL_DOMAINS',
        'SESSION_DOMAIN',
        'SESSION_DRIVER',
        'SESSION_ENCRYPT',
        'SESSION_LIFETIME',
        'SESSION_PATH',
        'SESSION_SAME_SITE',
        'SESSION_SECURE_COOKIE',
        'STATUS_URL',
        'STRIPE_PUBLISHABLE_KEY',
        'STRIPE_SECRET_KEY',
        'STRIPE_WEBHOOK_SECRET',
        'SUBSCRIPTION_PROVIDER',
        'SUPPORT_EMAIL',
    ];

    public function test_no_new_uncommented_key_reaches_the_production_env_contract(): void
    {
        $path = \dirname(__DIR__, 3).'/deploy/backend.production.env.example';
        self::assertFileExists($path);
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents($path), $m);

        $new = array_values(array_diff(array_unique($m[1]), self::KNOWN));

        self::assertSame([], $new, 'New uncommented key(s) in the production env example: '.implode(', ', $new)
            .'. The deploy refuses to run until the live env file has them. Comment them out if optional (see this test\'s docblock).');
    }
}
