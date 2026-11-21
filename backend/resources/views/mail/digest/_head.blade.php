{{-- The head of a digest: what it is, for whom, which period — one line, no greeting paragraph. Needs $title; optional $sub. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;">
    <tr>
        <td style="font-size:20px; font-weight:800; color:#0b1a16; line-height:1.3;">{{ $title }}</td>
        @if (! empty($sub))<td style="text-align:{{ $endSide }}; font-size:12px; color:#5b6b68; white-space:nowrap;" dir="auto">{{ $sub }}</td>@endif
    </tr>
</table>
