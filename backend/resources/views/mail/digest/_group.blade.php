{{-- One OBJECTIVE group of a project: a label chip, ITS tile row, its platforms (spend and the objective's own headline figure), its funnel. Needs $g, $label (bool: show the chip). --}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="group" data-objective="{{ $g['objective'] }}" style="border:1px solid #e4e0d6; border-radius:12px; margin:0 0 10px;">
    <tr><td style="padding:10px 12px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td><span data-chip="objective" style="display:inline-block; padding:2px 10px; border-radius:999px; font-size:11px; font-weight:800; background-color:#e8f5f1; color:#0a6b57;">{{ $g['label'] }}</span>
                <span style="font-size:11px; color:#8b9a97;" dir="ltr">· {{ $g['campaigns'] }}</span></td>
            <td style="text-align:{{ $endSide }};">@include('mail.digest._chip', ['state' => $g['state'], 'size' => 18])</td>
        </tr></table>
        @include('mail.digest._figures', ['figures' => $g['kpis'], 'per' => 3, 'big' => false])

        @if ($g['platforms'])
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="group-platforms" style="margin-top:8px; border-top:1px solid #eeeae0;">
                @foreach ($g['platforms'] as $pf)
                    <tr data-provider="{{ $pf['provider'] }}" data-state="{{ $pf['state'] }}">
                        <td width="26" valign="middle" style="padding:5px 0;">@include('mail.digest._chip', ['state' => $pf['state'], 'size' => 18])</td>
                        <td valign="middle" style="padding:5px 6px;">
                            <div style="font-size:12px; font-weight:800; color:#0b1a16;">{{ $pf['name'] }}</div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td dir="ltr" style="background-color:#eeeae0; border-radius:3px;">
                                <table role="presentation" width="{{ max(3, $pf['share']) }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="5" bgcolor="#0d8a6f" style="background-color:#0d8a6f; height:5px; line-height:5px; font-size:1px; border-radius:3px;">&nbsp;</td></tr></table>
                            </td></tr></table>
                        </td>
                        @foreach ($pf['figures'] as $f)
                            <td width="26%" align="center" valign="middle" style="padding:5px 0; text-align:center;">
                                <div style="font-size:12px; font-weight:800; color:{{ $f['missing'] ? '#8b9a97' : '#0b1a16' }};" dir="ltr">{{ $f['missing'] ? '—' : $f['value'] }}</div>
                                <div style="font-size:10px; font-weight:700; color:{{ $f['colour'] }};" dir="ltr">{{ $f['missing'] ? ($dir === 'rtl' ? 'لا بيانات' : 'No data') : ($f['change'] ?: ' ') }}</div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif

        @if ($g['funnel'])
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="funnel" style="font-size:11px; margin-top:6px;">
                @foreach ($g['funnel'] as $st)
                    <tr>
                        <td width="28%" style="padding:2px 0; color:#3d4742;">{{ $st['label'] }}</td>
                        <td style="padding:2px 4px;" dir="ltr"><table role="presentation" width="{{ $st['width'] }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="6" bgcolor="#0d8a6f" style="background-color:#0d8a6f; height:6px; line-height:6px; font-size:1px; border-radius:3px;">&nbsp;</td></tr></table></td>
                        <td width="30%" style="padding:2px 0; text-align:{{ $endSide }}; white-space:nowrap;" dir="ltr">{{ $st['count'] }}@if ($st['step']) <span style="color:#8b9a97;">({{ $st['step'] }})</span>@endif</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </td></tr>
</table>
