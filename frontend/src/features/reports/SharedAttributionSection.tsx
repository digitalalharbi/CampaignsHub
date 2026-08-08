import { useQuery } from '@tanstack/react-query'
import { AttributionPanel } from '@/features/analytics/AttributionPanel'
import type { Attribution } from '@/features/analytics/api'
import { useUi } from '@/stores/ui'

/**
 * §14.9 on a client link — ATTRIB-VIS-001.
 *
 * The same panel the operator reads on the analytics tab, rendered from the same service, for a
 * client whose link was given the section. Not a second implementation: a client asking «why does
 * your report say 1,169 orders when my shop recorded 640?» deserves the answer the operator would
 * give, and two answers to that question is worse than none.
 *
 * The parent only mounts this when the link's `sections.attribution` is true, so there is no code
 * path here that handles a refusal. That is deliberate — a section that appears and then fails is
 * worse than one that never appears, and a client cannot tell «not shared» from «broken».
 */
export function SharedAttributionSection({ token, password }: { token: string; password?: string }) {
  const { locale } = useUi()
  const ar = locale === 'ar'

  const q = useQuery({
    queryKey: ['shared-attribution', token],
    queryFn: async (): Promise<Attribution> => {
      const res = await fetch(`/api/v1/reports/shared/${token}/attribution`, {
        headers: { Accept: 'application/json', ...(password ? { 'X-Report-Password': password } : {}) },
      })
      const body = await res.json().catch(() => ({}))
      if (!res.ok) throw new Error(body?.message ?? 'unavailable')

      return body.data
    },
  })

  /*
   * The panel already draws its own loading and error states, so they are handed straight to it
   * rather than reimplemented one level up — two spinners for one request is how a section comes to
   * look broken while it is merely slow.
   */
  return (
    <section data-testid="shared-attribution" className="rounded-2xl border border-border bg-surface p-4">
      <h3 className="mb-1 text-lg font-extrabold text-text-primary">
        {ar ? 'شفافية الإسناد' : 'Attribution transparency'}
      </h3>
      <p className="mb-3 text-sm text-text-secondary">
        {ar
          ? 'ما أبلغت به المنصات مقابل ما أكده المتجر، مع نموذج الإسناد ونافذته وحالة معالجة التكرار.'
          : 'What the platforms reported against what the store confirmed, with the attribution model, its window and the deduplication status.'}
      </p>
      <AttributionPanel data={q.data} loading={q.isLoading} error={q.isError} locale={locale} />
    </section>
  )
}
