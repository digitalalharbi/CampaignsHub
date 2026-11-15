<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="privacy" style="margin:0 0 10px;">
    <tr>
        <td width="20" valign="top" style="padding-top:1px;">@include('mail.blocks.img', ['key' => 'lock', 'alt' => $m['lock_alt'], 'w' => 16])</td>
        <td valign="top" style="font-size:12px; line-height:1.6; color:#5b6b68;"><span style="font-weight:800; color:#0b1a16;">{{ $m['greeting'] }}</span> {{ $m['privacy'] }}</td>
    </tr>
</table>
