{{--
  The projects summary «ملخص المشاريع» — DIGESTS-VISUAL-V2-001, reordered by DIGESTS-YESTERDAY-BY-OBJECTIVE-001: performance
  first (spend, each objective from its own campaigns, best and weakest), then the decisions on running campaigns, the budgets,
  the map, and «ملخص الربط» last.

  Built for up to thirty projects with five to seven platforms each, read in the time it takes to drink a coffee:
  the counts first (✕ ! ✓ –), then WHO needs a decision in ONE ranked list, then the project × platform heatmap that
  shows EVERY project once, then who was not checked at all. No paragraph anywhere: a chip, a bar, a delta and a button.
  The status of every cell is `DigestStatus`; the figures are the report link's own.
--}}
@php($ar = $dir === 'rtl')
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($weekly = $kind === 'weekly')
@php($monthly = $kind === 'monthly')
@php($lean = ($external ?? false) || ($readOnly ?? false))
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    {{-- DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — «ملخص المشاريع : أمس» and the period with its dates. --}}
    @include('mail.digest._head', ['title' => \App\Domains\Notifications\Support\DigestSchedule::title('portfolio', $kind, $ar), 'sub' => $periodLabel])
    <div data-block="compared" style="font-size:11px; color:#8b9a97; margin:-8px 0 10px;">{{ $t['kind_note'] }}</div>

    {{-- 1. Performance first: the spend (the one figure every objective shares) and its change, then ONE tile row per objective from that objective's campaigns only. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="margin-bottom:8px; table-layout:fixed;">
        <tr>
            <td width="34%" valign="top">@include('mail.digest._figures', ['figures' => $m['kpis'], 'per' => 1, 'big' => true])</td>
            <td width="66%" valign="bottom" style="padding-{{ $startSide === 'right' ? 'right' : 'left' }}:8px;">@include('mail.digest._spark', ['bars' => $monthly && $m['weeks'] ? $m['weeks'] : $m['spark'], 'colour' => '#0d8a6f', 'height' => $weekly || $monthly ? 40 : 30, 'labels' => $weekly || $monthly])</td>
        </tr>
    </table>
    @foreach ($m['groups'] as $g)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="group" data-objective="{{ $g['objective'] }}" style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:8px;">
            <tr><td style="padding:8px 12px 8px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td><span data-chip="objective" style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $g['label'] }}</span>
                        <span style="font-size:11px; color:#8b9a97;" dir="ltr">· {{ $g['projects'] }}</span></td>
                    <td style="text-align:{{ $endSide }}; font-size:12px; font-weight:800; white-space:nowrap;" dir="ltr">{{ $g['spend']['value'] }} <span style="font-size:11px; color:{{ $g['spend']['colour'] }};">{{ $g['spend']['change'] }}</span></td>
                </tr></table>
                @include('mail.digest._figures', ['figures' => $g['kpis'], 'per' => max(1, count($g['kpis'])), 'big' => false])
            </td></tr>
        </table>
    @endforeach

    {{-- 3. Best and weakest, per OBJECTIVE group on its own figure, against the previous period — on every rhythm. --}}
    @if ($m['movers']['best'] || $m['movers']['worst'])
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:8px 0 6px;">{{ $ar ? 'الأفضل والأقل حسب الهدف' : 'Best and weakest, by objective' }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="movers" style="margin-bottom:12px;">
            @foreach (['best' => 'ok', 'worst' => 'bad'] as $k => $c)
                @foreach ($m['movers'][$k] as $r)
                    <tr>
                        <td width="22" style="padding:3px 0;">@include('mail.digest._chip', ['state' => $c, 'size' => 18])</td>
                        <td style="padding:3px 6px; font-size:12px; font-weight:700; color:#0b1a16;">{{ \Illuminate\Support\Str::limit($r['name'], 22, '…') }} <span style="display:inline-block; padding:0 7px; border-radius:999px; font-size:10px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $r['objective'] }}</span> <span style="font-size:11px; color:#8b9a97; font-weight:400;">{{ $r['label'] }}</span></td>
                        <td style="padding:3px 0; text-align:{{ $endSide }}; font-size:12px; color:#3d4742; white-space:nowrap;" dir="ltr">{{ $r['value'] }} <span style="font-weight:700; color:{{ $S::INK[$c] }};">{{ $r['change'] }}</span></td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endif

    {{-- 4. DIGEST-NO-DECISION-SECTION-001 — Owner 2026-11-21: the «يحتاج قرارك الآن» list is removed from the mail. --}}

    {{-- 5. DIGESTS-CADENCE-BUDGETS-001 — the month's budget, spend, remaining and pace per project, and the total. --}}
    @include('mail.digest._budgets', ['bud' => $m['budgets']])

    {{-- 6. The performance state of every project as four counts, then the project × platform map. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="counts" style="margin-bottom:8px; table-layout:fixed;">
        <tr>
            @foreach (['bad', 'watch', 'ok', 'none'] as $k)
                <td style="padding:0 {{ $loop->last ? 0 : 4 }}px 0 {{ $loop->first ? 0 : 0 }}px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                        <td align="center" bgcolor="{{ $S::CELL[$k] }}" data-count="{{ $k }}" style="background-color:{{ $S::CELL[$k] }}; border-radius:10px; padding:8px 2px;">
                            <div style="font-size:11px; line-height:1.3; font-weight:700; color:{{ $S::INK[$k] }}; text-align:center;">{{ $S::GLYPH[$k] }} {{ $S::label($k, $ar) }}</div>
                            <div style="font-size:22px; line-height:1.2; font-weight:800; color:{{ $S::INK[$k] }}; text-align:center;" dir="ltr">{{ $m['health'][$k] }}</div>
                        </td>
                    </tr></table>
                </td>
            @endforeach
        </tr>
    </table>

    {{-- 5. The heatmap: every project once, every platform once. A cell is a chip; healthy rows stay one line. --}}
    @php($hm = $m['heatmap'])
    @php($cols = count($hm['columns']))
    @php($cw = $cols > 6 ? 32 : 36)
    <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:14px 0 6px;">{{ $ar ? 'كل المشاريع × المنصات' : 'Every project × platform' }}</div>
    {{-- EMAIL-CLIENT-COMPAT-001 — every chip IS its cell: fixed width × height, its own bgcolor, the glyph at a fixed px size and
         line-height, 2px cellspacing for the gutters. A span stretched inside a padded cell (the old way) is what Gmail drew at
         different sizes, and it was 75 KB of a 30-project message that Gmail clips at ~102 KB. --}}
    @php($chip = $cw - 2)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="2" border="0" data-block="heatmap" style="width:100%; table-layout:fixed;">
        <tr data-block="heatmap-head">
            <td style="font-size:10px; line-height:14px; color:#8b9a97;">&nbsp;</td>
            @foreach ($hm['columns'] as $col)
                <td width="{{ $chip }}" align="center" data-provider="{{ $col['provider'] }}" style="width:{{ $chip }}px; font-size:9px; line-height:14px; font-weight:700; color:#5b6b68; text-align:center; overflow:hidden; white-space:nowrap;">{{ $col['short'] }}</td>
            @endforeach
        </tr>
        @foreach ($hm['rows'] as $row)
            <tr data-block="portfolio-row" data-state="{{ $row['state'] }}" data-project="{{ $row['id'] }}" height="20" style="height:20px; font-size:12px; line-height:20px; font-weight:800; text-align:center;">
                <td style="font-weight:{{ in_array($row['state'], ['bad', 'watch'], true) ? 800 : 600 }}; color:{{ $row['state'] === 'none' ? '#8b9a97' : '#0b1a16' }}; text-align:{{ $startSide }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">@if ($lean){{ \Illuminate\Support\Str::limit($row['name'], 18, '…') }}@else<a href="{{ $row['url'] }}" style="color:{{ $row['state'] === 'none' ? '#8b9a97' : '#0b1a16' }}; text-decoration:none;">{{ \Illuminate\Support\Str::limit($row['name'], 18, '…') }}</a>@endif</td>
                @foreach ($row['cells'] as $cell)
                    @if ($cell === null)
                        <td align="center" style="font-size:11px; font-weight:400; color:#c9c4b6;">·</td>
                    @else
                        <td align="center" bgcolor="{{ $S::CELL[$cell['state']] }}" data-state="{{ $cell['state'] }}" style="background-color:{{ $S::CELL[$cell['state']] }}; color:{{ $S::INK[$cell['state']] }}; border-radius:6px;">{{ $S::GLYPH[$cell['state']] }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </table>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="legend" style="width:100%; margin:8px 0 12px;"><tr>
        @foreach (['ok', 'watch', 'bad', 'none'] as $k)
            <td style="padding-{{ $endSide }}:10px; font-size:11px; color:#5b6b68; white-space:nowrap;">@include('mail.digest._chip', ['state' => $k, 'size' => 16]) {{ $S::label($k, $ar) }}</td>
        @endforeach
        <td width="100%" style="font-size:1px; line-height:1px;">&nbsp;</td>
    </tr></table>

    @unless ($lean)
    {{-- 7. DIGESTS-YESTERDAY-BY-OBJECTIVE-001 — «ملخص الربط»: linking and data freshness, apart from performance, short, at the end. --}}
    <div data-block="linking" style="border-top:1px solid #e4e0d6; margin-top:14px; padding-top:10px;">
    <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:0 0 6px;">{{ $ar ? 'ملخص الربط' : 'Connections summary' }}</div>
    {{-- How many projects' figures arrived current, and below it, who did not and why. --}}
    @php($cov = $m['coverage'])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="coverage" style="margin-bottom:12px;">
        <tr>
            <td style="font-size:12px; font-weight:700; color:{{ $cov['unchecked'] ? $S::INK['watch'] : $S::INK['ok'] }};" dir="auto">{{ $ar ? "{$cov['checked']} من {$cov['total']} أرقامها محدّثة" : "{$cov['checked']} of {$cov['total']} with current figures" }}</td>
            <td width="45%" dir="ltr" style="padding-{{ $startSide === 'right' ? 'right' : 'left' }}:10px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#e6e2d6; border-radius:4px;"><tr>
                    <td width="{{ $cov['total'] > 0 ? max(1, (int) round($cov['checked'] / $cov['total'] * 100)) : 1 }}%" height="8" bgcolor="{{ $cov['unchecked'] ? '#e0a23a' : '#0d8a6f' }}" style="background-color:{{ $cov['unchecked'] ? '#e0a23a' : '#0d8a6f' }}; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td>
                    @if ($cov['checked'] < $cov['total'])<td height="8" style="font-size:1px; line-height:8px;">&nbsp;</td>@endif
                </tr></table>
            </td>
        </tr>
    </table>

    {{-- 6. Who was NOT checked, and why. Never just a count. --}}
    @if ($cov['unchecked'])
        <div style="font-size:12px; font-weight:700; color:#5b6b68; margin:4px 0 4px;">{{ $ar ? 'أرقامها ناقصة' : 'Figures incomplete' }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="unchecked" style="margin-bottom:12px;">
            @foreach ($cov['unchecked'] as $u)
                <tr data-reason="{{ $u['reason'] }}">
                    <td style="padding:3px 0; font-size:12px; font-weight:700; color:#0b1a16;">{{ \Illuminate\Support\Str::limit($u['name'], 28, '…') }}</td>
                    <td style="padding:3px 0; text-align:{{ $endSide }}; font-size:11px; color:{{ $u['reason'] === 'failed' ? $S::INK['bad'] : $S::INK['watch'] }}; font-weight:700;">{{ $u['label'] }}</td>
                    <td width="64" style="padding:3px 0; text-align:{{ $endSide }};"><a href="{{ $u['url'] }}" style="font-size:11px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $ar ? 'الربط' : 'Connect' }}</a></td>
                </tr>
            @endforeach
        </table>
    @endif

        @if ($m['linking'])
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="linking-platforms" style="margin-bottom:8px;">
                @foreach ($m['linking'] as $lp)
                    @foreach ($lp['items'] as $li)
                        <tr data-state="{{ $li['state'] }}">
                            {{-- ALERT-VISUAL-EVERY-CARD-001 — a small ring in the link's state beside each row. --}}
                            <td width="30" style="padding:2px 0;">@include('mail.partials.visual', ['viz' => null, 'state' => $li['state'] === 'failed' ? 'bad' : 'watch', 'size' => 16])</td>
                            <td style="padding:2px 0; font-size:11px; color:#3d4742;">{{ \Illuminate\Support\Str::limit($lp['name'], 22, '…') }}@if ($li['platform'] !== '') · {{ $li['platform'] }}@endif</td>
                            <td style="padding:2px 0; text-align:{{ $endSide }}; font-size:11px; font-weight:700; color:{{ $li['state'] === 'failed' ? $S::INK['bad'] : $S::INK['watch'] }};">{{ $li['label'] }}@if ($li['since'] !== '') <span style="font-weight:400; color:#8b9a97;">· {{ $ar ? 'آخر تحديث' : 'last refreshed' }} {{ $li['since'] }}</span>@endif</td>
                        </tr>
                    @endforeach
                @endforeach
            </table>
        @endif
    </div>

    @include('mail.partials.button', ['href' => $m['links']['dashboard'], 'label' => $ar ? 'افتح لوحة المتابعة' : 'Open the dashboard'])
    @endunless
</div>
