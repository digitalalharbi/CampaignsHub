{{--
  The portfolio digest — DIGESTS-VISUAL-V2-001.

  Built for up to thirty projects with five to seven platforms each, read in the time it takes to drink a coffee:
  the counts first (✕ ! ✓ –), then WHO needs a decision in ONE ranked list, then the project × platform heatmap that
  shows EVERY project once, then who was not checked at all. No paragraph anywhere: a chip, a bar, a delta and a button.
  The status of every cell is `DigestStatus`; the figures are the report link's own.
--}}
@php($ar = $dir === 'rtl')
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($weekly = $kind === 'weekly')
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head', ['title' => $weekly ? ($ar ? 'المحفظة · الأسبوع' : 'Portfolio · week') : ($ar ? 'المحفظة · اليوم' : 'Portfolio · today'), 'sub' => $periodLabel])

    {{-- 1. Four counts, as four tiles. The Owner's first question: how many are on fire? --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="counts" style="margin-bottom:8px; table-layout:fixed;">
        <tr>
            @foreach (['bad', 'watch', 'ok', 'none'] as $k)
                <td style="padding:0 {{ $loop->last ? 0 : 4 }}px 0 {{ $loop->first ? 0 : 0 }}px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                        <td align="center" bgcolor="{{ $S::CELL[$k] }}" data-count="{{ $k }}" style="background-color:{{ $S::CELL[$k] }}; border-radius:10px; padding:8px 2px;">
                            <div style="font-size:24px; line-height:1.1; font-weight:800; color:{{ $S::INK[$k] }};" dir="ltr">{{ $m['health'][$k] }}</div>
                            <div style="font-size:11px; font-weight:700; color:{{ $S::INK[$k] }};">{{ $S::GLYPH[$k] }} {{ $S::label($k, $ar) }}</div>
                        </td>
                    </tr></table>
                </td>
            @endforeach
        </tr>
    </table>

    {{-- 2. Coverage: «30 of 30 projects checked» as a bar and a line — and below it, who was not. --}}
    @php($cov = $m['coverage'])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="coverage" style="margin-bottom:12px;">
        <tr>
            <td style="font-size:12px; font-weight:700; color:{{ $cov['unchecked'] ? $S::INK['watch'] : $S::INK['ok'] }};" dir="auto">{{ $ar ? "{$cov['checked']} من {$cov['total']} مشروعًا مفحوصة" : "{$cov['checked']} of {$cov['total']} projects checked" }}</td>
            <td width="45%" dir="ltr" style="padding-{{ $startSide === 'right' ? 'right' : 'left' }}:10px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#e6e2d6; border-radius:4px;"><tr>
                    <td width="{{ $cov['total'] > 0 ? max(1, (int) round($cov['checked'] / $cov['total'] * 100)) : 1 }}%" height="8" bgcolor="{{ $cov['unchecked'] ? '#e0a23a' : '#0d8a6f' }}" style="background-color:{{ $cov['unchecked'] ? '#e0a23a' : '#0d8a6f' }}; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td>
                    @if ($cov['checked'] < $cov['total'])<td height="8" style="font-size:1px; line-height:8px;">&nbsp;</td>@endif
                </tr></table>
            </td>
        </tr>
    </table>

    {{-- 3. The money, one line each, with the week's shape. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:14px;">
        <tr><td style="padding:2px 14px 12px;">
            @include('mail.digest._figures', ['figures' => $m['kpis'], 'big' => true])
            @include('mail.digest._spark', ['bars' => $m['spark'], 'colour' => '#0d8a6f', 'height' => $weekly ? 34 : 20, 'labels' => $weekly])
        </td></tr>
    </table>

    {{-- 4. WHO needs a decision — one list for the whole portfolio, in global priority order. --}}
    <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:4px 0 8px;">{{ $ar ? 'يحتاج قرارك الآن' : 'Needs your decision now' }}</div>
    @if ($m['issues_shown'])
        @include('mail.digest._issues', ['items' => $m['issues_shown'], 'showProject' => true, 'from' => 0])
        @if ($m['issues_more'] > 0)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="issues-more" style="margin-bottom:10px;"><tr>
                <td style="font-size:12px; font-weight:700; color:#5b6b68;"><span dir="ltr">+{{ $m['issues_more'] }}</span> {{ $ar ? 'أقل أولوية' : 'lower priority' }}</td>
                <td style="text-align:{{ $endSide }};"><a href="{{ $m['links']['alerts'] }}" style="font-size:12px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $ar ? 'عرضها كلها' : 'See them all' }} {{ $ar ? '←' : '→' }}</a></td>
            </tr></table>
        @endif
    @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:10px;"><tr><td bgcolor="{{ $S::CELL['ok'] }}" style="background-color:{{ $S::CELL['ok'] }}; border-radius:10px; padding:10px 14px; font-size:13px; font-weight:700; color:{{ $S::INK['ok'] }};">✓ {{ $ar ? 'لا شيء يحتاج قرارك' : 'Nothing needs your decision' }}</td></tr></table>
    @endif

    {{-- 5. The heatmap: every project once, every platform once. A cell is a chip; healthy rows stay one line. --}}
    @php($hm = $m['heatmap'])
    @php($cols = count($hm['columns']))
    @php($cw = $cols > 6 ? 32 : 36)
    <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:14px 0 6px;">{{ $ar ? 'كل المشاريع × المنصات' : 'Every project × platform' }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="heatmap" style="table-layout:fixed;">
        <tr data-block="heatmap-head">
            <td style="font-size:10px; color:#8b9a97; padding:0 0 3px;">&nbsp;</td>
            @foreach ($hm['columns'] as $col)
                <td width="{{ $cw }}" align="center" data-provider="{{ $col['provider'] }}" style="width:{{ $cw }}px; font-size:9px; font-weight:700; color:#5b6b68; padding:0 1px 3px; text-align:center; overflow:hidden; white-space:nowrap;">{{ $col['short'] }}</td>
            @endforeach
        </tr>
        @foreach ($hm['rows'] as $row)
            <tr data-block="portfolio-row" data-state="{{ $row['state'] }}" data-project="{{ $row['id'] }}">
                <td style="padding:1px 0; font-size:12px; font-weight:{{ in_array($row['state'], ['bad', 'watch'], true) ? 800 : 600 }}; color:{{ $row['state'] === 'none' ? '#8b9a97' : '#0b1a16' }}; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><a href="{{ $row['url'] }}" style="color:inherit; text-decoration:none;">{{ \Illuminate\Support\Str::limit($row['name'], 18, '…') }}</a></td>
                @foreach ($row['cells'] as $cell)
                    <td width="{{ $cw }}" align="center" style="width:{{ $cw }}px; padding:1px 1px;">
                        @if ($cell === null)
                            <span style="display:block; height:22px; line-height:22px; font-size:11px; color:#c9c4b6; text-align:center;">·</span>
                        @else
                            <span data-state="{{ $cell['state'] }}" style="display:block; height:20px; line-height:20px; border-radius:6px; text-align:center; font-size:12px; font-weight:800; background-color:{{ $S::CELL[$cell['state']] }}; color:{{ $S::INK[$cell['state']] }};">{{ $S::GLYPH[$cell['state']] }}</span>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="legend" style="margin:8px 0 12px;"><tr>
        @foreach (['ok', 'watch', 'bad', 'none'] as $k)
            <td style="padding-{{ $endSide }}:10px; font-size:11px; color:#5b6b68;">@include('mail.digest._chip', ['state' => $k, 'size' => 16]) {{ $S::label($k, $ar) }}</td>
        @endforeach
    </tr></table>

    {{-- 6. Who was NOT checked, and why. Never just a count. --}}
    @if ($cov['unchecked'])
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:8px 0 6px;">{{ $ar ? 'غير مفحوص' : 'Not checked' }}</div>
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

    {{-- 7. Weekly only: who moved most against the previous week. --}}
    @if ($weekly && ($m['movers']['best'] || $m['movers']['worst']))
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:8px 0 6px;">{{ $ar ? 'الأفضل والأضعف مقارنة بالأسبوع السابق' : 'Best and weakest vs the previous week' }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="movers" style="margin-bottom:12px;">
            @foreach (['best' => 'ok', 'worst' => 'bad'] as $k => $c)
                @foreach ($m['movers'][$k] as $r)
                    <tr>
                        <td width="22" style="padding:3px 0;">@include('mail.digest._chip', ['state' => $c, 'size' => 18])</td>
                        <td style="padding:3px 6px; font-size:12px; font-weight:700; color:#0b1a16;">{{ \Illuminate\Support\Str::limit($r['name'], 22, '…') }}</td>
                        <td style="padding:3px 0; text-align:{{ $endSide }}; font-size:12px; color:#3d4742; white-space:nowrap;" dir="ltr">{{ $r['value'] }} <span style="font-weight:700; color:{{ $S::INK[$c] }};">{{ $r['change'] }}</span></td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    @endif

    @include('mail.partials.button', ['href' => $m['links']['dashboard'], 'label' => $ar ? 'افتح لوحة المتابعة' : 'Open the dashboard'])
    <div style="font-size:11px; color:#8b9a97; line-height:1.7; margin-top:14px;">{{ $t['unit_note'] }}</div>
</div>
