{{-- The problem, drawn: two bars made of cells (before → now, or planned → spent), no script, no image. Needs $viz. LTR so a bar always grows from the same edge. --}}
@if ($viz)
    @php($rows = $viz['type'] === 'pace'
        ? [[$viz['plan_label'], $viz['plan'], '#b7c9c2'], [$viz['used_label'], $viz['used'], $viz['used'] > $viz['plan'] ? '#c0392b' : '#b45309']]
        : [[($dir === 'rtl' ? 'قبل ' : 'Before ').$viz['before_label'], $viz['before'], '#b7c9c2'], [($dir === 'rtl' ? 'الآن ' : 'Now ').$viz['now_label'], $viz['now'], '#c0392b']])
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="viz" style="margin-top:6px;">
        @foreach ($rows as [$text, $pct, $colour])
            <tr>
                <td width="46%" dir="{{ $dir }}" style="padding:1px 0; font-size:11px; color:#3d4742; white-space:nowrap; text-align:{{ $startSide }};">{{ $text }}</td>
                <td dir="ltr" style="padding:1px 0;">
                    <table role="presentation" width="{{ max(4, min(100, $pct)) }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="7" bgcolor="{{ $colour }}" style="background-color:{{ $colour }}; height:7px; line-height:7px; font-size:1px; border-radius:3px;">&nbsp;</td></tr></table>
                </td>
            </tr>
        @endforeach
    </table>
@endif
