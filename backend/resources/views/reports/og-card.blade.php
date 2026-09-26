{{--
  SHARE-PREVIEW-CARD-001 — the 1200×630 picture a shared report link renders as when it is PASTED.

  This is a page whose ONLY purpose is to be photographed by headless Chromium. Nothing here is ever
  served to a person: `SharePreviewController` serves the PNG, and a real browser following the link
  gets the SPA.

  ## Why a browser and not an image library

  The card is bilingual and the Arabic half has to JOIN. GD and Imagick draw a TTF glyph per
  codepoint with no shaping, so «تقرير الأداء» comes out as disconnected letters read
  left-to-right — which is the same defect class the PDF text layer already cost this product two
  round trips over. Chromium shapes, bidi-orders and kerns Arabic correctly because it is the engine
  the client's own phone uses.

  ## Two rules this must not break

  1. **No figures.** A preview card is fetched by a third party, cached by them, and shown to
     everybody who can see the message — including a group the client forwarded it into. It names
     WHOSE report it is and WHICH period, exactly as the crawler metadata beside it does, and
     carries no spend, no revenue and no result.
  2. **No network.** The page is opened from a temp file with no origin, and the mark arrives as a
     data URI the caller already read off the disk. A card that fetched its own logo would render a
     broken image whenever the app was slow, and would be a second way for a link to reach out.

  Every value is escaped by Blade. `$logo` is the only raw attribute and it is a data URI the caller
  built from bytes it had already put through `DrawableImage::draws()`.
--}}
<!doctype html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<style>
  /*
   * The exact frame the screenshot takes. Fixed rather than responsive: there is one viewer and it
   * is a 1200×630 camera, which is the ratio WhatsApp, X, LinkedIn, Slack and Telegram all crop to.
   */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --ink: #0a0f1c;
    --ink-soft: rgba(233, 238, 250, 0.62);
    --ink-strong: #f4f7ff;
    --accent: {{ $accent }};
  }

  /*
   * Both, and both clipped. `overflow: hidden` on the body alone PROPAGATES to the viewport rather
   * than clipping the body — so the decorations below still sized the document, and in RTL the
   * extra width is added on the LEFT, which moves the origin. The first Arabic card was photographed
   * from the wrong x and lost a word off each edge, while the Latin one looked perfect: the exact
   * shape of bug that ships when only one direction is looked at.
   */
  html, body { width: 1200px; height: 630px; overflow: hidden; }

  body {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 72px 80px;
    background: var(--ink);
    color: var(--ink-strong);
    /*
     * RAW, and it has to be. Blade's default echo escapes for HTML, so every apostrophe around a
     * family name arrives as a numeric character reference — not a parse error CSS reports, just a
     * declaration it drops, and the whole card is then photographed in the browser's DEFAULT face.
     * Headless Chromium's default is a SERIF, so the first drawn card came out in Times.
     * (The escaped spelling is not quoted here: `ShareCardContentsTest` sweeps the document for it,
     * and a note containing it would fail the guard it exists to explain.)
     *
     * Safe because this is not data: it is a private constant on ShareCardRenderer, never a stored
     * or submitted value. `ShareCardContentsTest` asserts it reaches the document unescaped.
     */
    font-family: {!! $fontStack !!};
    font-feature-settings: 'ss01';
    -webkit-font-smoothing: antialiased;
    position: relative;
  }

  /* Everything decorative lives in here, and this is what clips it — a box the layout owns, rather
     than the viewport, which is the one element whose overflow rules are not local. */
  .bg { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }

  /* The accent, spent in one place. A wash anchored away from the reading side, so the type always
     sits on the quiet half whichever direction the report runs in. */
  .bg::before {
    content: '';
    position: absolute;
    inset-inline-end: -180px;
    inset-block-start: -220px;
    width: 720px;
    height: 720px;
    border-radius: 50%;
    background: radial-gradient(circle, color-mix(in oklab, var(--accent) 55%, transparent) 0%, transparent 68%);
  }

  /* A hairline of the accent along the reading edge — the same device the report header uses. */
  .bg::after {
    content: '';
    position: absolute;
    inset-block: 0;
    inset-inline-start: 0;
    width: 10px;
    background: var(--accent);
  }

  header { display: flex; align-items: center; gap: 28px; position: relative; }

  .mark {
    height: 84px;
    max-width: 280px;
    object-fit: contain;
    /* A mark is supplied at whatever size the agency uploaded; the box is what keeps a tall one from
       pushing the title off the card. */
  }

  .eyebrow {
    font-size: 26px;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--ink-soft);
  }

  main { position: relative; }

  h1 {
    font-size: {{ $titleSize }}px;
    line-height: 1.16;
    font-weight: 700;
    text-wrap: balance;
    /* Two lines at most. A long client name is trimmed by the engine rather than by us guessing a
       character count that is wrong in the other language. */
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  .period {
    margin-block-start: 22px;
    font-size: 34px;
    font-weight: 500;
    color: var(--ink-soft);
    font-variant-numeric: tabular-nums;
    /*
     * Aligned to the READING edge — right on an Arabic card, left on a Latin one — while the date
     * inside keeps its own left-to-right order.
     *
     * Those are two different things and the first drawn card had them confused: `direction: ltr` on
     * the paragraph made `start` mean LEFT, so the period sat under the left margin of a card whose
     * every other line was right-aligned. The direction belongs on the date, not on the paragraph,
     * which is what the `<bdi>` below is for — without it the two halves of the range swap and an
     * Arabic card states the period backwards.
     */
    text-align: start;
  }

  footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    font-size: 26px;
    color: var(--ink-soft);
    position: relative;
  }

  .product { font-weight: 600; color: var(--ink-strong); }
</style>
</head>
<body>
<div class="bg"></div>

<header>
  @if ($logo)
    <img class="mark" src="{{ $logo }}" alt="">
  @else
    {{-- No mark configured. The eyebrow carries the identity instead of an invented placeholder. --}}
    <span class="eyebrow">{{ $eyebrow }}</span>
  @endif
</header>

<main>
  <h1>{{ $who }}</h1>
  @if ($period !== null)
    <p class="period"><bdi dir="ltr">{{ $period }}</bdi></p>
  @endif
</main>

<footer>
  <span>{{ $kicker }}</span>
  <span class="product">{{ $siteName }}</span>
</footer>
</body>
</html>
