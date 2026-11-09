{{-- Bars made of table cells: no SVG, no script, no image. LTR so the newest day is always on the right. Needs $bars, $colour. --}}
@if (count($bars) > 1)
<table role="presentation" dir="ltr" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="spark" style="table-layout:fixed; margin-top:6px;">
    <tr>
        @foreach ($bars as $b)
            <td valign="bottom" height="44" style="padding:0 1px; height:44px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td height="{{ $b['h'] }}" bgcolor="{{ $b['missing'] ? '#e4e0d6' : $colour }}" style="background-color:{{ $b['missing'] ? '#e4e0d6' : $colour }}; height:{{ $b['h'] }}px; line-height:{{ $b['h'] }}px; font-size:1px; border-radius:2px;">&nbsp;</td></tr>
                </table>
            </td>
        @endforeach
    </tr>
</table>
@endif
