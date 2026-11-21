<?php

declare(strict_types=1);

namespace App\Domains\ContentStudio\Http\Controllers;

use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\ContentIntake\Services\IntakeService;
use App\Domains\ContentStudio\Models\BrandKit;
use App\Domains\ContentStudio\Models\LayoutFamily;
use App\Domains\ContentStudio\Models\ReadinessScore;
use App\Domains\ContentStudio\Models\StudioOutput;
use App\Domains\ContentStudio\Models\StudioSession;
use App\Domains\ContentStudio\Services\StudioSaver;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CONTENT-STUDIO-001 batch 8 — the studio's storage API.
 *
 * Reads need `content.view`; the brand kit needs `content.update`; sessions, saved outputs and ratings need
 * `content.create`. Every route also needs the project in the caller's reach ({@see IntakeService::reaches()}); tenant
 * isolation is the models' global scope, so another tenant's id is a 404. Nothing here renders, encodes, publishes or
 * launches: a saved output is an ordinary library asset in `draft`.
 */
final class ContentStudioController extends Controller
{
    private const COLOR = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function __construct(
        private readonly IntakeService $intake,
        private readonly StudioSaver $saver,
    ) {}

    public function families(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.view'), 403);

        $rows = LayoutFamily::query()->visible()->orderBy('key')->orderByDesc('version')->get()
            ->unique('key')->values()
            ->map(static fn (LayoutFamily $f): array => ['key' => $f->key, 'version' => $f->version, 'is_system' => $f->is_system, 'definition' => $f->definition])
            ->all();

        return ApiResponse::success($rows, 'Layout families.');
    }

    public function brandKit(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.view'), 403);
        $project = (string) $request->validate(['project' => ['required', 'uuid']])['project'];
        abort_unless($this->intake->reaches($request->user(), $project), 403);

        $kit = BrandKit::query()->where('project_id', $project)->first();

        return ApiResponse::success($kit === null ? null : $this->presentKit($kit), 'Brand kit.');
    }

    public function saveBrandKit(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.update'), 403);
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'font_display' => ['nullable', 'string', 'max:80'],
            'font_text' => ['nullable', 'string', 'max:80'],
            'colors' => ['nullable', 'array'],
            'colors.text_on_dark' => ['nullable', self::COLOR],
            'colors.text_on_light' => ['nullable', self::COLOR],
            'colors.accent' => ['nullable', self::COLOR],
            'colors.surface' => ['nullable', self::COLOR],
            'colors.surface_text' => ['nullable', self::COLOR],
            'tone' => ['nullable', 'string', 'max:300'],
            'visual_style' => ['nullable', 'string', 'max:300'],
            'forbidden_words' => ['nullable', 'array', 'max:100'],
            'forbidden_words.*' => ['string', 'max:60'],
            'source_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimetypes:image/png,image/svg+xml'],
        ]);
        abort_unless($this->intake->reaches($request->user(), $data['project_id']), 403);

        $kit = BrandKit::query()->firstOrNew(['project_id' => $data['project_id']]);
        $kit->fill(collect($data)->only(['font_display', 'font_text', 'colors', 'tone', 'visual_style', 'forbidden_words', 'source_url'])->all());
        $kit->updated_by = $request->user()->getKey();
        if ($request->file('logo') !== null) {
            $disk = $this->intake->disk();
            if ($kit->logo_path !== null) {
                Storage::disk($kit->logo_disk ?? $disk)->delete($kit->logo_path);
            }
            $kit->logo_disk = $disk;
            $kit->logo_path = $request->file('logo')->store('brand-kits/'.app(TenantContext::class)->tenantId().'/'.$data['project_id'], $disk);
        }
        $kit->save();

        return ApiResponse::success($this->presentKit($kit), 'Brand kit saved.');
    }

    public function openSession(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.create'), 403);
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'source_item_ids' => ['nullable', 'array', 'max:20'],
            'source_item_ids.*' => ['uuid'],
        ]);
        abort_unless($this->intake->reaches($request->user(), $data['project_id']), 403);
        $sources = array_values(array_unique($data['source_item_ids'] ?? []));
        // A source from another project (or another tenant, hidden by the scope) is refused, never silently kept.
        if ($sources !== [] && ContentIntakeItem::query()->where('project_id', $data['project_id'])->whereIn('id', $sources)->count() !== count($sources)) {
            return ApiResponse::error('A source asset is not in this project.', status: 422);
        }

        $session = StudioSession::query()->create([
            'project_id' => $data['project_id'],
            'user_id' => $request->user()->getKey(),
            'source_item_ids' => $sources,
        ]);

        return ApiResponse::success($this->presentSession($session), 'Studio session opened.', status: 201);
    }

    public function showSession(Request $request, StudioSession $session): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.view'), 403);
        abort_unless($this->intake->reaches($request->user(), $session->project_id), 403);

        return ApiResponse::success($this->presentSession($session), 'Studio session.');
    }

    public function saveOutput(Request $request, StudioSession $session): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.create'), 403);
        abort_unless($this->intake->reaches($request->user(), $session->project_id), 403);
        $kb = (int) config('content_intake.max_upload_kb', 51200);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$kb, 'mimetypes:video/mp4,image/png,image/jpeg,image/webp'],
            'kind' => ['required', 'in:video,image'],
            'aspect' => ['required', 'in:9x16,4x5,1x1,191x100'],
            'name' => ['required', 'string', 'max:255'],
            'family_key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9-]*$/'],
            'family_version' => ['required', 'integer', 'min:1'],
            'hook' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:200'],
            'cta' => ['nullable', 'string', 'max:60'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'has_audio' => ['nullable', 'boolean'],
            'ai_generated' => ['nullable', 'boolean'],
            'ai_provider' => ['nullable', 'string', 'max:40'],
            'params' => ['nullable', 'array'],
            'tokens' => ['nullable', 'array'],
            'tokens_source' => ['nullable', 'in:brand_kit,derived'],
            'concept_id' => ['nullable', 'uuid'],
            'concept_name' => ['nullable', 'string', 'max:255'],
            'source_item_id' => ['nullable', 'uuid'],
            'provider_target' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'destination_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'readiness' => ['required', 'array'],
            'readiness.score' => ['required', 'integer', 'min:0', 'max:100'],
            'readiness.hard_fail' => ['required', 'boolean'],
            'readiness.checks' => ['nullable', 'array'],
        ]);
        if (! empty($data['source_item_id']) && ! ContentIntakeItem::query()->where('project_id', $session->project_id)->whereKey($data['source_item_id'])->exists()) {
            return ApiResponse::error('The source asset is not in this project.', status: 422);
        }

        $refusals = $this->saver->refusals($session, $data);
        if ($refusals !== []) {
            return ApiResponse::error('The output breaks a hard rule and was not saved.', ['refusals' => $refusals], status: 422);
        }

        $output = $this->saver->save($request->user(), $session, $data, $request->file('file'));

        return ApiResponse::success($this->presentOutput($output), 'Output saved to the library.', status: 201);
    }

    public function rate(Request $request, StudioOutput $output): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('content.create'), 403);
        abort_unless($this->intake->reaches($request->user(), $output->project_id), 403);
        $data = $request->validate(['rating' => ['present', 'nullable', 'integer', 'in:-1,1']]);
        $output->forceFill(['rating' => $data['rating']])->save();

        return ApiResponse::success($this->presentOutput($output), 'Rating saved.');
    }

    /** @return array<string, mixed> */
    private function presentKit(BrandKit $kit): array
    {
        return [
            'id' => $kit->id, 'project_id' => $kit->project_id,
            'font_display' => $kit->font_display, 'font_text' => $kit->font_text, 'colors' => $kit->colors,
            'tone' => $kit->tone, 'visual_style' => $kit->visual_style, 'forbidden_words' => $kit->forbidden_words ?? [],
            'source_url' => $kit->source_url, 'has_logo' => $kit->logo_path !== null,
            'updated_at' => $kit->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentSession(StudioSession $session): array
    {
        $outputs = StudioOutput::query()->where('session_id', $session->id)->latest()->get();

        return [
            'id' => $session->id, 'project_id' => $session->project_id, 'stage' => $session->stage,
            'source_item_ids' => $session->source_item_ids ?? [],
            'outputs' => $outputs->map(fn (StudioOutput $o): array => $this->presentOutput($o))->all(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentOutput(StudioOutput $output): array
    {
        $score = ReadinessScore::query()->where('studio_output_id', $output->id)->latest()->first();

        return [
            'id' => $output->id, 'session_id' => $output->session_id, 'intake_item_id' => $output->intake_item_id,
            'concept_id' => $output->concept_id, 'kind' => $output->kind, 'aspect' => $output->aspect,
            'family_key' => $output->family_key, 'family_version' => $output->family_version,
            'ai_generated' => $output->ai_generated, 'rating' => $output->rating,
            'duration_ms' => $output->duration_ms, 'has_audio' => $output->has_audio,
            'readiness' => $score === null ? null : ['score' => $score->score, 'checks' => $score->checks],
            'created_at' => $output->created_at?->toIso8601String(),
        ];
    }
}
