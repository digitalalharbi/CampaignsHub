<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="top" style="border:1px solid #e4e0d6; border-radius:12px; margin:0 0 10px;">
    <tr><td style="padding:10px 12px;">
        <div style="font-size:11px; font-weight:800; color:#5b6b68;">{{ $m['top']['title'] }}</div>
        @include('mail.blocks.rows', ['rows' => $m['top']['rows']])
    </td></tr>
</table>
