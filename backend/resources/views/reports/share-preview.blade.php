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
{{--
  SHARE-PREVIEW-CARD-001 — the SIZE, beside the url.

  A crawler that is not told the dimensions has to fetch and decode the image before it can decide
  how to lay the card out, and several of them give up rather than wait — which renders as a card
  with an empty picture slot, the same outcome as having no image at all. WhatsApp and X both read
  these, and the numbers come from the config the renderer draws at, so they cannot drift from the
  file that is actually served.

  `og:image:type` for the same reason: the renderer writes PNG and says so, rather than leaving a
  crawler to sniff it.
--}}
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="{{ $imageWidth }}">
<meta property="og:image:height" content="{{ $imageHeight }}">
<meta property="og:image:alt" content="{{ $description }}">
@endif

<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@if ($image)
<meta name="twitter:image" content="{{ $image }}">
{{-- The alt a screen reader announces where the card is read aloud rather than looked at. --}}
<meta name="twitter:image:alt" content="{{ $description }}">
@endif

{{-- A human who somehow lands here still gets the report rather than this stub. --}}
<meta http-equiv="refresh" content="0; url={{ $url }}">
</head>
<body>
<p>{{ $title }}</p>
<p><a href="{{ $url }}">{{ $description }}</a></p>
</body>
</html>
