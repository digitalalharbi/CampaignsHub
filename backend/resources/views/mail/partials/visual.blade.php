{{--
  ALERT-VISUAL-EVERY-CARD-001 — every notification card carries a GRAPHIC (`docs/OWNER_NOTIFICATIONS_RULES_2026-11-12.md` §9):
  never a text-only card, including «رجعت الأمور طبيعية».

  · a metric card draws its figure: before → after bars that name their days, a pace bar, a count (`mail.digest._viz`);
  · a card with no metric to draw (a connection, a recovery with no figure, an info notice) draws a STATUS RING beside a
    TIMELINE — opened → now / resolved, with the dates — and keeps its stage chips under it.

  Email-safe: tables, inline colours, no images, no CSS the mail clients drop. Needs $viz (nullable), $state (bad|watch|ok|none),
  $dir, $startSide; optional $timeline (['from' => label, 'to' => label]), $glyph, $size (ring diameter, default 34).
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@php($vizType = is_array($viz ?? null) ? ($viz['type'] ?? null) : null)
@php($graphic = in_array($vizType, ['before_now', 'pace', 'count'], true))
@php($vstate = in_array($state ?? 'none', ['bad', 'watch', 'ok', 'none'], true) ? ($state ?? 'none') : 'none')
@php($size = (int) ($size ?? 34))
@if ($graphic)
    @include('mail.digest._viz', ['viz' => $viz])
@else
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" data-block="viz" data-viz="ring" style="margin-top:6px;">
        <tr>
            <td width="{{ $size + 10 }}" valign="middle" style="padding:0;">
                {{-- The ring: the state's colour, its glyph in the middle. EMAIL-CLIENT-COMPAT-001 — a FIXED box: the table and its one cell
                     carry width/height attributes and px sizes (the border inside them), `table-layout:fixed`, a px line-height equal to the
                     inner box. `border-radius:50%` only rounds it; a client that drops it shows a small square, never a stretched oval. --}}
                @php($ring = max(3, (int) round($size / 8)))
                @php($inner = $size - 2 * $ring)
                <table role="presentation" width="{{ $size }}" height="{{ $size }}" cellpadding="0" cellspacing="0" border="0" style="width:{{ $size }}px; height:{{ $size }}px; table-layout:fixed;"><tr>
                    <td width="{{ $inner }}" height="{{ $inner }}" align="center" valign="middle" bgcolor="{{ $S::CELL[$vstate] }}" style="width:{{ $inner }}px; height:{{ $inner }}px; padding:0; border:{{ $ring }}px solid {{ $S::INK[$vstate] }}; border-radius:50%; background-color:{{ $S::CELL[$vstate] }}; font-size:{{ max(10, (int) round($size / 2.6)) }}px; font-weight:800; line-height:{{ $inner }}px; mso-line-height-rule:exactly; color:{{ $S::INK[$vstate] }}; text-align:center; white-space:nowrap; overflow:hidden;">{{ $glyph ?? $S::GLYPH[$vstate] }}</td>
                </tr></table>
            </td>
            @if (! empty($timeline['from']) || ! empty($timeline['to']))
                <td valign="middle" style="padding-{{ $startSide }}:8px;">
                    {{-- The timeline: from the left label to the right one, the stretch in the state's colour. --}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="font-size:10px; color:#5b6b68; white-space:nowrap; text-align:{{ $startSide }};" dir="auto">{{ $timeline['from'] ?? '' }}</td>
                            <td style="font-size:10px; color:#5b6b68; white-space:nowrap; text-align:{{ $startSide === 'right' ? 'left' : 'right' }};" dir="auto">{{ $timeline['to'] ?? '' }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" style="padding-top:3px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                                    <td width="10" height="10" bgcolor="{{ $S::INK['watch'] }}" style="width:10px; height:10px; border-radius:50%; background-color:{{ $S::INK['watch'] }}; font-size:1px; line-height:10px; mso-line-height-rule:exactly;">&nbsp;</td>
                                    <td height="4" bgcolor="{{ $S::INK[$vstate] }}" style="height:4px; background-color:{{ $S::INK[$vstate] }}; font-size:1px; line-height:4px; mso-line-height-rule:exactly;">&nbsp;</td>
                                    <td width="10" height="10" bgcolor="{{ $S::INK[$vstate] }}" style="width:10px; height:10px; border-radius:50%; background-color:{{ $S::INK[$vstate] }}; font-size:1px; line-height:10px; mso-line-height-rule:exactly;">&nbsp;</td>
                                </tr></table>
                            </td>
                        </tr>
                    </table>
                </td>
            @endif
        </tr>
    </table>
    @if ($vizType === 'steps')
        @include('mail.digest._viz', ['viz' => $viz])
    @endif
@endif
