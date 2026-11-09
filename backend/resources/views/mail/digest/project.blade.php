{{--
  The project digest — DIGESTS-VISUAL-V2-001.

  Verdict, the platform strip (one chip per platform), what needs a decision (drawn, ranked), the money with its
  shape, then each platform in one compact row. No paragraph: chips, bars, deltas and buttons. Every figure is
  `OperationalDigestModel`'s string; no `?? 0` exists in this file, so «لا بيانات» can never turn into a zero here.
--}}
@php($ar = $dir === 'rtl')
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($weekly = $kind === 'weekly')
@php($st = $m['status'])
@php($h = $m['headline'])
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head', ['title' => $weekly ? ($ar ? 'المشروع · الأسبوع' : 'Project · week') : ($ar ? 'المشروع · اليوم' : 'Project · today'), 'sub' => $periodLabel])

    {{-- Verdict + the ONE figure the project is judged on. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="project-head" data-state="{{ $m['state'] }}" style="background-color:{{ $S::SOFT[$m['state']] }}; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:10px;">
        <tr>
            <td style="padding:12px 14px; vertical-align:middle;">
                <div>@include('mail.digest._chip', ['state' => $m['state'], 'size' => 24, 'label' => true])</div>
                <div style="font-size:18px; font-weight:800; color:#0b1a16; padding-top:6px;">{{ $m['name'] }}</div>
                <div style="font-size:12px; color:#5b6b68; padding-top:2px;">{{ $m['objective_label'] }} · {{ $t['kind_note'] }}</div>
            </td>
            <td width="116" style="padding:12px 10px; vertical-align:middle; text-align:center; background-color:{{ $S::CELL[$m['state']] }}; border-radius:{{ $startSide === 'right' ? '12px 0 0 12px' : '0 12px 12px 0' }};">
                <div class="ch-kpi-lead" style="font-size:24px; line-height:1.15; font-weight:800; color:{{ $h['missing'] ? '#8b9a97' : $S::INK[$m['state']] }};" dir="ltr">{{ $h['value'] }}</div>
                <div style="font-size:11px; color:#5b6b68;">{{ $h['label'] }}</div>
                @if ($h['change'])<div style="font-size:12px; font-weight:800; color:{{ $h['colour'] }};" dir="ltr">{{ $h['change'] }}</div>@endif
            </td>
        </tr>
    </table>

    {{-- The platform strip: every platform of the project, one chip each. --}}
    @if ($m['cells'])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="platform-strip" style="margin-bottom:12px; table-layout:fixed;">
            <tr>
                @foreach ($m['cells'] as $c)
                    <td align="center" data-provider="{{ $c['provider'] }}" data-state="{{ $c['state'] }}" style="padding:0 2px;">
                        <div style="font-size:10px; font-weight:700; color:#5b6b68; padding-bottom:3px; white-space:nowrap; overflow:hidden;">{{ $c['short'] }}</div>
                        <span style="display:block; height:26px; line-height:26px; border-radius:8px; text-align:center; font-size:14px; font-weight:800; background-color:{{ $S::CELL[$c['state']] }}; color:{{ $S::INK[$c['state']] }};">{{ $S::GLYPH[$c['state']] }}</span>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    {{-- What needs a decision: ranked, the problem drawn, one button each. --}}
    @if ($m['issues'])
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:4px 0 8px;">{{ $ar ? 'يحتاج قرارك' : 'Needs your decision' }}</div>
        @include('mail.digest._issues', ['items' => array_slice($m['issues'], 0, \App\Domains\Notifications\Support\OperationalDigestModel::TOP_ISSUES), 'showProject' => false, 'from' => 0])
        @if (count($m['issues']) > \App\Domains\Notifications\Support\OperationalDigestModel::TOP_ISSUES)
            <div data-block="issues-more" style="font-size:12px; font-weight:700; color:#5b6b68; margin-bottom:10px;">+{{ count($m['issues']) - \App\Domains\Notifications\Support\OperationalDigestModel::TOP_ISSUES }} {{ $ar ? 'أقل أولوية' : 'lower priority' }} · <a href="{{ $m['links']['attention'] }}" style="color:#0b7a62; text-decoration:underline;">{{ $ar ? 'عرضها' : 'See them' }}</a></div>
        @endif
    @else
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:10px;"><tr><td bgcolor="{{ $S::CELL['ok'] }}" style="background-color:{{ $S::CELL['ok'] }}; border-radius:10px; padding:10px 14px; font-size:13px; font-weight:700; color:{{ $S::INK['ok'] }};">✓ {{ $ar ? 'لا شيء يحتاج قرارك' : 'Nothing needs your decision' }}</td></tr></table>
    @endif

    {{-- The money, with its shape: the week's bars carry weekday initials, the last bar is the strong one. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin:6px 0 10px;">
        <tr><td style="padding:2px 12px 10px;">
            @include('mail.digest._figures', ['figures' => $m['kpis'], 'per' => 3, 'big' => true])
            @include('mail.digest._spark', ['bars' => $m['spark'], 'colour' => '#0d8a6f', 'height' => $weekly ? 40 : 22, 'labels' => $weekly])
        </td></tr>
    </table>

    {{-- One block PER OBJECTIVE: its own tiles, its platforms with the objective's own figure, its funnel. Unlike objectives are never averaged. --}}
    @foreach ($m['groups'] as $g)
        @include('mail.digest._group', ['g' => $g])
    @endforeach
    @if (! $m['groups'])
        @forelse ($m['platforms'] as $pf)
            <div style="font-size:12px; font-weight:800; margin:4px 0;">{{ $pf['name'] }}</div>
            @include('mail.digest._figures', ['figures' => $pf['figures'], 'per' => 3, 'big' => false])
        @empty
            <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $t['no_platforms'] }}</div>
        @endforelse
    @endif

    @if ($m['content'] && ($m['content']['top'] || $m['content']['weak']))
        <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 6px;">{{ $t['content'] }}</div>
        @foreach (['top' => 'good', 'weak' => 'warn'] as $group => $c)
            @foreach ($m['content'][$group] as $item)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="content-{{ $group }}" style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:8px;">
                    <tr>
                        <td width="64" style="padding:8px; vertical-align:top;">
                            @if (! empty($item['cover']))
                                <img src="{{ $item['cover'] }}" width="56" height="56" alt="{{ $item['name'] }}" style="display:block; width:56px; height:56px; object-fit:cover; border-radius:8px; border:0;">
                            @else
                                <table role="presentation" width="56" height="56" cellpadding="0" cellspacing="0" border="0"><tr><td width="56" height="56" bgcolor="{{ $toneBg[$c] }}" style="background-color:{{ $toneBg[$c] }}; border-radius:8px; height:56px;">&nbsp;</td></tr></table>
                            @endif
                        </td>
                        <td style="padding:8px 8px 8px 0; vertical-align:top;">
                            <div style="font-size:11px; font-weight:700; color:{{ $tone[$c] }};">{{ $group === 'top' ? $t['top'] : $t['weak'] }}</div>
                            <div style="font-size:12px; color:#3d4742; line-height:1.6;">{{ $item['reason'] ?? $item['why'] ?? '' }}</div>
                            <div style="font-size:11px; color:#8b9a97; padding-top:2px; unicode-bidi:plaintext;">{{ \App\Domains\Notifications\Support\AlertEventText::truncate((string) $item['name']) }}</div>
                        </td>
                    </tr>
                </table>
            @endforeach
        @endforeach
        <div style="text-align:center; margin:4px 0 10px;"><a href="{{ $m['links']['content'] }}" style="font-size:13px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $t['open_content'] }}</a></div>
    @endif

    @if (! empty($m['follow_up']))
        @php($f = $m['follow_up'])
        <div data-block="follow-up" style="font-size:13px; color:#3d4742; line-height:1.8; margin:10px 0;">
            <span style="font-weight:700;">{{ $t['follow'] }}:</span>
            {{ $dir === 'rtl' ? 'وصل' : 'Received' }} {{ number_format((int) ($f['received'] ?? 0)) }} · {{ $dir === 'rtl' ? 'بلا تواصل' : 'not contacted' }} {{ number_format((int) ($f['not_contacted'] ?? 0)) }} · {{ $dir === 'rtl' ? 'متأخر' : 'overdue' }} {{ number_format((int) ($f['overdue'] ?? 0)) }}
        </div>
    @endif

    @include('mail.partials.button', ['href' => $m['links']['report'], 'label' => $t['open_report']])

</div>
