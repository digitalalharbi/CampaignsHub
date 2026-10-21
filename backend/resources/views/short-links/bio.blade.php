<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }}</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: start center; background: #f7f5f0; color: #0b1a16; font-family: 'IBM Plex Sans Arabic', 'Noto Sans Arabic', system-ui, sans-serif; }
  main { width: 100%; max-width: 440px; padding: 40px 20px 32px; text-align: center; box-sizing: border-box; }
  h1 { font-size: 24px; margin: 0 0 6px; }
  p.sub { margin: 0 0 24px; color: #3d4742; font-size: 15px; }
  ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
  a.btn { display: block; padding: 15px 20px; border-radius: 14px; background: #fff; border: 1px solid #d9d5cb; color: #0b1a16; font-weight: 700; text-decoration: none; font-size: 16px; }
  a.btn.wa { background: #0d8a6f; border-color: #0d8a6f; color: #fff; }
  small { display: block; margin-top: 28px; color: #6b746f; font-size: 12px; }
</style>
</head>
<body>
<main>
  <h1>{{ $title }}</h1>
  @if ($subtitle)<p class="sub">{{ $subtitle }}</p>@endif
  <ul>
    @forelse ($buttons as $b)
      <li><a class="btn{{ $b['whatsapp'] ? ' wa' : '' }}" href="{{ $b['href'] }}" rel="noopener">{{ $b['label'] }}</a></li>
    @empty
      <li><p class="sub">لا روابط هنا الآن.</p></li>
    @endforelse
  </ul>
  <small>كامبينز هب</small>
</main>
</body>
</html>
