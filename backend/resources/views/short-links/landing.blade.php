<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $whatsapp ? 'متابعة إلى واتساب' : 'متابعة' }}</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f7f5f0; color: #0b1a16; font-family: 'IBM Plex Sans Arabic', 'Noto Sans Arabic', system-ui, sans-serif; }
  main { max-width: 420px; padding: 32px 24px; text-align: center; }
  a.go { display: inline-block; margin-top: 20px; padding: 14px 28px; border-radius: 14px; background: #0d8a6f; color: #fff; font-weight: 700; text-decoration: none; }
  small { display: block; margin-top: 18px; color: #3d4742; word-break: break-all; }
</style>
</head>
<body>
<main>
  <h1>{{ $whatsapp ? 'سيتم فتح محادثة واتساب' : 'متابعة إلى الوجهة' }}</h1>
  <p>الوجهة: <strong dir="ltr">{{ $host }}</strong></p>
  <a class="go" href="{{ $destination }}" rel="noopener">{{ $whatsapp ? 'فتح واتساب' : 'متابعة' }}</a>
  <small dir="ltr">{{ $destination }}</small>
</main>
</body>
</html>
