<div data-block="ideas" style="margin:4px 0 6px;">
    <div style="font-size:11px; font-weight:800; color:#5b6b68; padding-bottom:4px;">{{ $m['ideas_title'] }}</div>
    @foreach ($m['ideas'] as $idea)<div style="padding:2px 0;">@include('mail.blocks.pill', ['tone' => 'info', 'text' => $idea, 'size' => 12])</div>@endforeach
</div>
