<?php

declare(strict_types=1);

namespace App\Domains\Agents\Engine;

use App\Domains\AI\Models\AIProviderCredential;
use App\Domains\AI\Models\PlatformAiKey;
use App\Domains\Projects\Models\Project;
use RuntimeException;

/**
 * AGENTS-ENGINE-001 — which provider and key an agent uses for a project.
 *
 * Read from the tenant's own `AIProviderCredential`s, most specific first (project, then client, then
 * tenant), active only, and only where the credential allows the `agents` feature (or does not restrict
 * features). No key in config. AI-PLATFORM-KEY-001 (Owner decision 2026-11-16): a workspace with no key of its own uses the
 * platform key the operator stored in the admin console, counted per person against the plan's allowance
 * (`AgentConversation::guardSpend`); with neither, a clear refusal.
 */
final class ModelClientFactory
{
    /** Providers whose client exists in this product. The others are configurable and say so. */
    public const SUPPORTED = ['anthropic', 'openai', 'gemini'];

    /**
     * @param  string|null  $preferredModel  AGENTS-ASSISTANT-001 — the workspace's chosen assistant model; used with an Anthropic key
     *                                       when the key allows it (or names no models), otherwise the role's configured model.
     * @return array{client: ModelClient, provider: string, model: string, token_limit: int|null, credential_id: string, platform?: bool}
     */
    public function for(Project $project, string $role, ?string $preferredModel = null): array
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
            $platform = PlatformAiKey::active();
            if ($platform === null) {
                throw new RuntimeException('no_ai_credential');
            }

            return $this->forPlatform($platform, $role, $preferredModel);
        }

        $model = (string) (config("agents.models.{$credential->provider}.{$role}") ?? '');
        $allowed = (array) ($credential->allowed_models ?? []);
        if ($preferredModel !== null && $credential->provider === 'anthropic' && ($allowed === [] || in_array($preferredModel, $allowed, true))) {
            $model = $preferredModel;
        }
        if ($allowed !== [] && ! in_array($model, $allowed, true)) {
            $model = (string) $allowed[0];
        }

        // No model name is invented: a provider whose role has no configured model uses the key's first
        // allowed model, and with neither the request is refused by name.
        if ($model === '') {
            throw new RuntimeException('model_not_configured');
        }

        $client = $this->clientFor($credential);

        $credential->forceFill(['last_used_at' => now()])->save();

        return ['client' => $client, 'provider' => $credential->provider, 'model' => $model,
            'token_limit' => $credential->monthly_token_limit !== null ? (int) $credential->monthly_token_limit : null,
            'credential_id' => (string) $credential->getKey()];
    }

    /**
     * AI-PLATFORM-KEY-001 — the platform key: the role's configured model (the workspace's chosen assistant model on Anthropic).
     *
     * @return array{client: ModelClient, provider: string, model: string, token_limit: int|null, credential_id: string, platform: bool}
     */
    private function forPlatform(PlatformAiKey $key, string $role, ?string $preferredModel): array
    {
        $model = (string) (config("agents.models.{$key->provider}.{$role}") ?? '');
        if ($preferredModel !== null && $key->provider === 'anthropic') {
            $model = $preferredModel;
        }
        if ($model === '') {
            throw new RuntimeException('model_not_configured');
        }

        if (app()->bound(ModelClient::class)) {
            $client = app(ModelClient::class);
        } elseif (config('agents.model_driver') === 'sandbox' && ! app()->environment('production')) {
            $client = app(SandboxModelClient::class);
        } else {
            $client = match ($key->provider) {
                'openai' => new OpenAiModelClient($key->revealSecret()),
                'gemini' => new GeminiModelClient($key->revealSecret()),
                default => new AnthropicModelClient($key->revealSecret()),
            };
        }

        return ['client' => $client, 'provider' => $key->provider, 'model' => $model, 'token_limit' => null, 'credential_id' => 'platform', 'platform' => true];
    }

    /** The client for one stored key. A test binds `ModelClient` in the container, so no test reaches a provider. */
    public function clientFor(AIProviderCredential $credential): ModelClient
    {
        if (app()->bound(ModelClient::class)) {
            return app(ModelClient::class);
        }
        // AGENTS-SANDBOX-001 — the local/E2E model; never in production, whatever the variable says.
        if (config('agents.model_driver') === 'sandbox' && ! app()->environment('production')) {
            return app(SandboxModelClient::class);
        }

        return match ($credential->provider) {
            'openai' => new OpenAiModelClient($credential->revealSecret(), $credential->organization_id, $credential->project_external_id),
            'gemini' => new GeminiModelClient($credential->revealSecret()),
            default => new AnthropicModelClient($credential->revealSecret()),
        };
    }
}
