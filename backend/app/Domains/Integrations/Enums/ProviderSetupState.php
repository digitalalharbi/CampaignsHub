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
 * - `credentials_verified` — the provider answered a real round trip and RECOGNISED our app. Next:
 *   a customer starts OAuth.
 *
 * ## Why there is no «production ready» here — PROBE-CLAIM-001
 *
 * There used to be, and it was reached by exactly this evidence: a complete key set, the environment
 * set to production, and a passing probe. The interface rendered it «جاهز للإنتاج».
 *
 * That is an overclaim, and the probe itself says so. It sends a deliberately impossible
 * authorisation code and reads the refusal; a refusal naming the GRANT proves the provider read our
 * client id and secret and accepted them. It proves nothing else. It does not prove the scopes were
 * approved, that the app passed review, that a developer token was granted, that any human has
 * consented, or that one ad account is reachable. Half the platforms here additionally require an
 * external approval that no request from this server can detect.
 *
 * So the ceiling for probe evidence is `credentials_verified` — «the provider recognised this app» —
 * and the honest next step is a customer starting OAuth. Anything beyond that is `LIVE_VERIFIED` in
 * `CLAUDE.md` terms and is earned by a real consent, a real discovery and a real first sync, none of
 * which this enum can observe.
 */
enum ProviderSetupState: string
{
    case NotConfigured = 'not_configured';
    case AwaitingCredentials = 'awaiting_credentials';
    case ReadyToConnect = 'ready_to_connect';
    case ConfigurationError = 'configuration_error';
    /*
     * Renamed from `production_ready`, deliberately keeping the STORED value stable.
     *
     * The old name is what made the overclaim easy to write; the value is what production rows and
     * any operator's saved filters already contain. Changing the meaning is the fix — changing the
     * string underneath live data would be a migration nobody asked for.
     */
    case CredentialsVerified = 'production_ready';

    /** Whether a workspace may be offered the connect button at all. */
    public function allowsConnecting(): bool
    {
        return $this === self::ReadyToConnect || $this === self::CredentialsVerified;
    }

    /**
     * Whether this state may be presented as an accomplishment rather than a step.
     *
     * Nothing here qualifies. Every state in this enum is about the platform's own configuration, and
     * the furthest it can reach is «a customer may now start OAuth». It is a method rather than a
     * comment so the interface cannot quietly decide otherwise.
     */
    public function isLiveVerified(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
