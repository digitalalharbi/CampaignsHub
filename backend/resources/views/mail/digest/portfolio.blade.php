{{--
  The portfolio digest — EMAIL-DIGESTS-OPERATIONAL-001. Every project of the reader, worst first.
  The ideas the Owner liked in his other product (status with a plain reason, health, notable moves,
  decisions, ONE headline figure per row) in CampaignsHub's own template, with each project's
  platforms underneath. Same model, same figures as the project digest and the report link.
--}}
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @include('mail.digest._head')
    <div style="font-size:14px; color:#5b6b68; margin:0 0 16px;">{{ $t['intro_portfolio'] }} {{ $t['kind_note'] }}.</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:14px;">
        <tr><td style="padding:6px 16px 14px;">
            @include('mail.digest._figures', ['figures' => $m['kpis'], 'big' => true])
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;"><tr>
                <td width="50%" style="vertical-align:top;"><div style="font-size:11px; color:#5b6b68;">{{ $t['projects'] }}</div><div style="font-size:18px; font-weight:800;" dir="ltr">{{ $m['projects_total'] }}</div></td>
                <td width="50%" style="vertical-align:top;"><div style="font-size:11px; color:#5b6b68;">{{ $dir === 'rtl' ? 'تحتاج انتباهًا' : 'Need attention' }}</div><div style="font-size:18px; font-weight:800; color:{{ $m['need_attention'] ? $tone['bad'] : $tone['good'] }};" dir="ltr">{{ $m['need_attention'] }}</div></td>
            </tr></table>
            @include('mail.digest._spark', ['bars' => $m['spark'], 'colour' => '#0d8a6f'])
        </td></tr>
    </table>

    @if ($m['projects_total'] > 1)
        <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin-bottom:6px;">{{ $t['health'] }}</div>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="health" style="margin-bottom:14px;"><tr>
            @foreach (['good', 'warn', 'bad', 'neutral'] as $k)
                @if ($m['health'][$k] > 0)
                    <td style="padding-{{ $endSide }}:8px;"><span style="display:inline-block; padding:5px 12px; border-radius:999px; font-size:12px; font-weight:700; background-color:{{ $toneBg[$k] }}; color:{{ $tone[$k] }};" dir="ltr">{{ $m['health'][$k] }} {{ \App\Domains\Notifications\Support\OperationalDigestModel::statusLabel($k, $dir === 'rtl') }}</span></td>
                @endif
            @endforeach
        </tr></table>
    @endif

    @if ($m['moves']['best'] || $m['moves']['worst'])
        <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin-bottom:6px;">{{ $t['moves'] }}</div>
        @foreach (['best' => 'good', 'worst' => 'bad'] as $k => $c)
            @if ($m['moves'][$k])
                <div data-block="move-{{ $k }}" style="margin-bottom:8px; padding:10px 12px; border-radius:10px; background-color:{{ $toneBg[$c] }}; border-{{ $startSide }}:3px solid {{ $tone[$c] }};">
                    <div style="font-size:13px; font-weight:700;">{{ $m['moves'][$k]['name'] }} — {{ $k === 'best' ? $t['best_move'] : $t['worst_move'] }}</div>
                    <div style="font-size:12px; color:{{ $tone[$c] }};">{{ $m['moves'][$k]['text'] }}</div>
                </div>
            @endif
        @endforeach
    @endif

    <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 8px;">{{ $t['needs'] }}</div>
    @if ($m['decisions'])
        @include('mail.digest._decisions', ['items' => $m['decisions'], 'showProject' => true])
        <div style="text-align:center; margin:4px 0 10px;"><a href="{{ $m['links']['alerts'] }}" style="font-size:13px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $t['open_alerts'] }}</a></div>
    @else
        <div style="font-size:13px; color:#5b6b68; margin-bottom:10px;">{{ $t['nothing'] }}</div>
    @endif

    @if ($m['mix'])
        <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 6px;">{{ $t['mix'] }}</div>
        {{-- A stacked bar of cells, one per platform, with its legend: the table-based donut. --}}
        @php($palette = ['#0d8a6f', '#e8a33d', '#3d6bd8', '#b45309', '#8b5cf6', '#0b7285', '#5b6b68'])
        <table role="presentation" dir="ltr" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="mix" style="margin-bottom:6px;"><tr>
            @foreach ($m['mix'] as $i => $seg)
                <td width="{{ max(2, $seg['share']) }}%" height="14" bgcolor="{{ $palette[$i % 7] }}" style="background-color:{{ $palette[$i % 7] }}; height:14px; line-height:14px; font-size:1px;">&nbsp;</td>
            @endforeach
        </tr></table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px; margin-bottom:14px;">
            @foreach ($m['mix'] as $i => $seg)
                <tr>
                    <td width="16" style="padding:2px 0;"><span style="display:inline-block; width:10px; height:10px; border-radius:5px; background-color:{{ $palette[$i % 7] }};"></span></td>
                    <td style="padding:2px 4px;">{{ $seg['name'] }}</td>
                    <td style="padding:2px 4px; font-weight:700; text-align:{{ $endSide }};" dir="ltr">{{ $seg['share'] }}%</td>
                    <td style="padding:2px 0; color:#5b6b68; text-align:{{ $endSide }};" dir="ltr">{{ $seg['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div style="font-size:12px; font-weight:700; color:#5b6b68; letter-spacing:0.4px; margin:14px 0 8px;">{{ $t['projects'] }}</div>
    @foreach ($m['projects'] as $pr)
        @php($st = $pr['status'])
        @php($h = $pr['headline'])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="portfolio-row" style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:10px;">
            <tr>
                <td style="padding:12px 14px; vertical-align:top;">
                    <div><span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; background-color:{{ $toneBg[$st['tone']] }}; color:{{ $tone[$st['tone']] }};">{{ $st['label'] }}</span>
                        <span style="font-size:15px; font-weight:800; padding-{{ $startSide }}:6px;">{{ $pr['name'] }}</span></div>
                    <div style="font-size:12px; color:#5b6b68; line-height:1.6; margin-top:4px;">{{ $pr['objective_label'] }} · {{ $st['reason'] }}</div>
                </td>
                <td width="104" style="padding:12px 10px; vertical-align:middle; text-align:center; background-color:{{ $toneBg[$st['tone']] }}; border-radius:{{ $startSide === 'right' ? '12px 0 0 12px' : '0 12px 12px 0' }};">
                    <div class="ch-kpi-lead" style="font-size:20px; line-height:1.15; font-weight:800; color:{{ $h['missing'] ? '#8b9a97' : $tone[$st['tone']] }};" dir="ltr">{{ $h['value'] }}</div>
                    <div style="font-size:11px; color:#5b6b68;">{{ $h['label'] }}</div>
                    @if ($h['change'])<div style="font-size:11px; font-weight:700; color:{{ $h['colour'] }};" dir="ltr">{{ $h['change'] }}</div>@endif
                </td>
            </tr>
            <tr><td colspan="2" style="padding:0 14px 8px;">
                @include('mail.digest._figures', ['figures' => array_slice($pr['kpis'], 0, 4), 'big' => false])
                {{-- Every platform of the project in one line each: name, spend and the objective's result. --}}
                @if ($pr['platforms'])
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px; border-top:1px solid #eeeae0; font-size:12px;">
                        @foreach ($pr['platforms'] as $pf)
                            <tr>
                                <td style="padding:4px 0; font-weight:700;">{{ $pf['name'] }}</td>
                                <td style="padding:4px 0; text-align:{{ $endSide }}; color:#3d4742;" dir="auto">{{ $pf['figures'][0]['value'] }}@if (isset($pf['figures'][1])) · {{ $pf['figures'][1]['label'] }} {{ $pf['figures'][1]['value'] }}@endif</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                <div style="padding-top:8px;"><a href="{{ $pr['links']['report'] }}" style="font-size:12px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $t['open_project'] }}</a> &nbsp;·&nbsp; <a href="{{ $pr['links']['platforms'] }}" style="font-size:12px; font-weight:700; color:#0b7a62; text-decoration:underline;">{{ $t['open_platforms'] }}</a></div>
            </td></tr>
        </table>
    @endforeach

    @include('mail.partials.button', ['href' => $m['links']['dashboard'], 'label' => $dir === 'rtl' ? 'افتح لوحة المتابعة' : 'Open the dashboard'])
    <div style="font-size:11px; color:#8b9a97; line-height:1.7; margin-top:18px;">{{ $t['unit_note'] }}</div>
</div>
