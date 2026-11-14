import { describe, expect, it } from 'vitest'

/**
 * WORDING-001 — the Owner's style guide (MASTER 2026-11-11, Appendix A task 4) retired these words.
 *
 * The acceptance rule: searching the displayed text for them returns zero. Money is «الميزانية، الإنفاق،
 * المتبقي»; the limit is «الحد», never «العتبة»; usage in a subscription is «الاستخدام»; and the client list is
 * not a «محفظة». Comments may still name a retired word to explain why it is gone, so they are stripped first.
 *
 * DEFERRED lists the files another batch owns (alert rules, registrations, payments); each is listed in the PR
 * as follow-up and leaves this list the moment its own batch lands.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', { query: '?raw', import: 'default', eager: true })

const FORBIDDEN = [
  'العتبة', 'المستهلك', 'المحفظة', 'محفظة', 'الأصليون', 'الأصليين', 'الإبداعات', 'المنصرف', 'المصروف',
  // The bare stems too: «استهلاك»، «مصروف» without the article are the same retired words.
  'استهلاك', 'مصروف', 'تحتاج انتباه',
  'الاستهلاك', 'الإيقاع', 'الحدود المضبوطة', 'المُبلَّغة', 'الاستبدالات', 'التهدئة', 'إطلاق الحملات',
  'سلامة التتبع', 'انقطاع القياس', 'يحتاج انتباه',
]

const DEFERRED = ['/src/features/alerts/', '/src/features/admin/RegistrationsPage', '/src/features/admin/PaymentSettingsPage']

function code(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1')
}

describe('retired words never reach the screen', () => {
  const files = Object.entries(TREE).filter(([path]) =>
    !/\.test\.(ts|tsx)$/.test(path) && !path.includes('/src/test/') && !path.includes('noForbiddenTerms') && !DEFERRED.some((d) => path.includes(d)))

  it.each(FORBIDDEN)('«%s» appears in no interface string', (word) => {
    const offenders = files.filter(([, source]) => code(source).includes(word)).map(([path]) => path)
    expect(offenders).toEqual([])
  })

  it('does not call the money «consumed» or «spent» in the Arabic budget columns', () => {
    const all = files.map(([, s]) => code(s)).join('\n')
    expect(all).not.toMatch(/['"`]المنصرف|['"`]المصروف/)
  })
})
