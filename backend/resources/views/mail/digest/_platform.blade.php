{{-- One platform of one project: share of spend, figures by the project's objective, the window's shape, budget pace. Needs $pf. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="platform" data-provider="{{ $pf['provider'] }}" style="border:1px solid #e4e0d6; border-radius:12px; margin-bottom:10px;">
    <tr>
        <td style="padding:12px 14px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="font-size:15px; font-weight:800; color:#0b1a16;">{{ $pf['name'] }}</td>
                    <td style="text-align:{{ $endSide }}; white-space:nowrap;">
                        @if ($pf['pacing'])
                            <span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:700; background-color:{{ $toneBg[$pf['pacing']['tone']] }}; color:{{ $tone[$pf['pacing']['tone']] }};">{{ $pf['pacing']['text'] }} · {{ $pf['pacing']['label'] }}</span>
                        @endif
                    </td>
                </tr>
            </table>
            {{-- Its share of the project's spend, as one bar. --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:6px;">
                <tr>
                    <td style="background-color:#eeeae0; border-radius:4px;">
                        <table role="presentation" width="{{ max(2, $pf['share']) }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="8" style="background-color:#0d8a6f; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td></tr></table>
                    </td>
                    <td width="46" style="padding-{{ $startSide }}:8px; font-size:12px; font-weight:700; color:#5b6b68; white-space:nowrap;" dir="ltr">{{ $pf['share'] }}%</td>
                </tr>
            </table>
            @include('mail.digest._figures', ['figures' => $pf['figures'], 'big' => false])
            @include('mail.digest._spark', ['bars' => $pf['spark'], 'colour' => '#0d8a6f'])
        </td>
    </tr>
</table>
