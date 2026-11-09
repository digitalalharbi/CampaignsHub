{{-- Figures two to a row: a four-column strip does not fit a 375px phone. Needs $figures, $big (bool). --}}
@foreach (array_chunk($figures, 2) as $pair)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;">
    <tr>
        @foreach ($pair as $f)
            <td width="50%" style="vertical-align:top; padding:0 6px 0 0;">
                <div style="font-size:11px; color:#5b6b68;">{{ $f['label'] }}</div>
                <div style="font-size:{{ ($big ?? false) ? 18 : 15 }}px; font-weight:800; color:{{ $f['missing'] ? '#8b9a97' : '#0b1a16' }}; text-align:{{ $startSide }};" @if (! $f['missing']) dir="ltr" @endif>{{ $f['value'] }}@if ($f['change']) <span style="font-size:11px; font-weight:700; color:{{ $f['colour'] }};" dir="ltr">{{ $f['change'] }}</span>@endif</div>
            </td>
        @endforeach
        @if (count($pair) === 1)<td width="50%">&nbsp;</td>@endif
    </tr>
</table>
@endforeach
