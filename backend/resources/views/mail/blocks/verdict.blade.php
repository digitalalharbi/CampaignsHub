{{-- The verdict card, shared by the project digest and the external service report: state chip, the name, one sub-line, and the ONE figure it is judged on in a state-coloured box. Needs $state, $name, $sub, $kpi (value, label, change, colour, missing); optional $reason (no-data), $stateLabel (replaces the state's word), $block (data-block). --}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="{{ $block ?? 'project-head' }}" data-state="{{ $state }}" style="background-color:{{ $S::SOFT[$state] }}; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:10px;">
        <tr>
            <td style="padding:12px 14px; vertical-align:middle;">
                <div>@include('mail.digest._chip', ['state' => $state, 'size' => 24, 'label' => true, 'labelText' => $stateLabel ?? null])</div>
                <div style="font-size:18px; font-weight:800; color:#0b1a16; padding-top:6px;">{{ $name }}</div>
                <div style="font-size:12px; color:#5b6b68; padding-top:2px;">{{ $sub }}</div>
                @if (($reason ?? '') !== '')<div data-block="no-data-reason" style="font-size:12px; color:#3d4742; padding-top:4px;">{{ $reason }}</div>@endif
            </td>
            <td width="116" style="padding:12px 10px; vertical-align:middle; text-align:center; background-color:{{ $S::CELL[$state] }}; border-radius:{{ $startSide === 'right' ? '12px 0 0 12px' : '0 12px 12px 0' }};">
                <div class="ch-kpi-lead" style="font-size:24px; line-height:1.15; font-weight:800; color:{{ $kpi['missing'] ? '#8b9a97' : $S::INK[$state] }};" dir="ltr">{{ $kpi['value'] }}</div>
                <div style="font-size:11px; color:#5b6b68;">{{ $kpi['label'] }}</div>
                @if ($kpi['change'])<div style="font-size:12px; font-weight:800; color:{{ $kpi['colour'] }};" dir="ltr">{{ $kpi['change'] }}</div>@endif
            </td>
        </tr>
    </table>
