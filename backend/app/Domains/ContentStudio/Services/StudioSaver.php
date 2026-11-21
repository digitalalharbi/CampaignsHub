<?php

declare(strict_types=1);

namespace App\Domains\ContentStudio\Services;

use App\Domains\ContentIntake\Services\IntakeService;
use App\Domains\ContentStudio\Models\BrandKit;
use App\Domains\ContentStudio\Models\CreativeConcept;
use App\Domains\ContentStudio\Models\DesignTokenSet;
use App\Domains\ContentStudio\Models\LayoutFamily;
use App\Domains\ContentStudio\Models\ReadinessScore;
use App\Domains\ContentStudio\Models\StudioOutput;
use App\Domains\ContentStudio\Models\StudioSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * CONTENT-STUDIO-001 batch 8 — saves one studio output.
 *
 * The browser renders, scores and encodes; the server only re-checks the hard rules it can check without rendering and
 * stores. The file becomes an ordinary library asset through {@see IntakeService::create()}, so it gets the same
 * pre-checks, approval path and history as any upload, and nothing here publishes or launches anything.
 */
final class StudioSaver
{
    /** The agency standard: no video longer than 15 seconds. */
    public const MAX_VIDEO_MS = 15_000;

    public function __construct(private readonly IntakeService $intake) {}

    /**
     * The hard rules the server can verify on its own. An empty list means the output may be saved.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function refusals(StudioSession $session, array $data): array
    {
        $out = [];
        if (($data['kind'] ?? null) === 'video' && (int) ($data['duration_ms'] ?? 0) > self::MAX_VIDEO_MS) {
            $out[] = 'video_too_long';
        }
        if (filter_var($data['readiness']['hard_fail'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $out[] = 'hard_check_failed';
        }
        $copy = array_filter([(string) ($data['hook'] ?? ''), (string) ($data['message'] ?? ''), (string) ($data['cta'] ?? '')]);
        if (trim((string) ($data['cta'] ?? '')) === '') {
            $out[] = 'cta_missing';
        }
        foreach ($copy as $text) {
            if (preg_match('/(^|\s)#\S/u', $text)) {
                $out[] = 'hashtag';
            }
            if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', $text)) {
                $out[] = 'emoji';
            }
        }
        $forbidden = (array) (BrandKit::query()->where('project_id', $session->project_id)->value('forbidden_words') ?? []);
        foreach ($forbidden as $word) {
            $word = trim((string) $word);
            if ($word !== '' && mb_stripos(implode(' ', $copy), $word) !== false) {
                $out[] = 'forbidden_word';
                break;
            }
        }
        $name = mb_strtolower(trim((string) ($data['name'] ?? '')));
        if ($name === '' || str_starts_with($name, 'untitled')) {
            $out[] = 'name_missing';
        }
        $family = LayoutFamily::query()->visible()->where('key', $data['family_key'] ?? '')->where('version', (int) ($data['family_version'] ?? 0))->exists();
        if (! $family) {
            $out[] = 'unknown_family';
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, StudioSession $session, array $data, UploadedFile $file): StudioOutput
    {
        return DB::transaction(function () use ($actor, $session, $data, $file): StudioOutput {
            $item = $this->intake->create($actor, $session->project_id, [
                'name' => $data['name'],
                'headline' => $data['hook'] ?? null,
                'copy_text' => $data['message'] ?? null,
                'cta' => $data['cta'] ?? null,
                'placement' => ($data['aspect'] ?? '9x16') === '9x16' ? 'story' : 'feed',
                'provider' => $data['provider_target'] ?? null,
                'objective' => $data['objective'] ?? null,
                'destination_url' => $data['destination_url'] ?? null,
            ], $file);

            $concept = null;
            if (! empty($data['concept_id'])) {
                $concept = CreativeConcept::query()->where('project_id', $session->project_id)->findOrFail($data['concept_id']);
            } elseif (! empty($data['concept_name'])) {
                $concept = CreativeConcept::query()->firstOrCreate(
                    ['project_id' => $session->project_id, 'name' => $data['concept_name']],
                    ['created_by' => $actor->getKey()],
                );
            }

            $tokens = null;
            if (! empty($data['tokens'])) {
                $tokens = DesignTokenSet::query()->create([
                    'project_id' => $session->project_id,
                    'concept_id' => $concept?->id,
                    'tokens' => $data['tokens'],
                    'source' => ($data['tokens_source'] ?? 'derived') === 'brand_kit' ? 'brand_kit' : 'derived',
                ]);
            }

            $output = StudioOutput::query()->create([
                'project_id' => $session->project_id,
                'session_id' => $session->id,
                'concept_id' => $concept?->id,
                'design_tokens_id' => $tokens?->id,
                'intake_item_id' => $item->id,
                'source_item_id' => $data['source_item_id'] ?? null,
                'kind' => $data['kind'],
                'aspect' => $data['aspect'] ?? '9x16',
                'family_key' => $data['family_key'],
                'family_version' => (int) $data['family_version'],
                'params' => $data['params'] ?? null,
                'provider' => $data['ai_provider'] ?? null,
                'ai_generated' => filter_var($data['ai_generated'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'duration_ms' => isset($data['duration_ms']) ? (int) $data['duration_ms'] : null,
                'has_audio' => filter_var($data['has_audio'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'created_by' => $actor->getKey(),
            ]);

            ReadinessScore::query()->create([
                'project_id' => $session->project_id,
                'studio_output_id' => $output->id,
                'score' => (int) ($data['readiness']['score'] ?? 0),
                'hard_fail' => false,
                'checks' => (array) ($data['readiness']['checks'] ?? []),
            ]);

            $session->forceFill(['stage' => 'evaluate'])->save();

            return $output;
        });
    }
}
