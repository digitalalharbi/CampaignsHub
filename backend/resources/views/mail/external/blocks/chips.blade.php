<div data-block="chips" style="margin:0 0 6px;">
    <div style="font-size:11px; font-weight:800; color:#5b6b68; padding-bottom:4px;">{{ $m['chips_title'] }}</div>
    @foreach ($m['chips'] as $chip)<span style="display:inline-block; margin:0 {{ $startSide === 'right' ? '0 6px 6px' : '6px 6px 0' }};">@include('mail.blocks.pill', ['tone' => $chip['tone'], 'text' => $chip['text'], 'size' => 13])</span>@endforeach
</div>
