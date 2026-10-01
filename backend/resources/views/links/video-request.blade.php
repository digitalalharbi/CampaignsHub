<!doctype html>
{{--
    SHORT-LINKS-LANDING-001 — a real page, served to everybody, that forwards nobody.

    Three properties make this honest rather than a slower redirect, and each is visible in the
    markup rather than promised in a comment:

      - nothing leaves on its own. There is no meta refresh, no timer, no script. The only way to
        WhatsApp is the button, pressed by a person;
      - everybody gets this document. Nothing here or in the controller reads the user agent or the
        referrer, so an ad reviewer and a customer see the same page;
      - the offer is on the page. A reader can tell what they are asking for before they ask.

    Self-contained CSS on purpose. This is served by the API host to a stranger arriving from an ad,
    frequently on a slow mobile connection: one document, no build step, no font fetch, no framework.
--}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>اطلب الفيديوهات</title>
    <meta name="description" content="تواصل معنا عبر واتساب وشاركنا طلبك، وسنساعدك في اختيار وتنفيذ الفيديوهات المناسبة.">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#0b1220">

    {{--
        THE PRODUCT'S OWN FACE, self-hosted — the same IBM Plex Sans Arabic the application uses.

        The interface loads it through `@fontsource` into the SPA bundle, which this page cannot
        reach: it is served by the API host, as one document, with no build step. So the three
        weights it actually uses are served from `public/fonts` and declared here.

        No Google Fonts and no CDN, for the reason the product already self-hosts: a landing page
        that fetches a face from a third party hands that third party every visit, and this one is
        opened from a paid ad by people who did not ask for it.

        `swap`, so the text is readable while the face arrives — this is a conversion page on a
        mobile connection, and invisible text is worse than a moment of the fallback.
    --}}
    <style>
        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 400;
            font-display: swap;
            src: url('/fonts/ibm-plex-sans-arabic-arabic-400-normal.woff2') format('woff2');
        }

        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 600;
            font-display: swap;
            src: url('/fonts/ibm-plex-sans-arabic-arabic-600-normal.woff2') format('woff2');
        }

        @font-face {
            font-family: 'IBM Plex Sans Arabic';
            font-style: normal;
            font-weight: 700;
            font-display: swap;
            src: url('/fonts/ibm-plex-sans-arabic-arabic-700-normal.woff2') format('woff2');
        }
    </style>

    <style>
        /*
            Mobile-first, because this traffic is an ad click on a phone.
            The desktop rules are the only media query, and they widen rather than rearrange.
        */
        :root {
            --ink: #0b1220;
            --surface: #ffffff;
            --muted: #5a6577;
            --line: #e6e9ef;
            --whatsapp: #25d366;
            --whatsapp-ink: #0b1220;
            --brand: #0e9f6e;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #f3f5f9;
                --surface: #0b1220;
                --muted: #9aa5b8;
                --line: #1e2838;
                --whatsapp-ink: #04120a;
            }
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            /* No horizontal overflow, ever — this is read at 390px and narrower. */
            overflow-x: hidden;
        }

        body {
            background: var(--surface);
            color: var(--ink);
            /* The application's own `--font-body`, minus the Latin face this page has no use for. */
            font-family: 'IBM Plex Sans Arabic', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }

        .wrap {
            max-width: 34rem;
            margin: 0 auto;
            /* The side gutter is what keeps a long Arabic word off the edge of a small screen. */
            padding: 2.25rem 1.25rem 3rem;
        }

        .art {
            margin: .5rem 0 1.5rem;
            display: flex;
            justify-content: center;
        }

        h1 {
            margin: 0 0 .625rem;
            font-size: 1.75rem;
            line-height: 1.3;
            font-weight: 700;
            letter-spacing: -.01em;
            text-wrap: balance;
        }

        p.lede {
            margin: 0 0 1.75rem;
            color: var(--muted);
            font-size: 1.0625rem;
        }

        /*
            The button is the page. Full width on a phone and tall enough to hit with a thumb, so it
            is unmistakably above the fold at 390px — which is where this link is actually opened.
        */
        a.cta {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .625rem;
            width: 100%;
            min-height: 3.5rem;
            padding: 0 1.25rem;
            border-radius: 1rem;
            background: var(--whatsapp);
            color: var(--whatsapp-ink);
            font-size: 1.0625rem;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 10px 24px -12px rgba(37, 211, 102, .8);
        }

        a.cta:focus-visible {
            outline: 3px solid var(--brand);
            outline-offset: 3px;
        }

        p.note {
            margin: .875rem 0 0;
            text-align: center;
            font-size: .8125rem;
            color: var(--muted);
        }

        ul.points {
            margin: 2.25rem 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: .625rem;
        }

        ul.points li {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .875rem 1rem;
            border: 1px solid var(--line);
            border-radius: .875rem;
            font-size: .9375rem;
            font-weight: 600;
        }

        ul.points svg { flex: none; color: var(--brand); }

        @media (min-width: 48rem) {
            .wrap { padding-top: 4rem; }
            h1 { font-size: 2.125rem; }
            a.cta { width: auto; min-width: 20rem; margin-inline: auto; }
            ul.points { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            ul.points li { flex-direction: column; text-align: center; gap: .5rem; }
        }
    </style>
</head>
<body>
    <main class="wrap">
        {{-- The visual: a film frame, which is what is being asked for. Inline, so nothing is fetched. --}}
        <div class="art" aria-hidden="true">
            <svg width="92" height="92" viewBox="0 0 48 48" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand)">
                <rect x="4" y="11" width="31" height="26" rx="4" />
                <path d="M35 21l8-5v16l-8-5z" />
                <path d="M13 11v26M26 11v26" opacity=".35" />
            </svg>
        </div>

        <h1>اطلب الفيديوهات بسهولة</h1>

        <p class="lede">
            تواصل معنا عبر واتساب وشاركنا طلبك، وسنساعدك في اختيار وتنفيذ الفيديوهات المناسبة.
        </p>

        {{--
            `rel="noopener"` because this opens an external application; no `target`, so the reader
            stays in one history stack and the back button returns them here.
        --}}
        <a class="cta" href="{{ $ctaUrl }}" rel="noopener" data-testid="whatsapp-cta">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.38a9.86 9.86 0 004.74 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.13-2.9-7A9.82 9.82 0 0012.04 2zm0 18.02h-.01a8.2 8.2 0 01-4.18-1.15l-.3-.18-3.11.81.83-3.03-.2-.31a8.17 8.17 0 01-1.25-4.36c0-4.53 3.69-8.22 8.23-8.22a8.17 8.17 0 015.81 2.41 8.17 8.17 0 012.41 5.82c0 4.54-3.69 8.21-8.23 8.21zm4.51-6.15c-.25-.12-1.46-.72-1.69-.8-.23-.09-.39-.13-.56.12-.16.25-.64.8-.78.97-.14.16-.29.18-.54.06-.25-.12-1.04-.38-1.98-1.22-.73-.65-1.23-1.46-1.37-1.71-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.15.16-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.86.85-.86 2.07s.89 2.4 1.01 2.56c.12.17 1.74 2.66 4.22 3.73.59.25 1.05.41 1.41.52.59.19 1.13.16 1.56.1.47-.07 1.46-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.11-.22-.17-.47-.29z"/>
            </svg>
            اطلب الآن عبر واتساب
        </a>

        <p class="note">سيتم تحويلك إلى واتساب لإكمال الطلب.</p>

        <ul class="points">
            <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/></svg>
                طلب سريع
            </li>
            <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 01-9 8.3 8.5 8.5 0 01-3.8-.9L3 20.5l1.6-4.9A8.38 8.38 0 013.7 11.5a8.5 8.5 0 018.5-8.5 8.38 8.38 0 018.8 8.5z"/></svg>
                تواصل مباشر
            </li>
            <li>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                متابعة الطلب عبر واتساب
            </li>
        </ul>
    </main>
</body>
</html>
