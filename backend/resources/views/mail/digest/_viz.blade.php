{{--
  The problem, drawn — no script, no image, no SVG: cells. Needs $viz. LTR so a bar always grows from the same edge.

  before_now · two bars, before → now (a cost that rose, a return that fell)
  pace       · two bars, the plan against what was spent (or a limit)
  steps      · DIGESTS-CADENCE-BUDGETS-001 — the stages a finding is about, the broken one marked (connection → sync ✕ → figures)
  count      · DIGESTS-CADENCE-BUDGETS-001 — one block per item, up to ten (leads nobody called)
--}}
@if ($viz)
    @php($S = \App\Domains\Notifications\Support\DigestStatus::class)
    @if ($viz['type'] === 'steps')
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="viz" data-viz="steps" style="margin-top:6px;">
            <tr>
                @foreach ($viz['steps'] as $i => $st)
                    @if ($i > 0)<td style="padding:0 3px; font-size:12px; color:#8b9a97;">{{ $dir === 'rtl' ? '←' : '→' }}</td>@endif
                    <td style="padding:0;"><span data-step-state="{{ $st['state'] }}" style="display:inline-block; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:800; white-space:nowrap; background-color:{{ $S::CELL[$st['state']] }}; color:{{ $S::INK[$st['state']] }};">{{ $S::GLYPH[$st['state']] }} {{ $st['label'] }}</span></td>
                @endforeach
            </tr>
        </table>
    @elseif ($viz['type'] === 'count')
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" data-block="viz" data-viz="count" style="margin-top:6px;">
            <tr>
                @for ($i = 0; $i < $viz['blocks']; $i++)
                    <td style="padding:0 2px 0 0;"><span style="display:inline-block; width:12px; height:12px; border-radius:3px; background-color:{{ $S::INK[$viz['state']] }};">&nbsp;</span></td>
                @endfor
                <td style="padding:0 6px; font-size:12px; font-weight:800; color:{{ $S::INK[$viz['state']] }}; white-space:nowrap;" dir="auto">{{ $viz['more'] ? '+' : '' }}{{ number_format($viz['n']) }} {{ $viz['label'] }}</td>
            </tr>
        </table>
    @else
        @php($rows = $viz['type'] === 'pace'
            ? [[$viz['plan_label'], $viz['plan'], '#b7c9c2'], [$viz['used_label'], $viz['used'], $viz['used'] > $viz['plan'] ? '#c0392b' : '#b45309']]
            : [[(isset($viz['before_when']) ? $viz['before_when'].($viz['before_label'] !== '' ? ' · ' : '') : ($dir === 'rtl' ? 'قبل ' : 'Before ')).$viz['before_label'], $viz['before'], '#b7c9c2'], [(isset($viz['now_when']) ? $viz['now_when'].' · ' : ($dir === 'rtl' ? 'الآن ' : 'Now ')).$viz['now_label'], $viz['now'], ! empty($viz['resolved']) ? $S::INK['ok'] : '#c0392b']])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="viz" data-viz="{{ $viz['type'] }}" style="margin-top:6px;">
            @foreach ($rows as [$text, $pct, $colour])
                <tr>
                    <td width="46%" dir="{{ $dir }}" style="padding:1px 0; font-size:11px; color:#3d4742; white-space:nowrap; text-align:{{ $startSide }};">{{ $text }}</td>
                    <td dir="ltr" style="padding:1px 0;">
                        <table role="presentation" width="{{ max(4, min(100, $pct)) }}%" cellpadding="0" cellspacing="0" border="0"><tr><td height="7" bgcolor="{{ $colour }}" style="background-color:{{ $colour }}; height:7px; line-height:7px; font-size:1px; border-radius:3px;">&nbsp;</td></tr></table>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
@endif
