<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Gateways\PaymentGatewayCatalogue;
use App\Domains\Billing\Gateways\PaymentGatewaySettings;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan platform:console-evidence` — what the Super Admin console has on THIS install, read from inside it.
 *
 * Production evidence for the console's requirements without anyone signing in as the owner: the schema each feature needs, the
 * routes it serves, and the state it holds — counts and states only. No secret, key, link, email or customer figure is printed;
 * for a payment gateway it says configured / enabled / mode / where the keys come from, never a value. Read-only.
 */
final class PlatformConsoleEvidenceCommand extends Command
{
    protected $signature = 'platform:console-evidence {--json : Emit the report as JSON}';

    protected $description = 'Report the Super Admin console\'s schema, routes and state on this install (read-only, no secrets)';

    /** requirement => [method, uri] the console serves for it */
    private const ROUTES = [
        'SUPERADMIN-WORKSPACE-001' => [['POST', 'api/v1/admin/tenants'], ['POST', 'api/v1/admin/tenants/{tenant}/owner-invitation']],
        'SUPERADMIN-ACCESS-001' => [['POST', 'api/v1/admin/users/{user}/access']],
        'SUPERADMIN-SUPPORT-ACCESS-001' => [['POST', 'api/v1/admin/tenants/{tenant}/support-session'], ['DELETE', 'api/v1/admin/support-session']],
        'SUPERADMIN-FEATURES-001' => [['GET', 'api/v1/admin/capabilities'], ['PUT', 'api/v1/admin/tenants/{tenant}/capabilities/{module}'], ['PUT', 'api/v1/admin/plans/{plan}/capabilities/{module}']],
        'SUPERADMIN-MEMBERS-001' => [['POST', 'api/v1/admin/tenants/{tenant}/members/{user}']],
        'SUPERADMIN-CONSOLE-HOME-001' => [['GET', 'api/v1/admin/overview']],
        'PAY-GATEWAY-CONSOLE-001' => [['GET', 'api/v1/admin/settings/integrations/payments']],
    ];

    /** requirement => [table, column|null] */
    private const SCHEMA = [
        'SUPERADMIN-ACCESS-001' => [['users', 'sessions_valid_after']],
        'SUPERADMIN-SUPPORT-ACCESS-001' => [['support_sessions', null]],
        'SUPERADMIN-FEATURES-001' => [['platform_settings', 'disabled_capabilities'], ['capability_switches', null]],
        'PAY-GATEWAY-CONSOLE-001' => [['provider_configurations', 'tested_fingerprint'], ['platform_settings', 'payment_primary_gateway']],
    ];

    public function handle(Router $router, PaymentGatewaySettings $gateways): int
    {
        $served = collect($router->getRoutes()->getRoutes())
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' '.$r->uri(), $r->methods()))->flip();

        $requirements = [];
        foreach (array_unique([...array_keys(self::ROUTES), ...array_keys(self::SCHEMA)]) as $req) {
            $routes = array_map(fn ($p) => ['route' => $p[0].' '.$p[1], 'present' => $served->has($p[0].' '.$p[1])], self::ROUTES[$req] ?? []);
            $schema = array_map(fn ($p) => [
                'schema' => $p[1] === null ? $p[0] : $p[0].'.'.$p[1],
                'present' => $p[1] === null ? Schema::hasTable($p[0]) : Schema::hasColumn($p[0], $p[1]),
            ], self::SCHEMA[$req] ?? []);
            $checks = [...$routes, ...$schema];
            $requirements[$req] = ['deployed' => collect($checks)->every(fn ($c) => $c['present']), 'checks' => $checks];
        }

        $report = [
            'requirements' => $requirements,
            'state' => [
                'support_sessions' => Schema::hasTable('support_sessions') ? [
                    'total' => DB::table('support_sessions')->count(),
                    'live' => DB::table('support_sessions')->whereNull('ended_at')->where('expires_at', '>', now())->count(),
                ] : null,
                'platform_disabled_capabilities' => Schema::hasColumn('platform_settings', 'disabled_capabilities')
                    ? array_values((array) json_decode((string) DB::table('platform_settings')->value('disabled_capabilities'), true))
                    : null,
                'connections_needing_reconnect' => DB::table('provider_connections')->where('status', 'error')->count(),
                'payment_gateways' => $this->gateways($gateways),
                'console_actions_30d' => DB::table('audit_logs')->where('created_at', '>=', now()->subDays(30))
                    ->where(fn ($q) => $q->where('action', 'like', 'platform.%')->orWhere('action', 'like', 'support.%'))
                    ->selectRaw('action, count(*) as n')->groupBy('action')->orderBy('action')->pluck('n', 'action')->map(fn ($n) => (int) $n)->all(),
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($report['requirements'] as $req => $r) {
            $this->line(sprintf('  %-32s %s', $req, $r['deployed'] ? 'DEPLOYED' : 'MISSING'));
            foreach ($r['checks'] as $c) {
                $this->line(sprintf('      %-8s %s', $c['present'] ? 'ok' : 'missing', $c['route'] ?? $c['schema']));
            }
        }
        $this->line('  state: '.json_encode($report['state'], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> states only — never a key or token */
    private function gateways(PaymentGatewaySettings $settings): array
    {
        $out = ['primary' => ['gateway' => $settings->primary(), 'from_console' => $settings->primaryIsFromConsole()]];
        foreach (PaymentGatewayCatalogue::KEYS as $g) {
            $out[$g] = [
                'configured' => $settings->isConfigured($g),
                'enabled' => $settings->isEnabled($g),
                'mode' => $settings->mode($g),
                'source' => $settings->source($g),
            ];
        }

        return $out;
    }
}
