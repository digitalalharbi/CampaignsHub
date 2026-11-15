import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/ui/States'
import type { PreviewResult } from './api'
import { useWords } from './strings'
import { ToggleChip } from './ui'

/**
 * The live preview in a phone frame (spec §14): the inbox line first — sender name and subject, the way a mail app
 * lists them — then the message itself. Light/dark and AR/EN are the viewer's own choice and do not change the
 * workspace's settings. The HTML comes from the server's real render, shown in a sandboxed frame with no scripts.
 */
export function PhonePreview({
  result, loading, onRender, theme, setTheme, lang, setLang, canRender, emptyText,
}: {
  result: PreviewResult | null
  loading: boolean
  onRender: () => void
  theme: 'light' | 'dark'
  setTheme: (t: 'light' | 'dark') => void
  lang: 'ar' | 'en'
  setLang: (l: 'ar' | 'en') => void
  canRender: boolean
  emptyText: string
}) {
  const { t } = useWords()
  const [open, setOpen] = useState(false)

  return (
    <div data-testid="phone-preview" className="flex min-w-0 flex-col items-center gap-2">
      <div className="flex flex-wrap items-center justify-center gap-1">
        <ToggleChip on={theme === 'light'} onClick={() => setTheme('light')} testid="pv-light">{t('light')}</ToggleChip>
        <ToggleChip on={theme === 'dark'} onClick={() => setTheme('dark')} testid="pv-dark">{t('dark')}</ToggleChip>
        <ToggleChip on={lang === 'ar'} onClick={() => setLang('ar')} testid="pv-ar">AR</ToggleChip>
        <ToggleChip on={lang === 'en'} onClick={() => setLang('en')} testid="pv-en">EN</ToggleChip>
        <Button size="sm" variant="secondary" disabled={!canRender} loading={loading} onClick={() => { setOpen(true); onRender() }} data-testid="pv-render">{t('preview')}</Button>
      </div>
      <div className="w-full max-w-[260px] rounded-[28px] border-4 border-border-strong bg-background p-2 shadow-[var(--shadow-small)]">
        <div className="mx-auto mb-1.5 h-1 w-10 rounded-full bg-border-strong" aria-hidden />
        <div className="rounded-xl bg-surface p-2 text-start" data-testid="pv-inbox">
          <div className="truncate text-xs font-bold text-text-primary" data-testid="pv-sender" title={result?.sender_name}>{result?.sender_name ?? t('senderLabel')}</div>
          <div className="truncate text-xs text-text-secondary" data-testid="pv-subject" title={result?.subject}>{result?.subject ?? t('subjectLabel')}</div>
        </div>
        <div className="mt-1.5 h-[260px] overflow-hidden rounded-xl bg-surface">
          {loading ? <Skeleton className="h-full" /> : result && open ? (
            <iframe title={t('preview')} sandbox="" srcDoc={result.html} data-testid="pv-frame" className="h-full w-full border-0" />
          ) : (
            <p className="p-3 text-xs text-text-muted">{emptyText}</p>
          )}
        </div>
      </div>
    </div>
  )
}
