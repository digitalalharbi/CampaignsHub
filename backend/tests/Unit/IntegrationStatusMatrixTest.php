<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Services\IntegrationStatusResolver;
use App\Domains\Integrations\Status\AccountFacts;
use App\Domains\Integrations\Status\ConnectionFacts;
use App\Domains\Integrations\Status\StatusText;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * INTEG-STATUS-MODEL-001 (contract §1.13, §3) — «input facts → five statuses», as a table.
 *
 * No database: the resolver's core takes plain facts and a clock, so every row below is a proof about the
 * RULES. Each row names the five states it expects, in the order authorisation · binding · sync · freshness ·
 * error, and optionally the detail/action that makes the state actionable.
 *
 * The rows are chosen so that for every pair of statuses there is a row where they DIFFER — that is the
 * test of «one status never implies another». A case where the answers happened to agree proves nothing.
 */
final class IntegrationStatusMatrixTest extends TestCase
{
    private const NOW = '2026-11-05 12:00:00';

    private static function now(): Carbon
    {
        return Carbon::parse(self::NOW);
    }

    private static function ago(string $spec): Carbon
    {
        return Carbon::parse(self::NOW)->modify('-'.$spec);
    }

    private static function ahead(string $spec): Carbon
    {
        return Carbon::parse(self::NOW)->modify('+'.$spec);
    }

    /**
     * @return array<string, array{0: ConnectionFacts, 1: AccountFacts, 2: list<string>, 3: array<string,mixed>}>
     */
    public static function accountCases(): array
    {
        $meta = static fn (array $o = []): ConnectionFacts => new ConnectionFacts(...array_merge(['provider' => 'meta', 'scopes' => ['ads_read']], $o));
        $bound = static fn (array $o = []): AccountFacts => new AccountFacts(...array_merge([
            'binding' => 'active', 'lastSyncedAt' => self::ago('1 hour'), 'latestRunStatus' => 'success', 'latestRunAt' => self::ago('1 hour'),
        ], $o));

        return [
            '01 healthy bound account' => [$meta(), $bound(), ['connected', 'bound', 'succeeded', 'fresh', 'none'], []],

            '02 discovered only: not linked, nothing synced, freshness unknown' => [
                $meta(), new AccountFacts(binding: 'none'),
                ['connected', 'discovered_unbound', 'never_started', 'unknown', 'none'],
                ['sync.detail' => 'not_linked', 'binding.action.code' => 'assign_project'],
            ],

            '03 connected is not synced: bound, never synced' => [
                $meta(), new AccountFacts(binding: 'active'),
                ['connected', 'bound', 'never_started', 'unknown', 'none'],
                ['freshness.detail' => 'never_synced', 'sync.action.code' => 'run_first_sync'],
            ],

            '04 expired token, no refresh path (TikTok): reauth, and the last sync is still reported as it was' => [
                new ConnectionFacts(provider: 'tiktok', tokenExpiresAt: self::ago('1 day')), $bound(),
                ['reauth_required', 'bound', 'succeeded', 'fresh', 'error'],
                ['authorisation.detail' => 'token_expired', 'error.category' => 'token_expired', 'error.action.code' => 'reauthorize', 'error.action.label_ar' => 'أعد المصادقة'],
            ],

            '05 expired token but refreshable (Snapchat) inside the grace: expiring, not an error' => [
                new ConnectionFacts(provider: 'snapchat', tokenExpiresAt: self::ago('2 hours')), $bound(),
                ['expiring', 'bound', 'succeeded', 'fresh', 'none'],
                ['authorisation.detail' => 'refresh_pending'],
            ],

            '06 refresh overdue (Snapchat, three days past): stops believing a refresh is coming' => [
                new ConnectionFacts(provider: 'snapchat', tokenExpiresAt: self::ago('3 days')), $bound(),
                ['reauth_required', 'bound', 'succeeded', 'fresh', 'error'],
                ['error.category' => 'refresh_overdue'],
            ],

            '07 non-refreshable token lapsing in three days: expiring' => [
                new ConnectionFacts(provider: 'tiktok', tokenExpiresAt: self::ahead('3 days')), $bound(),
                ['expiring', 'bound', 'succeeded', 'fresh', 'none'],
                ['authorisation.action.code' => 'reauthorize_before_expiry'],
            ],

            '08 token valid for a month: connected' => [
                new ConnectionFacts(provider: 'tiktok', tokenExpiresAt: self::ahead('30 days')), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                [],
            ],

            '09 refresh failure on this connection (status error)' => [
                new ConnectionFacts(provider: 'snapchat', status: 'error', hasError: true), $bound(['lastSyncedAt' => self::ago('3 days'), 'latestRunStatus' => 'failed']),
                ['reauth_required', 'bound', 'failed', 'stale', 'error'],
                ['authorisation.detail' => 'refresh_failed', 'error.category' => 'refresh_failed'],
            ],

            '10 revoked connection: bindings were cut, history is kept' => [
                new ConnectionFacts(provider: 'meta', status: 'revoked'), $bound(['binding' => 'inactive']),
                ['revoked', 'detached', 'paused', 'fresh', 'error'],
                ['error.category' => 'connection_revoked', 'binding.detail' => 'connection_revoked'],
            ],

            '11 removed connection (disconnected)' => [
                new ConnectionFacts(provider: 'meta', status: 'disconnected'), $bound(['binding' => 'inactive']),
                ['removed', 'detached', 'paused', 'fresh', 'attention'],
                ['error.category' => 'connection_removed', 'error.action.code' => 'reconnect'],
            ],

            '12 connection row gone altogether' => [
                ConnectionFacts::missing('meta'), $bound(),
                ['removed', 'bound', 'succeeded', 'fresh', 'attention'],
                [],
            ],

            '13 revoked scope: consent never carried a read scope (Meta)' => [
                $meta(['scopes' => ['public_profile']]), $bound(),
                ['reauth_required', 'bound', 'succeeded', 'fresh', 'error'],
                ['authorisation.detail' => 'scope_missing'],
            ],

            '14 insights refused by the provider (Meta #200)' => [
                $meta(['insightsDeniedAt' => self::ago('1 day')]), $bound(),
                ['reauth_required', 'bound', 'succeeded', 'fresh', 'error'],
                ['error.category' => 'insights_not_authorised', 'error.action.code' => 'grant_read_scope'],
            ],

            '15 API-key provider: a past token date is never consulted, nothing to refresh' => [
                new ConnectionFacts(provider: 'openai_ads', tokenExpiresAt: self::ago('30 days')), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                ['authorisation.detail' => 'api_key_has_no_expiry', 'profile.refresh' => false, 'profile.token_expiry' => false, 'profile.auth' => 'api_key'],
            ],

            '16 API-key provider, key rejected: api_key_invalid, never «refresh failed»' => [
                new ConnectionFacts(provider: 'openai_ads', status: 'error', hasError: true), $bound(['latestRunStatus' => 'failed', 'lastErrorCategory' => 'provider_error', 'lastFailureAt' => self::ago('10 minutes')]),
                ['api_key_invalid', 'bound', 'failed', 'fresh', 'error'],
                ['error.category' => 'api_key_invalid', 'error.action.code' => 'replace_api_key', 'error.action.label_ar' => 'استبدل مفتاح API بمفتاح صالح'],
            ],

            '17 partial pages: rows placed, some unplaced (partial_mapping)' => [
                $meta(), $bound(['latestRunStatus' => 'partial_mapping', 'lastErrorCategory' => 'unmapped_rows']),
                ['connected', 'bound', 'partial', 'partial', 'attention'],
                ['error.category' => 'unmapped_rows', 'error.action.code' => 'review_unmapped'],
            ],

            '18 partial_mapping run without a category still is not a success' => [
                $meta(), $bound(['latestRunStatus' => 'partial_mapping']),
                ['connected', 'bound', 'partial', 'partial', 'none'],
                [],
            ],

            '19 sync running while the link is cut: both said, neither hides the other' => [
                $meta(), $bound(['binding' => 'inactive', 'openRunStartedAt' => self::ago('10 minutes')]),
                ['connected', 'deselected', 'running', 'fresh', 'none'],
                ['sync.detail' => 'running_after_detach'],
            ],

            '20 abandoned running row is not believed when there is an older success' => [
                $meta(), $bound(['openRunStartedAt' => self::ago('3 hours')]),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                [],
            ],

            '21 abandoned running row with nothing else is a failure, not «never started»' => [
                $meta(), new AccountFacts(binding: 'active', openRunStartedAt: self::ago('3 hours')),
                ['connected', 'bound', 'failed', 'unknown', 'error'],
                ['sync.detail' => 'abandoned_run', 'error.category' => 'abandoned_run'],
            ],

            '22 currency unknown: sync ran, money WITHHELD, never fresh and never a zero' => [
                $meta(), $bound(['lastErrorCategory' => 'currency_unknown']),
                ['connected', 'bound', 'partial', 'withheld', 'attention'],
                ['error.category' => 'currency_unknown', 'error.action.code' => 'set_currency', 'freshness.detail' => 'currency_unknown'],
            ],

            '23 awaiting assignment on an active link: nothing fetched, withheld, paused' => [
                $meta(), $bound(['latestRunStatus' => 'awaiting_assignment', 'lastErrorCategory' => 'awaiting_assignment', 'lastSyncedAt' => null]),
                ['connected', 'bound', 'paused', 'withheld', 'attention'],
                ['error.action.code' => 'assign_project'],
            ],

            '24 access lost on this account only: connection is fine' => [
                $meta(), $bound(['accessLostAt' => self::ago('2 days'), 'lastSyncedAt' => self::ago('3 days'), 'latestRunStatus' => 'success']),
                ['access_lost', 'bound', 'succeeded', 'stale', 'error'],
                ['error.category' => 'access_lost', 'error.action.code' => 'restore_access'],
            ],

            '25 this account was not granted insights (account-level refusal)' => [
                $meta(), $bound(['lastErrorCategory' => 'insights_not_authorised']),
                ['scope_not_granted', 'bound', 'failed', 'withheld', 'error'],
                [],
            ],

            '26 provider error on healthy authorisation' => [
                $meta(), $bound(['latestRunStatus' => 'failed', 'lastErrorCategory' => 'provider_error', 'lastFailureAt' => self::ago('20 minutes')]),
                ['connected', 'bound', 'failed', 'fresh', 'error'],
                ['error.category' => 'provider_error', 'error.action.code' => 'retry_sync', 'sync.last_failure_at' => self::ago('20 minutes')->toIso8601String()],
            ],

            '27 API call succeeded but the data is three days old: succeeded ≠ fresh' => [
                $meta(), $bound(['lastSyncedAt' => self::ago('3 days'), 'latestRunAt' => self::ago('3 days')]),
                ['connected', 'bound', 'succeeded', 'stale', 'none'],
                ['freshness.detail' => 'older_than_threshold'],
            ],

            '28 provider answered with no rows: succeeded and fresh, said as such, not red' => [
                $meta(), $bound(['latestRunStatus' => 'no_data']),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                ['sync.detail' => 'no_rows_returned', 'freshness.detail' => 'no_rows_returned'],
            ],

            '29 sync switched off on the link' => [
                $meta(), $bound(['syncEnabled' => false]),
                ['connected', 'bound', 'paused', 'fresh', 'none'],
                ['sync.action.code' => 'resume_sync'],
            ],

            '30 deselected account keeps its history, says so' => [
                $meta(), $bound(['binding' => 'inactive', 'lastSyncedAt' => self::ago('4 days')]),
                ['connected', 'deselected', 'paused', 'stale', 'none'],
                ['freshness.detail' => 'binding_inactive'],
            ],

            '31 platform credentials absent: a failure the operator, not the customer, fixes' => [
                $meta(), $bound(['lastErrorCategory' => 'awaiting_credentials', 'lastSyncedAt' => null, 'latestRunStatus' => 'failed']),
                ['connected', 'bound', 'failed', 'unknown', 'attention'],
                ['error.action.code' => 'add_platform_credentials'],
            ],

            '32 polling-only provider states polling freshness' => [
                new ConnectionFacts(provider: 'tiktok'), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                ['freshness.mode' => 'polling', 'profile.webhooks' => 'polling_only'],
            ],

            '33 webhook provider (Meta) is webhook plus polling' => [
                $meta(), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                ['freshness.mode' => 'webhook_and_polling'],
            ],

            '34 commerce store (Salla) goes through the same object' => [
                new ConnectionFacts(provider: 'salla', tokenExpiresAt: self::ahead('20 days')), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                ['profile.kind' => 'commerce'],
            ],

            '35 measurement property (GA4) never synced' => [
                new ConnectionFacts(provider: 'ga4'), new AccountFacts(binding: 'active'),
                ['connected', 'bound', 'never_started', 'unknown', 'none'],
                ['profile.kind' => 'measurement'],
            ],

            '36 a provider the catalogue does not know does not crash and claims no capabilities' => [
                new ConnectionFacts(provider: 'future_x'), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'none'],
                ['profile.auth' => null, 'profile.refresh' => null],
            ],

            '37 discovery refused at the provider: an attention error with a prerequisite action' => [
                $meta(['discoveryBlockedReason' => 'provider_project_not_approved']), $bound(),
                ['connected', 'bound', 'succeeded', 'fresh', 'attention'],
                ['error.category' => 'provider_project_not_approved', 'error.action.code' => 'fix_provider_prerequisite'],
            ],

            '38 revoked authorisation does not rewrite the sync or freshness facts' => [
                new ConnectionFacts(provider: 'meta', status: 'revoked'), $bound(['lastSyncedAt' => self::ago('10 minutes')]),
                ['revoked', 'bound', 'succeeded', 'fresh', 'error'],
                [],
            ],
        ];
    }

    /**
     * @param  list<string>  $expected  authorisation, binding, sync, freshness, error
     * @param  array<string,mixed>  $extra  dotted-path => expected value
     */
    #[DataProvider('accountCases')]
    public function test_facts_resolve_to_five_independent_statuses(ConnectionFacts $c, AccountFacts $a, array $expected, array $extra): void
    {
        $out = (new IntegrationStatusResolver)->resolveAccount($c, $a, self::now());

        $got = array_map(static fn (string $d): string => $out['statuses'][$d]['state'], ['authorisation', 'binding', 'sync', 'freshness', 'error']);
        $this->assertSame($expected, $got);

        foreach ($extra as $path => $value) {
            $this->assertSame($value, Arr::get([...$out['statuses'], 'profile' => $out['profile']], $path), $path);
        }
    }

    /**
     * The properties no row may violate, over every row at once — the contract's «never» sentences.
     */
    #[DataProvider('accountCases')]
    public function test_no_row_ever_breaks_the_honesty_invariants(ConnectionFacts $c, AccountFacts $a): void
    {
        $s = (new IntegrationStatusResolver)->resolveAccount($c, $a, self::now())['statuses'];

        // Five statuses, exactly these keys, nothing combined.
        $this->assertSame(['authorisation', 'binding', 'sync', 'freshness', 'error'], array_keys($s));
        $this->assertArrayNotHasKey('healthy', $s);

        foreach ($s as $dimension => $status) {
            foreach (['state', 'label_ar', 'label_en', 'since', 'as_of', 'detail', 'action'] as $key) {
                $this->assertArrayHasKey($key, $status, "$dimension.$key");
            }
            $this->assertContains($status['state'], [...StatusText::states($dimension)], "$dimension has an unlisted state");
            if ($status['action'] !== null) {
                $this->assertNotSame('', $status['action']['label_ar']);
                $this->assertNotSame('', $status['action']['label_en']);
            }
        }

        // Discovered is not linked: an unlinked account never claims a sync in progress it does not own, nor fresh data.
        if ($s['binding']['state'] === 'discovered_unbound' && $s['sync']['state'] !== 'running') {
            $this->assertSame('never_started', $s['sync']['state']);
            $this->assertSame('unknown', $s['freshness']['state']);
        }

        // Withheld or partial data is never `fresh`, and a withheld/partial status always has a remediation or explanation.
        if ($s['sync']['state'] === 'partial') {
            $this->assertNotSame('fresh', $s['freshness']['state']);
        }

        // An error status is either none, or names a category and an action a person can take.
        if ($s['error']['state'] !== 'none') {
            $this->assertNotNull($s['error']['category']);
            $this->assertNotNull($s['error']['action']);
        }

        // Never a secret, never a provider's own words.
        $json = strtolower((string) json_encode($s));
        foreach (['"access_token"', '"refresh_token"', '"api_key"', '"secret"', '"password"', 'bearer '] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    /** One status never implies another: for every ordered pair of dimensions some row has them disagree. */
    public function test_every_pair_of_statuses_can_differ(): void
    {
        $healthy = ['connected', 'bound', 'succeeded', 'fresh', 'none'];
        $rows = array_map(static fn (array $case): array => $case[2], self::accountCases());

        foreach ([0, 1, 2, 3, 4] as $i) {
            foreach ([0, 1, 2, 3, 4] as $j) {
                // An unlinked or detached resource is not syncing BY DEFINITION; that is a definition, not an inference.
                if ($i === $j || ($i === 2 && $j === 1)) {
                    continue;
                }
                $found = false;
                foreach ($rows as $row) {
                    // dimension i is at its «good» value while j is not — i.e. j's trouble is not implied away by i.
                    if ($row[$i] === $healthy[$i] && $row[$j] !== $healthy[$j]) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "no row shows dimension $i fine while dimension $j is not");
            }
        }
    }

    /* ------------------------------------------------------------------ connection level */

    private function acct(array $o = []): AccountFacts
    {
        return new AccountFacts(...array_merge([
            'binding' => 'active', 'lastSyncedAt' => self::ago('1 hour'), 'latestRunStatus' => 'success',
        ], $o));
    }

    public function test_one_failed_account_out_of_three_is_partial_with_the_counts_beside_it(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(
            new ConnectionFacts(provider: 'meta', scopes: ['ads_read']),
            [$this->acct(), $this->acct(), $this->acct(['latestRunStatus' => 'failed', 'lastErrorCategory' => 'provider_error'])],
            17, self::now(),
        )['statuses'];

        $this->assertSame('connected', $r['authorisation']['state']);
        $this->assertSame('bound', $r['binding']['state']);
        $this->assertSame(3, $r['binding']['selected']);
        $this->assertSame(17, $r['binding']['discovered']);
        $this->assertSame('partial', $r['sync']['state']);
        $this->assertSame('some_accounts_failed', $r['sync']['detail']);
        $this->assertSame(['succeeded' => 2, 'failed' => 1], $r['sync']['counts']);
        $this->assertSame('error', $r['error']['state']);
        $this->assertSame(1, $r['error']['affected_accounts']);
    }

    public function test_every_selected_account_failing_is_failed(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(
            new ConnectionFacts(provider: 'meta'),
            [$this->acct(['lastErrorCategory' => 'provider_error']), $this->acct(['lastErrorCategory' => 'provider_error'])],
            2, self::now(),
        )['statuses'];

        $this->assertSame('failed', $r['sync']['state']);
    }

    public function test_every_selected_account_losing_access_means_the_grant_is_gone(): void
    {
        $lost = $this->acct(['accessLostAt' => self::ago('1 day')]);
        $r = (new IntegrationStatusResolver)->resolveConnection(new ConnectionFacts(provider: 'meta'), [$lost, $lost], 2, self::now())['statuses'];

        $this->assertSame('reauth_required', $r['authorisation']['state']);
        $this->assertSame('all_selected_accounts_lost_access', $r['authorisation']['detail']);
        $this->assertSame('bound', $r['binding']['state']);
    }

    public function test_one_account_losing_access_does_not_condemn_the_connection(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(
            new ConnectionFacts(provider: 'meta'),
            [$this->acct(['accessLostAt' => self::ago('1 day')]), $this->acct()],
            2, self::now(),
        )['statuses'];

        $this->assertSame('connected', $r['authorisation']['state']);
        $this->assertSame(1, $r['authorisation']['accounts_without_access']);
        $this->assertSame('access_lost', $r['error']['category']);
    }

    public function test_seventeen_discovered_none_selected_is_discovered_unbound_and_never_synced(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(new ConnectionFacts(provider: 'meta'), [], 17, self::now())['statuses'];

        $this->assertSame(['connected', 'discovered_unbound', 'never_started', 'unknown', 'none'], array_map(static fn ($s) => $s['state'], array_values($r)));
        $this->assertSame('choose_accounts', $r['binding']['action']['code']);
    }

    public function test_nothing_discovered_says_so(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(new ConnectionFacts(provider: 'meta'), [], 0, self::now())['statuses'];

        $this->assertSame('none_discovered', $r['binding']['state']);
    }

    public function test_a_revoked_connection_with_its_bindings_cut_is_detached_not_discovered(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(new ConnectionFacts(provider: 'meta', status: 'revoked'), [], 4, self::now())['statuses'];

        $this->assertSame('revoked', $r['authorisation']['state']);
        $this->assertSame('detached', $r['binding']['state']);
        $this->assertSame('connection_revoked', $r['error']['category']);
    }

    public function test_one_withheld_account_makes_the_connection_withheld_and_as_of_is_the_stalest(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(
            new ConnectionFacts(provider: 'meta'),
            [$this->acct(), $this->acct(['lastErrorCategory' => 'currency_unknown', 'lastSyncedAt' => self::ago('5 hours')])],
            2, self::now(),
        )['statuses'];

        $this->assertSame('withheld', $r['freshness']['state']);
        $this->assertSame(self::ago('5 hours')->toIso8601String(), $r['freshness']['as_of']);
        $this->assertSame('currency_unknown', $r['error']['category']);
        $this->assertSame('partial', $r['sync']['state']);
    }

    public function test_an_account_still_running_makes_the_connection_running_without_hiding_a_failure_elsewhere(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(
            new ConnectionFacts(provider: 'meta'),
            [$this->acct(['openRunStartedAt' => self::ago('5 minutes')]), $this->acct(['lastErrorCategory' => 'provider_error'])],
            2, self::now(),
        )['statuses'];

        $this->assertSame('running', $r['sync']['state']);
        $this->assertSame('error', $r['error']['state']);
    }

    public function test_connection_level_authorisation_error_outranks_account_level_errors(): void
    {
        $r = (new IntegrationStatusResolver)->resolveConnection(
            new ConnectionFacts(provider: 'tiktok', tokenExpiresAt: self::ago('1 day')),
            [$this->acct(['lastErrorCategory' => 'unmapped_rows', 'latestRunStatus' => 'partial_mapping'])],
            1, self::now(),
        )['statuses'];

        $this->assertSame('token_expired', $r['error']['category']);
        $this->assertSame('partial', $r['sync']['state']);
    }

    public function test_the_legacy_connection_word_is_unchanged_by_token_dates(): void
    {
        // The shipped `connection_state` never consulted token dates; the new object does. They are allowed to differ.
        $expired = new ConnectionFacts(provider: 'tiktok', tokenExpiresAt: self::ago('1 day'));

        $this->assertSame('CONNECTED', IntegrationStatusResolver::connectionTruth($expired));
        $this->assertSame('REVOKED', IntegrationStatusResolver::connectionTruth(new ConnectionFacts(provider: 'meta', status: 'disconnected')));
        $this->assertSame('REAUTH_REQUIRED', IntegrationStatusResolver::connectionTruth(new ConnectionFacts(provider: 'meta', status: 'error')));
        $this->assertSame('REAUTH_REQUIRED', IntegrationStatusResolver::connectionTruth(new ConnectionFacts(provider: 'meta', scopes: ['public_profile'])));
        $this->assertSame('REAUTH_REQUIRED', IntegrationStatusResolver::connectionTruth(new ConnectionFacts(provider: 'meta', scopes: ['ads_read']), authorisationLost: true));
    }

    public function test_every_action_code_has_arabic_and_english_text(): void
    {
        foreach (StatusText::actionCodes() as $code) {
            $a = StatusText::action($code);
            $this->assertNotNull($a);
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $a['label_ar'], $code);
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $a['label_en'], $code);
        }
    }
}
