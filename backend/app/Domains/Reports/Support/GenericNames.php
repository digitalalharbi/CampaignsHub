<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ClientReportView;
use App\Support\AdPlatforms;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * REPORT-GENERIC-LABELS-001 (Owner file 2026-11-03 §7.1) — the one place a hidden name's stand-in comes from.
 *
 * «إخفاء اسم حملة أو عمود الاسم لا يفرض إخفاء المقارنة أو الرسم أو القسم بأكمله.» A name switch takes the NAME and nothing else: the row,
 * its figures, its chart and its section stay, under a label that tells it apart from its neighbours and identifies nothing.
 *
 * ## The switches
 *
 * `generic_names` is a list of kinds on the link (`settings`) and on the report (`config`), read as one union: a link can hide a name
 * the report shows and can never show one the report hides. Each kind is independent, absent means «no switch», and nothing is
 * rewritten for a report or link that never chose one — {@see apply()} returns its input untouched.
 *
 * ## Why a sweep over the finished payload
 *
 * A name reaches a reader through a row's key, a chart's label, a sentence, a tooltip, an image's alt text or a file's cell, and every
 * one of those is drawn from the payload. Enumerating them is how a boundary comes to hold until the next feature, so this runs last
 * on the document every surface already passes through (`ReportSectionSurfaces::apply`, both share sanitisers and the drill-downs),
 * and removes the name by VALUE as well as by key: the project's own names and every name found under a known key of the document,
 * including a stored snapshot's, whose names may no longer match the database.
 *
 * ## The older `hide_campaign_names`
 *
 * That switch predates this and existing links rely on what it shows, so its words are kept: every campaign reads «حملة», the live
 * table «Meta — 1», the picker «حملة 1». Those strings live here now. The sweep closes what it left behind (a quoted name in a
 * sentence, an ad set's parent campaign, a stored «best campaign») and leaves every other name key alone, so such a link's content
 * names read as they did even where one quotes its campaign. A link that chose `campaign` here gets told-apart labels and loses that too.
 */
final class GenericNames
{
    public const KINDS = ['campaign', 'ad_set', 'account', 'content'];

    /** @var array<string, array{ar: string, en: string}> */
    private const WORDS = [
        'campaign' => ['ar' => 'حملة', 'en' => 'Campaign'],
        'ad_set' => ['ar' => 'مجموعة إعلانية', 'en' => 'Ad set'],
        'account' => ['ar' => 'حساب إعلاني', 'en' => 'Ad account'],
        'content' => ['ar' => 'محتوى', 'en' => 'Content'],
    ];

    /** A key whose value is that kind's name, wherever it sits. */
    private const NAME_KEYS = [
        'campaign_name' => 'campaign',
        'ad_set_name' => 'ad_set',
        'account_name' => 'account',
        'account_name_en' => 'account',
        'ad_name' => 'content',
        'creative_name' => 'content',
    ];

    /** A list whose rows' `name` is that kind's name. */
    private const NAMED_ROWS = [
        'campaigns' => 'campaign',
        'campaign_rows' => 'campaign',
        'ad_sets' => 'ad_set',
        'accounts' => 'account',
        'ads' => 'content',
        'ads_roster' => 'content',
        'ads_weakest' => 'content',
        'creatives' => 'content',
        'top_creatives' => 'content',
        'worst_creatives' => 'content',
    ];

    /** One row, under a key of its own, whose `name` is that kind's name. */
    private const NAMED_ROW = ['creative' => 'content', 'content' => 'content'];

    /**
     * Keys that hold the product's own vocabulary, never a name somebody typed. A campaign called «Sales» must not rewrite the
     * objective `sales` or the card «Sales value».
     */
    private const VOCABULARY_KEYS = [
        'id', 'key', 'content_key', 'provider', 'platform', 'format', 'status', 'objective', 'type', 'kind', 'state', 'data_state', 'path',
        'currency', 'level', 'ads_level', 'creative_level', 'form', 'mode', 'source', 'basis', 'label_ar', 'label_en', 'title_ar',
        'title_en', 'audience', 'reveals', 'result_kind', 'date',
    ];

    /** Sentences. A short name («Eid») is only safe to replace here, as a whole word. */
    private const PROSE_KEYS = ['title', 'detail', 'action', 'reason', 'summary', 'strengths', 'weaknesses', 'description', 'message', 'text', 'alt', 'caption'];

    /** Shorter than this, a name is too likely to be an ordinary word to replace inside every string in a document. */
    private const SAFE_ANYWHERE = 6;

    /** @var WeakMap<ReportShare, self>|null */
    private static ?WeakMap $ofLink = null;

    /** @var array<string, array<string, true>>|null */
    private ?array $projectNames = null;

    /**
     * @param  list<string>  $kinds  the kinds somebody chose to hide
     */
    private function __construct(
        private readonly array $kinds,
        private readonly bool $olderCampaignSwitch,
        private readonly string $locale,
        private readonly ?Report $report,
    ) {}

    // ---- The words ------------------------------------------------------------------------------

    /** The stand-in for one name: «حملة 3», «Content 3». Latin digits in both languages, by the product's rule. */
    public static function label(string $kind, int $ordinal, string $locale = 'ar'): string
    {
        return self::WORDS[$kind][$locale === 'en' ? 'en' : 'ar'].' '.$ordinal;
    }

    /** The older switch's word for every campaign on a row of a stored document. */
    public static function olderCampaignWord(): string
    {
        return self::WORDS['campaign']['ar'];
    }

    /** The older switch's picker entry. */
    public static function olderCampaignOption(int $ordinal): string
    {
        return self::label('campaign', $ordinal, 'ar');
    }

    /** The older switch's row on the live campaign table (and a campaign with no name at all). */
    public static function olderCampaignRow(string $provider, int $ordinal): string
    {
        return AdPlatforms::name($provider).' — '.$ordinal;
    }

    /** An ad account with its technical name withheld: «حساب سناب شات 1». */
    public static function accountRow(string $provider, int $ordinal, string $locale): string
    {
        return $locale === 'en'
            ? AdPlatforms::name($provider, 'en').' account '.$ordinal
            : 'حساب '.AdPlatforms::name($provider, 'ar').' '.$ordinal;
    }

    // ---- The switches ---------------------------------------------------------------------------

    /**
     * Stored or submitted kinds, cleaned: known ones only, once each, in canonical order.
     *
     * @return list<string>
     */
    public static function normalise(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_intersect(self::KINDS, array_filter($raw, 'is_string')));
    }

    /** @return list<string> */
    public static function ofReport(Report $report): array
    {
        return self::normalise(((array) ($report->config ?? []))['generic_names'] ?? null);
    }

    /** @return list<string> */
    public static function ofShare(ReportShare $share): array
    {
        return self::normalise(((array) ($share->settings ?? []))['generic_names'] ?? null);
    }

    public static function for(?Report $report, ?ReportShare $share): self
    {
        // One open of a link asks several times (the page, then each drawer block), and the answer belongs to the link's own object.
        self::$ofLink ??= new WeakMap;
        if ($share !== null && isset(self::$ofLink[$share])) {
            return self::$ofLink[$share];
        }

        $found = self::build($report ?? ($share === null ? null : Report::withoutGlobalScopes()->find($share->report_id)), $share);
        if ($share !== null) {
            self::$ofLink[$share] = $found;
        }

        return $found;
    }

    private static function build(?Report $report, ?ReportShare $share): self
    {

        $kinds = array_values(array_intersect(self::KINDS, array_unique([
            ...($report === null ? [] : self::ofReport($report)),
            ...($share === null ? [] : self::ofShare($share)),
        ])));

        return new self(
            $kinds,
            $share !== null && (bool) $share->hide_campaign_names && ! in_array('campaign', $kinds, true),
            $report?->reportLocale() ?? 'ar',
            $report,
        );
    }

    /** Whether somebody chose this kind on this report or link. The older switch answers for itself. */
    public function chose(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    /** Whether this link is on the older switch alone, so a row keeps the word it has always shown. */
    public function usesOlderCampaignWord(): bool
    {
        return $this->olderCampaignSwitch;
    }

    /**
     * Take note of the names a document holds, ahead of anything that overwrites them.
     *
     * @param  array<mixed>  $document
     */
    public function learn(array $document): void
    {
        if (! $this->isActive()) {
            return;
        }
        $this->projectNames ??= $this->namesInTheProject();
        $this->harvest($document, null, $this->projectNames);
    }

    public function isActive(): bool
    {
        return $this->kinds !== [] || $this->olderCampaignSwitch;
    }

    // ---- The sweep ------------------------------------------------------------------------------

    /**
     * The document with the chosen names gone, by key and by value. Rows, figures and structure are not touched; a second pass
     * over its own output changes nothing, because a stand-in is never taken for a name.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    public function apply(array $payload): array
    {
        if (! $this->isActive()) {
            return $payload;
        }

        $names = $this->projectNames ??= $this->namesInTheProject();
        $this->harvest($payload, null, $names);

        $labels = [];
        foreach ($names as $kind => $list) {
            $n = 0;
            foreach (array_keys($list) as $name) {
                $n++;
                foreach ($this->spellings((string) $name) as $spelling) {
                    $labels[$spelling] ??= [$kind, $n];
                }
            }
        }

        $anywhere = [];
        $wholeWords = [];
        foreach ($labels as $spelling => [$kind, $n]) {
            $spelling = (string) $spelling;
            $label = $this->wordFor($kind, $n, $this->locale);
            if (mb_strlen($spelling) >= self::SAFE_ANYWHERE) {
                $anywhere[$spelling] = $label;
                // A name inside a link: `utm_campaign=Summer%20Sale`.
                $anywhere[rawurlencode($spelling)] = rawurlencode($label);
                $anywhere[urlencode($spelling)] = urlencode($label);
            } elseif (mb_strlen($spelling) >= 2) {
                $wholeWords[$spelling] = $label;
            }
        }

        return $this->rewrite($payload, null, $labels, $anywhere, $this->wholeWordPattern($wholeWords), $wholeWords);
    }

    private function wordFor(string $kind, int $n, string $locale): string
    {
        return $kind === 'campaign' && $this->olderCampaignSwitch ? self::olderCampaignWord() : self::label($kind, $n, $locale);
    }

    /** @return list<string> the kinds this sweep removes */
    private function sweptKinds(): array
    {
        return $this->olderCampaignSwitch ? array_values(array_unique([...$this->kinds, 'campaign'])) : $this->kinds;
    }

    /**
     * The project's own names, oldest first, so a label does not depend on which rows a window or an endpoint happened to carry.
     * Read only to take them OUT of a document: nothing here can add a row, a figure or an account to anything.
     *
     * @return array<string, array<string, true>> kind => name => true
     */
    private function namesInTheProject(): array
    {
        $names = array_fill_keys($this->sweptKinds(), []);
        $projectId = (string) ($this->report->project_id ?? '');
        if ($projectId === '') {
            return $names;
        }

        $read = static fn (string $table, string $column): array => DB::table($table)
            ->where('project_id', $projectId)
            ->whereNotNull($column)
            ->orderBy('created_at')->orderBy('id')
            ->pluck($column)
            ->all();

        $columns = [
            'campaign' => [['unified_campaigns', 'name'], ['unified_campaigns', 'client_display_name'], ['external_campaigns', 'name']],
            'ad_set' => [['external_ad_sets', 'name']],
            'content' => [['external_creatives', 'name'], ['external_creatives', 'client_display_name'], ['external_ads', 'name']],
        ];

        foreach (array_keys($names) as $kind) {
            $values = [];
            foreach ($columns[$kind] ?? [] as [$table, $column]) {
                $values = [...$values, ...$read($table, $column)];
            }
            if ($kind === 'account') {
                $values = DB::table('external_accounts')
                    ->whereIn('id', DB::table('project_integration_bindings')->where('project_id', $projectId)->select('external_account_id'))
                    ->whereNotNull('name')->orderBy('created_at')->orderBy('id')->pluck('name')->all();
            }
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $names[$kind][$value] = true;
                }
            }
        }

        /*
         * The stored snapshot itself, as written: the client view empties its campaign rows before any surface sees them, and
         * a sentence it keeps may still quote a campaign renamed or removed since.
         */
        if (is_array($this->report?->data)) {
            $this->harvest($this->report->data, null, $names);
        }

        return $names;
    }

    /**
     * Every name a known key of this document holds — a stored snapshot quotes names the database may no longer have.
     *
     * @param  array<mixed>  $node
     * @param  array<string, array<string, true>>  $names
     */
    private function harvest(array $node, ?string $rowKind, array &$names): void
    {
        foreach ($node as $key => $value) {
            $key = (string) $key;

            if (is_string($value)) {
                $kind = $this->kindOfKey($key, $rowKind, $node);
                if ($kind !== null && isset($names[$kind]) && ! $this->isStandIn(trim($value)) && trim($value) !== '') {
                    $names[$kind][trim($value)] = true;
                }

                continue;
            }

            if (is_array($value)) {
                $this->harvest($value, self::NAMED_ROW[$key] ?? self::NAMED_ROWS[$key] ?? (array_is_list($node) ? $rowKind : null), $names);
            }
        }
    }

    /** @param array<mixed> $row */
    private function kindOfKey(string $key, ?string $rowKind, array $row): ?string
    {
        if (isset(self::NAME_KEYS[$key])) {
            return self::NAME_KEYS[$key];
        }
        if ($key === 'name') {
            // An observation's `scope`: `{type: campaign, name: …}`.
            $declared = $row['type'] ?? null;

            return is_string($declared) && in_array($declared, self::KINDS, true) ? $declared : $rowKind;
        }
        if ($key === 'client_display_name') {
            return $rowKind;
        }
        // `best.campaign`, which is a bare name on an older snapshot.
        if ($key === 'campaign') {
            return 'campaign';
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, array{0: string, 1: int}>  $labels
     * @param  array<string, string>  $anywhere
     * @param  array<string, string>  $wholeWords
     * @return array<mixed>
     */
    private function rewrite(array $node, ?string $rowKind, array $labels, array $anywhere, ?string $wordPattern, array $wholeWords): array
    {
        foreach ($node as $key => $value) {
            $stringKey = (string) $key;

            if (is_array($value)) {
                $node[$key] = $this->rewrite(
                    $value,
                    self::NAMED_ROW[$stringKey] ?? self::NAMED_ROWS[$stringKey] ?? (array_is_list($node) ? $rowKind : null),
                    $labels,
                    $anywhere,
                    $wordPattern,
                    $wholeWords,
                );

                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            $kind = $this->kindOfKey($stringKey, $rowKind, $node);
            if ($kind !== null && in_array($kind, $this->sweptKinds(), true)) {
                $trimmed = trim($value);
                if (! $this->isStandIn($trimmed) && isset($labels[$trimmed])) {
                    [$labelKind, $n] = $labels[$trimmed];
                    // An account row carries its name in both languages, and the English key stays English.
                    $node[$key] = $this->wordFor($labelKind, $n, $stringKey === 'account_name_en' ? 'en' : $this->locale);
                }

                continue;
            }

            /*
             * A content name on the older switch stays exactly as it was shown, even when it quotes its campaign: an existing link
             * keeps what it showed. Choosing `campaign` here is the opt-in that takes that out too.
             */
            if ($kind !== null && $this->olderCampaignSwitch) {
                continue;
            }

            if (in_array($stringKey, self::VOCABULARY_KEYS, true)) {
                continue;
            }

            $next = $anywhere === [] ? $value : strtr($value, $anywhere);
            if ($wordPattern !== null && in_array($stringKey, self::PROSE_KEYS, true) || ($wordPattern !== null && is_int($key))) {
                $next = (string) preg_replace_callback($wordPattern, static fn (array $m): string => $wholeWords[$m[0]] ?? $m[0], $next);
            }
            $node[$key] = $next;
        }

        return $node;
    }

    /** @param array<string, string> $wholeWords */
    private function wholeWordPattern(array $wholeWords): ?string
    {
        if ($wholeWords === []) {
            return null;
        }
        $spellings = array_map('strval', array_keys($wholeWords));
        usort($spellings, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return '/(?<![\p{L}\p{N}])(?:'.implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $spellings)).')(?![\p{L}\p{N}])/u';
    }

    /**
     * Every way a stored name is written on a client's document. A platform's own name is no identity to hide, and
     * replacing it would rewrite every platform on the page.
     *
     * @return list<string>
     */
    private function spellings(string $name): array
    {
        $variants = array_unique(array_filter([$name, ClientReportView::clientName($name), (string) ClientContentName::clean($name)], static fn (string $v): bool => trim($v) !== ''));

        return array_values(array_filter($variants, fn (string $v): bool => ! $this->isStandIn($v) && ! $this->isAPlatform($v)));
    }

    private function isAPlatform(string $value): bool
    {
        return in_array(mb_strtolower($value), ['meta', 'facebook', 'instagram', 'snapchat', 'tiktok', 'google', 'x', 'linkedin', 'microsoft', 'pinterest', 'openai', 'chatgpt'], true);
    }

    /** One of this class's own words, so a second pass over a finished document changes nothing. */
    private function isStandIn(string $value): bool
    {
        if ($value === ClientContentName::FALLBACK || $value === 'حملة إعلانية') {
            return true;
        }
        foreach (self::WORDS as $word) {
            if (preg_match('/^(?:'.preg_quote($word['ar'], '/').'|'.preg_quote($word['en'], '/').')(?: [0-9]+)?$/u', $value) === 1) {
                return true;
            }
        }

        // Only this class's own shapes around a real PLATFORM: «Meta — 1», «حملة — Meta», «حساب سناب شات 1», «Snapchat account 1».
        // «Spring Sale — 2» is somebody's campaign, and treating the shape as enough is how it would have stayed on the page.
        foreach (AdPlatforms::ORDER as $provider) {
            foreach (['ar', 'en'] as $locale) {
                $platform = preg_quote(AdPlatforms::name($provider, $locale), '/');
                if (preg_match('/^(?:'.$platform.' — [0-9]+|حملة — '.$platform.'|حساب '.$platform.' [0-9]+|'.$platform.' account [0-9]+)$/u', $value) === 1) {
                    return true;
                }
            }
        }
        // `ClientReportView::clientName()`'s own fallback names its platform in Latin capitalisation.
        if (preg_match('/^حملة — (?:Snapchat|Tiktok|Meta|Google|Linkedin|X|Microsoft|Pinterest)$/u', $value) === 1) {
            return true;
        }

        return false;
    }
}
