{{-- The quiet small print (spec §8, §12, §13 — all of it, in the short form): why, manage/unsubscribe, footprint, disclaimer, reference. Needs $m, $dir, $startSide, $font. --}}
<div data-fine="1" dir="{{ $dir }}" style="font-family:{!! $font !!}; font-size:11px; line-height:1.7; color:#5b6b68; text-align:{{ $startSide }};">
    <div data-footer-line="why">{{ $m['why'] }} · <a href="{{ $m['manage_url'] }}" data-unsubscribe="1" style="color:#0b7a62; font-weight:700; text-decoration:underline; white-space:nowrap;">{{ $m['manage_label'] }}</a>@if ($m['contact']) · <a href="{{ $m['contact']['url'] }}" style="color:#0b7a62; text-decoration:underline; white-space:nowrap;">{{ $m['contact']['label'] }}</a>@endif</div>
    @if ($m['footprint'])
        <div data-footer-line="footprint" style="padding-top:4px;"><span style="display:inline-block; width:9px; height:9px; border-radius:3px; background-color:#0d8a6f; vertical-align:middle;">&nbsp;</span> {{ $m['footprint']['text'] }} · <a href="{{ $m['footprint']['link'] }}" style="color:#0b7a62; text-decoration:underline;"><span dir="ltr">{{ $m['footprint']['link_label'] }}</span></a>@if ($m['footprint']['invitation'])<br>{{ $m['footprint']['invitation'] }}@endif</div>
    @endif
    <div data-footer-line="disclaimer" style="padding-top:4px;">{{ $m['disclaimer'] }}</div>
    <div data-footer-line="reference" style="padding-top:2px; color:#8b9a97;"><span dir="ltr">{{ $m['created'] }}</span> · {{ $m['sources'] }} · <span dir="ltr">{{ $m['reference'] }}</span>@if ($m['fx'])<br><span dir="ltr">{{ $m['fx'] }}</span>@endif</div>
</div>
