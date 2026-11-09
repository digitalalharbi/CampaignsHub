{{-- Bars made of table cells: no SVG, no script, no image. LTR so the newest day is always on the right; the last bar is the strong one. Needs $bars, $colour; optional $height (px), $labels (bool). --}}
@if (count($bars) > 1)
@php($hh = $height ?? 40)
<table role="presentation" dir="ltr" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="spark" style="table-layout:fixed; margin-top:6px;">
    <tr>
        @foreach ($bars as $i => $b)
            <td valign="bottom" height="{{ $hh + 4 }}" style="padding:0 2px; height:{{ $hh + 4 }}px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td height="{{ max(3, (int) round($b['h'] * $hh / 40)) }}" bgcolor="{{ $b['missing'] ? '#e4e0d6' : ($i === count($bars) - 1 ? $colour : '#9ccfc0') }}" style="background-color:{{ $b['missing'] ? '#e4e0d6' : ($i === count($bars) - 1 ? $colour : '#9ccfc0') }}; height:{{ max(3, (int) round($b['h'] * $hh / 40)) }}px; line-height:{{ max(3, (int) round($b['h'] * $hh / 40)) }}px; font-size:1px; border-radius:2px;">&nbsp;</td></tr>
                </table>
            </td>
        @endforeach
    </tr>
    @if (! empty($labels) && isset($bars[0]['label']))
        <tr>
            @foreach ($bars as $b)<td align="center" style="font-size:9px; color:#8b9a97; padding-top:2px;">{{ $b['label'] ?? '' }}</td>@endforeach
        </tr>
    @endif
</table>
@endif
