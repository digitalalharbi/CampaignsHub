<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\ClientWorkspaces\Enums\WorkspaceMode;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;

/**
 * CP-VISIBILITY-001 and CP-WRITE-DENY-001 — what a client workspace's portal may show, and what it may do.
 *
 * ## Two contracts, deliberately separate
 *
 * VISIBILITY answers «may this client SEE it». CAPABILITIES answer «may this client CHANGE anything».
 * Showing a client its campaigns grants no power over them: `campaigns = visible` with `campaign_writes
 * = denied` is the normal Managed state. Folding the two into one switch is how a portal account
 * becomes campaign management by accident.
 *
 * ## Enforced where the data is read
 *
 * The portal controller asks this class before it builds a payload, so a switched-off section is
 * refused (403) or absent from the response — never merely hidden by a screen that still received
 * it. The same map is handed to the front end so navigation matches, but the front end is not the
 * lock.
 *
 * ## Stored once
 *
 * `client_workspaces.settings.portal.visibility` holds ONLY the agency's explicit choices; anything
 * not chosen takes the default for the workspace's mode. A new key added here later therefore reaches
 * every existing workspace at its safe default without a migration.
 */
final class PortalVisibility
{
    /** Everything a client may be shown, in the order the settings screen lists it. */
    public const KEYS = [
        'dashboard', 'projects', 'campaigns', 'content', 'reports', 'recommendations',
        'spend', 'revenue', 'platform_evidence', 'requests', 'invoices', 'messages', 'files',
        'campaign_names', 'ad_account_names',
    ];

    /** Everything a client could be allowed to CHANGE. Off for Managed, and never implied by visibility. */
    public const CAPABILITIES = [
        'integration_admin', 'campaign_writes', 'pause_launch', 'budget_writes', 'team_admin',
        'create_requests', 'approve_content', 'internal_notes', 'margin_profit', 'technical_identifiers',
    ];

    /**
     * The safe default for a mode.
     *
     * Managed (the agency runs everything): the client sees the business picture and talks to the
     * agency, but money, account names and platform evidence are an agency decision. Collaborative and
     * Self-Service widen what a client may DO only through explicit capability grants, never here.
     *
     * @return array<string, bool>
     */
    public static function defaultVisibility(WorkspaceMode|string $mode): array
    {
        $on = ['dashboard', 'projects', 'campaigns', 'content', 'reports', 'recommendations', 'requests', 'invoices', 'messages', 'files', 'campaign_names'];

        return array_replace(array_fill_keys(self::KEYS, false), array_fill_keys($on, true));
    }

    /** @return array<string, bool> */
    public static function defaultCapabilities(WorkspaceMode|string $mode = WorkspaceMode::Managed): array
    {
        $base = array_fill_keys(self::CAPABILITIES, false);

        // Asking and approving are what a managed client does; everything else is the agency's.
        // Collaborative and Self-Service are NOT granted anything automatically either: a mode names an
        // operating relationship, and each power is switched on by the agency, one at a time — so the
        // default is the same for every mode.
        $base['create_requests'] = true;
        $base['approve_content'] = true;

        return $base;
    }

    /**
     * The effective visibility: the mode's defaults, overridden only by what the agency chose.
     *
     * @return array<string, bool>
     */
    public static function visibilityFor(ClientWorkspace $workspace): array
    {
        $chosen = (array) data_get($workspace->settings, 'portal.visibility', []);
        $effective = self::defaultVisibility((string) $workspace->mode);

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $chosen)) {
                $effective[$key] = (bool) $chosen[$key];
            }
        }

        return $effective;
    }

    /** @return array<string, bool> */
    public static function capabilitiesFor(ClientWorkspace $workspace): array
    {
        $chosen = (array) data_get($workspace->settings, 'portal.capabilities', []);
        $effective = self::defaultCapabilities((string) $workspace->mode);

        foreach (self::CAPABILITIES as $key) {
            if (array_key_exists($key, $chosen)) {
                $effective[$key] = (bool) $chosen[$key];
            }
        }

        // These two are the agency's own and can never be handed to a client, whatever was stored.
        $effective['internal_notes'] = false;
        $effective['margin_profit'] = false;

        return $effective;
    }

    public static function allows(ClientWorkspace $workspace, string $key): bool
    {
        return (self::visibilityFor($workspace)[$key] ?? false) === true;
    }

    /**
     * Keep only the keys this class knows, as booleans — what a settings write may store.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function sanitiseVisibility(array $input): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public static function sanitiseCapabilities(array $input): array
    {
        $out = [];
        foreach (self::CAPABILITIES as $key) {
            if (in_array($key, ['internal_notes', 'margin_profit'], true)) {
                continue;
            }
            if (array_key_exists($key, $input)) {
                $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }
}
