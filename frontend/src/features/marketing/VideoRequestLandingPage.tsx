import { Check, MessageCircle, Video, Zap } from 'lucide-react'

/**
 * SHORT-LINKS-LANDING-001 — the page an ad sends people to.
 *
 * ## Its own address, arrived at by eliminating two others
 *
 * It was first served AT the short link, so `/l/m5pxgr2` stopped forwarding and started rendering.
 * A short link is a forwarder, and repurposing an address already in circulation is a different act
 * from publishing one.
 *
 * Then it was `/videos`, which announces itself as a named page somebody can guess at.
 *
 * It is `/7kq3md`: a short address, at the root, belonging to the page and to nothing else. Not
 * under `/l/`, because that prefix files it under a feature whose job is forwarding.
 *
 * ## What makes it honest for an ad review
 *
 * It does not forward, it is not timed, nothing here reads the user agent or the referrer, and the
 * offer is on the page before any button is pressed. Everybody who opens it gets the same document.
 *
 * ## Why it carries no product chrome
 *
 * No navigation, no sign-in, no dashboard link, no footer. Somebody arriving from a paid ad came for
 * one thing, and every other control on the page is a way of not doing it.
 */

/**
 * Where an order goes — the number the owner confirmed, written once.
 *
 * An earlier version pointed this at the short link `/l/m5pxgr2` so the hop would count the click
 * and own the number. That was dropped deliberately: it filed this page under the short-link
 * feature, which is the coupling the owner asked to remove, and it made the button depend on a row
 * somebody could deactivate without ever opening this page.
 *
 * The cost is honest and worth stating: this page does not count its own clicks. The ad platform
 * already reports the click that brought somebody here, and what happens after it is a WhatsApp
 * conversation — which is measured in WhatsApp, not here.
 */
const ORDER_LINK = 'https://wa.me/966553190369'

export function VideoRequestLandingPage() {
  return (
    /*
      `dir="rtl"` and `lang="ar"` on the page itself rather than inherited.
      This is opened from an Arabic ad by somebody who has never set a language preference here, so
      it states its own rather than taking whatever the last visitor left in storage.
    */
    <div
      dir="rtl"
      lang="ar"
      data-testid="video-request-landing"
      className="flex min-h-screen flex-col items-center justify-center bg-surface px-5 py-12"
    >
      <main className="w-full max-w-lg">
        <div className="flex justify-center">
          <span className="flex h-20 w-20 items-center justify-center rounded-2xl bg-brand-primary-soft text-brand-600">
            <Video size={40} strokeWidth={1.75} aria-hidden />
          </span>
        </div>

        <h1 className="mt-7 text-center text-3xl font-extrabold tracking-tight text-text-primary sm:text-4xl">
          اطلب الفيديوهات بسهولة
        </h1>

        <p className="mx-auto mt-3 max-w-md text-center text-base leading-relaxed text-text-secondary">
          تواصل معنا عبر واتساب وشاركنا طلبك، وسنساعدك في اختيار وتنفيذ الفيديوهات المناسبة.
        </p>

        {/*
          A real link, not a button with a handler.

          It works with JavaScript disabled, it can be opened in a new tab, and a long-press offers
          «copy link» — all of which an ad reviewer expects of a destination and none of which a
          scripted navigation gives them.
        */}
        <a
          href={ORDER_LINK}
          data-testid="whatsapp-cta"
          className="mt-8 flex min-h-14 w-full items-center justify-center gap-2.5 rounded-2xl bg-[#25d366] px-5 text-lg font-bold text-[#06240f] shadow-[0_10px_24px_-12px_rgba(37,211,102,0.85)] transition-transform active:translate-y-px"
        >
          <MessageCircle size={22} aria-hidden />
          اطلب الآن عبر واتساب
        </a>

        <p className="mt-3.5 text-center text-xs text-text-muted">
          سيتم تحويلك إلى واتساب لإكمال الطلب.
        </p>

        <ul className="mt-9 grid gap-2.5 sm:grid-cols-3">
          {[
            { icon: Zap, label: 'طلب سريع' },
            { icon: MessageCircle, label: 'تواصل مباشر' },
            { icon: Check, label: 'متابعة الطلب عبر واتساب' },
          ].map(({ icon: Icon, label }) => (
            <li
              key={label}
              className="flex items-center gap-3 rounded-xl border border-border px-4 py-3.5 text-sm font-semibold text-text-primary sm:flex-col sm:gap-2 sm:text-center"
            >
              <Icon size={18} className="shrink-0 text-brand-600" aria-hidden />
              {label}
            </li>
          ))}
        </ul>
      </main>
    </div>
  )
}
