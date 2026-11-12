{{--
  «ملخص المشاريع» opens with the money — DIGESTS-CADENCE-BUDGETS-001. Needs $bud (`OperationalDigestModel::portfolio()['budgets']`).

  The TOTAL first (budget, spend, remaining and pace, one bar), then every project closest to running out first. The total is
  money only and only in one currency; when the projects mix currencies the line says why there is no total.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="budgets" style="background-color:#faf8f3; border:1px solid #e4e0d6; border-radius:12px; margin-bottom:12px;">
    <tr><td style="padding:10px 12px 6px;">
        <div style="font-size:13px; font-weight:800; color:#0b1a16; margin-bottom:6px;">{{ $dir === 'rtl' ? 'الميزانيات هذا الشهر' : 'Budgets this month' }}</div>
        @if ($bud['total'])
            <div data-block="budget-total">@include('mail.digest._budget', ['b' => $bud['total'], 'name' => $dir === 'rtl' ? 'الإجمالي' : 'Total'])</div>
        @elseif ($bud['total_reason'])
            <div data-block="budget-total" style="font-size:12px; color:#b45309; font-weight:700; margin-bottom:8px;">{{ $bud['total_reason'] }}</div>
        @endif
        @php($S = \App\Domains\Notifications\Support\DigestStatus::class)
        @php($shown = array_slice($bud['rows'], 0, \App\Domains\Notifications\Support\OperationalDigestModel::BUDGET_ROWS))
        {{-- One line per project: name · the month used (bar, LTR) · what remains · the pace glyph. Closest to running out first. --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="table-layout:fixed;">
            @forelse ($shown as $r)
                @php($fill = match ($r['tone']) { 'bad' => '#c0392b', 'watch' => '#e0a23a', 'ok' => '#0d8a6f', default => '#9ccfc0' })
                <tr data-block="budget-row" data-project="{{ $r['id'] }}" data-band="{{ $r['band'] ?? 'none' }}">
                    <td width="34%" style="padding:3px 0; font-size:12px; font-weight:700; color:#0b1a16; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><a href="{{ $r['url'] }}" style="color:#0b1a16; text-decoration:none;">{{ \Illuminate\Support\Str::limit($r['name'], 16, '…') }}</a></td>
                    <td dir="ltr" style="padding:3px 4px;">
                        @if ($r['state'] === 'ok')
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#e6e2d6; border-radius:4px;"><tr>
                                <td width="{{ $r['bar'] }}%" height="8" bgcolor="{{ $fill }}" style="background-color:{{ $fill }}; height:8px; line-height:8px; font-size:1px; border-radius:4px;">&nbsp;</td>
                                @if ($r['bar'] < 100)<td height="8" style="font-size:1px; line-height:8px;">&nbsp;</td>@endif
                            </tr></table>
                        @else
                            <span style="font-size:11px; color:#8b9a97;">{{ $dir === 'rtl' ? 'لا بيانات' : 'No data' }}</span>
                        @endif
                    </td>
                    <td width="26%" style="padding:3px 0; font-size:12px; font-weight:800; color:{{ $r['over'] ? '#b91c1c' : '#0a6b57' }}; text-align:{{ $endSide }}; white-space:nowrap;" dir="ltr">{{ $r['state'] === 'ok' ? $r['remaining'] : '' }}</td>
                    <td width="22" align="center" style="padding:3px 0 3px 4px;"><span title="{{ $r['band_label'] }}" style="display:inline-block; width:18px; height:18px; line-height:18px; border-radius:5px; text-align:center; font-size:11px; font-weight:800; background-color:{{ $S::CELL[$r['tone']] }}; color:{{ $S::INK[$r['tone']] }};">{{ $S::GLYPH[$r['tone']] }}</span></td>
                </tr>
                @if ($r['state'] !== 'ok' || ($r['outside_line'] ?? '') !== '')
                    {{-- Why there is no figure, or which campaigns the figure leaves out — the reason the data gives. --}}
                    <tr data-block="budget-outside"><td colspan="4" style="padding:0 0 4px; font-size:11px; font-weight:700; color:#b45309;">! {{ $r['state'] !== 'ok' ? $r['line'] : $r['outside_line'] }}@if ($r['state'] !== 'ok' && ($r['outside_line'] ?? '') !== '') · {{ explode(':', $r['outside_line'])[0] }}@endif</td></tr>
                @endif
            @empty
                <tr><td style="font-size:12px; color:#8b9a97;">{{ $dir === 'rtl' ? 'ما فيه ميزانيات مسجّلة لهذا الشهر' : 'No budgets recorded for this month' }}</td></tr>
            @endforelse
        </table>
        @if (count($bud['rows']) > count($shown))
            <div data-block="budgets-more" style="font-size:11px; color:#5b6b68; padding-top:4px;"><span dir="ltr">+{{ count($bud['rows']) - count($shown) }}</span> {{ $dir === 'rtl' ? 'مشاريع أبعد عن النفاد' : 'more, further from running out' }}</div>
        @endif
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="budget-legend" style="margin-top:6px;"><tr>
            @foreach (['bad' => 'ahead', 'ok' => 'on_track', 'watch' => 'behind'] as $tone => $band)
                <td style="padding-{{ $endSide }}:10px; font-size:11px; color:#5b6b68; white-space:nowrap;"><span style="display:inline-block; width:14px; height:14px; line-height:14px; border-radius:4px; text-align:center; font-size:10px; font-weight:800; background-color:{{ $S::CELL[$tone] }}; color:{{ $S::INK[$tone] }};">{{ $S::GLYPH[$tone] }}</span> {{ \App\Domains\Notifications\Support\OperationalDigestModel::bandLabel($band, $dir === 'rtl') }}</td>
            @endforeach
        </tr></table>
        @if ($bud['left_out_line'])
            <div style="font-size:11px; color:#5b6b68; padding-top:4px;">{{ $bud['left_out_line'] }}</div>
        @endif
    </td></tr>
</table>
