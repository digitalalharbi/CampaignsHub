{{--
  The external service report's body — NOTIF-V2-EXTERNAL-001 / Q2. VISUAL FIRST: the blocks are the digests' own components
  (verdict card, tiles, objective groups, platform strip, spend-summary cards, rows) in their calm states; a role changes the
  ORDER and emphasis, never the look. No action button, no link into the platform (spec §7); the shell is `mail.layout` in its
  standalone form. Needs $m (ReportView), $dir, $startSide, $endSide, $font.
--}}
<div dir="{{ $dir }}" style="font-family:{!! $font !!}; color:#0b1a16; text-align:{{ $startSide }};">
    @if ($m['copy_band'])
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 10px;"><tr><td bgcolor="#fffbeb" style="background-color:#fffbeb; border-radius:10px; padding:6px 12px; font-size:12px; font-weight:800; color:#8a4a07; text-align:center;">{{ $m['copy_band'] }}</td></tr></table>
    @endif
    @foreach ($m['order'] as $block)
        @include('mail.external.blocks.'.$block)
    @endforeach
    <div style="font-size:12px; font-weight:700; color:#3d4742; padding:6px 0 0; white-space:pre-line;">{{ $m['signature'] }}</div>
</div>
