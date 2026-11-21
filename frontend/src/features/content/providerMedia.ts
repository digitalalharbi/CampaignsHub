/**
 * CREATIVE-PREVIEWS-001 — a provider's short-lived signed media link is never put in an `<img>`.
 *
 * Owner, 2026-11-07: «حل مشكلة عدم ظهور المعاينة أو صورة الغلاف لمنصة تيك توك وميتا وباقي المنصات! باستثناء
 * سناب تظهر الصور والمعاينات.» Meta's `scontent-*.fbcdn.net` links carry an `oe=` expiry and TikTok's
 * `*-sign.tiktokcdn.com` / `ibyteimg.com` links an `x-expires` one; hot-linked, they draw a broken frame
 * once that time passes, which is most of the time. The server copies them into our own storage and
 * sends `/media/covers/…` instead. This is the second lock on the same door: should a raw platform link
 * ever reach a still again — an old payload, a new endpoint that forgot — the browser is not asked to
 * load it and the caller's own stated absence is drawn.
 *
 * Snapchat's media links are not on this list: they are what draws today, and the report names them as
 * the one platform that works. Films (`video_url`) are not stills and are not filtered here.
 */
const COPY_ONLY_HOSTS = ['fbcdn.net', 'facebook.com', 'fbsbx.com', 'cdninstagram.com', 'tiktokcdn.com', 'tiktokcdn-us.com', 'ibyteimg.com', 'byteimg.com']

export function isProviderSignedStill(url: string | null | undefined): boolean {
  if (typeof url !== 'string' || !/^https?:\/\//i.test(url)) return false

  let host: string
  try {
    host = new URL(url).hostname.toLowerCase()
  } catch {
    return false
  }

  return COPY_ONLY_HOSTS.some((suffix) => host === suffix || host.endsWith(`.${suffix}`))
}

/** The still a surface may load: the given link, unless it is a provider's short-lived signed grant. */
export function localStill<T extends string | null | undefined>(url: T): T | null {
  return isProviderSignedStill(url) ? null : url
}
