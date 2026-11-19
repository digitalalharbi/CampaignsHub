<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Analysis;

use App\Domains\AI\Models\PlatformAiKey;
use App\Domains\ContentIntake\Models\ContentIntakeItem;

/**
 * CONTENT-INTAKE-001 — the AI-style creative analysis: description, hook, call to action, on-image text, tone and
 * brand fit. PARKED: AI Connect and the Anthropic/OpenAI keys are not provided, so this adapter has no provider client
 * and sends NOTHING to anyone. Its fields stay null («غير مفعّل») and its status says why. It never returns generated
 * text, in any state — wiring a provider is a separate change that fills {@see self::FIELDS} behind the same flag.
 */
final class AiProviderAnalyzer implements CreativeAnalyzer
{
    public const FIELDS = ['description', 'hook', 'cta', 'on_image_text', 'tone', 'brand_fit'];

    public function key(): string
    {
        return 'ai';
    }

    public function status(): string
    {
        if (! (bool) config('content_intake.ai.enabled', false)) {
            return 'awaiting_credentials';
        }

        return PlatformAiKey::query()->where('enabled', true)->exists() ? 'not_wired' : 'awaiting_credentials';
    }

    public function analyze(ContentIntakeItem $item): array
    {
        return [
            'state' => $this->status(),
            'fields' => array_fill_keys(self::FIELDS, null),
        ];
    }
}
