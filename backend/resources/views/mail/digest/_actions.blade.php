{{--
  DIGEST-PM-ACTIONS-001 — «يحتاج قرارك الآن» in the project-management voice (Owner 2026-11-21): one line per project, what to do
  with the client, and only what the month's budget figures prove (`OperationalDigestModel::budgetActions()`). Nothing is drawn
  when nothing is proven. Needs $items, $ar, $startSide.
--}}
@php($S = \App\Domains\Notifications\Support\DigestStatus::class)
@if ($items)
    <div style="font-size:13px; font-weight:800; color:#0b1a16; margin:10px 0 6px;">{{ $ar ? 'يحتاج قرارك الآن' : 'Needs your decision now' }}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" data-block="pm-actions" style="margin-bottom:10px;">
        @foreach ($items as $a)
            <tr data-tone="{{ $a['tone'] }}">
                <td bgcolor="{{ $S::SOFT[$a['tone']] }}" style="background-color:{{ $S::SOFT[$a['tone']] }}; border-{{ $startSide }}:4px solid {{ $S::INK[$a['tone']] }}; border-radius:8px; padding:7px 10px;">
                    <div style="font-size:12px; font-weight:800; color:#0b1a16;">{{ \Illuminate\Support\Str::limit($a['name'], 34, '…') }}</div>
                    <div style="font-size:12px; color:{{ $S::INK[$a['tone']] }}; font-weight:700; line-height:1.6;">{{ $a['text'] }}</div>
                </td>
            </tr>
            <tr><td height="4" style="font-size:1px; line-height:4px;">&nbsp;</td></tr>
        @endforeach
    </table>
@endif
