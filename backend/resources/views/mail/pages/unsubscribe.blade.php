<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <meta name="color-scheme" content="light dark" />
    <title>{{ $t['title'] }} · CampaignsHub</title>
    <style>
        :root { --bg: #f7f5f0; --card: #ffffff; --ink: #0b1a16; --muted: #5b6b68; --line: #e4e0d6; --brand: #0b7a62; --link: #0d8a6f; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1a16; --card: #0b1a16; --ink: #f7f5f0; --muted: #b7c9c2; --line: #3d4742; --link: #b7c9c2; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--ink); font-family: -apple-system, 'SF Arabic', 'Segoe UI', Tahoma, Arial, sans-serif; line-height: 1.8; }
        main { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 28px 24px; }
        .brand { font-size: 13px; color: var(--muted); margin: 0 0 12px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { margin: 0 0 12px; font-size: 15px; }
        .note { color: var(--muted); font-size: 13px; }
        button { min-height: 44px; padding: 0 28px; border: 0; border-radius: 8px; background: var(--brand); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        a { color: var(--link); }
    </style>
</head>
<body>
<main>
    <div class="brand">{{ $wordmark }}</div>
    <h1>{{ $t['title'] }}</h1>

    @if ($state === 'confirm')
        <p>{{ $t['confirm'] }}</p>
        <form id="leave" method="post" action="{{ $action }}"><button type="submit">{{ $t['button'] }}</button></form>
        <script>document.getElementById('leave').submit();</script>
    @elseif ($state === 'done')
        <p>{{ $t['done'] }}</p>
        <p class="note">{{ $t['kept'] }}</p>
        <p class="note"><a href="{{ $preferences }}">{{ $t['change'] }}</a></p>
    @else
        <p>{{ $t['invalid'] }}</p>
        <p class="note"><a href="{{ $preferences }}">{{ $t['change'] }}</a></p>
    @endif
</main>
</body>
</html>
