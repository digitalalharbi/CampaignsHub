{{--
  One budget, drawn — DIGESTS-CADENCE-BUDGETS-001. Needs $b (`OperationalDigestModel::budget()`); optional $name, $url, $compact.

  Two bars on one track, LTR so they always grow from the same edge: what the month has used of the BUDGET, and how much of
  the month has passed (the plan line). Under them, the three money words and ONE pace chip. A spend that did not arrive is
  «لا بيانات», never a short bar; a project with no budget says so in one line.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($ar = $dir === 'rtl')
@php($compact = $compact ?? false)
@php($fill = match ($b['tone']) { 'bad' => '#c0392b', 'watch' => '#e0a23a', 'ok' => '#0d8a6f', default => '#9ccfc0' })
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="budget" data-state="{{ $b['state'] }}" data-band="{{ $b['band'] ?? 'none' }}" style="margin-bottom:{{ $compact ? 4 : 10 }}px;">
    @if (! empty($name))
        <tr><td colspan="2" style="padding:4px 0 2px; font-size:12px; font-weight:800; color:#0b1a16;">
            @if (! empty($url))<a href="{{ $url }}" style="color:#0b1a16; text-decoration:none;">{{ \Illuminate\Support\Str::limit($name, 30, '…') }}</a>@else{{ \Illuminate\Support\Str::limit($name, 30, '…') }}@endif
            @if ($b['band'])<span data-chip="pace" style="display:inline-block; margin-{{ $startSide === 'right' ? 'right' : 'left' }}:6px; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:800; background-color:{{ $S::CELL[$b['tone']] }}; color:{{ $S::INK[$b['tone']] }};">{{ $S::GLYPH[$b['tone']] }} {{ $b['band_label'] }}</span>@endif
        </td></tr>
    @endif
    @if (in_array($b['state'], ['ok', 'withheld'], true))
        <tr>
            <td colspan="2" dir="ltr" style="padding:2px 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#e6e2d6; border-radius:4px;"><tr>
                    @if ($b['state'] === 'ok')
                        <td width="{{ $b['bar'] }}%" height="10" bgcolor="{{ $fill }}" style="background-color:{{ $fill }}; height:10px; line-height:10px; font-size:1px; border-radius:4px;">&nbsp;</td>
                        @if ($b['bar'] < 100)<td height="10" style="font-size:1px; line-height:10px;">&nbsp;</td>@endif
                    @else
                        <td height="10" style="font-size:1px; line-height:10px;">&nbsp;</td>
                    @endif
                </tr></table>
                <table role="presentation" width="{{ max(2, $b['elapsed']) }}%" cellpadding="0" cellspacing="0" border="0" style="margin-top:2px;"><tr><td height="3" bgcolor="#5b6b68" style="background-color:#5b6b68; height:3px; line-height:3px; font-size:1px; border-radius:2px;">&nbsp;</td></tr></table>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-top:4px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;"><tr>
                    @foreach ([['الميزانية', 'Budget', $b['budget'], '#0b1a16'], ['الإنفاق', 'Spend', $b['spent'], '#0b1a16'], ['المتبقي', 'Remaining', $b['remaining'], $b['over'] ? '#b91c1c' : '#0a6b57']] as [$la, $le, $v, $c])
                        <td valign="top" style="padding:0 2px;">
                            <div style="font-size:11px; color:#5b6b68;">{{ $ar ? $la : $le }}</div>
                            <div style="font-size:{{ $compact ? 13 : 15 }}px; font-weight:800; color:{{ $c }}; white-space:nowrap;" dir="ltr">{{ $v }}</div>
                        </td>
                    @endforeach
                </tr></table>
            </td>
        </tr>
        @if (! $compact)
            <tr><td colspan="2" style="padding-top:3px; font-size:11px; color:#5b6b68;">{{ $b['line'] }}</td></tr>
        @endif
    @else
        <tr><td colspan="2" style="padding:2px 0 4px; font-size:12px; color:#8b9a97;">{{ $b['line'] }}</td></tr>
    @endif
    @if (($b['outside_line'] ?? '') !== '' && $b['state'] !== 'unpriced')
        {{-- Budgeted campaigns whose spend has no figure in the budget's currency: counted and named, never dropped in silence. --}}
        <tr><td colspan="2" data-block="budget-outside" style="padding-top:2px; font-size:11px; font-weight:700; color:#b45309;">! {{ $b['outside_line'] }}</td></tr>
    @endif
</table>
