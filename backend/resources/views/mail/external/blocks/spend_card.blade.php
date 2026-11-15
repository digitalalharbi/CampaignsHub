{{-- The weekly spend summary's own project card (limit · spent · remaining, the bar, the runway line) in the calm palette, with the budget ring beside it. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="budget-row"><tr>
    @if (isset($m['charts']['ring']) && $m['spend_card']['ring'])
        <td width="68" valign="top" style="padding-{{ $endSide }}:8px;">@include('mail.blocks.img', ['key' => 'ring', 'alt' => $m['spend_card']['ring']['label'], 'w' => 60])</td>
    @endif
    <td valign="top">
        @include('mail.blocks.spend-card', ['p' => $m['spend_card'], 'calm' => true])
        @if ($m['spend_card']['equivalent'])<div style="margin:-2px 0 8px;">@include('mail.blocks.pill', ['tone' => 'none', 'text' => $m['spend_card']['equivalent'], 'plain' => true])</div>@endif
    </td>
</tr></table>
