<?php

declare(strict_types=1);

namespace App\Domains\Integrations\MetaCandidate;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * META-CANDIDATE-001 — one Candidate app round trip, and the connection it produced.
 *
 * Platform-owned (no tenant), marked `profile = candidate` by a database constraint, and in a table no
 * customer surface reads. The token is encrypted AND hidden; `steps` is the checklist the console shows.
 *
 * @property array<int, array<string,mixed>>|null $steps
 */
final class MetaCandidateConnection extends Model
{
    use HasUuidKey;

    public const STEPS = ['oauth_start', 'consent', 'token_exchange', 'account_discovery', 'ads_read'];

    protected $fillable = [];

    protected $casts = [
        'encrypted_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'granted_scopes' => 'array',
        'discovered_accounts' => 'array',
        'steps' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = ['encrypted_token', 'credential_fingerprint'];

    public static function latestRun(): ?self
    {
        return self::query()->orderByDesc('started_at')->orderByDesc('created_at')->first();
    }

    /** @param array<string,mixed> $detail */
    public function recordStep(string $key, string $status, array $detail = []): void
    {
        $steps = [];

        foreach ($this->steps ?? [] as $step) {
            $steps[$step['key']] = $step;
        }

        $steps[$key] = ['key' => $key, 'status' => $status, 'at' => now()->toIso8601String(), ...$detail];

        // Always in the flow's order, with the ones not reached yet shown as pending.
        $this->steps = array_map(
            static fn (string $k) => $steps[$k] ?? ['key' => $k, 'status' => 'pending'],
            self::STEPS,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /** @return array<string,mixed> what the console and the command may show — never the token */
    public function toReport(): array
    {
        return [
            'id' => $this->id,
            'profile' => $this->profile,
            'status' => $this->status,
            'app_id_hint' => $this->app_id_hint,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'token_expires_at' => $this->token_expires_at?->toIso8601String(),
            'granted_scopes' => $this->granted_scopes ?? [],
            'discovered_accounts' => $this->discovered_accounts ?? [],
            'steps' => $this->steps ?? array_map(static fn ($k) => ['key' => $k, 'status' => 'pending'], self::STEPS),
        ];
    }
}
