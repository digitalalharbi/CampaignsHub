<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="kpis" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin:0 0 10px;">
    <tr><td style="padding:2px 12px 10px;">
        @include('mail.digest._figures', ['figures' => $m['tiles'], 'per' => 3, 'big' => true])
        @if ($m['trend'])@include('mail.digest._spark', ['bars' => $m['trend']['bars'], 'colour' => '#0d8a6f', 'height' => 22, 'labels' => false])@endif
    </td></tr>
</table>
