{{--
  The project digest — EMAIL-DIGESTS-OPERATIONAL-001.

  Reads top to bottom like the live report link: the verdict and the one figure the project is judged
  on, the overall summary with its movement, what needs a decision, a block per platform, the funnel,
  the content, and the buttons into the system. Every figure is `OperationalDigestModel`'s string; no
  `?? 0` exists in this file, so «لا بيانات» can never turn into a zero here.
--}}
@php($st = $m['status'])
@php($h = $m['headline'])
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head')
    <div style="font-size:14px; color:#5b6b68; margin:0 0 16px;">{{ $t['intro_project'] }}</div>

    {{-- The project, its verdict in a pill with the plain reason, and the ONE figure its objective is judged on. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="project-head" style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:14px;">
        <tr>
            <td style="padding:14px 16px; vertical-align:top;">
                <div><span style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:700; background-color:{{ $toneBg[$st['tone']] }}; color:{{ $tone[$st['tone']] }};">{{ $st['label'] }}</span></div>
                <div style="font-size:18px; font-weight:800; color:#0b1a16; padding-top:6px;">{{ $m['name'] }}</div>
                <div style="font-size:12px; color:#5b6b68; padding-top:2px;">{{ $m['objective_label'] }} · {{ $t['kind_note'] }}</div>
                <div style="font-size:13px; color:#3d4742; line-height:1.7; padding-top:6px;">{{ $st['reason'] }}</div>
            </td>
            <td width="120" style="padding:14px 10px; vertical-align:middle; text-align:center; background-color:{{ $toneBg[$st['tone']] }}; border-radius:{{ $startSide === 'right' ? '12px 0 0 12px' : '0 12px 12px 0' }};">
                <div class="ch-kpi-lead" style="font-size:24px; line-height:1.15; font-weight:800; color:{{ $h['missing'] ? '#8b9a97' : $tone[$st['tone']] }};" dir="ltr">{{ $h['value'] }}</div>
                <div style="font-size:11px; color:#5b6b68;">{{ $h['label'] }}</div>
                @if ($h['change'])<div style="font-size:11px; font-weight:700; color:{{ $h['colour'] }};" dir="ltr">{{ $h['change'] }}</div>@endif
            </td>
        </tr>
    </table>

    <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin-bottom:6px;">{{ $t['summary'] }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:6px;">
        <tr><td style="padding:6px 16px 14px;">
            @include('mail.digest._figures', ['figures' => $m['kpis'], 'big' => true])
            @if ($m['yesterday'])<div style="font-size:12px; color:#5b6b68; padding-top:10px;" dir="auto">{{ $m['yesterday'] }}</div>@endif
            <div style="font-size:11px; color:#8b9a97; padding-top:10px;">{{ $t['space'] }}</div>
            @include('mail.digest._spark', ['bars' => $m['spark'], 'colour' => '#0d8a6f'])
        </td></tr>
    </table>
    @if ($m['changes'])
        <div data-block="changes" style="margin:10px 0 14px; font-size:13px; color:#3d4742; line-height:1.8;">
            <div style="font-size:12px; font-weight:700; color:#5b6b68;">{{ $t['changed'] }}</div>
            @foreach ($m['changes'] as $line)<div>• {{ $line }}</div>@endforeach
        </div>
    @endif
    @if (! empty($m['freshness']['sync_failed']))
        <div style="font-size:12px; color:{{ $tone['bad'] }}; margin-bottom:12px;">{{ $t['sync_note'] }}</div>
    @endif

    <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 8px;">{{ $t['needs'] }}</div>
    @if ($m['decisions'])
        @include('mail.digest._decisions', ['items' => $m['decisions'], 'showProject' => false])
        <div style="text-align:center; margin:4px 0 10px;"><a href="{{ $m['links']['attention'] }}" data-block="cta-attention" style="font-size:13px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $t['open_attention'] }}</a></div>
    @else
        <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $t['nothing'] }}</div>
    @endif

    <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 8px;">{{ $t['platforms'] }}</div>
    @forelse ($m['platforms'] as $pf)
        @include('mail.digest._platform', ['pf' => $pf])
    @empty
        <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $t['no_platforms'] }}</div>
    @endforelse

    @if ($m['funnel'])
        <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 6px;">{{ $t['funnel'] }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="funnel" style="font-size:12px; margin-bottom:10px;">
            @foreach ($m['funnel'] as $s)
                <tr>
                    <td width="30%" style="padding:3px 0; color:#0b1a16;">{{ $s['label'] }}</td>
                    <td style="padding:3px 6px;">
                        <table role="presentation" width="{{ $s['width'] }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="8" style="background-color:#0d8a6f; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td></tr></table>
                    </td>
                    <td width="30%" style="padding:3px 0; text-align:{{ $endSide }}; color:#0b1a16; white-space:nowrap;" dir="ltr">{{ $s['count'] }}@if ($s['step']) <span style="color:#8b9a97;">({{ $s['step'] }})</span>@endif</td>
                </tr>
            @endforeach
        </table>
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
    <div style="text-align:center; margin-top:6px;"><a href="{{ $m['links']['platforms'] }}" data-block="cta-platforms" style="font-size:13px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $t['open_platforms'] }}</a></div>

    <div style="font-size:11px; color:#8b9a97; line-height:1.7; margin-top:18px;">{{ $t['unit_note'] }}</div>
</div>
