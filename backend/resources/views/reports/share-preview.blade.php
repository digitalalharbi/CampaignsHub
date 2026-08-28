{{--
  REPORT-TITLE-METADATA-001 — the card a shared report link renders as when it is PASTED.

  Served to crawlers only. A client link is sent in WhatsApp far more often than it is typed into a
  browser, and none of WhatsApp, X, LinkedIn, Slack or Telegram executes the React that would set the
  real title — so every client link previewed as «CampaignsHub — All your paid campaigns in one
  place». That is the requirement's own note about client-side metadata being insufficient.

  Deliberately thin. It names WHOSE report it is and the period, and carries NO figures: a preview is
  rendered by a third party, cached by them, and shown to everyone who can see the message —
  including a group the client forwarded it into.

  A real browser never reaches this: the SPA answers, and its own header renders the same identity.
--}}
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $url }}">

<meta property="og:type" content="article">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $url }}">
@if ($image)
<meta property="og:image" content="{{ $image }}">
@endif

<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@if ($image)
<meta name="twitter:image" content="{{ $image }}">
@endif

{{-- A human who somehow lands here still gets the report rather than this stub. --}}
<meta http-equiv="refresh" content="0; url={{ $url }}">
</head>
<body>
<p>{{ $title }}</p>
<p><a href="{{ $url }}">{{ $description }}</a></p>
</body>
</html>
