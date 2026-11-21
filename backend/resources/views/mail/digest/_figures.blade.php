{{--
  Tiles: ONE table row of equal-width cells, label on top and the NUMBER UNDER it, centred, `per` to a row (3 by default).
  Cells carry their width as a percentage on the cell itself — no media query, no `display:block`, no stacking class — so a phone
  keeps them side by side in Gmail, Outlook and Apple Mail alike. Needs $figures; optional $per, $big (bool).
--}}
@php($per = $per ?? 3)
@php($w = (int) floor(100 / $per))
@foreach (array_chunk($figures, $per) as $row)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="tiles" style="margin-top:6px; table-layout:fixed;">
    <tr>
        @foreach ($row as $i => $f)
            <td width="{{ $w }}%" align="center" valign="top" data-tile="{{ $f['key'] ?? '' }}" style="width:{{ $w }}%; padding:0 {{ $i === count($row) - 1 ? 0 : 3 }}px 0 {{ $i === 0 ? 0 : 3 }}px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td align="center" bgcolor="#faf8f3" style="background-color:#faf8f3; border:1px solid #eeeae0; border-radius:10px; padding:4px 2px; text-align:center;">
                        <div style="font-size:10px; line-height:1.3; color:#5b6b68; text-align:center;">{{ $f['label'] }}</div>
                        <div style="font-size:{{ ($big ?? false) ? 16 : 13 }}px; line-height:1.25; font-weight:800; color:{{ $f['missing'] ? '#8b9a97' : '#0b1a16' }}; text-align:center;" @if (! $f['missing']) dir="ltr" @endif>{{ $f['missing'] ? '—' : $f['value'] }}</div>
                        <div style="font-size:11px; line-height:1.3; font-weight:700; color:{{ $f['colour'] }}; text-align:center;" dir="ltr">{{ $f['missing'] ? ($dir === 'rtl' ? 'لا بيانات' : 'No data') : ($f['change'] ?: ' ') }}</div>
                    </td>
                </tr></table>
            </td>
        @endforeach
        @for ($pad = count($row); $pad < $per; $pad++)<td width="{{ $w }}%" style="width:{{ $w }}%;">&nbsp;</td>@endfor
    </tr>
</table>
@endforeach
