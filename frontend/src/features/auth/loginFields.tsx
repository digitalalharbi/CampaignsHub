import { toApiError } from '@/lib/api/client'

/**
 * A labelled input with an icon inside it, laid out the way the page reads.
 *
 * ## A flex row, not an absolutely-positioned icon over a padded input
 *
 * The obvious build — pin the icon with `position: absolute` and reserve room for it with padding —
 * is what put the lock underneath «أدخل كلمة المرور». It only holds while the padding and the
 * icon's offset agree about the icon's width, and they stop agreeing the moment either the icon
 * size, the font or the direction changes. A row cannot overlap: the icon occupies its own track and
 * the field takes what is left.
 *
 * ## Everything sits on the same side as the language
 *
 * The row inherits the page's direction, so in Arabic the icon lands at the right beside its
 * right-aligned label and the reveal control at the left; in English every one of them mirrors.
 * Nothing is pinned to a physical side.
 *
 * ## …but the VALUE still reads left to right
 *
 * `dir="ltr"` on the input, with the alignment set explicitly. An address and a password are Latin
 * strings, and rendering `name@company.com` in a right-to-left context reorders its punctuation —
 * the «.com» ends up in the wrong place and the field looks corrupted. Fixing the direction keeps
 * the string intact; setting the alignment separately keeps it against the same edge as the label.
 * `text-align: start` cannot do this: it resolves against the input's own direction, which is
 * exactly the thing being overridden.
 */
export function IconField({
  id, label, icon, trailing, error, ar, onChange, ...props
}: {
  id: string
  label: string
  icon: React.ReactNode
  trailing?: React.ReactNode
  error?: string
  ar: boolean
  onChange: (value: string) => void
} & Omit<React.InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'id'>) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-semibold text-text-secondary">{label}</label>
      <div
        className={`flex min-h-[52px] items-center gap-3 rounded-xl border bg-surface px-4 transition-colors focus-within:ring-[3px] focus-within:ring-brand-500/15 ${
          error ? 'border-danger focus-within:border-danger' : 'border-border focus-within:border-brand-500'
        }`}
      >
        <span className="shrink-0 text-text-secondary">{icon}</span>
        <input
          id={id}
          dir="ltr"
          onChange={(e) => onChange(e.target.value)}
          style={{ textAlign: ar ? 'right' : 'left' }}
          /*
           * `self-stretch` and 16px, both load-bearing.
           *
           * The input is a flex CHILD now, so without `self-stretch` its own box is only as tall as
           * its line — the row looked right and the touch target had quietly shrunk to about 46px,
           * under the 50px this product holds itself to. And 16px is the threshold below which iOS
           * zooms the whole page on focus, which on a phone throws the layout somebody was reading.
           */
          className="min-w-0 flex-1 self-stretch border-0 bg-transparent text-[16px] text-text-primary outline-none placeholder:text-text-secondary/70"
          aria-invalid={error ? true : undefined}
          {...props}
        />
        {trailing && <span className="shrink-0">{trailing}</span>}
      </div>
      {error && <p className="mt-1.5 text-[13px] text-danger">{error}</p>}
    </div>
  )
}

/**
 * The page's one error surface, so a failure never renders twice or in two different shapes.
 *
 * On the code step it also has to carry what a FIELD would normally say. A refused resend comes back
 * as a validation error on `destination` — «الرجاء الانتظار 43 ثانية قبل طلب رمز جديد» — and there
 * is no destination field on that step to render it. Falling back to the envelope's own `message`
 * there would print Laravel's generic «the given data was invalid», which tells somebody waiting out
 * a cooldown nothing at all. The specific sentence wins whenever there is one.
 */
export function ErrorNote({ error, always = false }: { error: ReturnType<typeof toApiError> | null; always?: boolean }) {
  // Field-level messages are rendered BY the field. Repeating them here would say the same thing
  // twice — except on the code step, which has no field-level slot of its own.
  if (!error || (!always && error.errors)) return null

  const specific = error.errors ? Object.values(error.errors).flat().find(Boolean) : undefined

  return (
    <p data-testid="login-error" role="alert" className="rounded-xl bg-[var(--negative-background)] px-4 py-3 text-sm text-danger">
      {specific ?? error.message}
    </p>
  )
}
