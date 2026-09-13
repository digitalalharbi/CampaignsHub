<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use Illuminate\Console\Command;

/**
 * GADS-CLOUD-PROJECT-001 — which Google Cloud project this install's Google Ads access follows.
 *
 * Since Google sunset developer tokens on 2026-09-09, production access belongs to the Google Cloud
 * project that owns the OAuth client, not to a token. So «is this install approved for production» is
 * really «does its OAuth client belong to the project that was approved», and until now nothing in the
 * product could answer that — which is why an owner holding an Explorer-approved project could still
 * watch every discovery answer 403 with no way to see the mismatch.
 *
 * A Google OAuth client id is `<PROJECT_NUMBER>-<random>.apps.googleusercontent.com`, and the leading
 * segment IS the Cloud project number. So the answer is derivable from the client id alone.
 *
 * ## What this deliberately does not print
 *
 * The client SECRET and the developer token are never read. A project number and a client-id suffix are
 * identifiers, not credentials — the suffix is shown truncated anyway, because an identifier nobody
 * needs in full is one nobody should have to redact before pasting a diagnosis into a ticket.
 */
final class GoogleAdsAccessCommand extends Command
{
    protected $signature = 'integrations:google-access';

    protected $description = 'Show which Google Cloud project the Google Ads OAuth client belongs to, and each connection\'s last discovery outcome.';

    public function handle(): int
    {
        $creds = PlatformCredentials::for('google');
        $clientId = $creds->get('client_id');

        if ($clientId === null) {
            $this->error('No GOOGLE_ADS_CLIENT_ID is configured, so this install has no Google Ads access to describe.');

            return self::FAILURE;
        }

        $project = $this->projectNumberOf($clientId);

        $this->line('OAuth client       : '.$this->safeClientId($clientId));
        $this->line('Cloud project #    : '.($project ?? 'UNRECOGNISED — the client id is not in Google\'s <project>-<random> form'));
        $this->line('Developer token    : '.($creds->get('developer_token') === null ? 'absent (optional since 2026-09-09)' : 'present (sent, but grants nothing)'));
        $this->newLine();
        $this->comment('Production access follows the Cloud project above. Compare that number with the project');
        $this->comment('showing Explorer/Basic access on its Google Ads API page; if they differ, this install is');
        $this->comment('authorising against a project that was never approved, whatever the other project says.');
        $this->newLine();

        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'google')->get();

        if ($connections->isEmpty()) {
            $this->line('No Google connections exist yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['connection', 'status', 'last attempt', 'last success', 'blocked reason'],
            $connections->map(fn (ProviderConnection $c): array => [
                (string) $c->getKey(),
                (string) $c->status,
                optional($c->last_discovery_attempted_at)->toDateTimeString() ?? '—',
                optional($c->last_discovery_succeeded_at)->toDateTimeString() ?? 'never',
                $c->discovery_blocked_reason ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** The leading segment of a Google OAuth client id is its Cloud project number. */
    private function projectNumberOf(string $clientId): ?string
    {
        return preg_match('/^(\d+)-/', $clientId, $m) === 1 ? $m[1] : null;
    }

    /**
     * Enough of the client id to recognise it, and no more.
     *
     * The project number is the part that answers the question, so it stays whole; the random half is
     * truncated because it identifies the client without needing to be complete, and a diagnosis that has
     * to be redacted before it can be shared is one nobody shares.
     */
    private function safeClientId(string $clientId): string
    {
        [$project, $rest] = array_pad(explode('-', $clientId, 2), 2, '');

        return $project.'-'.mb_substr($rest, 0, 6).'…'.(str_contains($rest, '.') ? '.apps.googleusercontent.com' : '');
    }
}
