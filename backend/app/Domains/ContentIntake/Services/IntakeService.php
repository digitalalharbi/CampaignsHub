<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Services;

use App\Domains\ContentIntake\Models\ContentIntakeItem;
use App\Domains\Projects\Access\ReachableProjects;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CONTENT-INTAKE-001 — creating and editing an item. It stores the file on the private disk, measures it with the
 * bounded probes, and runs the analysis. It never changes `status` after creation (that is IntakeTransitions), and it
 * edits only an item the team can still change (draft or needs changes).
 */
final class IntakeService
{
    public function __construct(
        private readonly MediaProbe $probe,
        private readonly IntakeAnalysis $analysis,
        private readonly IntakeTransitions $transitions,
        private readonly ReachableProjects $reach,
    ) {}

    public function disk(): string
    {
        return (string) config('content_intake.disk', 'local');
    }

    /**
     * Narrow a query on a project-id column to what this user reaches: the project ceiling (`ProjectAbilities`, same as
     * the creative library) AND the membership's client ceiling. Fails closed.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Query\Builder  $query
     */
    public function constrain(mixed $query, ?User $user, string $column = 'project_id'): void
    {
        $this->reach->constrain($query, $user, $column, 'campaigns.view');
        $clients = app(ClientScopeResolver::class)->reachableClientIds($user);
        if ($clients !== null) {
            $query->whereIn($column, static function ($sub) use ($clients): void {
                $sub->select('id')->from('projects')->whereIn('client_workspace_id', $clients);
            });
        }
    }

    /** Whether this user may work in this project at all (same ceilings as the creative library). */
    public function reaches(User $user, string $projectId): bool
    {
        $q = Project::query()->whereKey($projectId);
        $this->constrain($q, $user, 'id');

        return $q->exists();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, string $projectId, array $data, ?UploadedFile $file): ContentIntakeItem
    {
        $item = new ContentIntakeItem($this->fields($data));
        $item->project_id = $projectId;
        $item->uploaded_by = $actor->getKey();
        $item->uploader_name = $actor->name;
        $item->status = 'draft';
        $item->kind = $this->kindOf($file, $data);
        $item->source = $file !== null ? 'upload' : ($item->kind === 'link' ? 'link' : 'upload');
        $item->name = trim((string) ($data['name'] ?? '')) !== '' ? trim((string) $data['name']) : ($file?->getClientOriginalName() ?? 'content');
        $item->tenant_id = (string) app(TenantContext::class)->tenantId();
        $item->id = (string) Str::uuid();
        if ($file !== null) {
            $this->attach($item, $file);
        }
        $item->save();
        $this->transitions->record($item, 'created', $actor, null, 'draft');

        return $this->analysis->run($item);
    }

    /** @param array<string, mixed> $data */
    public function update(ContentIntakeItem $item, User $actor, array $data, ?UploadedFile $file): ContentIntakeItem
    {
        if (! in_array($item->status, ['draft', 'needs_changes'], true)) {
            throw new IntakeTransitionException('not_editable', 409, ['status' => $item->status]);
        }
        $before = $item->only(['name', 'provider', 'objective', 'placement', 'headline', 'cta', 'copy_text', 'destination_url']);
        $item->fill($this->fields($data));
        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $item->name = trim((string) $data['name']);
        }
        if ($file !== null) {
            $this->discard($item);
            $item->kind = $this->kindOf($file, $data);
            $item->source = 'upload';
            $this->attach($item, $file);
        }
        $item->save();
        $after = $item->only(array_keys($before));
        $changed = array_keys(array_filter($after, static fn ($v, $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
        $this->transitions->record($item, 'edited', $actor, $item->status, $item->status, null, null, ['changed' => $changed, 'file_replaced' => $file !== null]);

        return $this->analysis->run($item);
    }

    public function recheck(ContentIntakeItem $item, User $actor): ContentIntakeItem
    {
        $item = $this->analysis->run($item);
        $this->transitions->record($item, 'rechecked', $actor, $item->status, $item->status, null, null, ['result' => $item->check_result, 'score' => $item->readiness_score]);

        return $item;
    }

    public function absolutePath(ContentIntakeItem $item): ?string
    {
        if ($item->path === null || $item->disk === null) {
            return null;
        }
        $disk = Storage::disk($item->disk);

        return $disk->exists($item->path) ? $disk->path($item->path) : null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function fields(array $data): array
    {
        $out = [];
        foreach (['provider', 'objective', 'placement', 'headline', 'cta', 'copy_text', 'destination_url'] as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                $out[$k] = ($v === null || trim((string) $v) === '') ? null : trim((string) $v);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function kindOf(?UploadedFile $file, array $data): string
    {
        if ($file !== null) {
            return str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
        }
        $kind = (string) ($data['kind'] ?? 'copy');

        return in_array($kind, ['copy', 'link'], true) ? $kind : 'copy';
    }

    private function attach(ContentIntakeItem $item, UploadedFile $file): void
    {
        $disk = $this->disk();
        $ext = Str::lower($file->getClientOriginalExtension() ?: ($item->kind === 'video' ? 'mp4' : 'jpg'));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $path = $file->storeAs("content-intake/{$item->tenant_id}/{$item->project_id}", Str::uuid().'.'.$ext, $disk);
        $item->disk = $disk;
        $item->path = (string) $path;
        $item->original_name = mb_substr($file->getClientOriginalName(), 0, 255);
        $m = $this->probe->probe(Storage::disk($disk)->path((string) $path), $item->kind);
        $item->mime = $m['mime'] ?? $file->getMimeType();
        $item->size_bytes = $m['size_bytes'];
        $item->width = $m['width'];
        $item->height = $m['height'];
        $item->duration_ms = $m['duration_ms'];
        $item->content_hash = $m['content_hash'];
    }

    private function discard(ContentIntakeItem $item): void
    {
        if ($item->path !== null && $item->disk !== null) {
            Storage::disk($item->disk)->delete($item->path);
        }
        $item->path = null;
        $item->disk = null;
    }
}
