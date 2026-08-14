import type { ButtonHTMLAttributes, ReactNode } from 'react'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'
type Size = 'sm' | 'md' | 'lg'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  loading?: boolean
  children: ReactNode
}

const base =
  'inline-flex items-center justify-center gap-2 rounded-xl font-semibold ' +
  'transition-all duration-150 active:scale-[0.98] disabled:opacity-55 disabled:cursor-not-allowed ' +
  'disabled:active:scale-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:ring-offset-1 focus-visible:ring-offset-surface'

/*
 * MOBILE-001 — mobile-first heights, tightening on a pointer device rather than the other way round.
 *
 * Every size was a desktop size: 36px, 40px, 44px. On a phone that is the single biggest reason the
 * product felt like a shrunk desktop rather than an app — a 36px control is comfortably clickable
 * with a mouse and a poke with a thumb. The measured audit found 34 such targets on the homepage
 * alone at 375px.
 *
 * So the BASE is now the touch size (44px, the smallest reliable thumb target) and `sm:` and up
 * restore the previous density. Desktop is pixel-identical to before; only the phone changes, which
 * is what "mobile-first" has to mean if it is to mean anything.
 *
 * Widths and paddings are untouched, and so is every colour — this is a hit-area change, not a
 * redesign.
 */
const sizes: Record<Size, string> = {
  sm: 'min-h-11 sm:min-h-0 sm:h-9 px-3 text-sm',
  md: 'min-h-11 sm:min-h-0 sm:h-10 px-4 text-sm',
  lg: 'min-h-11 sm:min-h-0 sm:h-11 px-5 text-base',
}

const variants: Record<Variant, string> = {
  primary: 'bg-brand-600 text-white shadow-[var(--shadow-small)] hover:bg-brand-700',
  secondary: 'bg-surface text-text-primary border border-border-strong hover:bg-surface-hover',
  ghost: 'text-text-secondary hover:bg-surface-hover hover:text-text-primary',
  danger: 'bg-danger text-white shadow-[var(--shadow-small)] hover:opacity-90',
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading,
  children,
  className = '',
  disabled,
  ...rest
}: ButtonProps) {
  return (
    <button
      className={`${base} ${sizes[size]} ${variants[variant]} ${className}`}
      disabled={disabled || loading}
      {...rest}
    >
      {loading && (
        <span
          className="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current/30 border-t-current"
          aria-hidden
        />
      )}
      {children}
    </button>
  )
}
