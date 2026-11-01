<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\Projects\Models\Project;
use RuntimeException;

/**
 * AGENTS-ENGINE-001 — which provider and key an agent uses for a project.
 *
 * Read from the tenant's own `AIProviderCredential`s, most specific first (project, then client, then
 * tenant), active only, and only where the credential allows the `agents` feature (or does not restrict
 * features). No key in config, no fallback to a platform key: a tenant without a key gets a clear refusal.
 */
final class ModelClientFactory
{
    /** Providers whose client exists in this product. The others are configurable and say so. */
    public const SUPPORTED = ['anthropic', 'openai', 'gemini'];

    /** @return array{client: ModelClient, provider: string, model: string, token_limit: int|null, credential_id: string} */
    public function for(Project $project, string $role): array
    {
        $credential = AIProviderCredential::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('project_id', $project->getKey())
                ->orWhere(fn ($w) => $w->whereNull('project_id')->where('client_workspace_id', $project->client_workspace_id))
                ->orWhere(fn ($w) => $w->whereNull('project_id')->whereNull('client_workspace_id')))
            ->get()
            ->filter(fn (AIProviderCredential $c): bool => $c->allowed_features === null || $c->allowed_features === [] || in_array('agents', (array) $c->allowed_features, true))
            ->sortByDesc(fn (AIProviderCredential $c): int => $c->project_id !== null ? 2 : ($c->client_workspace_id !== null ? 1 : 0))
            ->first(fn (AIProviderCredential $c): bool => in_array($c->provider, self::SUPPORTED, true));

        if ($credential === null) {
            throw new RuntimeException('no_ai_credential');
        }

        $model = (string) (config("agents.models.{$credential->provider}.{$role}") ?? '');
        $allowed = (array) ($credential->allowed_models ?? []);
        if ($allowed !== [] && ! in_array($model, $allowed, true)) {
            $model = (string) $allowed[0];
        }

        // No model name is invented: a provider whose role has no configured model uses the key's first
        // allowed model, and with neither the request is refused by name.
        if ($model === '') {
            throw new RuntimeException('model_not_configured');
        }

        $client = app()->bound(ModelClient::class)
            ? app(ModelClient::class)
            : match ($credential->provider) {
                'openai' => new OpenAiModelClient($credential->revealSecret(), $credential->organization_id, $credential->project_external_id),
                'gemini' => new GeminiModelClient($credential->revealSecret()),
                default => new AnthropicModelClient($credential->revealSecret()),
            };

        $credential->forceFill(['last_used_at' => now()])->save();

        return ['client' => $client, 'provider' => $credential->provider, 'model' => $model,
            'token_limit' => $credential->monthly_token_limit !== null ? (int) $credential->monthly_token_limit : null,
            'credential_id' => (string) $credential->getKey()];
    }
}
