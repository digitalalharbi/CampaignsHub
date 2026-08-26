<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Notifications\Services\NotificationChoices;
use App\Domains\Notifications\Support\MessageCatalogue;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The preferences centre — MAIL-011.
 *
 * Each user manages only their own row; the record is tenant-scoped. No permission gate beyond being
 * authenticated — a person always controls their own delivery.
 *
 * ## Two screens, one row, and the defect that lived between them
 *
 * This endpoint had two clients. `/account/notifications` — the page every email's «إدارة التفضيلات»
 * link opens — had the digest opt-ins, the receiving hour, the timezone and the language. The
 * settings tab had the six category checkboxes and the quiet hours. Neither had the project scope,
 * which no screen in the product could set at all.
 *
 * The defect is what happened when somebody used both. The settings tab PUT a fixed body with no
 * `digests`, `timezone`, `locale` or `digest_hour` key, and `update()` wrote every column
 * unconditionally — `digests => isset($data['digests']) ? ... : null`. So ticking a category
 * checkbox in settings silently cleared the digest somebody had switched on from their account page,
 * and reset their hour, timezone and language to the defaults. Nothing errored; the digest simply
 * stopped arriving.
 *
 * Both are fixed here. `update()` writes only the keys it was sent, and there is now one screen
 * rendered at both routes rather than two that each did something the other could not.
 *
 * ## Effective values, not stored values
 *
 * `show()` returns what will ACTUALLY happen for every type — after mandatory types, the master
 * channel switch, the per-type choice, the older per-category choice and the catalogue default have
 * all been applied. A screen that renders sparse stored values has to invent something for the gaps,
 * and whatever it invents is a second copy of the resolution order in TypeScript, which will
 * disagree with the PHP one the first time either changes.
 *
 * The cost is that saving freezes today's defaults into that person's row. That is the right trade:
 * a person who has opened this screen and pressed save has expressed an opinion about what they saw.
 */
final class NotificationPreferenceController extends Controller
{
    /** The older six. Still returned and still written — every stored row is keyed by them. */
    private const LEGACY_CATEGORIES = ['budget', 'performance', 'sync', 'token', 'reports', 'security'];

    private const DEFAULTS_CHANNELS = ['in_app' => true, 'email' => true];

    /**
     * The rhythms a person can opt into.
     *
     * `alerts` joins the same map rather than getting a column of its own, because it answers the
     * same question — «what may reach my inbox on its own?» — and a row written before it existed
     * has no `alerts` key, which reads as «never asked» exactly as it should.
     */
    /*
     * EMAIL-INTELLIGENCE-001 — `monthly` joins the rhythms a recipient can choose.
     *
     * Ordered by period so the settings list reads as a scale rather than an arbitrary set. `alerts`
     * stays last because it is not a period at all — it is «mail me findings as they happen».
     */
    private const DIGESTS = ['daily', 'weekly', 'monthly', 'alerts'];

    public function __construct(
        private readonly NotificationChoices $choices,
        private readonly DigestScope $scope,
        private readonly TenantContext $tenants,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $row = $this->row($request);
        $tenantId = (string) $this->tenants->tenantId();
        $userId = (int) $request->user()->id;

        $this->choices->forget();

        return ApiResponse::success([
            'channels' => $row->channels ?? self::DEFAULTS_CHANNELS,
            'categories' => $row->categories ?? $this->defaultCategories(),
            'quiet_hours' => $row->quiet_hours ?? ['enabled' => false, 'start' => '22:00', 'end' => '08:00'],
            'frequency' => $row->frequency ?? 'realtime',
            'project_ids' => $row->project_ids,
            'available_categories' => self::LEGACY_CATEGORIES,
            'digests' => ($row->digests ?? []) + ['daily' => false, 'weekly' => false, 'alerts' => false],
            'available_digests' => self::DIGESTS,
            // The reader's own clock and language, which is what makes «daily» mean their morning.
            'timezone' => $row->timezone ?? 'Asia/Riyadh',
            'locale' => $row->locale ?? 'ar',
            'digest_hour' => (int) ($row->digest_hour ?? 8),
            'available_timezones' => timezone_identifiers_list(),
            // MAIL-011 — the catalogue, and this person's effective answer for every entry in it.
            'catalogue' => $this->catalogue(),
            'types' => $this->effectiveTypes($userId, $tenantId),
            'projects' => $this->reachableProjects($request, $tenantId),
        ], 'Notification preferences retrieved.');
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = (string) $this->tenants->tenantId();
        $userId = $request->user()->id;

        $data = $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.in_app' => ['boolean'],
            'channels.email' => ['boolean'],
            'categories' => ['sometimes', 'array'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['array'],
            'types.*.email' => ['boolean'],
            'types.*.in_app' => ['boolean'],
            'types.*.rhythm' => ['string'],
            'quiet_hours' => ['sometimes', 'nullable', 'array'],
            'quiet_hours.enabled' => ['boolean'],
            'quiet_hours.start' => ['nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['nullable', 'date_format:H:i'],
            'frequency' => ['sometimes', 'in:realtime,hourly,daily'],
            'project_ids' => ['sometimes', 'nullable', 'array'],
            'project_ids.*' => ['uuid'],
            'digests' => ['sometimes', 'nullable', 'array'],
            'digests.daily' => ['boolean'],
            'digests.weekly' => ['boolean'],
            'digests.alerts' => ['boolean'],
            /*
             * The timezone is validated against PHP's OWN list rather than a regex.
             *
             * A stored identifier this process cannot resolve would throw inside the hourly sweep and
             * abort it — one malformed row silencing every other recipient's digest.
             */
            'timezone' => ['sometimes', 'nullable', 'string', Rule::in(timezone_identifiers_list())],
            'locale' => ['sometimes', 'nullable', 'in:ar,en'],
            'digest_hour' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
        ]);

        if (array_key_exists('types', $data)) {
            $data['types'] = $this->vetted($request, $data['types'], $data['digests'] ?? null);
        }

        $existing = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereNull('client_workspace_id')->first();

        /*
         * Only the keys that arrived.
         *
         * `updateOrInsert` with a full array of columns is how the old screen erased the digest
         * opt-ins: a client that does not know about a setting must not be able to clear it. A key
         * that is present and null is still an instruction («no project scope») and is written; a
         * key that is absent is left exactly as it was.
         */
        $write = [];
        foreach (['channels', 'categories', 'types', 'quiet_hours', 'project_ids', 'digests'] as $json) {
            if (array_key_exists($json, $data)) {
                $write[$json] = $data[$json] === null ? null : json_encode($data[$json]);
            }
        }
        foreach (['frequency', 'timezone', 'locale', 'digest_hour'] as $scalar) {
            if (array_key_exists($scalar, $data) && $data[$scalar] !== null) {
                $write[$scalar] = $data[$scalar];
            }
        }

        $write['updated_at'] = now();

        if ($existing === null) {
            DB::table('notification_preferences')->insert($write + [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                // NOT NULL on the original table, so a first write has to say something.
                'channels' => $write['channels'] ?? json_encode(self::DEFAULTS_CHANNELS),
                'categories' => $write['categories'] ?? json_encode($this->defaultCategories()),
                'created_at' => now(),
            ]);
        } else {
            DB::table('notification_preferences')->where('id', $existing->id)->update($write);
        }

        // The resolver memoises per request; without this the response would describe the row as it
        // was before this write.
        $this->choices->forget();

        return $this->show($request);
    }

    /**
     * Refuse a switch that would be a lie, and drop the ones that are only an echo.
     *
     * ## Reading the document back must be a no-op
     *
     * `show()` returns an EFFECTIVE map covering every type, including the mandatory ones and the two
     * digests. The obvious client — and the account page is exactly this client — reads that
     * document, changes one field, and sends the whole thing back. If every key it cannot control
     * were a 422, the screen would break on a save that changed nothing.
     *
     * So an entry that merely restates what is already true is dropped, and only a CONTRADICTION is
     * refused. That is the difference between «you cannot say that» and «you may not decide that».
     *
     * ## The three refusals, kept separate
     *
     * They are three different mistakes, and «invalid input» would tell nobody which one they made:
     *
     * - **An unknown type.** There is no message by that name, so a stored switch for it would sit in
     *   the row forever controlling nothing.
     * - **Switching off a mandatory type.** A password reset or a security alert has no off switch —
     *   see `MessageCatalogue`. Accepting the key and ignoring it would be worse than refusing: the
     *   screen would show it as off while the mail kept arriving.
     * - **A rhythm that type does not offer.** Nothing batches an invoice or a new conversation
     *   message, so storing `weekly` against one would hold it for a digest that will never carry it.
     *
     * @param  array<string, mixed>  $types
     * @param  array<string, mixed>|null  $digests  as submitted in the same request, if it was
     * @return array<string, array<string, mixed>> what will actually be stored
     */
    private function vetted(Request $request, array $types, ?array $digests): array
    {
        $stored = [];

        foreach ($types as $key => $choice) {
            $type = (string) $key;
            $choice = is_array($choice) ? $choice : [];

            if (! MessageCatalogue::has($type)) {
                abort(422, "There is no message type called «{$type}».");
            }

            if (MessageCatalogue::isMandatory($type)) {
                foreach (['email', 'in_app'] as $channel) {
                    if (array_key_exists($channel, $choice) && $choice[$channel] !== true) {
                        abort(422, "«{$type}» is sent whenever it applies and cannot be switched off.");
                    }
                }

                continue;
            }

            if (isset(MessageCatalogue::DIGEST_SWITCH[$type])) {
                $which = MessageCatalogue::DIGEST_SWITCH[$type];
                $truth = $digests !== null
                    ? ($digests[$which] ?? false) === true
                    : $this->choices->wants((int) $request->user()->id, (string) $this->tenants->tenantId(), $type, 'email');

                if (array_key_exists('email', $choice) && $choice['email'] !== $truth) {
                    abort(422, "«{$type}» is switched through the digest opt-ins, not per type.");
                }

                continue;
            }

            $rhythm = $choice['rhythm'] ?? null;
            if ($rhythm !== null && ! in_array($rhythm, MessageCatalogue::rhythmsFor($type), true)) {
                abort(422, "«{$type}» cannot be sent on a «{$rhythm}» rhythm.");
            }

            $stored[$type] = $choice;
        }

        return $stored;
    }

    /**
     * The catalogue, grouped for the screen, with everything the screen needs to be honest about.
     *
     * `sent_by` is not rendered — it is here so a developer reading the response can see that every
     * switch belongs to something that exists.
     *
     * @return list<array{key: string, types: list<array<string, mixed>>}>
     */
    private function catalogue(): array
    {
        $out = [];

        foreach (MessageCatalogue::CATEGORIES as $category) {
            $types = [];
            foreach (MessageCatalogue::inCategory($category) as $type) {
                $definition = MessageCatalogue::get($type);
                $types[] = [
                    'key' => $type,
                    'mandatory' => $definition['mandatory'],
                    'rhythms' => $definition['rhythms'],
                    // The two digest rows are switched through `digests`, and the screen has to know
                    // that or it renders a checkbox that writes to nothing.
                    'digest_switch' => MessageCatalogue::DIGEST_SWITCH[$type] ?? null,
                    'sent_by' => $definition['sent_by'],
                ];
            }

            if ($types !== []) {
                $out[] = ['key' => $category, 'types' => $types];
            }
        }

        return $out;
    }

    /**
     * What will actually happen, per type, for this person today.
     *
     * @return array<string, array{email: bool, in_app: bool, rhythm: string}>
     */
    private function effectiveTypes(int $userId, string $tenantId): array
    {
        $out = [];

        foreach (MessageCatalogue::keys() as $type) {
            $out[$type] = [
                'email' => $this->choices->wants($userId, $tenantId, $type, 'email'),
                'in_app' => $this->choices->wants($userId, $tenantId, $type, 'in_app'),
                'rhythm' => $this->choices->rhythm($userId, $tenantId, $type),
            ];
        }

        return $out;
    }

    /**
     * The projects this person may narrow their digest to.
     *
     * From `DigestScope`, which is the same ceiling the digest itself is built against — so the list
     * cannot offer a project whose figures would then be withheld. Client-qualified for the reason
     * MAIL-010 records: project names are only unique inside a client.
     *
     * @return list<array{id: string, name: string, client_name: ?string}>
     */
    private function reachableProjects(Request $request, string $tenantId): array
    {
        $ids = $this->scope->projectIdsFor($request->user(), $tenantId);

        if ($ids === []) {
            return [];
        }

        return Project::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)
            ->with('clientWorkspace:id,name')->orderBy('name')->get(['id', 'name', 'client_workspace_id'])
            ->map(static fn (Project $p): array => [
                'id' => (string) $p->id,
                'name' => (string) $p->name,
                'client_name' => $p->clientWorkspace?->name,
            ])->all();
    }

    private function row(Request $request): object
    {
        $tenantId = (string) $this->tenants->tenantId();
        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)->where('user_id', $request->user()->id)
            ->whereNull('client_workspace_id')->first();
        if ($row) {
            foreach (['channels', 'categories', 'types', 'quiet_hours', 'project_ids', 'digests'] as $k) {
                $row->{$k} = $row->{$k} !== null ? json_decode($row->{$k}, true) : null;
            }
        }

        return $row ?? (object) [
            'channels' => null, 'categories' => null, 'types' => null, 'quiet_hours' => null, 'frequency' => null,
            'project_ids' => null, 'digests' => null, 'timezone' => null, 'locale' => null, 'digest_hour' => null,
        ];
    }

    /** @return array<string, array<string,bool>> */
    private function defaultCategories(): array
    {
        $out = [];
        foreach (self::LEGACY_CATEGORIES as $c) {
            $out[$c] = ['in_app' => true, 'email' => $c !== 'performance'];
        }

        return $out;
    }
}
