<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Enums;

/**
 * PROVCFG-001 — what is true about a PROVIDER'S SETUP on this install, in five honest states.
 *
 * This is deliberately NOT `ConnectorStatus`. That enum answers "what happened to one workspace's
 * connection"; this one answers "has the platform operator finished the system-level configuration
 * that makes connecting possible at all". A workspace cannot be `connected` while the provider behind
 * it is `not_configured`, and conflating the two is how a customer ends up pressing a connect button
 * that could never have worked.
 *
 * The states are ordered by how far the setup has got, and each names the ONE thing that moves it on:
 *
 * - `not_configured`   — nothing stored. Next: enter the provider's keys.
 * - `awaiting_credentials` — some required values present, others missing. Next: the named missing ones.
 * - `ready_to_connect` — every required value present, no successful round trip yet. Next: press Test.
 * - `configuration_error` — the last real round trip was refused. Next: read the refusal, fix, re-test.
 * - `production_ready` — every required value present, environment is production, and the last round
 *   trip SUCCEEDED. This is the only state that may be described as ready for customers.
 *
 * ## `production_ready` is earned by a round trip, never by filling in a form
 *
 * A complete set of keys proves somebody typed four strings. It does not prove the app was approved,
 * the developer token was granted, the redirect URI matches, or the account has any access. Only a
 * successful call does — which is why `ready_to_connect` exists as a separate state rather than being
 * folded into the last one.
 */
enum ProviderSetupState: string
{
    case NotConfigured = 'not_configured';
    case AwaitingCredentials = 'awaiting_credentials';
    case ReadyToConnect = 'ready_to_connect';
    case ConfigurationError = 'configuration_error';
    case ProductionReady = 'production_ready';

    /** Whether a workspace may be offered the connect button at all. */
    public function allowsConnecting(): bool
    {
        return $this === self::ReadyToConnect || $this === self::ProductionReady;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
