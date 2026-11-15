{{-- A chart image with its dark alternate (inline CID PNGs at 2x). Needs $m (carries `charts`, `theme`), $key, $alt; optional $w (display width px; the height follows). The light image is replaced by the dark one under prefers-color-scheme (class swap; Outlook desktop never sees the dark one); a message composed in the dark theme carries the dark one only. Without the image the caller draws the cell fallback. --}}
@php($ch = $m['charts'][$key] ?? null)
@if ($ch)
@php($iw = (int) ($w ?? round($ch['width'] / 2)))
@php($ih = (int) round($ch['height'] / 2 * $iw / ($ch['width'] / 2)))
@php($alt = htmlspecialchars((string) $alt, ENT_QUOTES, 'UTF-8'))
@if ($m['theme'] === 'dark')
<img src="cid:{{ $key }}-dark" width="{{ $iw }}" height="{{ $ih }}" alt="{!! $alt !!}" style="display:block; width:100%; max-width:{{ $iw }}px; height:auto; border:0;">
@else
<img class="ch-light-img" src="cid:{{ $key }}" width="{{ $iw }}" height="{{ $ih }}" alt="{!! $alt !!}" style="display:block; width:100%; max-width:{{ $iw }}px; height:auto; border:0;">
<!--[if !mso]><!--><img class="ch-dark-img" src="cid:{{ $key }}-dark" width="{{ $iw }}" height="{{ $ih }}" alt="{!! $alt !!}" style="display:none; width:100%; max-width:{{ $iw }}px; height:auto; border:0; max-height:0; overflow:hidden;"><!--<![endif]-->
@endif
@endif
